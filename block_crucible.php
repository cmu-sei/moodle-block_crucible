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
 * Crucible block.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../../config.php');

class block_crucible extends block_base {

    /**
     * The root URL of the Moodle site.
     *
     * @var string
     */
    private $wwwroot;

    /**
     * Initialises the block.
     *
     * @return void
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_crucible');
    }

    /**
     * Determines if multiple instances of this block can be added to a single page.
     *
     * By default, this method returns false to enforce only one instance of the block per page.
     *
     * @return bool False to indicate that multiple instances are not allowed.
     */
    public function instance_allow_multiple() {
        return false;
    }

    public function hide_header() {
        $showtitle= get_config('block_crucible', 'blocktitle');
        if ($showtitle) {
            return TRUE;
        } else {
            return FALSE;
        }
    }

    /**
     * Indicates whether the block has a configuration page for site-wide settings.
     *
     * This method returns true to enable the use of a `settings.php` file for configuring
     * block settings at the site level.
     *
     * @return bool True to indicate that the block has a configuration page.
     */
    public function has_config() {
        return true;
    }

    /**
     * Defines in which pages this block can be added.
     *
     * @return array of the pages where the block can be added.
     */
    public function applicable_formats() {
        return [
            'admin' => false,
            'site-index' => true,
            'course-view' => false,
            'mod' => false,
            'my' => true,
        ];
    }

