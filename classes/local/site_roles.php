<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Reconciles Keycloak group membership onto site-level Moodle roles.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\local;

/**
 * The site role map and the system-context reconcile.
 *
 * Deliberately separate from org_roles. Category roles are granted from the ssogroupslist
 * profile field, which anyone who can edit a profile can change, and that is tolerable there
 * because the next hourly run puts it back. A site-level role is a different order of
 * privilege - the SSO Administrator role among them - so these grants never read a profile
 * field. Every caller hands in group membership it has just read from the Keycloak admin
 * API, and anything that could not be read leaves the grants as they are.
 *
 * The assignments carry component = block_crucible, so Moodle's role UI cannot remove them,
 * and they live only at the system context, which org_roles leaves alone.
 */
class site_roles {
    /** @var string Config name of the site role map. */
    const SETTING = 'siterolemap';

    /** @var int Revocations a single run may make before it refuses to make any change. */
    const DEFAULT_MAX_REVOCATIONS = 2;

    /** @var string The mapped role exists but is not allowed in the system context. */
    const ROLE_NOTINSYSTEM = 'notinsystem';

    /**
     * Keycloak group name => Moodle role shortname, granted at the system context.
     *
     * Ships empty. Unlike the category map there is no history to preserve, and a site role
     * nobody configured is not one to hand out.
     *
     * @return array<string, string>
     */
    public static function map(): array {
        $map = [];
        foreach (self::lines() as $line) {
            $map[$line['group']] = $line['role'];
        }

        return $map;
    }

    /**
     * Every configured mapping, in the order written, duplicates included.
     *
     * @return array<int, array{group: string, role: string}>
     */
    private static function lines(): array {
        return org_roles::parse_map_lines((string)get_config('block_crucible', self::SETTING));
    }

    /**
     * Store the site role map, in the same text the setting holds.
     *
     * @param array<string, string> $mapping group name => role shortname
     */
    public static function set_map(array $mapping): void {
        org_roles::store_map(self::SETTING, $mapping);
    }

    /**
     * Whether there is anything for the site reconcile to do.
     *
     * A map that has just been emptied still has grants to take back, so this stays true
     * until the last one has gone. With no map and no grants the plugin behaves exactly as
     * it did before the site map existed.
     *
     * @return bool
     */
    public static function is_active(): bool {
        global $DB;

        if (self::map()) {
            return true;
        }

        return $DB->record_exists('role_assignments', [
            'component' => org_roles::COMPONENT,
            'contextid' => \context_system::instance()->id,
        ]);
    }

    /**
     * The most site-level assignments one run may revoke.
     *
     * Tighter than the user sync's deprovision share on purpose. Losing a site role is
     * immediate and the people who lose it are the ones who would fix the cause, so a run
     * that wants to take more than a handful away is far more likely a broken read - a
     * renamed group, a changed service account - than a decision.
     *
     * @return int
     */
    public static function max_revocations(): int {
        $value = get_config('block_crucible', 'siterolemaxrevoke');
        if ($value === false || $value === null || trim((string)$value) === '') {
            return self::DEFAULT_MAX_REVOCATIONS;
        }

        return max(0, (int)$value);
    }

    /**
     * Record which site-mapped groups the realm turned out not to have.
     *
     * @param string[] $missing configured group names the realm has no group for
     */
    public static function record_missing_groups(array $missing): void {
        set_config('siterolemissing', org_roles::join_list($missing), 'block_crucible');
        set_config('siterolecheckedat', time(), 'block_crucible');
    }

    /**
     * The site-mapped groups the last successful group read did not find.
     *
     * @return string[]
     */
    public static function missing_groups(): array {
        return org_roles::split_list((string)get_config('block_crucible', 'siterolemissing'));
    }

    /**
     * Each configured mapping and whether its role can actually be granted at site level.
     *
     * @return array<int, array{group: string, role: string, state: string}> state is one of
     *         org_roles' ROLE_* values or ROLE_NOTINSYSTEM
     */
    public static function report(): array {
        global $DB;

        $lines = self::lines();
        $missing = self::missing_groups();

        $inforce = [];
        foreach ($lines as $index => $line) {
            $inforce[$line['group']] = $index;
        }

        $rows = [];
        foreach ($lines as $index => $line) {
            if ($index !== $inforce[$line['group']]) {
                $state = org_roles::ROLE_OVERRIDDEN;
            } else if (in_array($line['group'], $missing, true)) {
                $state = org_roles::ROLE_NOGROUP;
            } else {
                $roleid = (int)$DB->get_field('role', 'id', ['shortname' => $line['role']], IGNORE_MISSING);
                if (!$roleid) {
                    $state = org_roles::ROLE_MISSING;
                } else if (!self::allowed_in_system($roleid)) {
                    $state = self::ROLE_NOTINSYSTEM;
                } else {
                    $state = org_roles::ROLE_OK;
                }
            }
            $rows[] = ['group' => $line['group'], 'role' => $line['role'], 'state' => $state];
        }

        return $rows;
    }

