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
 * Form for adding and editing applications in the Crucible block.
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
 * Moodle form for creating and editing a Crucible application.
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

        // Description shown on the app card.
        $mform->addElement(
            'textarea',
            'description',
            get_string('appdescription', 'block_crucible'),
            ['rows' => 3, 'cols' => 50]
        );
        $mform->setType('description', PARAM_TEXT);
        $mform->addHelpButton('description', 'appdescription', 'block_crucible');

        // Application URL.
        $mform->addElement('text', 'appurl', get_string('appurl', 'block_crucible'), ['size' => 60]);
        $mform->setType('appurl', PARAM_URL);
        $mform->addRule('appurl', null, 'required', null, 'client');
        $mform->addHelpButton('appurl', 'appurl', 'block_crucible');

        // Logo upload via Moodle file manager.
        $mform->addElement(
            'filemanager',
            'logo',
            get_string('applogo', 'block_crucible'),
            null,
            self::logo_options()
        );
        $mform->addHelpButton('logo', 'applogo', 'block_crucible');

        // --- API section ---

        // "Enable API integration" checkbox — reveals the API URL field when checked.
        $mform->addElement('advcheckbox', 'useapi', get_string('appuseapi', 'block_crucible'));
        $mform->setDefault('useapi', 0);
        $mform->addHelpButton('useapi', 'appuseapi', 'block_crucible');

        $mform->addElement('text', 'apiurl', get_string('appapiurl', 'block_crucible'), ['size' => 60]);
        $mform->setType('apiurl', PARAM_URL);
        $mform->addHelpButton('apiurl', 'appapiurl', 'block_crucible');
        $mform->hideIf('apiurl', 'useapi', 'notchecked');

        // "API requires authentication key" checkbox — reveals the API key field when checked.
        $mform->addElement('advcheckbox', 'useapikey', get_string('appuseapikey', 'block_crucible'));
        $mform->setDefault('useapikey', 0);
        $mform->addHelpButton('useapikey', 'appuseapikey', 'block_crucible');

        $mform->addElement('text', 'apikey', get_string('appapikey', 'block_crucible'), ['size' => 60]);
        $mform->setType('apikey', PARAM_RAW);
        $mform->addHelpButton('apikey', 'appapikey', 'block_crucible');
        $mform->hideIf('apikey', 'useapikey', 'notchecked');

        // --- Keycloak role mapping section ---

        // "Keycloak Role Mapping Enabled?" checkbox — reveals role name field when checked.
        $mform->addElement('advcheckbox', 'keycloakenabled', get_string('appkeycloakenabled', 'block_crucible'));
        $mform->setDefault('keycloakenabled', 0);
        $mform->addHelpButton('keycloakenabled', 'appkeycloakenabled', 'block_crucible');

        $mform->addElement('text', 'keycloakrole', get_string('appkeycloakrole', 'block_crucible'), ['size' => 50]);
        $mform->setType('keycloakrole', PARAM_TEXT);
        $mform->addHelpButton('keycloakrole', 'appkeycloakrole', 'block_crucible');
        $mform->hideIf('keycloakrole', 'keycloakenabled', 'notchecked');

        // "Override role permissions" checkbox — show app regardless of token role.
        $mform->addElement('advcheckbox', 'overriderole', get_string('appoverriderole', 'block_crucible'));
        $mform->setDefault('overriderole', 0);
        $mform->addHelpButton('overriderole', 'appoverriderole', 'block_crucible');
        $mform->hideIf('overriderole', 'keycloakenabled', 'notchecked');

        // Enabled toggle.
        $mform->addElement('advcheckbox', 'enabled', get_string('appenabled', 'block_crucible'));
        $mform->setDefault('enabled', 1);
        $mform->addHelpButton('enabled', 'appenabled', 'block_crucible');

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

        // Auto-generate the app key from the name and check for duplicates.
        $key = preg_replace('/[^a-z0-9]+/', '_', strtolower(trim($data['name'] ?? '')));
        $key = trim($key, '_');

        if ($key !== '') {
            $params = ['appkey' => $key];
            $sql = 'SELECT id FROM {block_crucible_apps} WHERE appkey = :appkey';
            // When editing, exclude the current record from the duplicate check.
            if (!empty($data['id'])) {
                $sql .= ' AND id <> :id';
                $params['id'] = $data['id'];
            }
            if ($DB->record_exists_sql($sql, $params)) {
                $errors['name'] = get_string('appnameexists', 'block_crucible');
            }
        }

        return $errors;
    }
}
