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
 * Reconciles Keycloak org/group membership onto category-scoped Moodle roles.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\local;

/**
 * The single source of truth for the org role mapping and the assign/unassign reconcile.
 *
 * Both entry points - the hourly sync_org_roles task and the user_loggedin observer -
 * call reconcile_users() here, so they cannot grant different role sets for the same
 * profile data. Keycloak is authoritative: the desired set is computed from the sso*
 * profile fields alone, and any role_assignments row carrying
 * component = 'block_crucible' that is not in that set gets removed.
 *
 * Matching is on exact list elements, not substrings. ssoorg and ssogroups hold
 * delimiter-wrapped lists (",a,b,") so that a dynamic cohort's "contains" operator can
 * be anchored on ",value,", and so that a group called "ex-cyber-managers" can never
 * satisfy a rule that wants "cyber-managers".
 */
class org_roles {
    /** @var string Component marking the role assignments this plugin owns. */
    const COMPONENT = 'block_crucible';

    /** @var string Delimiter used to wrap and separate list values. */
    const DELIM = ',';

    /** @var string The auth plugin org role sync applies to. */
    const AUTH = 'oauth2';

    /** @var array<string, int|null> org name => category id, for the life of the request. */
    private static $categorycache = [];

    /**
     * Keycloak group name => Moodle role shortname.
     *
     * The roles must already exist; a missing role is logged and that pair skipped.
     *
     * @return array
     */
    public static function group_role_map(): array {
        return [
            'cyber-managers' => 'cyber-manager',
            'lab-builders' => 'lab-builder',
            'curriculum-developers' => 'curriculum-developer',
        ];
    }

