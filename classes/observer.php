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

defined('MOODLE_INTERNAL') || die();

/**
 * Event observer for block_crucible.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Triggered when a user logs in.
     * Syncs the user's organization roles if they have ssoorg and ssogroups data,
     * and if a matching course category exists (must be created manually by admins).
     *
     * @param \core\event\user_loggedin $event
     */
    public static function user_loggedin(\core\event\user_loggedin $event) {
        global $DB, $CFG;

        // Check if org role sync is enabled.
        if (!get_config('block_crucible', 'enableorgrolesync')) {
            return;
        }

        // Only proceed if the user logged in via OAuth2.
        $userid = $event->userid;
        $user = $DB->get_record('user', ['id' => $userid], '*', IGNORE_MISSING);

        if (!$user || $user->auth !== 'oauth2') {
            return;
        }

        // Check if user has ssoorg and ssogroups profile data.
        require_once($CFG->dirroot . '/user/profile/lib.php');
        profile_load_data($user);

        $ssoorg = isset($user->profile_field_ssoorg) ? trim($user->profile_field_ssoorg) : '';
        $ssogroups = isset($user->profile_field_ssogroups) ? trim($user->profile_field_ssogroups) : '';

        if (empty($ssoorg) || empty($ssogroups)) {
            // User doesn't have required profile data, nothing to sync.
            return;
        }

        // Throttle: only sync if not synced in the last 5 minutes to avoid hammering on multiple logins.
        $cachekey = 'org_role_sync_' . $userid;
        $cache = \cache::make('block_crucible', 'org_role_sync');
        $lastsync = $cache->get($cachekey);

        if ($lastsync && (time() - $lastsync) < 300) {
            // Already synced within the last 5 minutes, skip.
            return;
        }

        // Trigger a targeted sync for this user's org only.
        try {
            self::sync_user_org_roles($user, $ssoorg, $ssogroups);
            $cache->set($cachekey, time());
        } catch (\Exception $e) {
            debugging('block_crucible: Failed to sync org roles for user ' . $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Sync organization roles for a specific user.
     * This is a lightweight version of the scheduled task that only processes one user's org.
     * Only syncs if a course category matching the org name already exists (must be created manually).
     *
     * @param object $user
     * @param string $ssoorg
     * @param string $ssogroups
     */
    private static function sync_user_org_roles($user, string $ssoorg, string $ssogroups) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/cohort/lib.php');
        require_once($CFG->libdir  . '/accesslib.php');

        // Check if the org category exists (must be created manually by admins).
        $categoryid = self::get_org_category($ssoorg);
        if (!$categoryid) {
            // Category doesn't exist yet - will be synced by scheduled task once created.
            return;
        }

        // Parse user's groups (comma-separated).
        $usergroups = array_filter(array_map('trim', explode(',', $ssogroups)));

        // Map of group names to role shortnames.
        $groupRoleMap = [
            'cyber-managers'        => 'cyber-manager',
            'lab-builders'          => 'lab-builder',
            'curriculum-developers' => 'curriculum-developer',
        ];

        foreach ($groupRoleMap as $group => $roleshort) {
            if (!in_array($group, $usergroups, true)) {
                continue;
            }

            // User has this group, ensure they have the role.
            $role = $DB->get_record('role', ['shortname' => $roleshort], 'id', IGNORE_MISSING);
            if (!$role) {
                continue;
            }

            $catctx = \context_coursecat::instance($categoryid);

            // Check if user already has this role.
            if (!$DB->record_exists('role_assignments', [
                'roleid'    => $role->id,
                'contextid' => $catctx->id,
                'userid'    => $user->id,
                'component' => 'block_crucible',
            ])) {
                // Assign the role.
                role_assign($role->id, $user->id, $catctx->id, 'block_crucible', 0);
            }
        }

        // Clear cache.
        if (class_exists('\\cache_helper')) {
            \cache_helper::purge_by_event('changesincapabilities');
        }
    }

    /**
     * Check if org category exists.
     * Returns the category id if it exists, or 0 if it doesn't.
     * Categories must be manually created by administrators.
     *
     * @param string $org
     * @return int Category ID or 0 if not found
     */
    private static function get_org_category(string $org): int {
        global $DB;

        // Look for exact match by name (case-sensitive)
        $existing = $DB->get_record('course_categories', ['name' => $org, 'parent' => 0], 'id', IGNORE_MISSING);
        if ($existing) {
            return (int)$existing->id;
        }

        // Also check by idnumber in case it was created with the expected idnumber pattern
        $idnumber = 'org-' . self::slugify($org);
        $cat = $DB->get_record('course_categories', ['idnumber' => $idnumber, 'parent' => 0], 'id', IGNORE_MISSING);
        if ($cat) {
            return (int)$cat->id;
        }

        // Category doesn't exist - admin needs to create it manually
        return 0;
    }

    /**
     * Convert string to slug.
     *
     * @param string $value
     * @return string
     */
    private static function slugify(string $value): string {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', trim($value)));
    }
}
