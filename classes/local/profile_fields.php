<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Custom profile fields the Keycloak sync writes into.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\local;

/**
 * Creates and looks up the sso* custom profile fields.
 *
 * The plugin hardcodes these shortnames, so it has to own them: without the fields
 * both sync paths silently no-op. They are created on install and by an upgrade step
 * rather than by the deployment tooling so that every environment gets them.
 */
class profile_fields {
    /** @var string Shortname of the category the fields are created in. */
    const CATEGORY = 'Crucible SSO';

    /** @var string Organisation(s) the user belongs to, as a delimited list. */
    const ORG = 'ssoorg';

    /** @var string Keycloak groups the user belongs to, as a delimited list. */
    const GROUPS = 'ssogroups';

    /** @var string Moodle roles asserted by Keycloak. */
    const ROLE = 'ssorole';

    /** @var string Team the user belongs to. */
    const TEAM = 'ssoteam';

    /** @var string NICE/DCWF work role the user holds. */
    const WORKROLE = 'ssoworkrole';

    /**
     * Every field this plugin owns, in display order.
     *
     * @return array shortname => language string identifier for the field name
     */
    public static function all(): array {
        return [
            self::ORG => 'profilefield_ssoorg',
            self::GROUPS => 'profilefield_ssogroups',
            self::ROLE => 'profilefield_ssorole',
            self::TEAM => 'profilefield_ssoteam',
            self::WORKROLE => 'profilefield_ssoworkrole',
        ];
    }

    /**
     * Create the category and any of the fields that do not exist yet.
     *
     * Idempotent: existing fields are left exactly as the administrator configured
     * them, so re-running this never overwrites local customisation.
     *
     * @return int number of fields created
     */
    public static function install(): int {
        global $DB;

        $created = 0;
        $categoryid = null;
        $sortorder = null;

        foreach (self::all() as $shortname => $stringid) {
            if ($DB->record_exists('user_info_field', ['shortname' => $shortname])) {
                continue;
            }

            // Only touch the category once we know we actually have a field to add.
            if ($categoryid === null) {
                $categoryid = self::ensure_category();
                $sortorder = (int)$DB->get_field(
                    'user_info_field',
                    'COALESCE(MAX(sortorder), 0)',
                    ['categoryid' => $categoryid]
                );
            }

            $DB->insert_record('user_info_field', (object)[
                'shortname' => $shortname,
                'name' => get_string($stringid, 'block_crucible'),
                'datatype' => 'text',
                'description' => get_string($stringid . '_desc', 'block_crucible'),
                'descriptionformat' => FORMAT_HTML,
                'categoryid' => $categoryid,
                'sortorder' => ++$sortorder,
                'required' => 0,
                // Keycloak owns these values; a user editing them would just be
                // overwritten on the next sync, so lock them and keep them out of
                // the profile page for everyone but administrators.
                'locked' => 1,
                'visible' => 0,
                'forceunique' => 0,
                'signup' => 0,
                'defaultdata' => '',
                'defaultdataformat' => FORMAT_MOODLE,
                'param1' => 30,
                'param2' => 2048,
                'param3' => 0,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Get the id of the profile field category, creating it if needed.
     *
     * @return int
     */
    private static function ensure_category(): int {
        global $DB;

        $name = get_string('profilefieldcategory', 'block_crucible');
        $id = $DB->get_field('user_info_category', 'id', ['name' => $name]);
        if ($id) {
            return (int)$id;
        }

        $sortorder = (int)$DB->get_field_select('user_info_category', 'COALESCE(MAX(sortorder), 0)', '1=1');

        return (int)$DB->insert_record('user_info_category', (object)[
            'name' => $name,
            'sortorder' => $sortorder + 1,
        ]);
    }

    /**
     * Look up the id of one of our profile fields.
     *
     * @param string $shortname one of the class constants
     * @return int|null null when the field does not exist
     */
    public static function field_id(string $shortname): ?int {
        global $DB;

        $id = $DB->get_field('user_info_field', 'id', ['shortname' => $shortname]);

        return $id ? (int)$id : null;
    }
}
