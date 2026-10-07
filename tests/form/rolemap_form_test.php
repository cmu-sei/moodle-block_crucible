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
 * Unit tests for the group role mapping form.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\form;

use block_crucible\local\org_roles;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for \block_crucible\form\rolemap_form.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(rolemap_form::class)]
final class rolemap_form_test extends \advanced_testcase {
    /**
     * A page and a role the mapping can use.
     */
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();
        $PAGE->set_url('/blocks/crucible/manage_rolemap.php');
        $this->setAdminUser();
    }

    /**
     * Build the form with a group list and a stored mapping.
     *
     * @param string[] $groups
     * @param array<string, string> $mapping
     * @return rolemap_form
     */
    private function create_form(array $groups = [], array $mapping = []): rolemap_form {
        return new rolemap_form(null, ['groups' => $groups, 'mapping' => $mapping]);
    }

    /**
     * Only roles assignable in a category are offered.
     *
     * A role that can be assigned nowhere the sync assigns would be pickable and then do
     * nothing, with no explanation anywhere.
     */
    public function test_only_category_roles_are_offered(): void {
        $categoryrole = create_role('Category Only', 'category-only', 'Test role');
        set_role_contextlevels($categoryrole, [CONTEXT_COURSECAT]);
        $courserole = create_role('Course Only', 'course-only', 'Test role');
        set_role_contextlevels($courserole, [CONTEXT_COURSE]);

        $offered = rolemap_form::category_roles();

        $this->assertArrayHasKey('category-only', $offered);
        $this->assertArrayNotHasKey('course-only', $offered);
    }

    /**
     * Half a mapping is refused, because it grants nothing and reads as though it should.
     */
    public function test_half_a_mapping_is_refused(): void {
        $form = $this->create_form(['range-staff']);

        $errors = $form->validation(['mapgroup' => ['range-staff'], 'maprole' => ['']], []);

        $this->assertArrayHasKey('mapgroup[0]', $errors);
    }

    /**
     * An empty pair is not an error - it is the spare row the form always offers.
     */
    public function test_an_empty_pair_is_accepted(): void {
        $form = $this->create_form(['range-staff']);

        $errors = $form->validation(['mapgroup' => ['', ''], 'maprole' => ['', '']], []);

        $this->assertSame([], $errors);
    }

    /**
     * Mapping one group twice is refused rather than silently resolved.
     *
     * The stored format keeps the last line, so this would otherwise be accepted and then
     * reported back as a duplicate - after the earlier choice had been discarded.
     */
    public function test_a_duplicated_group_is_refused(): void {
        $form = $this->create_form(['range-staff']);

        $errors = $form->validation(
            ['mapgroup' => ['range-staff', 'range-staff'], 'maprole' => ['cyber-manager', 'lab-builder']],
            []
        );

        $this->assertArrayHasKey('mapgroup[1]', $errors);
        $this->assertArrayNotHasKey('mapgroup[0]', $errors);
    }

    /**
     * A group name carrying the delimiter is refused even though it came from a dropdown.
     *
     * The name comes from Keycloak, not from the administrator, so a picker does not make it
     * storable - the delimiter is what separates the two halves of a stored mapping.
     */
    public function test_a_group_name_carrying_the_delimiter_is_refused(): void {
        $name = 'range' . org_roles::DELIM . 'staff';
        $form = $this->create_form([$name]);

        $errors = $form->validation(['mapgroup' => [$name], 'maprole' => ['lab-builder']], []);

        $this->assertArrayHasKey('mapgroup[0]', $errors);
    }

    /**
     * A group in the stored mapping that the realm no longer has is still offered.
     *
     * Dropping it from the options would mean saving the form silently deleted a mapping the
     * administrator never touched. The page lists it as granting nothing instead.
     */
    public function test_a_stored_group_missing_from_the_realm_is_still_offered(): void {
        $form = $this->create_form(['range-staff'], ['gone-from-keycloak' => 'lab-builder']);

        $html = $form->render();

        $this->assertStringContainsString('gone-from-keycloak', $html);
    }

    /**
     * A stored role that cannot be assigned in a category is still offered.
     *
     * The dropdown lists only category-assignable roles, so such a row would otherwise load
     * blank - and saving would then fail "half a mapping" against a row the administrator
     * never touched, with no way to save the page until they worked out which row and why.
     * These are exactly the rows the report flags as granting nothing.
     */
    public function test_a_stored_role_not_assignable_in_a_category_is_still_offered(): void {
        $roleid = create_role('Course Only', 'course-only', 'Test role');
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        $form = $this->create_form(['range-staff'], ['range-staff' => 'course-only']);

        $html = $form->render();

        $this->assertStringContainsString('course-only', $html);
        $this->assertStringContainsString(
            get_string('grouprolemaprolenotincategory', 'block_crucible'),
            $html
        );
    }

    /**
     * A stored role that no longer exists at all is still offered, and said to be missing.
     */
    public function test_a_stored_role_that_does_not_exist_is_still_offered(): void {
        $form = $this->create_form(['range-staff'], ['range-staff' => 'deleted-role']);

        $html = $form->render();

        $this->assertStringContainsString('deleted-role', $html);
        $this->assertStringContainsString(get_string('grouprolemaprolemissing', 'block_crucible'), $html);
    }

    /**
     * A mapping whose role is not assignable in a category saves unchanged.
     *
     * The whole point of keeping it selectable: an administrator editing some other row must
     * not have this one silently emptied, or be blocked from saving by it.
     */
    public function test_a_row_with_an_unassignable_role_saves_unchanged(): void {
        $roleid = create_role('Course Only', 'course-only', 'Test role');
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        $form = $this->create_form(['range-staff', 'range-leads'], ['range-staff' => 'course-only']);

        $errors = $form->validation(
            ['mapgroup' => ['range-staff', 'range-leads'], 'maprole' => ['course-only', 'lab-builder']],
            []
        );

        $this->assertSame([], $errors);
        $this->assertSame(
            ['range-staff' => 'course-only', 'range-leads' => 'lab-builder'],
            rolemap_form::submitted_mapping((object)[
                'mapgroup' => ['range-staff', 'range-leads'],
                'maprole' => ['course-only', 'lab-builder'],
            ])
        );
    }

    /**
     * The submitted pairs become a group => role map, dropping the empty rows.
     */
    public function test_the_submitted_pairs_become_a_mapping(): void {
        $data = (object)[
            'mapgroup' => ['range-staff', '', 'range-leads'],
            'maprole' => ['lab-builder', '', 'cyber-manager'],
        ];

        $this->assertSame(
            ['range-staff' => 'lab-builder', 'range-leads' => 'cyber-manager'],
            rolemap_form::submitted_mapping($data)
        );
    }

    /**
     * What the picker stores is what the setting's parse reads back.
     *
     * One storage format with two editors. If these could disagree, the picker would be a
     * second place roles came from rather than another way to edit the one place.
     */
    public function test_the_picker_and_the_setting_agree_on_storage(): void {
        $mapping = ['range-staff' => 'lab-builder', 'range-leads' => 'cyber-manager'];

        org_roles::set_group_role_map($mapping);

        $this->assertSame($mapping, org_roles::group_role_map());
    }

    /**
     * Storing an empty mapping clears the setting, which means "no mappings".
     */
    public function test_storing_nothing_clears_the_mapping(): void {
        org_roles::set_group_role_map(['range-staff' => 'lab-builder']);

        org_roles::set_group_role_map([]);

        $this->assertSame([], org_roles::group_role_map());
    }

    /**
     * A save through the picker is recorded in the config log.
     *
     * The admin settings writer logs its own saves, so a change made through the setting is
     * already dated. set_config() on its own is not, which would make the picker the one way
     * to change who gets which role and leave no trace of it.
     */
    public function test_a_save_is_written_to_the_config_log(): void {
        // Counted as a delta, not an absolute: installing the site applies this setting's
        // default and logs that, so there is already one row before any test runs.
        $before = $this->config_log_count();

        org_roles::set_group_role_map(['range-staff' => 'lab-builder']);

        $this->assertSame($before + 1, $this->config_log_count());
        $this->assertSame('range-staff|lab-builder', $this->latest_config_log()->value);
    }

    /**
     * Clearing the mapping is recorded too, since that is the change worth dating.
     */
    public function test_clearing_the_mapping_is_written_to_the_config_log(): void {
        $before = $this->config_log_count();

        org_roles::set_group_role_map(['range-staff' => 'lab-builder']);
        org_roles::set_group_role_map([]);

        $this->assertSame($before + 2, $this->config_log_count());
        $latest = $this->latest_config_log();
        $this->assertSame('', $latest->value);
        $this->assertSame('range-staff|lab-builder', $latest->oldvalue);
    }

    /**
     * Saving the same mapping again logs nothing, so the log stays a list of real changes.
     */
    public function test_saving_an_unchanged_mapping_logs_nothing(): void {
        org_roles::set_group_role_map(['range-staff' => 'lab-builder']);
        $before = $this->config_log_count();

        org_roles::set_group_role_map(['range-staff' => 'lab-builder']);

        $this->assertSame($before, $this->config_log_count());
    }

    /**
     * How many times this setting has been logged as changing.
     *
     * @return int
     */
    private function config_log_count(): int {
        global $DB;

        return $DB->count_records('config_log', ['plugin' => 'block_crucible', 'name' => 'grouprolemap']);
    }

    /**
     * The most recent config log row for this setting.
     *
     * @return \stdClass
     */
    private function latest_config_log(): \stdClass {
        global $DB;

        $rows = $DB->get_records(
            'config_log',
            ['plugin' => 'block_crucible', 'name' => 'grouprolemap'],
            'id DESC',
            '*',
            0,
            1
        );

        return reset($rows);
    }

    /**
     * An unstorable group name is never written, whatever asked for it.
     */
    public function test_an_unstorable_group_name_is_not_stored(): void {
        org_roles::set_group_role_map([
            'range' . org_roles::DELIM . 'staff' => 'lab-builder',
            'range-leads' => 'cyber-manager',
        ]);

        $this->assertSame(['range-leads' => 'cyber-manager'], org_roles::group_role_map());
    }
}
