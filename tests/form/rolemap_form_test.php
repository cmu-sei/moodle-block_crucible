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
