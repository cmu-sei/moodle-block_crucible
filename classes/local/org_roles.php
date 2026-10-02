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

    /** @var string Resolved by an administrator-configured alias. */
    const RESOLVE_ALIAS = 'alias';

    /** @var string An alias is configured, but names no category. */
    const RESOLVE_ALIASMISSING = 'aliasmissing';

    /** @var string Resolved by the org- idnumber convention. */
    const RESOLVE_IDNUMBER = 'idnumber';

    /** @var string Resolved by an exact top-level category name. */
    const RESOLVE_NAME = 'name';

    /** @var string Several top-level categories carry that name, so none was chosen. */
    const RESOLVE_AMBIGUOUS = 'ambiguous';

    /** @var string Nothing represents this org. */
    const RESOLVE_UNMATCHED = 'unmatched';

    /** @var array<string, array> org name => resolution result, for the life of the request. */
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
     * A value containing ", " is read as one opaque element instead of being split.
     * join_list() can never produce that sequence - it trims every element and drops any
     * element containing the delimiter - so the only values carrying it are the ones the
     * 2026092300 upgrade deliberately preserved as "a single name that happens to contain a
     * comma". Splitting "Acme, Holdings" there would produce "Acme" and "Holdings", and if either
     * happened to name a top-level category the user would be granted roles in an
     * organization they have nothing to do with. Granting nothing is the safe reading.
     *
     * Note that this is deliberately narrower than "anything unwrapped". Bare values keep
     * being written after the upgrade - an OAuth 2 login field mapping stores the raw claim
     * unwrapped at every login, and is the only writer of ssogroups in one deployment - so
     * treating every unwrapped value as opaque would stop a multi-group user matching any
     * group and revoke the roles of exactly the most privileged accounts.
     *
     * @param string|null $value
     * @return string[] unique, non-empty, in order of first appearance
     */
    public static function split_list(?string $value): array {
        if ($value === null || trim($value) === '') {
            return [];
        }

        if (strpos($value, self::DELIM . ' ') !== false) {
            return [trim($value)];
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
     * Each array element is one list element and is kept whole. An element containing the
     * delimiter is dropped rather than stored: the delimiter is structural here, so
     * "Acme, Inc." would otherwise be split into "Acme" and "Inc.", two organizations
     * that match no category and no cohort rule. Dropping it loudly beats inventing them.
     *
     * The debugging() call here is only a developer aid, and a caller that would *store*
     * the result must not rely on it: dropping every value returns "", and writing that
     * over a real organization destroys it. Ask unstorable_values() first.
     *
     * @param string[] $values
     * @return string "" for an empty list, otherwise ",a,b,"
     */
    public static function join_list(array $values): string {
        $elements = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '' || in_array($value, $elements, true)) {
                continue;
            }
            if (strpos($value, self::DELIM) !== false) {
                debugging(
                    "block_crucible: list value '" . $value . "' contains the '" . self::DELIM
                    . "' delimiter and cannot be stored - it has been dropped.",
                    DEBUG_DEVELOPER
                );
                continue;
            }
            $elements[] = $value;
        }

        if (!$elements) {
            return '';
        }

        return self::DELIM . implode(self::DELIM, $elements) . self::DELIM;
    }

    /**
     * The values join_list() would refuse to store, so a caller can tell "no values" from
     * "no storable values".
     *
     * "Acme, Inc." is an ordinary organization name, and this storage cannot represent it
     * while the delimiter is a comma - splitting it invents two organizations that match
     * no category, so join_list() drops it and returns "". A caller that then wrote ""
     * would replace a real organization with nothing, which is the thing to avoid.
     *
     * @param string[] $values
     * @return string[] the unstorable values, trimmed, in the order given
     */
    public static function unstorable_values(array $values): array {
        $unstorable = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '' && strpos($value, self::DELIM) !== false) {
                $unstorable[] = $value;
            }
        }

        return $unstorable;
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
        $removedin = [];

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
                $contextid = (int)$row->contextid;
                $removedin[$contextid] = ($removedin[$contextid] ?? 0) + 1;
            }
        }

        if ($trace && $removedin) {
            // Say where the losses landed. A revocation is almost always a side effect of
            // something else - an org renamed in Keycloak, a category renamed in the UI -
            // and without this the only evidence is users reporting lost access.
            foreach ($removedin as $contextid => $count) {
                $trace("revoked {$count} managed grant(s) in " . self::context_label($contextid)
                    . ' - nothing resolving there grants them any more.');
            }
        }

        if ($result['assigned'] || $result['unassigned']) {
            \cache_helper::purge_by_event('changesincapabilities');
        }

        return $result;
    }

    /**
     * Name a context for the log, falling back to its id when it has gone.
     *
     * A deleted category takes its context with it, and that is exactly when a revocation
     * most needs explaining, so this must not throw.
     *
     * @param int $contextid
     * @return string
     */
    private static function context_label(int $contextid): string {
        $context = \context::instance_by_id($contextid, IGNORE_MISSING);

        return $context ? "category '" . $context->get_context_name(false) . "'" : "context {$contextid}";
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
        return self::resolve_org($org)['categoryid'];
    }

    /**
     * Resolve an org to a category, and say which rule matched.
     *
     * The settings page reports resolution to an administrator from this same function, so
     * the report cannot claim an outcome the reconcile would not reach.
     *
     * Order is alias, then idnumber, then name. The alias is an administrator's explicit
     * instruction, so it wins and may point at a category at any depth. The two conventions
     * are guesses, so they only match at the top level. The idnumber comes before the name
     * because the name is what gets renamed in the UI, while the idnumber is a stable key.
     *
     * Name matching ignores case and surrounding whitespace. An exact match meant that
     * tidying a category's capitalisation in the UI silently stopped resolving the org, and
     * because every unresolved org revokes what it granted, the next run took every role in
     * that category away. One site's grants were revoked that way in a replay against live
     * data, by a rename made days after the grants.
     *
     * Several top-level categories may match the same name - including two that differ only
     * in case. That used to resolve to an arbitrary one through IGNORE_MULTIPLE, which could
     * grant roles in the wrong organization's category with nothing said. Ambiguity now
     * grants nothing and is reported, because guessing is the worse answer.
     *
     * @param string $org
     * @return array ['categoryid' => int|null, 'how' => string, one of the RESOLVE_* values]
     */
    public static function resolve_org(string $org): array {
        global $DB;

        $org = trim($org);
        if (array_key_exists($org, self::$categorycache)) {
            return self::$categorycache[$org];
        }

        $result = ['categoryid' => null, 'how' => self::RESOLVE_UNMATCHED];
        $aliases = self::org_aliases();
        $key = \core_text::strtolower($org);

        if (isset($aliases[$key])) {
            $target = $aliases[$key];
            // The administrator named this category, so a duplicate name here is their own
            // doing rather than a guess of ours - take either one.
            $id = $DB->get_field_select(
                'course_categories',
                'id',
                $DB->sql_equal('TRIM(name)', ':name', false) . ' OR idnumber = :idnumber',
                ['name' => $target, 'idnumber' => $target],
                IGNORE_MULTIPLE
            );
            $result = [
                'categoryid' => $id ? (int)$id : null,
                'how' => $id ? self::RESOLVE_ALIAS : self::RESOLVE_ALIASMISSING,
            ];

            return self::$categorycache[$org] = $result;
        }

        $id = $DB->get_field(
            'course_categories',
            'id',
            ['idnumber' => 'org-' . self::slugify($org), 'parent' => 0],
            IGNORE_MULTIPLE
        );
        if ($id) {
            $result = ['categoryid' => (int)$id, 'how' => self::RESOLVE_IDNUMBER];

            return self::$categorycache[$org] = $result;
        }

        $named = $DB->get_fieldset_select(
            'course_categories',
            'id',
            'parent = :parent AND ' . $DB->sql_equal('TRIM(name)', ':name', false),
            ['parent' => 0, 'name' => $org]
        );
        if (count($named) === 1) {
            $result = ['categoryid' => (int)reset($named), 'how' => self::RESOLVE_NAME];
        } else if (count($named) > 1) {
            $result = ['categoryid' => null, 'how' => self::RESOLVE_AMBIGUOUS];
        }

        return self::$categorycache[$org] = $result;
    }

    /**
     * The administrator-configured org to category aliases.
     *
     * Needed because the idnumber convention cannot be relied on: a site whose top-level
     * categories already carry idnumbers for another purpose can never match org-<slug>, and
     * an organization whose name does not match a category by name has nothing left. The map
     * ships empty and is set per site, because the org list is whatever distinct ssoorg
     * values Keycloak produced and so cannot live in a source file.
     *
     * Keyed on the whole raw ssoorg value, lower-cased. An organization name may contain the
     * delimiter, and such a value never survives split_list(), so matching per split element
     * would never see it - see org_values(). Lower-cased because these lines are typed by
     * hand against a value nobody sees, and matching the capitalisation exactly is not a
     * requirement worth a silent miss.
     *
     * @return array<string, string> lower-cased ssoorg value => category name or idnumber
     */
    public static function org_aliases(): array {
        $raw = (string)get_config('block_crucible', 'orgcategoryaliases');
        $map = [];

        foreach (preg_split('/\R/', $raw) as $line) {
            if (strpos($line, '|') === false) {
                continue;
            }
            [$org, $target] = explode('|', $line, 2);
            $org = trim($org);
            $target = trim($target);
            if ($org !== '' && $target !== '') {
                $map[\core_text::strtolower($org)] = $target;
            }
        }

        return $map;
    }

    /**
     * The org values to resolve from one stored ssoorg field.
     *
     * Aliases are keyed on the whole field, unwrapped, and are checked before it is split -
     * otherwise an alias for an organization whose name contains the delimiter could never
     * match, which is exactly the case aliases exist to rescue. Anything not aliased splits
     * as before.
     *
     * @param string|null $value stored field value
     * @return string[]
     */
    public static function org_values(?string $value): array {
        $whole = trim(trim((string)$value), self::DELIM);
        if ($whole !== '' && isset(self::org_aliases()[\core_text::strtolower($whole)])) {
            return [$whole];
        }

        return self::split_list($value);
    }

    /**
     * Every distinct org named by any user's ssoorg field.
     *
     * Uses org_values(), so an aliased organization whose name contains the delimiter counts
     * as itself rather than as two fragments that represent nothing.
     *
     * @return string[]
     */
    public static function distinct_orgs(): array {
        global $DB;

        $fieldid = profile_fields::field_id(profile_fields::ORG);
        if (!$fieldid) {
            return [];
        }

        $values = $DB->get_fieldset_sql(
            'SELECT DISTINCT data FROM {user_info_data} WHERE fieldid = ? AND ' .
                $DB->sql_isnotempty('user_info_data', 'data', false, true),
            [$fieldid]
        );

        $orgs = [];
        foreach ($values as $value) {
            foreach (self::org_values($value) as $org) {
                $orgs[$org] = true;
            }
        }

        return array_keys($orgs);
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
        // One line per org, not one per user: a site where an org stops resolving has every
        // user carrying it in this list, and the same line thousands of times buries it.
        $reported = [];

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

            foreach (self::org_values($state['org']) as $org) {
                $resolved = self::resolve_org($org);
                $categoryid = $resolved['categoryid'];
                if (!$categoryid) {
                    if ($trace && !isset($reported[$org])) {
                        $reported[$org] = true;
                        $trace("  org '{$org}' resolved to no category (" . $resolved['how']
                            . ") - granting nothing there.");
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
