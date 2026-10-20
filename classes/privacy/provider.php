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
 * Privacy Subsystem implementation for block_crucible.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\writer;

/**
 * Privacy Subsystem for block_crucible.
 *
 * The block stores one user preference itself. Everything else it touches about a user is
 * either held by a core subsystem - the profile fields, roles and cohorts the Keycloak sync
 * writes - or sent to an external service to identify the user there.
 *
 * @copyright  Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\user_preference_provider {
    /** @var string The preference holding the user's application tile order. */
    const PREFERENCE_APP_ORDER = 'block_crucible_app_order';

    /** @var string[] The Crucible applications whose APIs are sent the user's ID number. */
    const CRUCIBLE_APPS = ['player', 'blueprint', 'cite', 'gallery', 'topomojo', 'gameboard'];

    /**
     * Describe the data this plugin stores and sends.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_user_preference(self::PREFERENCE_APP_ORDER, 'privacy:metadata:preference:apporder');

        $collection->add_external_location_link('keycloak', [
            'idnumber' => 'privacy:metadata:keycloak:idnumber',
            'email' => 'privacy:metadata:keycloak:email',
        ], 'privacy:metadata:keycloak');

        foreach (self::CRUCIBLE_APPS as $app) {
            $collection->add_external_location_link($app, [
                'idnumber' => 'privacy:metadata:crucibleapp:idnumber',
            ], 'privacy:metadata:' . $app);
        }

        $collection->add_subsystem_link('core_user', [], 'privacy:metadata:core_user');
        $collection->add_subsystem_link('core_role', [], 'privacy:metadata:core_role');
        $collection->add_subsystem_link('core_cohort', [], 'privacy:metadata:core_cohort');

        return $collection;
    }

    /**
     * Export the user's preferences for this plugin.
     *
     * @param int $userid
     */
    public static function export_user_preferences(int $userid) {
        $order = get_user_preferences(self::PREFERENCE_APP_ORDER, null, $userid);
        if ($order !== null) {
            writer::export_user_preference(
                'block_crucible',
                self::PREFERENCE_APP_ORDER,
                $order,
                get_string('privacy:metadata:preference:apporder', 'block_crucible')
            );
        }
    }
}
