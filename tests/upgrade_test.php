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
     */
    private function run_upgrade(): void {
        ob_start();
        xmldb_block_crucible_upgrade(self::SPLIT_VERSION - 1);
        ob_get_clean();
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
     * The cohort conditions this plugin wrote are re-pointed at the matching fields.
     *
     * Leaving them for the next hourly run would not do: the rules are processed in real time,
     * so until then every one of them matches nobody, and the cohorts - along with any
     * enrolment made through them - empty out.
     */
    public function test_the_cohort_conditions_are_repointed(): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('tool_dynamic_cohorts_c')) {
            $this->markTestSkipped('tool_dynamic_cohorts is not installed.');
        }

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
     * A condition an administrator wrote by hand is theirs, and still means what it says.
     */
    public function test_a_hand_written_condition_is_left_alone(): void {
        global $DB;

        if (!$DB->get_manager()->table_exists('tool_dynamic_cohorts_c')) {
            $this->markTestSkipped('tool_dynamic_cohorts is not installed.');
        }

        // No wrapping delimiters, so this is not one of ours.
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
}
