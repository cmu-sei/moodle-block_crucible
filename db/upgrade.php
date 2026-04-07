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
 * Upgrade script for block_crucible.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade function for block_crucible.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_block_crucible_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026040100) {
        // Create block_crucible_apps table for dynamically managed applications.
        $table = new xmldb_table('block_crucible_apps');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('appkey', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('description', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('appurl', XMLDB_TYPE_CHAR, '1333', null, null, null, null);
        $table->add_field('enabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '1');
        $table->add_field('sortorder', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('appkey_unique', XMLDB_KEY_UNIQUE, ['appkey']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2026040100, 'crucible');
    }

    if ($oldversion < 2026040200) {
        // Add apiurl and apikey columns to block_crucible_apps.
        $table = new xmldb_table('block_crucible_apps');

        $apiurlfield = new xmldb_field('apiurl', XMLDB_TYPE_CHAR, '1333', null, null, null, null, 'appurl');
        if (!$dbman->field_exists($table, $apiurlfield)) {
            $dbman->add_field($table, $apiurlfield);
        }

        $apikeyfield = new xmldb_field('apikey', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'apiurl');
        if (!$dbman->field_exists($table, $apikeyfield)) {
            $dbman->add_field($table, $apikeyfield);
        }

        upgrade_block_savepoint(true, 2026040200, 'crucible');
    }

    if ($oldversion < 2026040300) {
        // Add Keycloak role mapping fields to block_crucible_apps.
        $table = new xmldb_table('block_crucible_apps');

        $keycloakenabledfield = new xmldb_field('keycloakenabled', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'apikey');
        if (!$dbman->field_exists($table, $keycloakenabledfield)) {
            $dbman->add_field($table, $keycloakenabledfield);
        }

        $keycloakrolefield = new xmldb_field('keycloakrole', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'keycloakenabled');
        if (!$dbman->field_exists($table, $keycloakrolefield)) {
            $dbman->add_field($table, $keycloakrolefield);
        }

        $overriderolefield = new xmldb_field('overriderole', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0', 'keycloakrole');
        if (!$dbman->field_exists($table, $overriderolefield)) {
            $dbman->add_field($table, $overriderolefield);
        }

        upgrade_block_savepoint(true, 2026040300, 'crucible');
    }

    if ($oldversion < 2026040800) {
        // Change apikey column from CHAR(255) to TEXT so it can hold encrypted values.
        $table = new xmldb_table('block_crucible_apps');
        $field = new xmldb_field('apikey', XMLDB_TYPE_TEXT, null, null, null, null, null, 'apiurl');
        $dbman->change_field_type($table, $field);

        // Encrypt any existing plain-text API keys.
        $apps = $DB->get_records_select('block_crucible_apps', "apikey IS NOT NULL AND apikey <> ''");
        foreach ($apps as $app) {
            // Skip values that are already encrypted (they start with the method prefix).
            if (strpos($app->apikey, 'sodium:') === 0 || strpos($app->apikey, 'openssl-') === 0) {
                continue;
            }
            $DB->set_field('block_crucible_apps', 'apikey', \core\encryption::encrypt($app->apikey), ['id' => $app->id]);
        }

        upgrade_block_savepoint(true, 2026040800, 'crucible');
    }

    if ($oldversion < 2026040801) {
        // Clean up orphaned config rows for settings that were removed from the plugin.
        $stalesettings = [
            'showrocketchat',
            'rocketchatapiurl',
            'rocketchatauthtoken',
            'rocketchatuserid',
            'showroundcube',
            'roundcubeappurl',
            'showmisp',
            'mispappurl',
            'mispapikey',
        ];
        foreach ($stalesettings as $setting) {
            unset_config($setting, 'block_crucible');
        }

        // Strip stale app keys from users' saved app-order preferences.
        $staleappkeys = ['rocketchat', 'roundcube', 'misp'];
        $prefs = $DB->get_records('user_preferences', ['name' => 'block_crucible_app_order']);
        foreach ($prefs as $pref) {
            $order = json_decode($pref->value, true);
            if (!is_array($order)) {
                continue;
            }
            $filtered = array_values(array_diff($order, $staleappkeys));
            if (count($filtered) !== count($order)) {
                $DB->set_field('user_preferences', 'value', json_encode($filtered), ['id' => $pref->id]);
            }
        }

        upgrade_block_savepoint(true, 2026040801, 'crucible');
    }

    return true;
}
