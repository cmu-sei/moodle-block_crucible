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
 * Add / edit a Crucible application.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('block_crucible_manageapps');

$context = context_system::instance();
$appid   = optional_param('id', 0, PARAM_INT);

// Load existing record when editing.
$app = null;
if ($appid > 0) {
    $app = $DB->get_record('block_crucible_apps', ['id' => $appid], '*', MUST_EXIST);
}

$manageurl = new moodle_url('/blocks/crucible/manage_apps.php');
$editurl   = new moodle_url('/blocks/crucible/edit_app.php', $appid ? ['id' => $appid] : []);

$mform = new \block_crucible\form\app_form($editurl->out(false));

if ($mform->is_cancelled()) {
    redirect($manageurl);
}

$logooptions = \block_crucible\form\app_form::logo_options();

if ($formdata = $mform->get_data()) {
    // Auto-generate the app key from the name.
    $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($formdata->name)));
    $key = trim($key, '_');

    // Only persist API URL / key when the corresponding checkbox was checked.
    $apiurl = !empty($formdata->useapi)    ? trim($formdata->apiurl ?? '') : '';
    $apikeyplain = !empty($formdata->useapikey) ? trim($formdata->apikey ?? '') : '';
    $apikey = ($apikeyplain !== '') ? \core\encryption::encrypt($apikeyplain) : '';

    // Only persist Keycloak role fields when role mapping is enabled.
    $keycloakenabled = (int)(!empty($formdata->keycloakenabled));
    $keycloakrole    = $keycloakenabled ? trim($formdata->keycloakrole ?? '') : '';
    $overriderole    = $keycloakenabled ? (int)(!empty($formdata->overriderole)) : 0;

    $now = time();

    if ($appid > 0) {
        $record = (object)[
            'id'             => $appid,
            'name'           => $formdata->name,
            'appkey'         => $key,
            'description'    => $formdata->description,
            'appurl'         => $formdata->appurl,
            'apiurl'         => $apiurl,
            'apikey'         => $apikey,
            'keycloakenabled' => $keycloakenabled,
            'keycloakrole'   => $keycloakrole,
            'overriderole'   => $overriderole,
            'enabled'        => (int)(!empty($formdata->enabled)),
            'timemodified'   => $now,
        ];
        $DB->update_record('block_crucible_apps', $record);

        file_save_draft_area_files(
            $formdata->logo,
            $context->id,
            'block_crucible',
            'app_logo',
            $appid,
            $logooptions
        );

        redirect($manageurl, get_string('appupdated', 'block_crucible'), null, \core\output\notification::NOTIFY_SUCCESS);
    } else {
        $record = (object)[
            'name'            => $formdata->name,
            'appkey'          => $key,
            'description'     => $formdata->description,
            'appurl'          => $formdata->appurl,
            'apiurl'          => $apiurl,
            'apikey'          => $apikey,
            'keycloakenabled' => $keycloakenabled,
            'keycloakrole'    => $keycloakrole,
            'overriderole'    => $overriderole,
            'enabled'         => (int)(!empty($formdata->enabled)),
            'sortorder'       => 0,
            'timecreated'     => $now,
            'timemodified'    => $now,
        ];
        $newid = $DB->insert_record('block_crucible_apps', $record);

        file_save_draft_area_files(
            $formdata->logo,
            $context->id,
            'block_crucible',
            'app_logo',
            $newid,
            $logooptions
        );

        redirect($manageurl, get_string('appadded', 'block_crucible'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

// Pre-populate form with existing data (editing) or defaults (adding).
if ($app) {
    $draftitemid = file_get_submitted_draft_itemid('logo');
    file_prepare_draft_area(
        $draftitemid,
        $context->id,
        'block_crucible',
        'app_logo',
        $app->id,
        $logooptions
    );

    $mform->set_data([
        'id'              => $app->id,
        'name'            => $app->name,
        'description'     => $app->description,
        'appurl'          => $app->appurl,
        'useapi'          => !empty($app->apiurl) ? 1 : 0,
        'apiurl'          => $app->apiurl ?? '',
        'useapikey'       => !empty($app->apikey) ? 1 : 0,
        'apikey'          => !empty($app->apikey) ? \core\encryption::decrypt($app->apikey) : '',
        'keycloakenabled' => (int)($app->keycloakenabled ?? 0),
        'keycloakrole'    => $app->keycloakrole ?? '',
        'overriderole'    => (int)($app->overriderole ?? 0),
        'enabled'         => $app->enabled,
        'logo'            => $draftitemid,
    ]);
} else {
    $draftitemid = file_get_submitted_draft_itemid('logo');
    file_prepare_draft_area($draftitemid, $context->id, 'block_crucible', 'app_logo', 0, $logooptions);
    $mform->set_data(['id' => 0, 'logo' => $draftitemid]);
}

$PAGE->set_url($editurl);
$heading = $app ? get_string('editapp', 'block_crucible') : get_string('addnewapp', 'block_crucible');

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);

$mform->display();

echo $OUTPUT->footer();
