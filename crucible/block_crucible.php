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

/**
 * Crucible block.
 *
 * @package    block_crucible
 * @copyright  Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/../../config.php');
require_login();

// Get the site name
/**
 * Global search block.
 *
 * @package    block_crucible
 * @copyright  Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
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
        $views = $crucible->get_player_views();
        if ($views) {
            $data->player = get_config('block_crucible', 'playerappurl');
            $data->playerDescription = get_string('playerdescription', 'block_crucible');
            $data->playerLogo  = $OUTPUT->image_url('crucible-icon-player', 'block_crucible');
        } else if ($views == 0) {
            debugging("No views found on Player for User: " . $userid, DEBUG_DEVELOPER);
        }

        ////////////////////PLAYER/////////////////////////////
        $permsplayer = $crucible->get_player_permissions();
        if ($permsplayer) {
            $data->alloy = get_config('block_crucible', 'alloyappurl');
            $data->alloyDescription = get_string('alloydescription', 'block_crucible');
            $data->alloyLogo  = $OUTPUT->image_url('crucible-icon-alloy', 'block_crucible');
        } else if ($views == 0) {
            debugging("No permissions found on Player for User: " . $userid, DEBUG_DEVELOPER);
        }
        ////////////////////BLUEPRINT/////////////////////////////
        $msels = $crucible->get_blueprint_msels();
        $permsblueprint = $crucible->get_blueprint_permissions();
        if (($msels && $showapps) || $permsblueprint) {
            $data->blueprint = get_config('block_crucible', 'blueprintappurl');
            $data->blueprintDescription = get_string('blueprintdescription', 'block_crucible');
            $data->blueprintLogo = $OUTPUT->image_url('crucible-icon-blueprint', 'block_crucible');

            if ($permsblueprint && $showcomms) {
                $data->roundcube = get_config('block_crucible', 'roundcubeappurl');
                $data->roundcubeDescription = get_string('roundcubedescription', 'block_crucible');
                $data->roundcubeLogo = $OUTPUT->image_url('icon-roundcube', 'block_crucible');
            } else {
                debugging("Roundcube not enabled", DEBUG_DEVELOPER);
            }
        } else if ($permsblueprint == 0) {
            debugging("No user data found on Blueprint for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($msels == 0) {
            debugging("No MSELs found on Blueprint for User: " . $userid, DEBUG_DEVELOPER);
        }

        ////////////////////CASTER/////////////////////////////
        $permscaster = $crucible->get_caster_permissions();
        if ($permscaster) {
            $data->caster = get_config('block_crucible', 'casterappurl');
            $data->casterDescription = get_string('casterdescription', 'block_crucible');
            $data->casterLogo  = $OUTPUT->image_url('crucible-icon-caster', 'block_crucible');
        } else if ($permscaster == 0) {
            debugging("No user data found on Caster for User: " . $userid, DEBUG_DEVELOPER);
        }

        ////////////////////CITE/////////////////////////////
        $permscite = $crucible->get_cite_permissions();
        $evalscite = $crucible->get_cite_evaluations();
        if (($evalscite && $showapps) || $permscite) {
            $data->cite = get_config('block_crucible', 'citeappurl');
            $data->citeDescription = get_string('citedescription', 'block_crucible');
            $data->citeLogo  = $OUTPUT->image_url('crucible-icon-cite', 'block_crucible');
        } else if ($permscite == 0) {
            debugging("No user data found on CITE for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($evalscite = 0) {
            debugging("No evaluations found on CITE for User: " . $userid, DEBUG_DEVELOPER);
        }

        ////////////////////GALLERY/////////////////////////////
        $permsgallery = $crucible->get_gallery_permissions();
        $exhibitsgallery = $crucible->get_gallery_exhibits();
        if (($exhibitsgallery && $showapps) || $permsgallery) {
            $data->gallery = get_config('block_crucible', 'galleryappurl');
            $data->galleryDescription = get_string('gallerydescription', 'block_crucible');
            $data->galleryLogo  = $OUTPUT->image_url('crucible-icon-gallery', 'block_crucible');
        } else if ($permsgallery == 0) {
            debugging("No user data found on Gallery for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($exhibitsgallery = 0) {
            debugging("No exhibits found on Gallery for User: " . $userid, DEBUG_DEVELOPER);
        }

        ////////////////////STEAMFITTER/////////////////////////////
        $permssteam = $crucible->get_steamfitter_permissions();
        if ($permssteam) {
            $data->steamfitter = get_config('block_crucible', 'steamfitterappurl');
            $data->steamfitterDescription = get_string('steamfitterdescription', 'block_crucible');
            $data->steamfitterLogo  = $OUTPUT->image_url('crucible-icon-steamfitter', 'block_crucible');
        } else if ($permssteam == 0) {
            debugging("No user data found on Steamfitter for User: " . $userid, DEBUG_DEVELOPER);
        }

        ////////////////////RocketChat/////////////////////////////
        if ($showcomms) {
            $rocketchat = $crucible->get_rocketchat_user_info();

            if ($rocketchat) {
                $rocketperms = $rocketchat->user->roles;

                if ($showapps) {
                    $data->rocket = get_config('block_crucible', 'rocketchatappurl');
                    $data->rocketDescription = get_string('rocketchatdescription', 'block_crucible');
                    $data->rocketLogo = $OUTPUT->image_url('icon-rocketchat', 'block_crucible');
                } else if (in_array("admin", $rocketperms)) {
                    $data->rocket = get_config('block_crucible', 'rocketchatappurl');
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
        $permstopomojo = $crucible->get_topomojo_permissions();
        if ($permstopomojo) {
            $data->topomojo = get_config('block_crucible', 'topomojoappurl');
            $data->topomojoDescription = get_string('topomojodescription', 'block_crucible');
            $data->topomojoLogo  = $OUTPUT->image_url('topomojo-logo', 'block_crucible');
        } else if ($permstopomojo == 0) {
            debugging("No user data found on Topomojo for User: " . $userid, DEBUG_DEVELOPER);
        }

        ////////////////////GAMEBOARD/////////////////////////////
        $permsgameboard = $crucible->get_gameboard_permissions();
        $activechallenges = $crucible->get_active_challenges();
        if (($activechallenges && $showapps) || $permsgameboard) {
            $data->gameboard = get_config('block_crucible', 'gameboardappurl');
            $data->gameboardDescription = get_string('gameboarddescription', 'block_crucible');
            $data->gameboardLogo  = $OUTPUT->image_url('gameboard-icon', 'block_crucible');
        } else if ($permsgameboard == 0) {
            debugging("No user data found on Gameboard for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($activechallenges = 0) {
            debugging("No active challenges found on Gameboard for User: " . $userid, DEBUG_DEVELOPER);
        }

        ////////////////////MISP/////////////////////////////
        $permsmisp = $crucible->get_misp_permissions();
        $usermisp = $crucible->get_misp_user();
        if (($usermisp && $showapps) || $permsmisp) {
            $data->misp = get_config('block_crucible', 'mispappurl');
            $data->mispDescription = get_string('mispdescription', 'block_crucible');
            $data->mispLogo  = $OUTPUT->image_url('misp-icon', 'block_crucible');
        } else if ($permsmisp == 0) {
            debugging("No user data found on MISP for User: " . $userid, DEBUG_DEVELOPER);
        } else if ($usermisp = 0) {
            debugging("No user data found on MISP for User: " . $userid, DEBUG_DEVELOPER);
        }
        if (!empty($userid)) {
            foreach ($data as $key => $value) {
                if ($key !== 'sitename' && $key !== 'username') {
                    $datafiltered->$key = $value;
                }
            }

            if (empty((array) $datafiltered)) {
                $data->crucibleLogo = $OUTPUT->image_url('crucible-icon', 'block_crucible');
            }
        
            // Render the landing_parent template with $data
            $this->content->text = $OUTPUT->render_from_template('block_crucible/landing_parent', $data);      
        } else {
            $datafiltered->crucibleLogoAuth = $OUTPUT->image_url('crucible-icon', 'block_crucible');
            // Render the no_oauth template with $datafiltered
            $this->content->text = $OUTPUT->render_from_template('block_crucible/no_oauth', $datafiltered);
        }

        return $this->content;
    }
}