    /**
     * Whether the administrator has enabled org role sync.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return (bool)get_config('block_crucible', 'enableorgrolesync');
    }

    /**
     * Parse a stored list value into its elements.
     *
     * Accepts both the canonical wrapped form (",a,b,") and a bare comma separated
     * list, so values written before the wrapping convention still resolve.
     *
     * @param string|null $value
     * @return string[] unique, non-empty, in order of first appearance
     */
    public static function split_list(?string $value): array {
        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = [];
        foreach (explode(self::DELIM, $value) as $part) {
            $part = trim($part);
            if ($part !== '' && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * Render list elements in the canonical wrapped form.
     *
     * @param string[] $values
     * @return string "" for an empty list, otherwise ",a,b,"
     */
    public static function join_list(array $values): string {
        $values = self::split_list(implode(self::DELIM, $values));
        if (!$values) {
            return '';
        }

        return self::DELIM . implode(self::DELIM, $values) . self::DELIM;
    }

    /**
     * The value a cohort "contains" condition has to match for an exact list element.
     *
     * @param string $value
     * @return string
     */
    public static function list_needle(string $value): string {
        return self::DELIM . trim($value) . self::DELIM;
    }

    /**
     * Reconcile one user's managed role assignments against their Keycloak state.
     *
     * @param int $userid
     * @param callable|null $trace optional logger, called with one string per line
     * @return array ['assigned' => int, 'unassigned' => int]
     */
    public static function reconcile_user(int $userid, ?callable $trace = null): array {
        return self::reconcile_users([$userid], $trace);
    }

    /**
     * Reconcile the managed role assignments of a set of users.
     *
     * Users whose desired set is empty - org cleared in Keycloak, category renamed
     * away, feature disabled - lose every managed assignment they hold, which is what
     * makes removals propagate without waiting for a login.
     *
     * @param int[] $userids
     * @param callable|null $trace optional logger, called with one string per line
     * @return array ['assigned' => int, 'unassigned' => int]
     */
    public static function reconcile_users(array $userids, ?callable $trace = null): array {
        global $DB;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        $result = ['assigned' => 0, 'unassigned' => 0];
        if (!$userids) {
            return $result;
        }

        $enabled = self::is_enabled();
        $roleids = self::role_ids($trace);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');

        // Everything this plugin has granted these users, wherever it lives. Comparing
        // against all of it - rather than only the contexts we manage to resolve this
        // run - is what cleans up after a renamed or deleted org category.
        $existing = [];
        $rows = $DB->get_records_select(
            'role_assignments',
            "userid {$insql} AND component = :component AND itemid = 0",
            $inparams + ['component' => self::COMPONENT]
        );
        foreach ($rows as $row) {
            $existing[(int)$row->userid][(int)$row->roleid . ':' . (int)$row->contextid] = $row;
        }

        $desired = $enabled ? self::desired_assignments($userids, $roleids, $trace) : [];

        foreach ($userids as $userid) {
            $want = $desired[$userid] ?? [];
            $have = $existing[$userid] ?? [];

            foreach ($want as $key => $pair) {
                if (isset($have[$key])) {
                    continue;
                }
                role_assign($pair['roleid'], $userid, $pair['contextid'], self::COMPONENT, 0);
                $result['assigned']++;
            }

            foreach ($have as $key => $row) {
                if (isset($want[$key])) {
                    continue;
                }
                role_unassign((int)$row->roleid, $userid, (int)$row->contextid, self::COMPONENT, 0);
                $result['unassigned']++;
            }
        }

        if ($result['assigned'] || $result['unassigned']) {
            \cache_helper::purge_by_event('changesincapabilities');
        }

        return $result;
    }

    /**
     * Remove every role assignment this plugin owns, site-wide.
     *
     * Used when the feature is turned off: disabling org role sync should give back
     * what it granted, not freeze it in place.
     *
     * @param callable|null $trace optional logger, called with one string per line
     * @return int number of assignments removed
     */
    public static function revoke_all(?callable $trace = null): int {
        global $DB;

        $count = $DB->count_records('role_assignments', ['component' => self::COMPONENT]);
        if (!$count) {
            return 0;
        }

        role_unassign_all(['component' => self::COMPONENT]);
        \cache_helper::purge_by_event('changesincapabilities');

        if ($trace) {
            $trace("revoked {$count} managed role assignment(s).");
        }

        return $count;
    }

    /**
     * Every user who either carries org data or currently holds a managed assignment.
     *
     * The second half matters: a user whose ssoorg was cleared has no org data left to
     * find them by, but still needs their roles taken away.
     *
     * @return int[]
     */
    public static function users_to_reconcile(): array {
        global $DB;

        $ids = $DB->get_fieldset_select(
            'role_assignments',
            'DISTINCT userid',
            'component = ?',
            [self::COMPONENT]
        );

        $orgfieldid = profile_fields::field_id(profile_fields::ORG);
        if ($orgfieldid) {
            $ids = array_merge($ids, $DB->get_fieldset_sql(
                'SELECT DISTINCT d.userid
                   FROM {user_info_data} d
                   JOIN {user} u ON u.id = d.userid
                  WHERE d.fieldid = ? AND u.deleted = 0 AND ' .
                      $DB->sql_isnotempty('d', 'd.data', false, true),
                [$orgfieldid]
            ));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Resolve the role shortnames we map to, keyed by shortname.
     *
     * @param callable|null $trace optional logger, called with one string per line
     * @return array shortname => roleid
     */
    public static function role_ids(?callable $trace = null): array {
        global $DB;

        $shortnames = array_values(self::group_role_map());
        [$insql, $params] = $DB->get_in_or_equal($shortnames);
        $found = $DB->get_records_select_menu('role', "shortname {$insql}", $params, '', 'shortname, id');

        if ($trace) {
            foreach (array_diff($shortnames, array_keys($found)) as $missing) {
                $trace("WARNING: role '{$missing}' does not exist - users in the matching group get nothing.");
            }
        }

        return array_map('intval', $found);
    }

    /**
     * Find the top-level course category that represents an org.
     *
     * Categories are never created automatically: a typo in Keycloak must not be able
     * to conjure a category, and an unrecognised org must grant nothing.
     *
     * @param string $org
     * @return int|null category id, or null when no category represents this org
     */
    public static function org_category_id(string $org): ?int {
        global $DB;

        if (array_key_exists($org, self::$categorycache)) {
            return self::$categorycache[$org];
        }

        $id = $DB->get_field('course_categories', 'id', ['name' => $org, 'parent' => 0], IGNORE_MULTIPLE);
        if (!$id) {
            $id = $DB->get_field(
                'course_categories',
                'id',
                ['idnumber' => 'org-' . self::slugify($org), 'parent' => 0],
                IGNORE_MULTIPLE
            );
        }

        return self::$categorycache[$org] = $id ? (int)$id : null;
    }

    /**
     * Forget the per-request org category lookups.
     *
     * Needed by tests, which create categories between reconciles inside one request.
     */
    public static function reset_caches(): void {
        self::$categorycache = [];
    }

    /**
     * Convert a value to the slug used in the org- category idnumber convention.
     *
     * @param string $value
     * @return string
     */
    public static function slugify(string $value): string {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($value)));
    }

    /**
     * Compute the role assignments Keycloak state says each user should hold.
     *
     * @param int[] $userids
     * @param array $roleids shortname => roleid
     * @param callable|null $trace optional logger, called with one string per line
     * @return array userid => ["roleid:contextid" => ['roleid' => int, 'contextid' => int]]
     */
    private static function desired_assignments(array $userids, array $roleids, ?callable $trace = null): array {
        $map = self::group_role_map();
        $desired = [];

        foreach (self::load_sso_state($userids) as $userid => $state) {
            // The cohort rules this plugin writes are scoped to auth = oauth2; keep the
            // reconcile scoped the same way so the two never disagree.
            if ($state['auth'] !== self::AUTH) {
                continue;
            }

            $groups = self::split_list($state['groups']);
            if (!$groups) {
                continue;
            }

            foreach (self::split_list($state['org']) as $org) {
                $categoryid = self::org_category_id($org);
                if (!$categoryid) {
                    if ($trace) {
                        $trace("  no top-level category for org '{$org}' - granting nothing there.");
                    }
                    continue;
                }
                $context = \context_coursecat::instance($categoryid, IGNORE_MISSING);
                if (!$context) {
                    continue;
                }

                foreach ($groups as $group) {
                    if (!isset($map[$group]) || !isset($roleids[$map[$group]])) {
                        continue;
                    }
                    $roleid = $roleids[$map[$group]];
                    // Union semantics: several mapped groups grant several roles in the
                    // same context, and Moodle merges same-context assignments.
                    $desired[$userid][$roleid . ':' . $context->id] = [
                        'roleid' => $roleid,
                        'contextid' => (int)$context->id,
                    ];
                }
            }
        }

        return $desired;
    }

    /**
     * Load auth method and the two sso list fields for a set of users in one query.
     *
     * @param int[] $userids
     * @return array userid => ['auth' => string, 'org' => string|null, 'groups' => string|null]
     */
    private static function load_sso_state(array $userids): array {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'u');
        $params['orgfield'] = (int)profile_fields::field_id(profile_fields::ORG);
        $params['groupfield'] = (int)profile_fields::field_id(profile_fields::GROUPS);

        $rows = $DB->get_records_sql(
            "SELECT u.id, u.auth, org.data AS orgdata, grp.data AS groupdata
               FROM {user} u
          LEFT JOIN {user_info_data} org ON org.userid = u.id AND org.fieldid = :orgfield
          LEFT JOIN {user_info_data} grp ON grp.userid = u.id AND grp.fieldid = :groupfield
              WHERE u.id {$insql} AND u.deleted = 0",
            $params
        );

        $state = [];
        foreach ($rows as $row) {
            $state[(int)$row->id] = [
                'auth' => $row->auth,
                'org' => $row->orgdata,
                'groups' => $row->groupdata,
            ];
        }

        return $state;
    }
}
