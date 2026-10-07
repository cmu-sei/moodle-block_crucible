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
 * Admin page for mapping Keycloak groups to the roles they grant.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use block_crucible\form\rolemap_form;
use block_crucible\local\keycloak;
use block_crucible\local\org_roles;

admin_externalpage_setup('block_crucible_managerolemap');

$pageurl = new moodle_url('/blocks/crucible/manage_rolemap.php');
$refresh = optional_param('refresh', 0, PARAM_BOOL);
$clearall = optional_param('clearall', 0, PARAM_BOOL);

if ($refresh) {
    require_sesskey();
    // The administrator asked, so bypass the cache rather than wait out its lifetime. A
    // group created in Keycloak a moment ago is exactly when this button gets pressed.
    \core\di::get(keycloak::class)->group_names(true);
    redirect($pageurl);
}

if ($clearall) {
    require_sesskey();
    org_roles::set_group_role_map([]);
    redirect($pageurl, get_string('grouprolemapsaved', 'block_crucible'), null, \core\output\notification::NOTIFY_SUCCESS);
}

$mapping = org_roles::group_role_map();
$groups = \core\di::get(keycloak::class)->group_names();

// Keycloak unreachable, or no issuer configured. Showing a picker built from an empty group
// list would offer nothing and look like a realm with no groups, and letting the form save
// in that state would drop every mapping whose group it could not offer. So the page becomes
// read-only and says where to edit instead - the setting is text, so it needs no realm.
$readonly = $groups === null;

$form = null;
$confirmclear = false;
if (!$readonly) {
    $form = new rolemap_form($pageurl->out(false), ['groups' => $groups, 'mapping' => $mapping]);

    if ($form->is_cancelled()) {
        redirect($pageurl);
    } else if ($data = $form->get_data()) {
        $submitted = rolemap_form::submitted_mapping($data);
        if (!$submitted && $mapping && org_roles::is_enabled()) {
            // Emptying the map takes every role this feature granted back on the next sync
            // run. The setting warns about that state on the settings page, but only once it
            // is already saved; here it can be asked first. Nothing has to be carried through
            // the confirmation, because the state being confirmed is "no mappings at all".
            $confirmclear = true;
        } else {
            org_roles::set_group_role_map($submitted);
            redirect(
                $pageurl,
                get_string('grouprolemapsaved', 'block_crucible'),
                null,
                \core\output\notification::NOTIFY_SUCCESS
            );
        }
    }

    $defaults = ['mapgroup' => [], 'maprole' => []];
    foreach ($mapping as $group => $role) {
        $defaults['mapgroup'][] = $group;
        $defaults['maprole'][] = $role;
    }
    $form->set_data($defaults);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managerolemap', 'block_crucible'));

if ($confirmclear) {
    echo $OUTPUT->confirm(
        get_string('grouprolemapclearconfirm', 'block_crucible'),
        new moodle_url($pageurl, ['clearall' => 1, 'sesskey' => sesskey()]),
        $pageurl
    );
    echo $OUTPUT->footer();
    die();
}

if ($readonly) {
    echo $OUTPUT->notification(get_string('grouprolemapnorealm', 'block_crucible'), \core\output\notification::NOTIFY_ERROR);
}

// What is stored, whatever state the realm is in. On the read-only path this is the whole
// page; otherwise it is the reference the form is edited against.
echo $OUTPUT->heading(get_string('grouprolereport', 'block_crucible'), 3);
$report = org_roles::group_role_report();
if (!$report) {
    echo $OUTPUT->notification(get_string('grouprolemapemptydesc', 'block_crucible'), \core\output\notification::NOTIFY_WARNING);
} else {
    $table = new html_table();
    $table->head = [
        get_string('grouprolegroup', 'block_crucible'),
        get_string('grouprolerole', 'block_crucible'),
        get_string('grouprolestate', 'block_crucible'),
    ];
    foreach ($report as $row) {
        $state = get_string('grouprole_' . $row['state'], 'block_crucible');
        // A row that grants nothing is the reason to look at this page at all, so it is not
        // left to be spotted in a column of similar-looking text.
        $table->data[] = [
            s($row['group']),
            s($row['role']),
            $row['state'] === org_roles::ROLE_OK
                ? $state
                : html_writer::span($state, 'text-danger'),
        ];
    }
    echo html_writer::table($table);
}

if ($readonly) {
    echo html_writer::div(
        html_writer::link(
            new moodle_url('/admin/settings.php', ['section' => 'blocksettingcrucible']),
            get_string('grouprolemapeditastext', 'block_crucible')
        )
    );
} else {
    echo html_writer::div(
        html_writer::link(
            new moodle_url($pageurl, ['refresh' => 1, 'sesskey' => sesskey()]),
            get_string('grouprolemaprefresh', 'block_crucible'),
            ['class' => 'btn btn-secondary']
        ),
        'mb-3'
    );
    $form->display();
}

echo $OUTPUT->footer();
