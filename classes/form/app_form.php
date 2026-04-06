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
 * Form for adding and editing custom applications in the Crucible block.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Moodle form for creating and editing a custom Crucible application.
 */
class app_form extends \moodleform {

    /** @var array File manager options for the logo upload. */
    public static function logo_options(): array {
        return [
            'subdirs'        => 0,
            'maxbytes'       => 2 * 1024 * 1024, // 2 MB.
            'maxfiles'       => 1,
            'accepted_types' => ['image'],
        ];
    }

    /**
     * Defines the form fields.
     */
    public function definition() {
        $mform = $this->_form;

        // Hidden record id (0 for new apps).
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        // App display name.
        $mform->addElement('text', 'name', get_string('appname', 'block_crucible'), ['size' => 50]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addHelpButton('name', 'appname', 'block_crucible');

        // App key / slug (auto-generated from name if left blank).
        $mform->addElement('text', 'appkey', get_string('appkey', 'block_crucible'), ['size' => 30]);
        $mform->setType('appkey', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('appkey', 'appkey', 'block_crucible');

        // Short description / slogan shown on the app card.
        $mform->addElement(
            'textarea',
            'description',
            get_string('appdescription', 'block_crucible'),
            ['rows' => 3, 'cols' => 50]
        );
        $mform->setType('description', PARAM_TEXT);

        // Application URL.
        $mform->addElement('text', 'appurl', get_string('appurl', 'block_crucible'), ['size' => 60]);
        $mform->setType('appurl', PARAM_URL);
        $mform->addRule('appurl', null, 'required', null, 'client');

        // Logo upload via Moodle file manager.
        $mform->addElement(
            'filemanager',
            'logo',
            get_string('applogo', 'block_crucible'),
            null,
            self::logo_options()
        );
        $mform->addHelpButton('logo', 'applogo', 'block_crucible');

        // Sort order (lower numbers appear first).
        $mform->addElement('text', 'sortorder', get_string('appsortorder', 'block_crucible'), ['size' => 5]);
        $mform->setType('sortorder', PARAM_INT);
        $mform->setDefault('sortorder', 0);

        // Enabled toggle.
        $mform->addElement('advcheckbox', 'enabled', get_string('appenabled', 'block_crucible'));
        $mform->setDefault('enabled', 1);

        $this->add_action_buttons();
    }

    /**
     * Validates form data.
     *
     * @param array $data  Submitted form data.
     * @param array $files Uploaded files.
     * @return array Associative array of field => error message.
     */
    public function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);

        // Validate or auto-generate the app key.
        $key = trim($data['appkey'] ?? '');
        if ($key === '') {
            // Auto-generate from name: lowercase, replace non-alphanumeric with underscore.
            $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($data['name'] ?? '')));
            $key = trim($key, '_');
        }

        if ($key === '') {
            $errors['appkey'] = get_string('appkeyrequired', 'block_crucible');
        } else {
            // Check uniqueness, ignoring the current record when editing.
            $existing = $DB->get_record('block_crucible_apps', ['appkey' => $key]);
            if ($existing && (int)$existing->id !== (int)($data['id'] ?? 0)) {
                $errors['appkey'] = get_string('appkeyexists', 'block_crucible');
            }
        }

        return $errors;
    }
}