    /**
     * Who holds each mapped role at site level, split by where the grant came from.
     *
     * "managed" is what this plugin granted from Keycloak. "other" is everyone else holding
     * the same role in the system context without a managed grant beside it - a manual
     * assignment, a cohort role, anything the plugin does not own. Those are the grants a
     * site moving onto the site map has to clean up by hand.
     *
     * @return array<string, array{managed: \stdClass[], other: \stdClass[]}> role shortname =>
     *         user records carrying id, username and the name fields
     */
    public static function holders(): array {
        global $DB;

        $shortnames = array_values(array_unique(self::map()));
        if (!$shortnames) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($shortnames, SQL_PARAMS_NAMED, 'r');
        $roles = $DB->get_records_select_menu('role', "shortname {$insql}", $params, '', 'id, shortname');

        $result = [];
        foreach ($shortnames as $shortname) {
            $result[$shortname] = ['managed' => [], 'other' => []];
        }
        if (!$roles) {
            return $result;
        }

        $namefields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        [$rolesql, $roleparams] = $DB->get_in_or_equal(array_keys($roles), SQL_PARAMS_NAMED, 'rid');
        $rows = $DB->get_recordset_sql(
            "SELECT ra.id AS raid, ra.roleid, ra.component, u.id, u.username, {$namefields}
               FROM {role_assignments} ra
               JOIN {user} u ON u.id = ra.userid AND u.deleted = 0
              WHERE ra.contextid = :sysctx AND ra.roleid {$rolesql}
           ORDER BY u.lastname, u.firstname, u.id",
            $roleparams + ['sysctx' => \context_system::instance()->id]
        );

        $managed = [];
        $other = [];
        foreach ($rows as $row) {
            $shortname = $roles[(int)$row->roleid];
            $user = clone $row;
            unset($user->raid, $user->roleid, $user->component);
            if ($row->component === org_roles::COMPONENT) {
                $managed[$shortname][(int)$row->id] = $user;
            } else {
                $other[$shortname][(int)$row->id] = $user;
            }
        }
        $rows->close();

        foreach ($shortnames as $shortname) {
            $result[$shortname]['managed'] = array_values($managed[$shortname] ?? []);
            $result[$shortname]['other'] = array_values(
                array_diff_key($other[$shortname] ?? [], $managed[$shortname] ?? [])
            );
        }

        return $result;
    }

