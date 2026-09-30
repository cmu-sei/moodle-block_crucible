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
 * Tests for the admin settings page.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible;

use block_crucible\local\profile_fields;

defined('MOODLE_INTERNAL') || die();

/**
 * Tests for the OAuth 2 field mapping notice on the settings page.
 *
 * An OAuth 2 field mapping onto one of the sso* profile fields means something other than
 * this plugin writes them at login. Whether that is a fault depends on whether the org role
 * sync is on, and the advice differs, so the page must pick the right message.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class settings_test extends \advanced_testcase {
    /**
     * Install the profile fields the notice looks for.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        profile_fields::install();
    }

    /**
     * Point an OAuth 2 issuer's field mapping at one of our profile fields.
     *
     * @param string $shortname profile field shortname, e.g. 'ssogroups'
     * @param string $issuername
     */
    private function map_issuer_field(string $shortname, string $issuername = 'Keycloak'): void {
        global $DB;

        $issuerid = $DB->insert_record('oauth2_issuer', (object)[
            'name' => $issuername,
            'image' => '',
            'baseurl' => 'https://keycloak.example.com',
            'clientid' => 'moodle',
            'clientsecret' => '',
            'loginscopes' => 'openid profile email',
            'loginscopesoffline' => 'openid profile email',
            'loginparams' => '',
            'loginparamsoffline' => '',
            'alloweddomains' => '',
            'enabled' => 1,
            'showonloginpage' => 1,
            'sortorder' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);

        $DB->insert_record('oauth2_user_field_mapping', (object)[
            'issuerid' => $issuerid,
            'externalfield' => 'groups',
            'internalfield' => 'profile_field_' . $shortname,
            'timecreated' => time(),
            'timemodified' => time(),
            'usermodified' => 0,
        ]);
    }

    /**
     * Build the plugin's settings page the way Moodle's admin tree does.
     *
     * @return \admin_setting[] settings present on the page, keyed by name
     */
    private function settings_on_page(): array {
        global $CFG, $ADMIN, $DB, $OUTPUT, $hassiteconfig;

        require_once($CFG->libdir . '/adminlib.php');

        // Both arguments true: without a full tree a plugin settings.php adds nothing.
        $ADMIN = admin_get_root(true, true);
        $ADMIN->fulltree = true;
        $hassiteconfig = true;
        $settings = new \admin_settingpage('blocksettingcrucible', 'Crucible');

        // Resolves under both layouts - on Moodle 5.2 dirroot is the public/ directory.
        include($CFG->dirroot . '/blocks/crucible/settings.php');

        // 5.0 stores these in an array, 5.2 in a stdClass, and both key them by
        // plugin-and-name concatenated. Re-key by the setting's own name.
        $found = [];
        foreach ((array)$settings->settings as $setting) {
            $found[$setting->name] = $setting;
        }

        return $found;
    }

    /**
     * Names of the settings the page added.
     *
     * @return string[]
     */
    private function setting_names(): array {
        return array_keys($this->settings_on_page());
    }

    /**
     * With the sync on, a mapping is a real conflict and the page warns about it.
     */
    public function test_a_mapping_warns_when_the_sync_is_enabled(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        $this->map_issuer_field(profile_fields::GROUPS);

        $names = $this->setting_names();

        $this->assertContains('orgrolesyncmappingconflict', $names);
        $this->assertNotContains('orgrolesyncmappingpending', $names);
    }

    /**
     * With the sync off the mapping is the only writer, so telling an admin to remove it
     * would empty the field. The page must show the ordered advice instead of the warning.
     */
    public function test_a_mapping_does_not_warn_when_the_sync_is_disabled(): void {
        set_config('enableorgrolesync', 0, 'block_crucible');
        $this->map_issuer_field(profile_fields::GROUPS);

        $names = $this->setting_names();

        $this->assertContains('orgrolesyncmappingpending', $names);
        $this->assertNotContains('orgrolesyncmappingconflict', $names);
    }

    /**
     * No mapping, nothing to say, in either state.
     */
    public function test_no_notice_without_a_mapping(): void {
        foreach ([0, 1] as $enabled) {
            set_config('enableorgrolesync', $enabled, 'block_crucible');

            $names = $this->setting_names();

            $this->assertNotContains('orgrolesyncmappingconflict', $names);
            $this->assertNotContains('orgrolesyncmappingpending', $names);
        }
    }

    /**
     * A mapping onto an unrelated profile field is none of this plugin's business.
     */
    public function test_a_mapping_onto_another_field_is_ignored(): void {
        global $DB;

        set_config('enableorgrolesync', 1, 'block_crucible');
        $DB->insert_record('user_info_field', (object)[
            'shortname' => 'somethingelse',
            'name' => 'Something else',
            'categoryid' => 1,
            'datatype' => 'text',
            'description' => '',
            'descriptionformat' => 1,
            'sortorder' => 99,
            'required' => 0,
            'locked' => 0,
            'visible' => 2,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => 0,
            'param1' => 30,
            'param2' => 2048,
        ]);
        $this->map_issuer_field('somethingelse');

        $names = $this->setting_names();

        $this->assertNotContains('orgrolesyncmappingconflict', $names);
        $this->assertNotContains('orgrolesyncmappingpending', $names);
    }

    /**
     * Every mapped issuer is named, so an admin knows which ones to edit.
     */
    public function test_each_mapped_issuer_is_listed(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        $this->map_issuer_field(profile_fields::GROUPS, 'Keycloak One');
        $this->map_issuer_field(profile_fields::ORG, 'Keycloak Two');

        $notice = $this->settings_on_page()['orgrolesyncmappingconflict'];
        $description = $notice->description ?? '';

        $this->assertStringContainsString('Keycloak One', $description);
        $this->assertStringContainsString('Keycloak Two', $description);
    }
}
