<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/*
Crucible Applications Landing Page Block for Moodle

Copyright 2024 Carnegie Mellon University.

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL.
CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM24-1176
*/

namespace block_crucible\task;

use block_crucible\local\org_roles;
use block_crucible\local\profile_fields;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: sync Keycloak org group memberships to Moodle category-scoped roles.
 *
 * Each run:
 *   1. Maintains one dynamic cohort per org × Keycloak group, for admins to enrol and
 *      report on. These are an output of the mapping, not an input to it.
 *   2. Reconciles every affected user's role assignments against their sso* profile
 *      fields via \block_crucible\local\org_roles, which the login observer also uses.
 *
 * Adding a new org requires:
 *   1. Admin manually creates a top-level course category with the org's exact name
 *      (or with idnumber org-<slug>).
 *   2. This task will discover the category and assign roles on its next run.
 *   This prevents automatic category creation from typos or unauthorized orgs.
 *
 * Adding a new group/role mapping only requires extending org_roles::group_role_map().
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_org_roles extends \core\task\scheduled_task {
    /** @var string tool_dynamic_cohorts condition matching on the auth plugin. */
    const CLASS_AUTH = 'tool_dynamic_cohorts\\local\\tool_dynamic_cohorts\\condition\\auth_method';

    /** @var string tool_dynamic_cohorts condition matching on a custom profile field. */
    const CLASS_PROFILE = 'tool_dynamic_cohorts\\local\\tool_dynamic_cohorts\\condition\\user_custom_profile';

    /** @var int condition_base::TEXT_CONTAINS */
    const OP_CONTAINS = '1';

    /** @var int condition_base::TEXT_IS_EQUAL_TO */
    const OP_EQUALS = '3';

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_org_roles', 'block_crucible');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute(): void {
        global $CFG;

        $trace = static function (string $line): void {
            mtrace('sync_org_roles: ' . $line);
        };

        if (!org_roles::is_enabled()) {
            // Turning the feature off has to give back what it granted, otherwise every
            // managed assignment is orphaned with no code path left to clean it up.
            $trace('org role sync is disabled in plugin settings.');
            org_roles::revoke_all($trace);
            return;
        }

        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->libdir . '/accesslib.php');

        foreach ([profile_fields::ORG, profile_fields::GROUPS] as $shortname) {
            if (!profile_fields::field_id($shortname)) {
                $trace("WARNING: profile field '{$shortname}' does not exist, so no user has org data - "
                    . 'every managed assignment will be revoked. Re-run the plugin upgrade to recreate it.');
            }
        }

        $this->sync_cohorts($trace);

        $userids = org_roles::users_to_reconcile();
        $counts = org_roles::reconcile_users($userids, $trace);
        $trace(count($userids) . " user(s) checked: +{$counts['assigned']} assigned, "
            . "-{$counts['unassigned']} removed.");
        $trace('completed.');
    }

    // -------------------------------------------------------------------------
    // Dynamic cohort upkeep
    // -------------------------------------------------------------------------

    /**
     * Keep one dynamic cohort per org × group in step with the mapping.
     *
     * Optional: the cohorts exist so administrators can enrol and report on these
     * populations. Role assignment does not read them, so a missing
     * tool_dynamic_cohorts is a reason to skip this step, not to skip the whole task.
     *
     * @param callable $trace
     */
    private function sync_cohorts(callable $trace): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('tool_dynamic_cohorts')) {
            $trace('tool_dynamic_cohorts is not installed - skipping cohort upkeep '
                . '(role assignment does not depend on it).');
            return;
        }

        $orgs = $this->get_distinct_orgs();
        if (!$orgs) {
            $trace('no ssoorg values found in user profiles - no cohorts to maintain.');
            return;
        }

        $ruleids = [];
        foreach ($orgs as $org) {
            $categoryid = org_roles::org_category_id($org);
            if (!$categoryid) {
                $trace("org '{$org}' has no top-level category - no cohort maintained.");
                continue;
            }

            foreach (array_keys(org_roles::group_role_map()) as $group) {
                $cohortname = $org . ' ' . ucwords(str_replace('-', ' ', $group));
                $cohortidnum = org_roles::slugify($org) . '-' . $group;

                try {
                    $ruleids[] = $this->ensure_org_group_cohort($cohortname, $cohortidnum, $org, $group, $trace);
                } catch (\Exception $e) {
                    $trace("  ERROR maintaining cohort '{$cohortname}': " . $e->getMessage());
                }
            }
        }

        if (!$ruleids) {
            return;
        }

        // Write every rule first, then invalidate once, then process. The two events are
        // the ones tool_dynamic_cohorts declares on its rule and condition caches, so
        // this replaces a full-site purge_all() per org × group pair, every hour.
        \cache_helper::purge_by_event('ruleschanged');
        \cache_helper::purge_by_event('conditionschanged');

        foreach ($ruleids as $ruleid) {
            $this->process_cohort_rule_now($ruleid, $trace);
        }
    }

    /**
     * Return every distinct org named by any user's ssoorg field.
     *
     * A user carrying two orgs contributes both, rather than one bogus "A,B" org that
     * matches no category.
     *
     * @return string[]
     */
    private function get_distinct_orgs(): array {
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
            foreach (org_roles::split_list($value) as $org) {
                $orgs[$org] = true;
            }
        }

        return array_keys($orgs);
    }

    /**
     * Ensure a dynamic cohort and its rule exist for one org + Keycloak group pair.
     *
     * Conditions: auth = oauth2 AND ssoorg contains ",org," AND ssogroups contains
     * ",group,". The delimiters are what stop "ex-cyber-managers" from satisfying a
     * rule that wants "cyber-managers", and "Army Reserve" from satisfying "Army".
     *
     * @param string $cohortname
     * @param string $cohortidnumber
     * @param string $orgvalue
     * @param string $groupvalue
     * @param callable $trace
     * @return int rule id, for the caller to process once the caches have been invalidated
     * @throws \dml_exception
     */
    private function ensure_org_group_cohort(
        string $cohortname,
        string $cohortidnumber,
        string $orgvalue,
        string $groupvalue,
        callable $trace
    ): int {
        global $DB;

        $sysctx = \context_system::instance();
        $adminid = (int)get_admin()->id;
        $now = time();

        // Upsert cohort. The idnumber is the stable key.
        $cohort = $DB->get_record('cohort', ['idnumber' => $cohortidnumber, 'contextid' => $sysctx->id]);
        if (!$cohort) {
            $cohort = (object)[
                'contextid' => $sysctx->id,
                'name' => $cohortname,
                'idnumber' => $cohortidnumber,
                'description' => "Auto-managed: org={$orgvalue}, group={$groupvalue}",
                'descriptionformat' => FORMAT_HTML,
                'visible' => 1,
                'component' => 'tool_dynamic_cohorts',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $cohort->id = cohort_add_cohort($cohort);
            $trace("  created cohort '{$cohortname}' (id: {$cohort->id}).");
        }

        // Upsert the dynamic rule, keyed on the cohort rather than on a display name
        // derived from the org - otherwise renaming an org orphans the old rule and
        // creates a second one pointing at the same cohort.
        $rule = $DB->get_record('tool_dynamic_cohorts', ['cohortid' => $cohort->id]);
        if ($rule) {
            $rule->name = $cohortname;
            $rule->enabled = 1;
            $rule->realtime = 1;
            $rule->operator = 0; // AND.
            $rule->usermodified = $adminid;
            $rule->timemodified = $now;
            $DB->update_record('tool_dynamic_cohorts', $rule);
            $ruleid = (int)$rule->id;
        } else {
            $ruleid = (int)$DB->insert_record('tool_dynamic_cohorts', (object)[
                'name' => $cohortname,
                'description' => '',
                'cohortid' => $cohort->id,
                'enabled' => 1,
                'bulkprocessing' => 0,
                'broken' => 0,
                'operator' => 0, // AND.
                'realtime' => 1,
                'usermodified' => $adminid,
                'timecreated' => $now,
                'timemodified' => $now,
            ], true);
            $trace("  created dynamic rule '{$cohortname}' (id: {$ruleid}).");
        }

        // Condition 0: auth = oauth2.
        $this->upsert_condition($ruleid, self::CLASS_AUTH, [
            'authmethod' => 'auth',
            'auth_operator' => self::OP_EQUALS,
            'auth_value' => org_roles::AUTH,
        ], 0, $adminid, $now);

        // Conditions 1 & 2: the ssoorg and ssogroups list fields. Both are stored
        // delimiter-wrapped, so "contains ,value," is an exact element test.
        $orgkey = 'profile_field_' . profile_fields::ORG;
        $grpkey = 'profile_field_' . profile_fields::GROUPS;

        $orgcfg = [
            'profilefield' => $orgkey,
            "{$orgkey}_operator" => self::OP_CONTAINS,
            "{$orgkey}_value" => org_roles::list_needle($orgvalue),
            'include_missing_data' => 0,
        ];
        $grpcfg = [
            'profilefield' => $grpkey,
            "{$grpkey}_operator" => self::OP_CONTAINS,
            "{$grpkey}_value" => org_roles::list_needle($groupvalue),
            'include_missing_data' => 0,
        ];

        // Two conditions share the same classname; distinguish by 'profilefield'.
        $orgcond = null;
        $grpcond = null;
        foreach ($DB->get_records('tool_dynamic_cohorts_c', ['ruleid' => $ruleid, 'classname' => self::CLASS_PROFILE]) as $rec) {
            $cfg = json_decode($rec->configdata, true);
            if (!isset($cfg['profilefield'])) {
                continue;
            }
            if ($cfg['profilefield'] === $orgkey) {
                $orgcond = $rec;
            }
            if ($cfg['profilefield'] === $grpkey) {
                $grpcond = $rec;
            }
        }

        $this->upsert_condition($ruleid, self::CLASS_PROFILE, $orgcfg, 1, $adminid, $now, $orgcond);
        $this->upsert_condition($ruleid, self::CLASS_PROFILE, $grpcfg, 2, $adminid, $now, $grpcond);

        return $ruleid;
    }

    /**
     * Upsert a condition record.
     *
     * @param int $ruleid
     * @param string $classname
     * @param array $config
     * @param int $sortorder
     * @param int $adminid
     * @param int $now
     * @param object|null $existing pre-resolved record, for classnames used more than once per rule
     */
    private function upsert_condition(
        int $ruleid,
        string $classname,
        array $config,
        int $sortorder,
        int $adminid,
        int $now,
        ?object $existing = null
    ): void {
        global $DB;

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Sortorder is part of the key, not just the classname: two conditions on this
        // rule share the profile-field classname, so matching on classname alone made the
        // second write land on top of the first and the org condition disappear.
        $existing = $existing ?: $DB->get_record(
            'tool_dynamic_cohorts_c',
            ['ruleid' => $ruleid, 'classname' => $classname, 'sortorder' => $sortorder]
        );

        if ($existing) {
            $existing->configdata = $json;
            $existing->sortorder = $sortorder;
            $existing->usermodified = $adminid;
            $existing->timemodified = $now;
            $DB->update_record('tool_dynamic_cohorts_c', $existing);
            return;
        }

        $DB->insert_record('tool_dynamic_cohorts_c', (object)[
            'ruleid' => $ruleid,
            'classname' => $classname,
            'configdata' => $json,
            'sortorder' => $sortorder,
            'usermodified' => $adminid,
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Process a dynamic cohort rule synchronously right now.
     *
     * @param int $ruleid
     * @param callable $trace
     */
    private function process_cohort_rule_now(int $ruleid, callable $trace): void {
        if (!class_exists('\\tool_dynamic_cohorts\\rule') || !class_exists('\\tool_dynamic_cohorts\\rule_manager')) {
            $trace('  WARNING: tool_dynamic_cohorts classes not found - cannot process rule synchronously.');
            return;
        }

        try {
            $rule = \tool_dynamic_cohorts\rule::get_record(['id' => $ruleid]);
            \tool_dynamic_cohorts\rule_manager::process_rule($rule);
        } catch (\Throwable $e) {
            $trace('  WARNING: failed to process cohort rule synchronously: ' . $e->getMessage());
        }
    }
}
