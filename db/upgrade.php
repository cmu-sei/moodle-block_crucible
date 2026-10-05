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
            'rocketchatappurl',
            'rocketchatapiurl',
            'rocketchatauthtoken',
            'rocketchatuserid',
            'showroundcube',
            'roundcubeappurl',
            'showmisp',
            'mispappurl',
            'mispapikey',
            'docsappurl',
        ];
        foreach ($stalesettings as $setting) {
            unset_config($setting, 'block_crucible');
        }

        // Strip stale app keys from users' saved app-order preferences.
        $staleappkeys = ['rocketchat', 'roundcube', 'misp', 'docs'];
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

    if ($oldversion < 2026092300) {
        // The sso* profile field shortnames are hardcoded by both sync paths, so the
        // plugin has to create them - without them the syncs silently do nothing.
        \block_crucible\local\profile_fields::install();

        // ssoorg and ssogroups are matched element by element now, which needs the
        // values delimiter-wrapped (",a,b,"). Rewrite anything already stored, or the
        // cohort conditions would stop matching users provisioned before this release.
        $listfields = [
            \block_crucible\local\profile_fields::ORG,
            \block_crucible\local\profile_fields::GROUPS,
        ];
        // "Acme, Inc." and the two-element list "Acme,Inc." are indistinguishable once
        // split, and this rewrite cannot be undone. A comma followed by a space is never
        // produced by join_list(), so treat those values as a single name that happens to
        // contain a comma, leave them exactly as they are, and name them in the upgrade
        // output for an administrator to resolve by hand.
        $ambiguous = [];
        foreach ($listfields as $shortname) {
            $fieldid = \block_crucible\local\profile_fields::field_id($shortname);
            if (!$fieldid) {
                continue;
            }
            $rows = $DB->get_recordset('user_info_data', ['fieldid' => $fieldid], '', 'id, userid, data');
            foreach ($rows as $row) {
                if (strpos((string)$row->data, ', ') !== false) {
                    $ambiguous[] = $shortname . ' user ' . $row->userid . ': "' . $row->data . '"';
                    continue;
                }
                // The delimiter of the day, explicitly: it is a comma no longer, and this
                // step has to keep rewriting values into the form it rewrote them into when
                // it shipped, or a site upgrading across both releases gets a value that
                // neither step understands.
                $legacy = \block_crucible\local\org_roles::LEGACY_DELIM;
                $elements = \block_crucible\local\org_roles::split_list($row->data, $legacy);
                $canonical = \block_crucible\local\org_roles::join_list($elements, $legacy);
                if ($canonical !== $row->data) {
                    $DB->set_field('user_info_data', 'data', $canonical, ['id' => $row->id]);
                }
            }
            $rows->close();
        }

        if ($ambiguous) {
            mtrace('[crucible] ' . count($ambiguous) . ' profile value(s) contain ", " and were left'
                . ' unchanged, because splitting them would invent organizations that match nothing.'
                . ' Review them and re-save each as a single value or a comma separated list:');
            foreach ($ambiguous as $line) {
                mtrace('[crucible]   ' . $line);
            }
        }

        upgrade_block_savepoint(true, 2026092300, 'crucible');
    }

    if ($oldversion < 2026100300) {
        // One field cannot be both readable and matchable. ssoorg and ssogroups showed users
        // "|Acme|Globex Holdings|" on their own profile, and widening the delimiter to let the
        // value read plainly would have stopped an exact element match working. Split them:
        // the existing fields keep the readable value, and a new matching field beside each
        // carries the delimited form everything matches on.
        \block_crucible\local\profile_fields::install();

        $legacy = \block_crucible\local\org_roles::LEGACY_DELIM;
        $unstorable = [];
        foreach (\block_crucible\local\profile_fields::MATCHING as $display => $matching) {
            $displayid = \block_crucible\local\profile_fields::field_id($display);
            $matchingid = \block_crucible\local\profile_fields::field_id($matching);
            if (!$displayid || !$matchingid) {
                continue;
            }

            $rows = $DB->get_recordset('user_info_data', ['fieldid' => $displayid], '', 'id, userid, data');
            foreach ($rows as $row) {
                // Read under the old comma delimiter, including its rule that a comma
                // followed by a space is one name rather than a separator. Values that could
                // not be stored then - "Acme, Inc." - can be stored now, so this is also when
                // they stop being dropped.
                $elements = \block_crucible\local\org_roles::split_list($row->data, $legacy);
                $stored = \block_crucible\local\org_roles::join_list($elements);

                $existing = $DB->get_record('user_info_data', ['userid' => $row->userid, 'fieldid' => $matchingid]);
                if ($existing) {
                    if ($existing->data !== $stored) {
                        $DB->set_field('user_info_data', 'data', $stored, ['id' => $existing->id]);
                    }
                } else if ($stored !== '') {
                    $DB->insert_record('user_info_data', (object)[
                        'userid' => $row->userid,
                        'fieldid' => $matchingid,
                        'data' => $stored,
                        'dataformat' => 0,
                    ]);
                }

                // The readable value is the only copy of a name the new delimiter cannot
                // store, so leave it exactly as it is. join_display() reads back what was
                // stored, which for "Acme|Inc." is nothing at all, and writing that over a
                // real organization is the thing this release is careful not to do.
                $dropped = \block_crucible\local\org_roles::unstorable_values($elements);
                if ($dropped) {
                    foreach ($dropped as $value) {
                        $unstorable[] = $display . ' user ' . $row->userid . ': "' . $value . '"';
                    }
                    continue;
                }

                $readable = \block_crucible\local\org_roles::join_display($stored);
                if ($readable !== $row->data) {
                    $DB->set_field('user_info_data', 'data', $readable, ['id' => $row->id]);
                }
            }
            $rows->close();
        }

        if ($unstorable) {
            mtrace('[crucible] ' . count($unstorable) . ' profile value(s) contain the "'
                . \block_crucible\local\org_roles::DELIM . '" delimiter, so they cannot be matched on and'
                . ' grant no roles. They have been left readable rather than overwritten. Rename the'
                . ' organization in Keycloak, or add an alias, to resolve each:');
            foreach ($unstorable as $line) {
                mtrace('[crucible]   ' . $line);
            }
        }

        // Re-point the cohort conditions this plugin wrote at the matching fields. Waiting
        // for the next sync_org_roles run would do it, but the rules are processed in real
        // time, so for up to an hour every one of them would match nobody and the cohorts
        // would empty - taking any enrolment made through them with it.
        if ($dbman->table_exists('tool_dynamic_cohorts_c')) {
            $class = \block_crucible\task\sync_org_roles::CLASS_PROFILE;
            $repointed = 0;
            foreach ($DB->get_records('tool_dynamic_cohorts_c', ['classname' => $class]) as $condition) {
                $config = json_decode($condition->configdata, true);
                if (!is_array($config) || !isset($config['profilefield'])) {
                    continue;
                }
                $old = (string)$config['profilefield'];
                $shortname = preg_replace('/^profile_field_/', '', $old);
                if (!isset(\block_crucible\local\profile_fields::MATCHING[$shortname])) {
                    continue;
                }

                // Only conditions carrying a wrapped needle. An unwrapped one is not
                // necessarily hand-written - this plugin wrote the needle bare itself until
                // 2026092300, so on a site that has not run the sync task since upgrading to
                // that release every condition it owns is still in the bare form.
                //
                // Leaving those alone is the right answer either way. The needle is a
                // "contains" test, the readable field still holds the same names, so it keeps
                // matching exactly as well as it did before - which is to say loosely, since
                // that is what the bare form always was - and the next sync_org_roles run
                // rewrites the condition onto the matching field with a wrapped needle. The
                // alternative is to guess here whether a bare needle was meant as a whole
                // element, and a wrong guess empties a cohort.
                $value = (string)($config[$old . '_value'] ?? '');
                if (strlen($value) < 3 || $value[0] !== $legacy || substr($value, -1) !== $legacy) {
                    continue;
                }

                $new = 'profile_field_' . \block_crucible\local\profile_fields::MATCHING[$shortname];
                $config['profilefield'] = $new;
                $config[$new . '_operator'] = $config[$old . '_operator'] ?? null;
                $config[$new . '_value'] = \block_crucible\local\org_roles::list_needle(trim($value, $legacy));
                unset($config[$old . '_operator'], $config[$old . '_value']);

                $DB->set_field(
                    'tool_dynamic_cohorts_c',
                    'configdata',
                    json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    ['id' => $condition->id]
                );
                $repointed++;
            }

            if ($repointed) {
                \cache_helper::purge_by_event('ruleschanged');
                \cache_helper::purge_by_event('conditionschanged');
                mtrace("[crucible] re-pointed {$repointed} cohort condition(s) at the matching profile fields.");
            }
        }

        upgrade_block_savepoint(true, 2026100300, 'crucible');
    }

    return true;
}
