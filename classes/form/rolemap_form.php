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
 * Form for mapping Keycloak groups to the roles they grant.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\form;

use block_crucible\local\org_roles;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Pick a Keycloak group and the role it grants, as many times as needed.
 *
 * The same stored value as the Group role mappings setting, so this is another editor over
 * one configuration rather than a second place roles can come from. The setting stays: it is
 * the only way to edit the mapping when Keycloak cannot be reached, and the only sensible way
 * to paste a long list in.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rolemap_form extends \moodleform {
    /**
     * Build the repeated group and role pair.
     */
    public function definition() {
        $mform = $this->_form;
        $groups = $this->_customdata['groups'] ?? [];
        $mapping = $this->_customdata['mapping'] ?? [];

        // A group named in the stored mapping but absent from the realm has to stay
        // selectable, or saving the form would silently drop it. It is offered, and the
        // page lists it as unknown above.
        $groupoptions = ['' => get_string('choosedots')];
        foreach (array_unique(array_merge($groups, array_keys($mapping))) as $name) {
            $groupoptions[$name] = $name;
        }

        // Stored roles need the same protection as stored groups above, and for the same
        // reason. The dropdown lists only roles assignable in a category, so a stored mapping
        // naming one that is missing or course-only - exactly the rows the report flags as
        // granting nothing - would load its dropdown blank. Saving would then fail with "half
        // a mapping" against a row the administrator never touched, and the page could not be
        // saved at all until they worked out which row and why.
        $roleoptions = ['' => get_string('choosedots')];
        $assignable = self::category_roles();
        foreach ($assignable as $shortname => $label) {
            $roleoptions[$shortname] = $label;
        }
        foreach (array_unique(array_values($mapping)) as $shortname) {
            if (!isset($roleoptions[$shortname])) {
                $roleoptions[$shortname] = self::unassignable_role_label($shortname);
            }
        }

        // One more than is stored, so there is always an empty pair to fill in without
        // having to press the button first.
        $repeats = max(count($mapping) + 1, 1);

        // Each pair is one row under a single header, so the mappings read as a table rather
        // than as a run of identical-looking dropdowns. The column labels stay on each
        // dropdown for screen readers. The children keep their own names, so the submitted
        // data is the same two arrays either way.
        $grouplabel = get_string('grouprolegroup', 'block_crucible');
        $rolelabel = get_string('grouprolecategoryrole', 'block_crucible');
        $mform->addElement('group', 'mapheader', '', [
            $mform->createElement('static', 'mapheadgroup', '', \html_writer::span($grouplabel, 'block-crucible-mapcol fw-bold')),
            $mform->createElement('static', 'mapheadrole', '', \html_writer::span($rolelabel, 'block-crucible-mapcol fw-bold')),
        ], ' ', false);

        $groupselect = $mform->createElement('select', 'mapgroup', $grouplabel, $groupoptions);
        $groupselect->setHiddenLabel(true);
        $roleselect = $mform->createElement('select', 'maprole', $rolelabel, $roleoptions);
        $roleselect->setHiddenLabel(true);
        $pairlabel = get_string('grouprolemapping', 'block_crucible');
        $pair = $mform->createElement('group', 'mapping', $pairlabel, [$groupselect, $roleselect], ' ', false);
        $this->repeat_elements(
            [$pair],
            $repeats,
            [],
            'maprepeats',
            'mapadd',
            1,
            get_string('grouprolemapadd', 'block_crucible'),
            true
        );

        $this->add_action_buttons();
    }

    /**
     * Roles that can actually be granted in a category context, by shortname.
     *
     * Offering every role would let an administrator pick one that can be assigned nowhere
     * the sync assigns, and the mapping would then do nothing with no explanation.
     *
     * @return array<string, string> shortname => display name
     */
    public static function category_roles(): array {
        $all = get_all_roles();
        $roles = [];
        foreach (get_roles_for_contextlevels(CONTEXT_COURSECAT) as $roleid) {
            if (isset($all[$roleid])) {
                $role = $all[$roleid];
                $roles[$role->shortname] = role_get_name($role);
            }
        }
        \core_collator::asort($roles);

        return $roles;
    }

    /**
     * How a stored role the dropdown would not otherwise offer is labelled.
     *
     * It stays selectable so the row can be saved, but it says what is wrong with it, since
     * choosing it again grants nothing.
     *
     * @param string $shortname
     * @return string
     */
    private static function unassignable_role_label(string $shortname): string {
        global $DB;

        $exists = $DB->record_exists('role', ['shortname' => $shortname]);

        return $shortname . ' ' . get_string(
            $exists ? 'grouprolemaprolenotincategory' : 'grouprolemaprolemissing',
            'block_crucible'
        );
    }

    /**
     * Reject a mapping that could not be stored or that says two things at once.
     *
     * @param array $data
     * @param array $files
     * @return array errors keyed by the row's group name
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $groups = $data['mapgroup'] ?? [];
        $roles = $data['maprole'] ?? [];
        $seen = [];

        foreach ($groups as $index => $group) {
            $group = trim((string)$group);
            $role = trim((string)($roles[$index] ?? ''));

            if ($group === '' && $role === '') {
                continue;
            }
            if ($group === '' || $role === '') {
                // Half a mapping grants nothing and reads as though it should.
                $errors['mapping[' . $index . ']'] = get_string('grouprolemaphalf', 'block_crucible');
                continue;
            }
            // The dropdown does not remove this check: the name comes from Keycloak, and a
            // group whose name carries the delimiter cannot be stored at all.
            if (strpos($group, org_roles::DELIM) !== false) {
                $errors['mapping[' . $index . ']'] = get_string(
                    'grouprolemapdelimiter',
                    'block_crucible',
                    org_roles::DELIM
                );
                continue;
            }
            if (isset($seen[$group])) {
                // One group cannot grant two roles. The stored format silently keeps the
                // last, which is worth refusing here rather than reporting afterwards.
                $errors['mapping[' . $index . ']'] = get_string('grouprolemapduplicate', 'block_crucible');
                continue;
            }
            $seen[$group] = true;
        }

        return $errors;
    }

    /**
     * The submitted mapping, in the order given, as group => role shortname.
     *
     * @param object $data as returned by get_data()
     * @return array<string, string>
     */
    public static function submitted_mapping(object $data): array {
        $mapping = [];
        foreach ($data->mapgroup ?? [] as $index => $group) {
            $group = trim((string)$group);
            $role = trim((string)($data->maprole[$index] ?? ''));
            if ($group !== '' && $role !== '') {
                $mapping[$group] = $role;
            }
        }

        return $mapping;
    }
}
