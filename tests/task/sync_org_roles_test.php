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

/**
 * Unit tests for the org role sync task.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\task;

use block_crucible\local\org_roles;
use block_crucible\local\profile_fields;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for \block_crucible\task\sync_org_roles.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sync_org_roles::class)]
final class sync_org_roles_test extends \advanced_testcase {
    /** @var array Role shortname => roleid for the mapped roles. */
    private array $roleids = [];

    /**
     * Provision the profile fields and roles the task needs.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        profile_fields::install();
        org_roles::reset_caches();
        set_config('enableorgrolesync', 1, 'block_crucible');

        foreach (org_roles::group_role_map() as $shortname) {
            $roleid = create_role(ucwords(str_replace('-', ' ', $shortname)), $shortname, 'Test role');
            set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
            $this->roleids[$shortname] = $roleid;
        }
    }

    /**
     * Run the task, discarding its trace output.
     *
     * @return string the trace output
     */
    private function run_task(): string {
        ob_start();
        (new sync_org_roles())->execute();

        return (string)ob_get_clean();
    }

    /**
     * Create an oauth2 user carrying the given org and group lists.
     *
     * @param string[] $orgs
     * @param string[] $groups
     * @return int user id
     */
    private function create_sso_user(array $orgs, array $groups): int {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $user = $this->getDataGenerator()->create_user(['auth' => 'oauth2']);
        profile_save_data((object)[
            'id' => $user->id,
            'profile_field_' . profile_fields::ORG => org_roles::join_list($orgs),
            'profile_field_' . profile_fields::GROUPS => org_roles::join_list($groups),
        ]);

        return (int)$user->id;
    }

    /**
     * The role shortnames this plugin has granted a user in a category context.
     *
     * @param int $userid
     * @param int $categoryid
     * @return string[] sorted
     */
    private function managed_roles(int $userid, int $categoryid): array {
        global $DB;

        $context = \context_coursecat::instance($categoryid);
        $roleids = $DB->get_fieldset_select(
            'role_assignments',
            'roleid',
            'userid = ? AND contextid = ? AND component = ?',
            [$userid, $context->id, org_roles::COMPONENT]
        );

        $shortnames = array_keys(array_intersect($this->roleids, array_map('intval', $roleids)));
        sort($shortnames);

        return $shortnames;
    }

    /**
     * Skip a test that needs the optional cohort plugin.
     */
    private function require_dynamic_cohorts(): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('tool_dynamic_cohorts')) {
            $this->markTestSkipped('tool_dynamic_cohorts is not installed.');
        }
    }

    /**
     * One run assigns the roles every user's groups map to.
     */
    public function test_the_task_assigns_roles_for_every_user(): void {
        $category = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();
        $manager = $this->create_sso_user(['Demo Org'], ['cyber-managers']);
        $builder = $this->create_sso_user(['Demo Org'], ['lab-builders']);

        $this->run_task();

        $this->assertSame(['cyber-manager'], $this->managed_roles($manager, (int)$category->id));
        $this->assertSame(['lab-builder'], $this->managed_roles($builder, (int)$category->id));
    }

    /**
     * A group whose name contains a mapped group name grants nothing, even though the
     * cohort condition is a "contains" test.
     */
    public function test_a_substring_group_grants_nothing(): void {
        $category = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();
        $userid = $this->create_sso_user(['Demo Org'], ['ex-cyber-managers']);

        $this->run_task();

        $this->assertSame([], $this->managed_roles($userid, (int)$category->id));
    }

    /**
     * Turning the feature off revokes what it granted rather than freezing it.
     */
    public function test_disabling_the_feature_revokes_managed_assignments(): void {
        $category = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers']);
        $this->run_task();
        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, (int)$category->id));

        set_config('enableorgrolesync', 0, 'block_crucible');
        $this->run_task();

        $this->assertSame([], $this->managed_roles($userid, (int)$category->id));
    }

    /**
     * Deleting the org category leaves no managed assignment behind, and does not throw.
     */
    public function test_deleting_the_org_category_leaves_nothing_behind(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers']);
        $this->run_task();
        $this->assertTrue($DB->record_exists(
            'role_assignments',
            ['userid' => $userid, 'component' => org_roles::COMPONENT]
        ));

        // Deleting a category is an admin action, and 5.2 enforces the capability on the read.
        $this->setAdminUser();
        \core_course_category::get((int)$category->id)->delete_full(false);
        $this->setUser(null);
        org_roles::reset_caches();
        $this->run_task();

        $this->assertFalse($DB->record_exists(
            'role_assignments',
            ['userid' => $userid, 'component' => org_roles::COMPONENT]
        ));
    }

    /**
     * A user carrying two orgs contributes both, rather than one unmatchable "A,B" org.
     */
    public function test_a_multi_org_user_is_synced_in_both_orgs(): void {
        $first = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        $second = $this->getDataGenerator()->create_category(['name' => 'Second Org', 'parent' => 0]);
        org_roles::reset_caches();
        $userid = $this->create_sso_user(['Demo Org', 'Second Org'], ['lab-builders']);

        $this->run_task();

        $this->assertSame(['lab-builder'], $this->managed_roles($userid, (int)$first->id));
        $this->assertSame(['lab-builder'], $this->managed_roles($userid, (int)$second->id));
    }

    /**
     * A missing role is reported and does not stop the other groups syncing.
     */
    public function test_a_missing_role_is_reported_and_skipped(): void {
        global $DB;

        $category = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers', 'lab-builders']);
        $DB->delete_records('role', ['id' => $this->roleids['cyber-manager']]);

        $output = $this->run_task();

        $this->assertStringContainsString("role 'cyber-manager' does not exist", $output);
        $this->assertSame(['lab-builder'], $this->managed_roles($userid, (int)$category->id));
    }

    /**
     * The cohort conditions are anchored on the list delimiters.
     */
    public function test_the_cohort_conditions_are_anchored_on_delimiters(): void {
        global $DB;

        $this->require_dynamic_cohorts();

        $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();
        $this->create_sso_user(['Demo Org'], ['cyber-managers']);

        $this->run_task();

        $cohortid = $DB->get_field('cohort', 'id', ['idnumber' => 'demo-org-cyber-managers']);
        $this->assertNotEmpty($cohortid);
        $ruleid = $DB->get_field('tool_dynamic_cohorts', 'id', ['cohortid' => $cohortid]);
        $this->assertNotEmpty($ruleid);

        $configs = $DB->get_fieldset_select('tool_dynamic_cohorts_c', 'configdata', 'ruleid = ?', [$ruleid]);
        $configs = implode("\n", $configs);
        $this->assertStringContainsString('",Demo Org,"', $configs);
        $this->assertStringContainsString('",cyber-managers,"', $configs);
    }

    /**
     * A rule under a different display name is reused, not duplicated: the rule is keyed
     * on its cohort, which is keyed on the stable idnumber.
     */
    public function test_a_renamed_rule_is_reused_rather_than_duplicated(): void {
        global $DB;

        $this->require_dynamic_cohorts();

        $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();
        $this->create_sso_user(['Demo Org'], ['cyber-managers']);
        $this->run_task();

        $cohortid = $DB->get_field('cohort', 'id', ['idnumber' => 'demo-org-cyber-managers']);
        $ruleid = $DB->get_field('tool_dynamic_cohorts', 'id', ['cohortid' => $cohortid]);
        $this->assertNotEmpty($ruleid);

        // Something renames the rule - an admin, or an earlier release's naming.
        $DB->set_field('tool_dynamic_cohorts', 'name', 'Old Name Cyber Managers', ['id' => $ruleid]);
        $this->run_task();

        $this->assertSame(1, $DB->count_records('tool_dynamic_cohorts', ['cohortid' => $cohortid]));
        $this->assertSame(
            'Demo Org Cyber Managers',
            $DB->get_field('tool_dynamic_cohorts', 'name', ['id' => $ruleid])
        );
    }

    /**
     * The task still assigns roles when the optional cohort plugin is absent.
     */
    public function test_the_task_reports_a_missing_cohort_plugin_without_stopping(): void {
        global $DB;

        if ($DB->get_manager()->table_exists('tool_dynamic_cohorts')) {
            $this->markTestSkipped('tool_dynamic_cohorts is installed, so this path cannot be reached.');
        }

        $category = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers']);

        $output = $this->run_task();

        $this->assertStringContainsString('tool_dynamic_cohorts is not installed', $output);
        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, (int)$category->id));
    }
}
