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

use block_crucible\local\keycloak;
use block_crucible\local\org_roles;
use block_crucible\local\site_roles;

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
     * This user's organization and groups are read from Keycloak first, so that someone
     * logging in for the first time is not left without any organization data until the next
     * hourly run. It is one account's worth of request, it fails soft, and the throttle below
     * means repeated logins do not each make it - see \block_crucible\local\keycloak.
     *
     * Site-level roles are reconciled on every login, throttle or not, from the groups that
     * read returned rather than from any profile field: a removed administrator has to lose
     * the role at once. A read that fails leaves their site-level roles exactly as they are.
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event) {
        global $CFG, $DB;

        $orgenabled = org_roles::is_enabled();
        $siteactive = site_roles::is_active();
        if (!$orgenabled && !$siteactive) {
            return;
        }

        $userid = (int)$event->userid;
        require_once($CFG->libdir . '/accesslib.php');

        $state = null;
        $read = false;

        // Throttle: several logins in quick succession should not each reconcile.
        $cachekey = 'org_role_sync_' . $userid;
        $cache = \cache::make('block_crucible', 'org_role_sync');
        $lastsync = $cache->get($cachekey);
        if ($orgenabled && !($lastsync && (time() - $lastsync) < self::THROTTLE)) {
            // Separately, because the reconcile has to happen either way: it is the part that
            // takes a lost role back, and it reads what Moodle already holds.
            try {
                $read = true;
                \core\di::get(keycloak::class)->refresh_user($userid, $state);
            } catch (\Throwable $e) {
                $state = null;
                debugging(
                    'block_crucible: could not read user ' . $userid . ' from Keycloak: ' . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }

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

        if (!$siteactive) {
            return;
        }

        // Only a linked OAuth 2 account has anything in Keycloak to read. Anyone else holds no
        // grant the site map could make, and saying "no read" for every manual login is noise.
        $user = $DB->get_record('user', ['id' => $userid], 'id, auth, idnumber', IGNORE_MISSING);
        if (!$user || $user->auth !== org_roles::AUTH || trim((string)$user->idnumber) === '') {
            return;
        }

        try {
            if (!$read) {
                $state = \core\di::get(keycloak::class)->read_user($userid);
            }
            if (!is_array($state) || !isset($state['sitegroups']) || !is_array($state['sitegroups'])) {
                debugging(
                    'block_crucible: no Keycloak read for user ' . $userid . ' - site-level roles left unchanged.',
                    DEBUG_DEVELOPER
                );
                return;
            }
            // One account's groups cannot show that a mapped group has been renamed away - it
            // just looks like the user left it. The hourly run sees the whole realm, so while
            // its last check had a mapped group missing, a login grants but never revokes.
            site_roles::reconcile(
                [$userid => $state['sitegroups']],
                [$userid],
                null,
                (bool)site_roles::missing_groups()
            );
        } catch (\Throwable $e) {
            debugging(
                'block_crucible: Failed to sync site roles for user ' . $userid . ': ' . $e->getMessage(),
                DEBUG_DEVELOPER
            );
        }
    }
}
