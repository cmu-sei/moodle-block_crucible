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

/**
 * Admin page for managing custom Crucible applications.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// This sets up the page as a registered admin external page.
admin_externalpage_setup('block_crucible_manageapps');

$context = context_system::instance();

$action = optional_param('action', '', PARAM_ALPHA);
$appid  = optional_param('id', 0, PARAM_INT);

// Handle delete action.
if ($action === 'delete' && $appid > 0) {
    require_sesskey();

    // Remove any uploaded logo files.
    $fs = get_file_storage();
    $fs->delete_area_files($context->id, 'block_crucible', 'app_logo', $appid);

    $DB->delete_records('block_crucible_apps', ['id' => $appid]);

    redirect(
        new moodle_url('/blocks/crucible/manage_apps.php'),
        get_string('appdeleted', 'block_crucible'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

// Handle enable/disable toggle.
if ($action === 'toggle' && $appid > 0) {
    require_sesskey();

    $app = $DB->get_record('block_crucible_apps', ['id' => $appid], '*', MUST_EXIST);
    $DB->update_record('block_crucible_apps', (object)[
        'id'           => $appid,
        'enabled'      => $app->enabled ? 0 : 1,
        'timemodified' => time(),
    ]);

    redirect(new moodle_url('/blocks/crucible/manage_apps.php'));
}

$PAGE->set_url(new moodle_url('/blocks/crucible/manage_apps.php'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageapps', 'block_crucible'));

// "Add new app" button.
$addurl = new moodle_url('/blocks/crucible/edit_app.php');
echo $OUTPUT->single_button($addurl, get_string('addnewapp', 'block_crucible'), 'get');

echo html_writer::empty_tag('br');

// Load all custom apps.
$apps = $DB->get_records('block_crucible_apps', null, 'sortorder ASC, name ASC');

if ($apps) {
    $table            = new html_table();
    $table->attributes = ['class' => 'generaltable'];
    $table->head = [
        get_string('applogo', 'block_crucible'),
        get_string('appname', 'block_crucible'),
        get_string('appkey', 'block_crucible'),
        get_string('appurl', 'block_crucible'),
        get_string('appdescription', 'block_crucible'),
        get_string('appenabled', 'block_crucible'),
        get_string('actions', 'block_crucible'),
    ];
    $table->data = [];

    foreach ($apps as $app) {
        $editurl   = new moodle_url('/blocks/crucible/edit_app.php', ['id' => $app->id]);
        $toggleurl = new moodle_url('/blocks/crucible/manage_apps.php', [
            'action'  => 'toggle',
            'id'      => $app->id,
            'sesskey' => sesskey(),
        ]);
        $deleteurl = new moodle_url('/blocks/crucible/manage_apps.php', [
            'action'  => 'delete',
            'id'      => $app->id,
            'sesskey' => sesskey(),
        ]);

        // Retrieve the uploaded logo (if any).
        $fs    = get_file_storage();
        $files = $fs->get_area_files($context->id, 'block_crucible', 'app_logo', $app->id, 'id DESC', false);
        $logohtml = '';
        if ($files) {
            $logofile = reset($files);
            $logourl  = moodle_url::make_pluginfile_url(
                $logofile->get_contextid(),
                $logofile->get_component(),
                $logofile->get_filearea(),
                $logofile->get_itemid(),
                $logofile->get_filepath(),
                $logofile->get_filename()
            );
            $logohtml = html_writer::empty_tag('img', [
                'src'   => $logourl,
                'alt'   => format_string($app->name),
                'style' => 'max-height:40px; max-width:60px;',
            ]);
        }

        $enabledbadge = $app->enabled
            ? html_writer::span(get_string('yes'), 'badge badge-success')
            : html_writer::span(get_string('no'), 'badge badge-secondary');

        $togglelabel = $app->enabled ? get_string('disable') : get_string('enable');

        $actions  = html_writer::link($editurl, get_string('edit'));
        $actions .= ' | ';
        $actions .= html_writer::link($toggleurl, $togglelabel);
        $actions .= ' | ';
        $actions .= html_writer::link(
            $deleteurl,
            get_string('delete'),
            ['data-confirm' => get_string('confirmdelete', 'block_crucible'),
             'onclick' => 'return confirm(' . json_encode(get_string('confirmdelete', 'block_crucible')) . ')']
        );

        $table->data[] = [
            $logohtml,
            format_string($app->name),
            $app->appkey,
            format_string($app->appurl),
            format_string($app->description),
            $enabledbadge,
            $actions,
        ];
    }

    echo html_writer::table($table);
} else {
    echo $OUTPUT->notification(get_string('noapps', 'block_crucible'), 'info');
}

echo $OUTPUT->footer();