    /**
     * Gets the block contents.
     *
     * If we can avoid it better not check the server status here as connecting
     * to the server will slow down the whole page load.
     *
     * @return string The block HTML.
     */
    public function get_content() {
        global $OUTPUT;
        global $USER;
        global $SITE;
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        /* data for template */
        $data = new stdClass();
        $nodata = new stdClass();
        $data->sitename = $SITE->fullname;
        $data->shortname = $SITE->shortname;
        $data->username = $USER->firstname;
        $datafiltered = new stdClass();
        $enabledApp = get_config('block_crucible', 'enabled');
        if ($enabledApp) {
            $crucible = new \block_crucible\crucible();
        } else {
            debugging("Applications block plugin has not been enabled", DEBUG_DEVELOPER);
            $datafiltered->crucibleLogoAuth = $OUTPUT->image_url('crucible-icon', 'block_crucible');
            // Render the no_oauth template with $datafiltered
            $this->content->text = $OUTPUT->render_from_template('block_crucible/not_enabled', $datafiltered);    
            return false;
        }
        $crucible->setup_system();
        $userid = $USER->idnumber;
        $showapps = get_config('block_crucible', 'showallapps');
        $showcomms = get_config('block_crucible', 'enablecommapps');

        ////////////////////PLAYER/////////////////////////////
        $playerurl = get_config('block_crucible', 'playerappurl');

        $views = null;
        if ($playerurl) {
            $views = $crucible->get_player_views();
        }
        if ($views) {
            $data->player = $playerurl;
            $data->playerDescription = get_string('playerdescription', 'block_crucible');
            $data->playerLogo  = $OUTPUT->image_url('crucible-icon-player', 'block_crucible');
        } else if ($views == 0 && $views != null) {
            debugging("No views found on Player for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($views == null) {
            debugging("Player not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        $permsplayer = null;
        $showplayer = null;

        if ($playerurl) {
            $permsplayer = $crucible->get_player_permissions();
            $showplayer = get_config('block_crucible', 'showplayer');
        }
        if ($permsplayer || $showplayer) {
            $data->alloy = get_config('block_crucible', 'alloyappurl');
            $data->alloyDescription = get_string('alloydescription', 'block_crucible');
            $data->alloyLogo  = $OUTPUT->image_url('crucible-icon-alloy', 'block_crucible');
        } else if ($views == 0 && $views != null) {
            debugging("No permissions found on Player for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($views == null) {
            debugging("Player not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////BLUEPRINT/////////////////////////////
        $blueprinturl = get_config('block_crucible', 'blueprintappurl');
        $msels = null;
        $permsblueprint = null;
        $showblueprint = null;

        if ($blueprinturl) {
            $msels = $crucible->get_blueprint_msels();
            $permsblueprint = $crucible->get_blueprint_permissions();
            $showblueprint = get_config('block_crucible', 'showblueprint');
        }

        if (($msels && $showapps) || $permsblueprint || $showblueprint) {
            $data->blueprint = $blueprinturl;
            $data->blueprintDescription = get_string('blueprintdescription', 'block_crucible');
            $data->blueprintLogo = $OUTPUT->image_url('crucible-icon-blueprint', 'block_crucible');

            if ($permsblueprint && $showcomms) {
                $data->roundcube = get_config('block_crucible', 'roundcubeappurl');
                $data->roundcubeDescription = get_string('roundcubedescription', 'block_crucible');
                $data->roundcubeLogo = $OUTPUT->image_url('icon-roundcube', 'block_crucible');
            } else {
                debugging("Roundcube not enabled", DEBUG_DEVELOPER);
            }
        } else if ($permsblueprint == 0 && $permsblueprint != null) {
            debugging("No user data found on Blueprint for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($msels == 0 && $msels != null) {
            debugging("No MSELs found on Blueprint for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($permsblueprint == null && $msels == null) {
            debugging("Blueprint not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////CASTER/////////////////////////////
        $casterurl = get_config('block_crucible', 'casterappurl');
        $permscaster = null;
        $showcaster = null;

        if ($casterurl) {
            $permscaster = $crucible->get_caster_permissions();
            $showcaster = get_config('block_crucible', 'showcaster');
        }

        if ($permscaster || $showcaster) {
            $data->caster = $casterurl;
            $data->casterDescription = get_string('casterdescription', 'block_crucible');
            $data->casterLogo  = $OUTPUT->image_url('crucible-icon-caster', 'block_crucible');
        } else if ($permscaster == 0 && $permscaster != null) {
            debugging("No user data found on Caster for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($permscaster == null) {
            debugging("Caster not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////CITE/////////////////////////////
        $citeurl = get_config('block_crucible', 'citeappurl');
        $permscite = null;
        $evalscite = null;
        $showcite = null;

        if ($citeurl) {
            $permscite = $crucible->get_cite_permissions();
            $evalscite = $crucible->get_cite_evaluations();
            $showcite = get_config('block_crucible', 'showcite');
        }

        if (($evalscite && $showapps) || $permscite || $showcite) {
            $data->cite = $citeurl;
            $data->citeDescription = get_string('citedescription', 'block_crucible');
            $data->citeLogo  = $OUTPUT->image_url('crucible-icon-cite', 'block_crucible');
        } else if ($permscite == 0 && $permscite != null) {
            debugging("No user data found on CITE for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($evalscite = 0 && $evalscite != null) {
            debugging("No evaluations found on CITE for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($evalscite == null && $permscite == null) {
            debugging("CITE not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////GALLERY/////////////////////////////
        $galleryurl = get_config('block_crucible', 'galleryappurl');
        $permsgallery = null;
        $exhibitsgallery = null;
        $showgallery = null;

        if ($galleryurl) {
            $permsgallery = $crucible->get_gallery_permissions();
            $exhibitsgallery = $crucible->get_gallery_exhibits();
            $showgallery = get_config('block_crucible', 'showgallery');
        }

        if (($exhibitsgallery && $showapps) || $permsgallery || $showgallery) {
            $data->gallery = $galleryurl;
            $data->galleryDescription = get_string('gallerydescription', 'block_crucible');
            $data->galleryLogo  = $OUTPUT->image_url('crucible-icon-gallery', 'block_crucible');
        } else if ($permsgallery == 0 && $permsgallery != null) {
            debugging("No user data found on Gallery for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($exhibitsgallery = 0 && $exhibitsgallery != null) {
            debugging("No exhibits found on Gallery for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($permsgallery == null && $exhibitsgallery == null) {
            debugging("Gallery not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////STEAMFITTER/////////////////////////////
        $steamfitterurl = get_config('block_crucible', 'steamfitterappurl');
        $permssteam = null;
        $showsteamfitter = null;

        if ($steamfitterurl) {
            $permssteam = $crucible->get_steamfitter_permissions();
            $showsteamfitter = get_config('block_crucible', 'showsteamfitter');
        }

        if ($permssteam || $showsteamfitter) {
            $data->steamfitter = $steamfitterurl;
            $data->steamfitterDescription = get_string('steamfitterdescription', 'block_crucible');
            $data->steamfitterLogo  = $OUTPUT->image_url('crucible-icon-steamfitter', 'block_crucible');
        } else if ($permssteam == 0 && $permssteam != null) {
            debugging("No user data found on Steamfitter for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($permssteam == null) {
            debugging("Steamfitter not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////RocketChat/////////////////////////////
        if ($showcomms) {
            $rocketchaturl = get_config('block_crucible', 'rocketchatappurl');
            $rocketchat = null;

            if ($rocketchaturl) {
                $rocketchat = $crucible->get_rocketchat_user_info();
            }

            if ($rocketchat) {
                $rocketperms = $rocketchat->user->roles;

                if ($showapps) {
                    $data->rocket = $rocketchaturl;
                    $data->rocketDescription = get_string('rocketchatdescription', 'block_crucible');
                    $data->rocketLogo = $OUTPUT->image_url('icon-rocketchat', 'block_crucible');
                } else if (in_array("admin", $rocketperms)) {
                    $data->rocket = $rocketchaturl;
                    $data->rocketDescription = get_string('rocketchatdescription', 'block_crucible');
                    $data->rocketLogo = $OUTPUT->image_url('icon-rocketchat', 'block_crucible');
                }
            } else if ($rocketchat == -1) {
                debugging("Rocket.Chat is not configured", DEBUG_DEVELOPER);
            }
        } else if ($showcomms == 0) {
            debugging("Rocket.Chat not enabled", DEBUG_DEVELOPER);
        }

        ////////////////////TOPOMOJO////////////////////////////
        $topomojourl = get_config('block_crucible', 'topomojoappurl');
        $permstopomojo = null;
        $showtopomojo = null;

        if ($topomojourl) {
            $permstopomojo = $crucible->get_topomojo_permissions();
            $showtopomojo = get_config('block_crucible', 'showtopomojo');
        }

        if ($permstopomojo || $showtopomojo) {
            $data->topomojo = $topomojourl;
            $data->topomojoDescription = get_string('topomojodescription', 'block_crucible');
            $data->topomojoLogo  = $OUTPUT->image_url('topomojo-logo', 'block_crucible');
        } else if ($permstopomojo == 0 && $permstopomojo != null) {
            debugging("No user data found on Topomojo for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($permstopomojo == null) {
            debugging("Topomojo not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////GAMEBOARD/////////////////////////////
        $gameboardurl = get_config('block_crucible', 'gameboardappurl');
        $permsgameboard = null;
        $activechallenges = null;
        $showgameboard = null;

        if ($gameboardurl) {
            $permsgameboard = $crucible->get_gameboard_permissions();
            $activechallenges = $crucible->get_active_challenges();
            $showgameboard = get_config('block_crucible', 'showgameboard');
        }

        if (($activechallenges && $showapps) || $permsgameboard || $showgameboard) {
            $data->gameboard = $gameboardurl;
            $data->gameboardDescription = get_string('gameboarddescription', 'block_crucible');
            $data->gameboardLogo  = $OUTPUT->image_url('gameboard-icon', 'block_crucible');
        } else if ($permsgameboard == 0 && $permsgameboard != null) {
            debugging("No user data found on Gameboard for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($activechallenges = 0 && $activechallenges != null) {
            debugging("No active challenges found on Gameboard for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($permsgameboard == null && $activechallenges == null) {
            debugging("Gameboard not configured. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////Welcome Message/////////////////////////////
        $optionalmessagecb = get_config('block_crucible', 'customwelcomemessagecb');
        if ($optionalmessagecb) {
            $data->welcomemessage = get_config('block_crucible', 'customwelcomemessage');
        }

        ///////////////////Keycloak/////////////////////////////
        $allowedGroups = get_config('block_crucible', 'keycloakgroups');
        $groupsArray = explode('|', $allowedGroups);
        $groupsArray = array_map('trim', $groupsArray);

        $showkeycloak = get_config('block_crucible', 'showkeycloak');
        $userredirect = get_config('block_crucible', 'userredirect');

        $keycloakuserurl = get_config('block_crucible', 'keycloakuserurl');
        $keycloakadminurl = get_config('block_crucible', 'keycloakadminurl');

        if ($keycloakuserurl || $keycloakadminurl) {
            $keycloakGroups = $crucible->get_keycloak_groups();
            $keycloakRoles = $crucible->get_keycloak_roles();
        }
        
        if (!is_array($keycloakGroups)) {
            $keycloakGroups = $keycloakGroups ? [$keycloakGroups] : [];
        }

        // Check if the user has any allowed group in their Keycloak groups.
        $hasAllowedGroup = false;
        foreach ($keycloakGroups as $group) {
            if (in_array($group, $groupsArray)) {
                $hasAllowedGroup = true;
                break;
            }
        }

        if (!is_array($keycloakRoles)) {
            $keycloakRoles = $keycloakRoles ? [$keycloakRoles] : [];
        }

        // Check if the user has admin role.
        $keycloakAdmin = false;
        $keycloakAdmin = in_array('admin', $keycloakRoles);

        if ($showkeycloak) {
            if ($userredirect) {
                $data->keycloak = $keycloakuserurl;
                $data->keycloakDescription = get_string('keycloakdescription', 'block_crucible');
                $data->keycloakLogo  = $OUTPUT->image_url('keycloak-icon', 'block_crucible');
            }
            else if ($hasAllowedGroup || $keycloakAdmin) {
                $data->keycloak = $keycloakadminurl;
                $data->keycloakDescription = get_string('keycloakdescription', 'block_crucible');
                $data->keycloakLogo  = $OUTPUT->image_url('keycloak-icon', 'block_crucible');
            } else {
                $data->keycloak = $keycloakuserurl;
                $data->keycloakDescription = get_string('keycloakdescription', 'block_crucible');
                $data->keycloakLogo  = $OUTPUT->image_url('keycloak-icon', 'block_crucible');
            }
        } else {
            debugging("Keycloak not enabled. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////MISP/////////////////////////////
        $mispurl = get_config('block_crucible', 'mispappurl');
        $permsmisp = null;
        $usermisp = null;
        $showmisp = null;

        if ($mispurl) {
            $permsmisp = $crucible->get_misp_permissions();
            $usermisp = $crucible->get_misp_user();
            $showmisp = get_config('block_crucible', 'showmisp');
        }

        if (($usermisp && $showapps) || $permsmisp || $showmisp) {
            $data->misp = $mispurl;
            $data->mispDescription = get_string('mispdescription', 'block_crucible');
            $data->mispLogo  = $OUTPUT->image_url('misp-icon', 'block_crucible');
        } else if ($permsmisp == 0 && $permsmisp != null) {
            debugging("No user data found on MISP for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($usermisp = 0 && $usermisp != null) {
            debugging("No user data found on MISP for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($permsmisp == null && $usermisp == null) {
            debugging("MISP not enabled. Configure plugin settings to enable this application.", DEBUG_DEVELOPER);
        }

        ////////////////////DOCS/////////////////////////////
        $docsurl = get_config('block_crucible', 'docsappurl');

        if ($docsurl) {
            $data->docs = $docsurl;
            $data->docsDescription = get_string('docsdescription', 'block_crucible');
            $data->docsLogo  = $OUTPUT->image_url('docs-logo', 'block_crucible');
        }

        if (!empty($userid)) {
            foreach ($data as $key => $value) {
                if (!in_array($key, ['sitename', 'username', 'welcomemessage'])) {
                    $datafiltered->$key = $value;
                }
            }

            if (empty((array) $datafiltered)) {
                $this->content = null;
            } else {
                $datafiltered->crucibleLogoAuth = $OUTPUT->image_url('crucible-icon', 'block_crucible');
                $this->content->text = $OUTPUT->render_from_template('block_crucible/with_applications', $data); 
            }
             
        } else {
            $datafiltered->crucibleLogoAuth = $OUTPUT->image_url('crucible-icon', 'block_crucible');
            // Render the no_oauth template with $datafiltered
            $this->content->text = $OUTPUT->render_from_template('block_crucible/no_oauth', $datafiltered);
        }

        return $this->content;

    }
}
