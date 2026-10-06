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

use block_crucible\local\org_roles;
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
        $this->map_issuer_field(profile_fields::GROUPSLIST);

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
        $this->map_issuer_field(profile_fields::GROUPSLIST);

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
     * A mapping onto a field the sync does not write is not a conflict with the sync.
     *
     * The sync stopped writing ssorole, so that mapping is the field's only writer. Calling it
     * a conflict and telling the administrator to remove it would destroy the data, and the
     * remedy the conflict notice offers - run the sync task - would not bring it back.
     */
    public function test_a_mapping_on_a_field_the_sync_does_not_write_is_not_a_conflict(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        $this->map_issuer_field(profile_fields::ROLE);

        $names = $this->setting_names();

        $this->assertContains('orgrolesyncmappingunmanaged', $names);
        $this->assertNotContains('orgrolesyncmappingconflict', $names);
        $this->assertNotContains('orgrolesyncmappingpending', $names);
    }

    /**
     * The split does not depend on whether the sync is enabled.
     *
     * Enabling the sync does not make it start writing ssorole, so neither notice about
     * maintained fields applies to that mapping in either state.
     */
    public function test_an_unwritten_field_is_reported_the_same_with_the_sync_off(): void {
        set_config('enableorgrolesync', 0, 'block_crucible');
        $this->map_issuer_field(profile_fields::ROLE);

        $names = $this->setting_names();

        $this->assertContains('orgrolesyncmappingunmanaged', $names);
        $this->assertNotContains('orgrolesyncmappingpending', $names);
        $this->assertNotContains('orgrolesyncmappingconflict', $names);
    }

    /**
     * With both kinds mapped, each field is listed under the advice that fits it.
     */
    public function test_each_mapping_is_listed_under_the_advice_that_applies_to_it(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        $this->map_issuer_field(profile_fields::GROUPSLIST, 'Maintained Issuer');
        $this->map_issuer_field(profile_fields::ROLE, 'Unmanaged Issuer');

        $settings = $this->settings_on_page();
        $conflict = $settings['orgrolesyncmappingconflict']->description ?? '';
        $unmanaged = $settings['orgrolesyncmappingunmanaged']->description ?? '';

        $this->assertStringContainsString(profile_fields::GROUPSLIST, $conflict);
        $this->assertStringNotContainsString(profile_fields::ROLE, $conflict);
        $this->assertStringContainsString(profile_fields::ROLE, $unmanaged);
        $this->assertStringNotContainsString(profile_fields::GROUPSLIST, $unmanaged);
    }

    /**
     * A mapping onto a readable field is untidy rather than broken, and is said to be.
     *
     * Nothing matches on the readable fields, so such a mapping cannot revoke a role or empty
     * a cohort - it only means the two writers overwrite each other. Calling that a conflict
     * would spend an administrator's attention on the wrong mapping.
     */
    public function test_a_mapping_on_a_readable_field_is_reported_separately(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        $this->map_issuer_field(profile_fields::GROUPS);

        $names = $this->setting_names();

        $this->assertContains('orgrolesyncmappingdisplay', $names);
        $this->assertNotContains('orgrolesyncmappingconflict', $names);
        $this->assertNotContains('orgrolesyncmappingunmanaged', $names);
    }

    /**
     * All three kinds at once, each under the advice that fits it.
     */
    public function test_the_three_kinds_of_mapping_are_separated(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        $this->map_issuer_field(profile_fields::ORGLIST, 'Matching Issuer');
        $this->map_issuer_field(profile_fields::ORG, 'Readable Issuer');
        $this->map_issuer_field(profile_fields::ROLE, 'Unmanaged Issuer');

        $settings = $this->settings_on_page();

        $this->assertStringContainsString(
            'Matching Issuer',
            $settings['orgrolesyncmappingconflict']->description ?? ''
        );
        $this->assertStringContainsString(
            'Readable Issuer',
            $settings['orgrolesyncmappingdisplay']->description ?? ''
        );
        $this->assertStringContainsString(
            'Unmanaged Issuer',
            $settings['orgrolesyncmappingunmanaged']->description ?? ''
        );
    }

    /**
     * The conflict advice must not tell an administrator to remove a mapping before checking.
     *
     * Removing it first leaves nothing writing the field until the task next runs, which is
     * the outcome the ordering exists to prevent.
     */
    public function test_the_conflict_advice_puts_the_check_before_the_removal(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        $this->map_issuer_field(profile_fields::GROUPSLIST);

        $description = $this->settings_on_page()['orgrolesyncmappingconflict']->description ?? '';
        $check = strpos($description, 'confirm the Sync Keycloak Users task has run');
        $remove = strpos($description, 'only then remove the mappings');

        $this->assertNotFalse($check);
        $this->assertNotFalse($remove);
        $this->assertLessThan($remove, $check);
    }

    /**
     * Every mapped issuer is named, so an admin knows which ones to edit.
     */
    public function test_each_mapped_issuer_is_listed(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        $this->map_issuer_field(profile_fields::GROUPSLIST, 'Keycloak One');
        $this->map_issuer_field(profile_fields::ORGLIST, 'Keycloak Two');

        $notice = $this->settings_on_page()['orgrolesyncmappingconflict'];
        $description = $notice->description ?? '';

        $this->assertStringContainsString('Keycloak One', $description);
        $this->assertStringContainsString('Keycloak Two', $description);
    }

    /**
     * Give one user an org, the way the sync stores it.
     *
     * @param string $org
     */
    private function create_user_with_org(string $org): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $user = $this->getDataGenerator()->create_user(['auth' => 'oauth2']);
        profile_save_data((object)[
            'id' => $user->id,
            'profile_field_' . profile_fields::ORGLIST => org_roles::join_list([$org]),
            'profile_field_' . profile_fields::ORG => $org,
        ]);
        org_roles::reset_caches();
    }

    /**
     * The report names the category an org resolved to, and the rule that resolved it.
     */
    public function test_the_resolution_report_lists_each_org_and_its_category(): void {
        $this->getDataGenerator()->create_category(['name' => 'Acme', 'parent' => 0]);
        $this->create_user_with_org('Acme');

        $report = $this->settings_on_page()['orgresolutionreport'];
        $description = $report->description ?? '';

        $this->assertStringContainsString('Acme', $description);
        $this->assertStringContainsString(get_string('orgresolve_name', 'block_crucible'), $description);
    }

    /**
     * An org matching nothing is still listed, so the admin can see it grants nothing.
     */
    public function test_the_resolution_report_lists_an_unmatched_org(): void {
        $this->create_user_with_org('Globex Holdings');

        $description = $this->settings_on_page()['orgresolutionreport']->description ?? '';

        $this->assertStringContainsString('Globex Holdings', $description);
        $this->assertStringContainsString(get_string('orgresolve_unmatched', 'block_crucible'), $description);
    }

    /**
     * With no org data there is nothing to report, so the table is not rendered.
     */
    public function test_the_resolution_report_is_absent_without_any_org_data(): void {
        $this->assertNotContains('orgresolutionreport', $this->setting_names());
    }

    /**
     * The alias setting is always offered, since it is the only way to fix an unmatched org.
     */
    public function test_the_alias_setting_is_always_present(): void {
        $this->assertContains('orgcategoryaliases', $this->setting_names());
    }

    /**
     * The group role mapping setting is always offered.
     */
    public function test_the_group_role_mapping_setting_is_always_present(): void {
        $this->assertContains('grouprolemap', $this->setting_names());
    }

    /**
     * The report lists each mapping and whether its role can be granted.
     */
    public function test_the_group_role_report_lists_each_mapping(): void {
        $roleid = create_role('Lab Builder', 'lab-builder', 'Test role');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        set_config('grouprolemap', 'range-staff|lab-builder', 'block_crucible');

        $description = $this->settings_on_page()['grouprolereport']->description ?? '';

        $this->assertStringContainsString('range-staff', $description);
        $this->assertStringContainsString(get_string('grouprole_ok', 'block_crucible'), $description);
    }

    /**
     * A mapping whose role does not exist is reported as granting nothing.
     */
    public function test_the_group_role_report_names_a_missing_role(): void {
        set_config('grouprolemap', 'range-staff|no-such-role', 'block_crucible');

        $description = $this->settings_on_page()['grouprolereport']->description ?? '';

        $this->assertStringContainsString(get_string('grouprole_missing', 'block_crucible'), $description);
    }

    /**
     * Clearing the mappings while the sync is on is warned about.
     *
     * It is expressible on purpose, but far more likely a cleared box than a decision, and
     * the next sync run takes back every role the feature granted.
     */
    public function test_an_empty_mapping_list_is_warned_about_when_the_sync_is_on(): void {
        set_config('enableorgrolesync', 1, 'block_crucible');
        set_config('grouprolemap', '', 'block_crucible');

        $names = $this->setting_names();

        $this->assertContains('grouprolemapempty', $names);
        $this->assertNotContains('grouprolereport', $names);
    }

    /**
     * With the sync off there is nothing to warn about - no roles are being granted anyway.
     */
    public function test_an_empty_mapping_list_is_not_warned_about_when_the_sync_is_off(): void {
        set_config('enableorgrolesync', 0, 'block_crucible');
        set_config('grouprolemap', '', 'block_crucible');

        $this->assertNotContains('grouprolemapempty', $this->setting_names());
    }

    /**
     * A mapping naming a group the realm does not have is reported as granting nothing.
     */
    public function test_the_group_role_report_names_a_missing_group(): void {
        $roleid = create_role('Lab Builder', 'lab-builder', 'Test role');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        set_config('grouprolemap', 'lab-builder|lab-builder', 'block_crucible');
        org_roles::record_missing_groups(['lab-builder']);

        $description = $this->settings_on_page()['grouprolereport']->description ?? '';

        $this->assertStringContainsString(get_string('grouprole_nogroup', 'block_crucible'), $description);
    }

    /**
     * The report says when the group names were last checked, so the table is not read as
     * being resolved on render.
     */
    public function test_the_group_role_report_says_when_the_groups_were_checked(): void {
        set_config('grouprolemap', 'range-staff|lab-builder', 'block_crucible');

        $unchecked = $this->settings_on_page()['grouprolereport']->description ?? '';
        $this->assertStringContainsString(
            get_string('grouprolereportunchecked', 'block_crucible'),
            $unchecked
        );

        org_roles::record_missing_groups([]);
        $checked = $this->settings_on_page()['grouprolereport']->description ?? '';

        $this->assertStringNotContainsString(
            get_string('grouprolereportunchecked', 'block_crucible'),
            $checked
        );
    }

    /**
     * A group mapped twice is flagged, since only the last line applies.
     */
    public function test_the_group_role_report_flags_a_duplicated_group(): void {
        set_config('grouprolemap', "range-staff|cyber-manager\nrange-staff|lab-builder", 'block_crucible');

        $description = $this->settings_on_page()['grouprolereport']->description ?? '';

        $this->assertStringContainsString(get_string('grouprole_overridden', 'block_crucible'), $description);
    }
}
