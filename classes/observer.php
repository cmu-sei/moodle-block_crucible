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

namespace block_crucible;

use block_crucible\local\org_roles;

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for block_crucible.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {
    /** @var int Seconds to wait before reconciling the same user again. */
    const THROTTLE = 300;

    /**
     * Triggered when a user logs in.
     *
     * Reconciles the user's managed role assignments against their sso* profile fields
     * using the same code the scheduled task runs, so the two cannot grant different
     * role sets. That includes removals: a user who has lost a Keycloak group loses the
     * role here rather than keeping it until the next hourly run.
     *
     * The profile fields themselves are not refreshed here - sync_keycloak_users owns
     * them, and having login write them too would reintroduce login-only staleness.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event) {
        global $CFG;

        if (!org_roles::is_enabled()) {
            return;
        }

        $userid = (int)$event->userid;

        // Throttle: several logins in quick succession should not each reconcile.
        $cachekey = 'org_role_sync_' . $userid;
        $cache = \cache::make('block_crucible', 'org_role_sync');
        $lastsync = $cache->get($cachekey);
        if ($lastsync && (time() - $lastsync) < self::THROTTLE) {
            return;
        }

        require_once($CFG->libdir . '/accesslib.php');

        try {
            org_roles::reconcile_user($userid);
            $cache->set($cachekey, time());
        } catch (\Exception $e) {
            debugging(
                'block_crucible: Failed to sync org roles for user ' . $userid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }
}
