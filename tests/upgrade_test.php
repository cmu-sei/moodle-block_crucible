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
 * Unit tests for the display and matching field split upgrade.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible;

use block_crucible\local\org_roles;
use block_crucible\local\profile_fields;
use block_crucible\task\sync_org_roles;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for xmldb_block_crucible_upgrade().
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class upgrade_test extends \advanced_testcase {
    /** @var int The release that split the readable fields from the matching ones. */
    const SPLIT_VERSION = 2026100300;

    /**
     * Put the site back in the state the previous release left it in.
     */
    protected function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        $this->resetAfterTest();
        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/blocks/crucible/db/upgrade.php');

        profile_fields::install();
        // The matching fields did not exist yet, and the upgrade is what creates them.
        $DB->delete_records_list('user_info_field', 'shortname', profile_fields::matching());
        set_config('version', self::SPLIT_VERSION - 1, 'block_crucible');
    }

    /**
     * Write a value straight into a profile field, as the previous release stored it.
     *
     * @param int $userid
     * @param string $shortname
     * @param string $value
     */
    private function store_raw(int $userid, string $shortname, string $value): void {
        global $DB;

        $fieldid = profile_fields::field_id($shortname);
        $this->assertNotEmpty($fieldid);
        $DB->insert_record('user_info_data', (object)[
            'userid' => $userid,
            'fieldid' => $fieldid,
            'data' => $value,
            'dataformat' => 0,
        ]);
    }

    /**
     * Read a profile field's stored value.
     *
     * @param int $userid
     * @param string $shortname
     * @return string
     */
    private function stored(int $userid, string $shortname): string {
        global $DB;

        $fieldid = profile_fields::field_id($shortname);
        $this->assertNotEmpty($fieldid, "profile field {$shortname} does not exist");

        return (string)$DB->get_field('user_info_data', 'data', ['userid' => $userid, 'fieldid' => $fieldid]);
    }

    /**
     * Run the upgrade from the release before the split.
     *
     * @return string the upgrade's trace output
     */
    private function run_upgrade(): string {
        ob_start();
        xmldb_block_crucible_upgrade(self::SPLIT_VERSION - 1);

        return (string)ob_get_clean();
    }

    /**
     * Run the org role sync task, discarding its trace output.
     */
    private function run_sync(): void {
        ob_start();
        (new sync_org_roles())->execute();
        ob_get_clean();
    }

    /**
     * Skip a test that needs the optional cohort plugin.
     */
    private function require_dynamic_cohorts(): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('tool_dynamic_cohorts_c')) {
            $this->markTestSkipped('tool_dynamic_cohorts is not installed.');
        }
    }

    /**
     * The upgrade creates the matching fields and fills them from the values in place.
     */
    public function test_the_matching_fields_are_created_and_populated(): void {
        $userid = (int)$this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
        $this->store_raw($userid, profile_fields::ORG, ',Demo Org,Second Org,');
        $this->store_raw($userid, profile_fields::GROUPS, ',cyber-managers,');

        $this->run_upgrade();

        $this->assertSame('|Demo Org|Second Org|', $this->stored($userid, profile_fields::ORGLIST));
        $this->assertSame('|cyber-managers|', $this->stored($userid, profile_fields::GROUPSLIST));
    }

    /**
     * The readable fields are left reading plainly, which is the point of the release.
     */
    public function test_the_readable_fields_are_unwrapped(): void {
        $userid = (int)$this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
        $this->store_raw($userid, profile_fields::ORG, ',Demo Org,Second Org,');

        $this->run_upgrade();

        $this->assertSame('Demo Org, Second Org', $this->stored($userid, profile_fields::ORG));
    }

    /**
     * A value the previous release had to preserve whole is now stored properly.
     *
     * The old delimiter was a comma, so "Acme, Holdings" could not be held as one element and
     * was left exactly as it arrived. Under the new delimiter it is an ordinary value.
     */
    public function test_a_preserved_comma_value_becomes_one_element(): void {
        $userid = (int)$this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
        $this->store_raw($userid, profile_fields::ORG, 'Acme, Holdings');

        $this->run_upgrade();

        $this->assertSame('|Acme, Holdings|', $this->stored($userid, profile_fields::ORGLIST));
        $this->assertSame('Acme, Holdings', $this->stored($userid, profile_fields::ORG));
    }

    /**
     * A name the new delimiter cannot store is left readable rather than overwritten.
     *
     * The readable field is the only copy of it. join_display() reads back what was stored,
     * which for a single such value is nothing, so writing that would replace a real
     * organization with "" - the outcome the rest of this release is careful to avoid. It
     * cannot be matched on, so it grants no roles, and the upgrade says so in its output.
     */
    public function test_a_value_the_delimiter_cannot_store_is_left_alone(): void {
        $userid = (int)$this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
        $this->store_raw($userid, profile_fields::ORG, 'Acme|Holdings');

        $output = $this->run_upgrade();

        $this->assertSame('Acme|Holdings', $this->stored($userid, profile_fields::ORG));
        $this->assertStringContainsString('Acme|Holdings', $output);
        // join_list() says so as it drops it, which is how the caller knows to ask.
        $this->assertDebuggingCalled();
    }

    /**
     * A storable organization beside an unstorable one still grants its roles.
     */
    public function test_the_storable_half_of_a_mixed_list_is_still_matched_on(): void {
        $userid = (int)$this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
        $this->store_raw($userid, profile_fields::ORG, ',Demo Org,Acme|Holdings,');

        $this->run_upgrade();

        $this->assertSame('|Demo Org|', $this->stored($userid, profile_fields::ORGLIST));
        $this->assertDebuggingCalled();
    }

    /**
     * The cohort conditions this plugin wrote are re-pointed at the matching fields.
     *
     * Leaving them for the next hourly run would not do: the rules are processed in real time,
     * so until then every one of them matches nobody, and the cohorts - along with any
     * enrolment made through them - empty out.
     */
    public function test_the_cohort_conditions_are_repointed(): void {
        global $DB;

        $this->require_dynamic_cohorts();

        $id = $DB->insert_record('tool_dynamic_cohorts_c', (object)[
            'ruleid' => 1,
            'classname' => sync_org_roles::CLASS_PROFILE,
            'configdata' => json_encode([
                'profilefield' => 'profile_field_' . profile_fields::ORG,
                'profile_field_' . profile_fields::ORG . '_operator' => sync_org_roles::OP_CONTAINS,
                'profile_field_' . profile_fields::ORG . '_value' => ',Demo Org,',
                'include_missing_data' => 0,
            ]),
            'sortorder' => 1,
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->run_upgrade();

        $config = json_decode($DB->get_field('tool_dynamic_cohorts_c', 'configdata', ['id' => $id]), true);
        $newkey = 'profile_field_' . profile_fields::ORGLIST;
        $this->assertSame($newkey, $config['profilefield']);
        $this->assertSame(sync_org_roles::OP_CONTAINS, $config[$newkey . '_operator']);
        $this->assertSame(org_roles::list_needle('Demo Org'), $config[$newkey . '_value']);
        // Nothing left pointing at the readable field, under any key.
        $this->assertArrayNotHasKey('profile_field_' . profile_fields::ORG . '_operator', $config);
        $this->assertArrayNotHasKey('profile_field_' . profile_fields::ORG . '_value', $config);
    }

    /**
     * A condition carrying an unwrapped needle is left exactly as it is.
     *
     * Not because an administrator must have written it - this plugin wrote the needle bare
     * itself until 2026092300, so on a site that has not run the sync task since upgrading to
     * that release every condition it owns is still in this form. Rewriting one would mean
     * guessing whether a bare needle was meant as a whole element, and a wrong guess empties
     * a cohort. The next sync_org_roles run re-points it, which the test below checks.
     */
    public function test_a_condition_with_an_unwrapped_needle_is_left_alone(): void {
        global $DB;

        $this->require_dynamic_cohorts();

        $configdata = json_encode([
            'profilefield' => 'profile_field_' . profile_fields::ORG,
            'profile_field_' . profile_fields::ORG . '_operator' => sync_org_roles::OP_CONTAINS,
            'profile_field_' . profile_fields::ORG . '_value' => 'Demo Org',
            'include_missing_data' => 0,
        ]);
        $id = $DB->insert_record('tool_dynamic_cohorts_c', (object)[
            'ruleid' => 1,
            'classname' => sync_org_roles::CLASS_PROFILE,
            'configdata' => $configdata,
            'sortorder' => 1,
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->run_upgrade();

        $this->assertSame($configdata, $DB->get_field('tool_dynamic_cohorts_c', 'configdata', ['id' => $id]));
    }

    /**
     * The realistic production path: conditions in the pre-2026092300 bare form, the upgrade,
     * then one sync_org_roles run.
     *
     * A site that last ran the sync task before 2026092300 holds the plugin's own conditions
     * with bare needles on the readable fields. The upgrade leaves them, so this checks that
     * the state it leaves behind still works and that one task run finishes the job: the
     * conditions end up on the matching fields with wrapped needles, no condition is left
     * pointing at a readable field, and the user is still in the cohort throughout.
     */
    public function test_bare_conditions_are_repointed_by_the_next_sync_run(): void {
        global $DB;

        $this->require_dynamic_cohorts();

        $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        foreach (org_roles::group_role_map() as $shortname) {
            $roleid = create_role(ucwords(str_replace('-', ' ', $shortname)), $shortname, 'Test role');
            set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        }
        set_config('enableorgrolesync', 1, 'block_crucible');
        org_roles::reset_caches();

        $userid = (int)$this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
        $this->store_raw($userid, profile_fields::ORG, ',Demo Org,');
        $this->store_raw($userid, profile_fields::GROUPS, ',cyber-managers,');

        // The rule as a site last synced before 2026092300 holds it: the readable fields, and
        // bare needles. The sortorders are the ones the task has always used, because that is
        // how it finds its own conditions again.
        $ruleid = $this->create_bare_rule('demo-org-cyber-managers', 'Demo Org Cyber Managers', [
            1 => [profile_fields::ORG, 'Demo Org'],
            2 => [profile_fields::GROUPS, 'cyber-managers'],
        ]);

        $this->run_upgrade();
        $this->run_sync();

        $expected = [
            'profile_field_' . profile_fields::ORGLIST => org_roles::list_needle('Demo Org'),
            'profile_field_' . profile_fields::GROUPSLIST => org_roles::list_needle('cyber-managers'),
        ];
        $conditions = $DB->get_records('tool_dynamic_cohorts_c', ['ruleid' => $ruleid]);
        // Three, not five: the task finds its own conditions by sortorder and rewrites them
        // in place, rather than adding a second pair beside the bare ones.
        $this->assertCount(3, $conditions);

        $found = [];
        foreach ($conditions as $condition) {
            $config = json_decode($condition->configdata, true);
            if (!isset($config['profilefield'])) {
                continue;
            }
            $key = $config['profilefield'];
            $found[$key] = $config[$key . '_value'] ?? null;
        }
        ksort($expected);
        ksort($found);
        $this->assertSame($expected, $found);
    }

    /**
     * Create a cohort, a rule and the conditions in the pre-2026092300 bare form.
     *
     * @param string $idnumber cohort idnumber, which is how the task finds the rule again
     * @param string $name
     * @param array $conditions sortorder => [profile field shortname, bare needle]
     * @return int rule id
     */
    private function create_bare_rule(string $idnumber, string $name, array $conditions): int {
        global $DB;

        $cohortid = (int)$DB->insert_record('cohort', (object)[
            'contextid' => \context_system::instance()->id,
            'name' => $name,
            'idnumber' => $idnumber,
            'description' => '',
            'descriptionformat' => FORMAT_HTML,
            'visible' => 1,
            'component' => 'tool_dynamic_cohorts',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $ruleid = (int)$DB->insert_record('tool_dynamic_cohorts', (object)[
            'name' => $name,
            'description' => '',
            'cohortid' => $cohortid,
            'enabled' => 1,
            'bulkprocessing' => 0,
            'broken' => 0,
            'operator' => 0,
            'realtime' => 1,
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $this->insert_condition($ruleid, sync_org_roles::CLASS_AUTH, 0, [
            'authmethod' => 'auth',
            'auth_operator' => sync_org_roles::OP_EQUALS,
            'auth_value' => org_roles::AUTH,
        ]);
        foreach ($conditions as $sortorder => [$shortname, $needle]) {
            $key = 'profile_field_' . $shortname;
            $this->insert_condition($ruleid, sync_org_roles::CLASS_PROFILE, $sortorder, [
                'profilefield' => $key,
                $key . '_operator' => sync_org_roles::OP_CONTAINS,
                $key . '_value' => $needle,
                'include_missing_data' => 0,
            ]);
        }

        return $ruleid;
    }

    /**
     * Insert one rule condition.
     *
     * @param int $ruleid
     * @param string $classname
     * @param int $sortorder
     * @param array $config
     */
    private function insert_condition(int $ruleid, string $classname, int $sortorder, array $config): void {
        global $DB;

        $DB->insert_record('tool_dynamic_cohorts_c', (object)[
            'ruleid' => $ruleid,
            'classname' => $classname,
            'configdata' => json_encode($config),
            'sortorder' => $sortorder,
            'usermodified' => 2,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }
}
