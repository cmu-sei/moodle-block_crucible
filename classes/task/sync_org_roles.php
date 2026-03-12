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

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task: sync Keycloak org group memberships to Moodle category-scoped roles.
 *
 * Each run:
 *   1. Reads all distinct profile_field_ssoorg values from user profiles.
 *   2. Looks for matching top-level course categories (must be created manually by admins).
 *   3. Ensures a dynamic cohort exists for each org × Keycloak group combination,
 *      filtered on auth=oauth2 + ssoorg contains org + ssogroups contains group.
 *   4. Syncs cohort members to the matching role assignment in the org's category context.
 *
 * Adding a new org requires:
 *   1. Admin manually creates a top-level course category with the org's exact name.
 *   2. This task will discover the category and assign roles on its next run.
 *   This prevents automatic category creation from typos or unauthorized orgs.
 *
 * Adding a new group/role mapping only requires extending GROUP_ROLE_MAP.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sync_org_roles extends \core\task\scheduled_task {

    /**
     * Maps Keycloak group name => Moodle role shortname.
     * The roles must already exist (created by the moodle-install.sh setup script).
     */
    const GROUP_ROLE_MAP = [
        'cyber-managers'        => 'cyber-manager',
        'lab-builders'          => 'lab-builder',
        'curriculum-developers' => 'curriculum-developer',
    ];

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_org_roles', 'block_crucible');
    }

    /**
     * Execute the scheduled task.
     */
    public function execute(): void {
        global $CFG, $DB;

        // Check if org role sync is enabled.
        if (!get_config('block_crucible', 'enableorgrolesync')) {
            mtrace('sync_org_roles: org role sync is disabled in plugin settings - skipping.');
            return;
        }

        // Check for required dependencies before proceeding.
        if (!$DB->get_manager()->table_exists('tool_dynamic_cohorts')) {
            mtrace('sync_org_roles: tool_dynamic_cohorts plugin not installed - skipping.');
            return;
        }

        $ssoorgifield = $DB->get_record('user_info_field', ['shortname' => 'ssoorg'], 'id', IGNORE_MISSING);
        if (!$ssoorgifield) {
            mtrace('sync_org_roles: profile field "ssoorg" not found - skipping.');
            return;
        }

        $ssogroupsfield = $DB->get_record('user_info_field', ['shortname' => 'ssogroups'], 'id', IGNORE_MISSING);
        if (!$ssogroupsfield) {
            mtrace('sync_org_roles: profile field "ssogroups" not found - skipping.');
            return;
        }

        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->libdir  . '/accesslib.php');

        $orgs = $this->get_distinct_orgs();

        if (empty($orgs)) {
            mtrace('sync_org_roles: no ssoorg values found in user profiles — nothing to do.');
            return;
        }

        foreach ($orgs as $org) {
            mtrace("sync_org_roles: processing org '{$org}'");

            $categoryid = $this->get_org_category($org);
            if (!$categoryid) {
                // Category doesn't exist - skip this org entirely
                continue;
            }

            foreach (self::GROUP_ROLE_MAP as $group => $roleshort) {
                $cohortname  = $org . ' ' . ucwords(str_replace('-', ' ', $group));
                $cohortidnum = $this->slugify($org) . '-' . $group;

                try {
                    $cohortid = $this->ensure_org_group_cohort($cohortname, $cohortidnum, $org, $group);
                    $this->sync_cohort_category_role($cohortname, $cohortid, $roleshort, $categoryid);
                } catch (\Exception $e) {
                    mtrace("  ERROR processing '{$cohortname}': " . $e->getMessage());
                    continue;
                }
            }
        }

        mtrace('sync_org_roles: completed.');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Return all distinct non-empty ssoorg profile field values across all users.
     *
     * @return array
     */
    private function get_distinct_orgs(): array {
        global $DB;

        $field = $DB->get_record('user_info_field', ['shortname' => 'ssoorg'], 'id', IGNORE_MISSING);
        if (!$field) {
            mtrace('sync_org_roles: profile field "ssoorg" not found.');
            return [];
        }

        $values = $DB->get_fieldset_sql(
            'SELECT DISTINCT data FROM {user_info_data} WHERE fieldid = ? AND ' .
            $DB->sql_isnotempty('user_info_data', 'data', false, true),
            [$field->id]
        );

        return array_filter(array_map('trim', $values));
    }

    /**
     * Check if a top-level course category named after the org exists.
     * Returns the category id if it exists, or 0 if it doesn't.
     *
     * Note: Categories must be manually created by administrators.
     * This prevents automatic creation of categories due to typos or unauthorized orgs.
     *
     * @param string $org
     * @return int
     */
    private function get_org_category(string $org): int {
        global $DB;

        // Look for exact match by name (case-sensitive)
        $existing = $DB->get_record('course_categories', ['name' => $org, 'parent' => 0], 'id', IGNORE_MISSING);
        if ($existing) {
            mtrace("  category '{$org}' found (id: {$existing->id}).");
            return (int)$existing->id;
        }

        // Also check by idnumber in case it was created with the expected idnumber pattern
        $idnumber = 'org-' . $this->slugify($org);
        $cat = $DB->get_record('course_categories', ['idnumber' => $idnumber, 'parent' => 0], 'id', IGNORE_MISSING);
        if ($cat) {
            mtrace("  category found by idnumber '{$idnumber}' (id: {$cat->id}).");
            return (int)$cat->id;
        }

        mtrace("  category '{$org}' does not exist - skipping (create it manually to enable role sync).");
        return 0;
    }

    /**
     * Ensure a dynamic cohort exists for the given org + Keycloak group combination,
     * with conditions: auth=oauth2 AND ssoorg contains $org AND ssogroups contains $group.
     * Returns the cohort id.
     *
     * @param string $cohortname
     * @param string $cohortidnumber
     * @param string $orgvalue
     * @param string $groupvalue
     * @return int
     * @throws \dml_exception
     */
    private function ensure_org_group_cohort(
        string $cohortname,
        string $cohortidnumber,
        string $orgvalue,
        string $groupvalue
    ): int {
        global $DB;

        $sysctx  = \context_system::instance();
        $adminid = (int)get_admin()->id;
        $now     = time();

        // Upsert cohort.
        $cohort = $DB->get_record('cohort', ['idnumber' => $cohortidnumber, 'contextid' => $sysctx->id]);
        if (!$cohort) {
            $cohort = (object)[
                'contextid'         => $sysctx->id,
                'name'              => $cohortname,
                'idnumber'          => $cohortidnumber,
                'description'       => "Auto-managed: org={$orgvalue}, group={$groupvalue}",
                'descriptionformat' => FORMAT_HTML,
                'visible'           => 1,
                'component'         => 'tool_dynamic_cohorts',
                'timecreated'       => $now,
                'timemodified'      => $now,
            ];
            $cohort->id = cohort_add_cohort($cohort);
            mtrace("  created cohort '{$cohortname}' (id: {$cohort->id}).");
        } else {
            mtrace("  cohort '{$cohortname}' exists (id: {$cohort->id}).");
        }

        // Upsert dynamic rule.
        $CLASS_AUTH    = 'tool_dynamic_cohorts\\local\\tool_dynamic_cohorts\\condition\\auth_method';
        $CLASS_PROFILE = 'tool_dynamic_cohorts\\local\\tool_dynamic_cohorts\\condition\\user_custom_profile';

        $rule = $DB->get_record('tool_dynamic_cohorts', ['name' => $cohortname]);
        if ($rule) {
            $rule->cohortid     = $cohort->id;
            $rule->enabled      = 1;
            $rule->realtime     = 1;
            $rule->operator     = 0; // AND
            $rule->usermodified = $adminid;
            $rule->timemodified = $now;
            $DB->update_record('tool_dynamic_cohorts', $rule);
            $ruleid = (int)$rule->id;
        } else {
            $ruleid = (int)$DB->insert_record('tool_dynamic_cohorts', (object)[
                'name'           => $cohortname,
                'description'    => '',
                'cohortid'       => $cohort->id,
                'enabled'        => 1,
                'bulkprocessing' => 0,
                'broken'         => 0,
                'operator'       => 0, // AND
                'realtime'       => 1,
                'usermodified'   => $adminid,
                'timecreated'    => $now,
                'timemodified'   => $now,
            ], true);
            mtrace("  created dynamic rule '{$cohortname}' (id: {$ruleid}).");
        }

        // Condition 0: auth = oauth2.
        $this->upsert_condition($ruleid, $CLASS_AUTH, [
            'authmethod'    => 'auth',
            'auth_operator' => '3',
            'auth_value'    => 'oauth2',
        ], 0, $adminid, $now);

        // Conditions 1 & 2: ssoorg and ssogroups profile fields.
        // Two conditions share the same classname; distinguish by 'profilefield' in configdata.
        $orgkey = 'profile_field_ssoorg';
        $grpkey = 'profile_field_ssogroups';

        $orgcfg = ['profilefield' => $orgkey, "{$orgkey}_operator" => '1', "{$orgkey}_value" => $orgvalue, 'include_missing_data' => 0];
        $grpcfg = ['profilefield' => $grpkey, "{$grpkey}_operator" => '1', "{$grpkey}_value" => $groupvalue, 'include_missing_data' => 0];

        $orgcond = null;
        $grpcond = null;
        foreach ($DB->get_records('tool_dynamic_cohorts_c', ['ruleid' => $ruleid, 'classname' => $CLASS_PROFILE]) as $rec) {
            $cfg = json_decode($rec->configdata, true);
            if (isset($cfg['profilefield'])) {
                if ($cfg['profilefield'] === $orgkey) { $orgcond = $rec; }
                if ($cfg['profilefield'] === $grpkey) { $grpcond = $rec; }
            }
        }

        $this->upsert_profile_condition($orgcond, $ruleid, $CLASS_PROFILE, $orgcfg, 1, $adminid, $now);
        $this->upsert_profile_condition($grpcond, $ruleid, $CLASS_PROFILE, $grpcfg, 2, $adminid, $now);

        if (class_exists('\\cache_helper')) {
            \cache_helper::purge_all();
        }

        // Queue adhoc task to process this dynamic cohort rule immediately
        $this->queue_cohort_processing($ruleid);

        return (int)$cohort->id;
    }

    /**
     * Sync all members of a cohort to a role assignment in a course category context.
     * Uses component='block_crucible' to distinguish managed assignments from manual ones.
     * Adds missing assignments and removes stale ones.
     *
     * @param string $cohortname
     * @param int $cohortid
     * @param string $roleshortname
     * @param int $categoryid
     */
    private function sync_cohort_category_role(
        string $cohortname,
        int $cohortid,
        string $roleshortname,
        int $categoryid
    ): void {
        global $DB;

        $role = $DB->get_record('role', ['shortname' => $roleshortname], 'id', IGNORE_MISSING);
        if (!$role) {
            mtrace("  WARNING: role '{$roleshortname}' not found — skipping sync for '{$cohortname}'.");
            return;
        }

        $catctx = \context_coursecat::instance($categoryid);

        $memberids  = array_map('intval', array_keys(
            $DB->get_records('cohort_members', ['cohortid' => $cohortid], '', 'userid')
        ));
        $existingids = array_map('intval', array_keys(
            $DB->get_records('role_assignments', [
                'roleid'    => $role->id,
                'contextid' => $catctx->id,
                'component' => 'block_crucible',
            ], '', 'userid')
        ));

        $added = 0;
        foreach ($memberids as $userid) {
            if (!in_array($userid, $existingids, true)) {
                role_assign($role->id, $userid, $catctx->id, 'block_crucible', 0);
                $added++;
            }
        }

        $removed = 0;
        foreach ($existingids as $userid) {
            if (!in_array($userid, $memberids, true)) {
                role_unassign($role->id, $userid, $catctx->id, 'block_crucible', 0);
                $removed++;
            }
        }

        if (class_exists('\\cache_helper')) {
            \cache_helper::purge_by_event('changesincapabilities');
        }

        mtrace("  '{$cohortname}' → '{$roleshortname}': +{$added} added, -{$removed} removed.");
    }

    // -------------------------------------------------------------------------
    // Condition helpers
    // -------------------------------------------------------------------------

    /**
     * Upsert a condition record.
     *
     * @param int $ruleid
     * @param string $classname
     * @param array $config
     * @param int $sortorder
     * @param int $adminid
     * @param int $now
     */
    private function upsert_condition(int $ruleid, string $classname, array $config, int $sortorder, int $adminid, int $now): void {
        global $DB;

        $json     = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $existing = $DB->get_record('tool_dynamic_cohorts_c', ['ruleid' => $ruleid, 'classname' => $classname]);
        if ($existing) {
            $existing->configdata   = $json;
            $existing->sortorder    = $sortorder;
            $existing->usermodified = $adminid;
            $existing->timemodified = $now;
            $DB->update_record('tool_dynamic_cohorts_c', $existing);
        } else {
            $DB->insert_record('tool_dynamic_cohorts_c', (object)[
                'ruleid'       => $ruleid,
                'classname'    => $classname,
                'configdata'   => $json,
                'sortorder'    => $sortorder,
                'usermodified' => $adminid,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Upsert a profile field condition record.
     *
     * @param object|null $existing
     * @param int $ruleid
     * @param string $classname
     * @param array $config
     * @param int $sortorder
     * @param int $adminid
     * @param int $now
     */
    private function upsert_profile_condition(?object $existing, int $ruleid, string $classname, array $config, int $sortorder, int $adminid, int $now): void {
        global $DB;

        $json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($existing) {
            $existing->configdata   = $json;
            $existing->sortorder    = $sortorder;
            $existing->usermodified = $adminid;
            $existing->timemodified = $now;
            $DB->update_record('tool_dynamic_cohorts_c', $existing);
        } else {
            $DB->insert_record('tool_dynamic_cohorts_c', (object)[
                'ruleid'       => $ruleid,
                'classname'    => $classname,
                'configdata'   => $json,
                'sortorder'    => $sortorder,
                'usermodified' => $adminid,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
    }

    /**
     * Convert a string to a slug.
     *
     * @param string $value
     * @return string
     */
    private function slugify(string $value): string {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($value)));
    }

    /**
     * Queue an adhoc task to process the dynamic cohort rule immediately.
     *
     * @param int $ruleid The dynamic cohort rule ID
     */
    private function queue_cohort_processing(int $ruleid): void {
        // Check if the adhoc task class exists
        if (!class_exists('tool_dynamic_cohorts\\task\\process_rule')) {
            mtrace("  WARNING: tool_dynamic_cohorts plugin not found - cohort membership may be delayed.");
            return;
        }

        try {
            // Queue adhoc task to process this specific rule (same as scheduled task does)
            $adhoctask = new \tool_dynamic_cohorts\task\process_rule();
            $adhoctask->set_custom_data($ruleid);
            $adhoctask->set_component('tool_dynamic_cohorts');
            \core\task\manager::queue_adhoc_task($adhoctask, true);
            mtrace("  queued cohort processing task for rule (id: {$ruleid}).");
        } catch (\Exception $e) {
            mtrace("  WARNING: failed to queue cohort processing: " . $e->getMessage());
        }
    }
}