    /**
     * Reconcile site-level grants against group membership just read from Keycloak.
     *
     * Grants what the membership says and is missing, and revokes managed system-context
     * grants it no longer backs. A run that would revoke more than max_revocations() makes no
     * change at all, grants included: whatever made it want to strip that many people is
     * more likely to be wrong about the rest too.
     *
     * @param array<int, string[]> $membership Moodle user id => Keycloak group names, for every
     *        user in scope who is in any group. A user in scope and absent here is in none.
     * @param int[]|null $scope the users to reconcile, or null for every user this plugin has a
     *        site-level grant for plus every user in $membership
     * @param callable|null $trace optional logger, called with one string per line
     * @param bool $grantonly add missing grants but revoke nothing, for a caller that cannot
     *        tell "left the group" from "the group is gone"
     * @return array ['assigned' => int, 'unassigned' => int, 'blocked' => bool]
     */
    public static function reconcile(
        array $membership,
        ?array $scope = null,
        ?callable $trace = null,
        bool $grantonly = false
    ): array {
        global $DB;

        $result = ['assigned' => 0, 'unassigned' => 0, 'blocked' => false];
        $sysctx = \context_system::instance()->id;
        $map = self::map();
        $roleids = self::role_ids($trace);

        // A mapped role that cannot be granted is the role-side twin of a missing group: a
        // renamed shortname or an unticked System context level, far more often than a
        // decision. Granting the rest while revoking every holder of that role would strip
        // them for a settings slip, so the whole pass waits until it is fixed.
        $unusable = array_diff(array_unique(array_values($map)), array_keys($roleids));
        if ($unusable) {
            $result['blocked'] = true;
            if ($trace) {
                $trace("site roles: mapped role(s) '" . implode("', '", $unusable) . "' cannot be granted at site"
                    . ' level - making no site-level change. Fix the role or the site role mappings.');
            }
            return $result;
        }

        $params = ['component' => org_roles::COMPONENT, 'sysctx' => $sysctx];
        $where = 'component = :component AND contextid = :sysctx AND itemid = 0';
        if ($scope !== null) {
            $scope = array_values(array_unique(array_map('intval', $scope)));
            if (!$scope) {
                return $result;
            }
            [$insql, $inparams] = $DB->get_in_or_equal($scope, SQL_PARAMS_NAMED, 'u');
            $where .= " AND userid {$insql}";
            $params += $inparams;
            $membership = array_intersect_key($membership, array_flip($scope));
        }

        $have = [];
        foreach ($DB->get_records_select('role_assignments', $where, $params, '', 'id, userid, roleid') as $row) {
            $have[(int)$row->userid . ':' . (int)$row->roleid] = ['userid' => (int)$row->userid, 'roleid' => (int)$row->roleid];
        }

        // Only linked OAuth 2 accounts are granted anything, matching the category reconcile.
        $eligible = [];
        if ($membership) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_keys($membership), SQL_PARAMS_NAMED, 'm');
            $eligible = array_flip(array_map('intval', $DB->get_fieldset_select(
                'user',
                'id',
                "id {$insql} AND deleted = 0 AND auth = :auth",
                $inparams + ['auth' => org_roles::AUTH]
            )));
        }

        $want = [];
        foreach ($membership as $userid => $groups) {
            if (!isset($eligible[(int)$userid])) {
                continue;
            }
            foreach ($groups as $group) {
                if (!isset($map[$group]) || !isset($roleids[$map[$group]])) {
                    continue;
                }
                $roleid = $roleids[$map[$group]];
                $want[(int)$userid . ':' . $roleid] = ['userid' => (int)$userid, 'roleid' => $roleid];
            }
        }

        $grant = array_diff_key($want, $have);
        $revoke = $grantonly ? [] : array_diff_key($have, $want);

        $limit = self::max_revocations();
        if (count($revoke) > $limit) {
            $result['blocked'] = true;
            if ($trace) {
                $trace('site roles: this run would revoke ' . count($revoke) . ' site-level assignment(s), over the'
                    . " limit of {$limit} - making no site-level change. Check the site role mappings, the Keycloak"
                    . ' groups and the service account, or raise the limit if the removals are intended.');
            }
            return $result;
        }

        $names = self::usernames(array_merge(array_column($grant, 'userid'), array_column($revoke, 'userid')));
        $shortnames = array_flip($roleids);

        foreach ($grant as $pair) {
            role_assign($pair['roleid'], $pair['userid'], $sysctx, org_roles::COMPONENT, 0);
            $result['assigned']++;
            if ($trace) {
                $trace("site roles: granted '" . ($shortnames[$pair['roleid']] ?? $pair['roleid']) . "' to "
                    . ($names[$pair['userid']] ?? '?') . " (id {$pair['userid']}).");
            }
        }
        foreach ($revoke as $pair) {
            role_unassign($pair['roleid'], $pair['userid'], $sysctx, org_roles::COMPONENT, 0);
            $result['unassigned']++;
            if ($trace) {
                $trace("site roles: revoked '" . self::role_label($pair['roleid'], $shortnames) . "' from "
                    . ($names[$pair['userid']] ?? '?') . " (id {$pair['userid']}).");
            }
        }

        if ($result['assigned'] || $result['unassigned']) {
            \cache_helper::purge_by_event('changesincapabilities');
        }

        return $result;
    }

    /**
     * Mapped role shortname => id, for the roles that exist and may be held site-wide.
     *
     * @param callable|null $trace optional logger, called with one string per line
     * @return array<string, int>
     */
    private static function role_ids(?callable $trace = null): array {
        global $DB;

        $shortnames = array_values(array_unique(self::map()));
        if (!$shortnames) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($shortnames);
        $found = array_map('intval', $DB->get_records_select_menu('role', "shortname {$insql}", $params, '', 'shortname, id'));

        $usable = [];
        foreach ($shortnames as $shortname) {
            if (!isset($found[$shortname])) {
                if ($trace) {
                    $trace("site roles: WARNING: role '{$shortname}' does not exist - its group grants nothing.");
                }
                continue;
            }
            if (!self::allowed_in_system($found[$shortname])) {
                if ($trace) {
                    $trace("site roles: WARNING: role '{$shortname}' cannot be assigned at system level -"
                        . ' its group grants nothing.');
                }
                continue;
            }
            $usable[$shortname] = $found[$shortname];
        }

        return $usable;
    }

    /**
     * Whether a role may be assigned in the system context.
     *
     * @param int $roleid
     * @return bool
     */
    private static function allowed_in_system(int $roleid): bool {
        return in_array(CONTEXT_SYSTEM, array_map('intval', get_role_contextlevels($roleid)), true);
    }

    /**
     * Name a role being revoked, which may no longer be mapped or even exist.
     *
     * @param int $roleid
     * @param array<int, string> $shortnames roleid => shortname for the usable mapped roles
     * @return string
     */
    private static function role_label(int $roleid, array $shortnames): string {
        global $DB;

        return $shortnames[$roleid] ?? (string)($DB->get_field('role', 'shortname', ['id' => $roleid]) ?: $roleid);
    }

    /**
     * Usernames for the log lines.
     *
     * @param int[] $userids
     * @return array<int, string>
     */
    private static function usernames(array $userids): array {
        global $DB;

        if (!$userids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_values(array_unique($userids)));

        return $DB->get_records_select_menu('user', "id {$insql}", $params, '', 'id, username');
    }
}
