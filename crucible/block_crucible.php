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

/**
 * Global search block.
 *
 * @package    block_crucible
 * @copyright  Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_crucible extends block_base {

    /**
     * Initialises the block.
     *
     * @return void
     */

    private $wwwroot;

    public function init() {
        $this->title = get_string('pluginname', 'block_crucible');
    }

    /*
    function specialization() {
        if (isset($this->config->title)) {
            $this->title = format_string($this->config->title, true, ['context' => $this->context]);
        } else {
            $this->title = "no title set";
            //$this->title = get_string('newhtmlblock', 'block_html');
        }
    }
    */

    /* enforce only one block per page (this should be default) */
    public function instance_allow_multiple() {
        return false;
    }

    /* enable use of the settings.php file for site wide config */
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
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

        /* data for template */
        $data = new stdClass();
        $data ->username = $USER->firstname;
        $crucible = new \block_crucible\crucible();
        $crucible->setup_system();
        $userID = $USER->idnumber;
        $showapps = get_config('block_crucible', 'showallapps');
        $showComms = get_config('block_crucible', 'enablecommapps');

        ////////////////////PLAYER/////////////////////////////
        $views = $crucible->get_player_views();
        if ($views) {
            $data->player = get_config('block_crucible', 'playerappurl');
            $data->playerDescription = get_string('playerdescription', 'block_crucible');
            $data->playerLogo  = $OUTPUT->image_url('crucible-icon-player', 'block_crucible');
        } else if ($views == 0) {
            debugging("No views found on Player for User: " . $userID, DEBUG_DEVELOPER);
        }

        ////////////////////BLUEPRINT/////////////////////////////

        $msels = $crucible->get_blueprint_msels();
        $permsBlueprint = $crucible->get_blueprint_permissions();
        if (($msels && $showapps) || $permsBlueprint) {
            $data->blueprint = get_config('block_crucible', 'blueprintappurl');
            $data->blueprintDescription = get_string('blueprintdescription', 'block_crucible');
            $data->blueprintLogo = $OUTPUT->image_url('crucible-icon-blueprint', 'block_crucible');
        
            if ($permsBlueprint && $showComms) {
                $data->roundcube = get_config('block_crucible', 'roundcubeappurl');
                $data->roundcubeDescription = get_string('roundcubedescription', 'block_crucible');
                $data->roundcubeLogo = $OUTPUT->image_url('icon-roundcube', 'block_crucible');
            }
        } else if ($permsBlueprint == 0){
            debugging("No user data found on Blueprint for User: " . $userID, DEBUG_DEVELOPER);
        } else if ($msels == 0) {
            debugging("No MSELs found on Blueprint for User: " . $userID, DEBUG_DEVELOPER);
        }

        ////////////////////CITE/////////////////////////////
    
        $permsCite = $crucible->get_cite_permissions();
        $evalsCite = $crucible->get_cite_evaluations();
        if (($evalsCite && $showapps) || $permsCite) {
            $data->cite = get_config('block_crucible', 'citeappurl');
            $data->citeDescription = get_string('citedescription', 'block_crucible');
            $data->citeLogo  = $OUTPUT->image_url('crucible-icon-cite', 'block_crucible');
        } else if ($permsCite == 0) {
            debugging("No user data found on CITE for User: " . $userID, DEBUG_DEVELOPER);
        } else if ($evalsCite= 0) {
            debugging("No evaluations found on CITE for User: " . $userID, DEBUG_DEVELOPER);
        }
    
        ////////////////////GALLERY/////////////////////////////
        $permsGallery = $crucible->get_gallery_permissions();
        $exhibitsGallery = $crucible->get_gallery_exhibits();
        if (($exhibitsGallery && $showapps) || $permsGallery) {
            $data->gallery = get_config('block_crucible', 'galleryappurl');
            $data->galleryDescription = get_string('gallerydescription', 'block_crucible');
            $data->galleryLogo  = $OUTPUT->image_url('crucible-icon-gallery', 'block_crucible');
        } else if ($permsGallery == 0) {
            debugging("No user data found on Gallery for User: " . $userID, DEBUG_DEVELOPER);
        } else if ($exhibitsGallery = 0) {
            debugging("No exhibits found on Gallery for User: " . $userID, DEBUG_DEVELOPER);
        }

        ////////////////////STEAMFITTER/////////////////////////////
        $permsSteam = $crucible->get_steamfitter_permissions();
        if ($permsSteam) {
            $data->steamfitter = get_config('block_crucible', 'steamfitterappurl');
            $data->steamfitterDescription = get_string('steamfitterdescription', 'block_crucible');
            $data->steamfitterLogo  = $OUTPUT->image_url('crucible-icon-steamfitter', 'block_crucible');
        } else if ($permsSteam == 0) {
            debugging("No user data found on Steamfitter for User: " . $userID, DEBUG_DEVELOPER);
        }

        ////////////////////RocketChat/////////////////////////////
        $rocketchat = $crucible->get_rocketchat_user_info();
        if ($rocketchat && $showComms) {
            $data->rocket = get_config('block_crucible', 'rocketchatappurl');
            $data->rocketDescription = get_string('rocketchatdescription', 'block_crucible');
            $data->rocketLogo = $OUTPUT->image_url('icon-rocketchat', 'block_crucible');
        } else if ($rocketchat == 0)
        {
            debugging("User not found in Rocket.Chat", DEBUG_DEVELOPER);
        }
        

        $this->content->text = $OUTPUT->render_from_template('block_crucible/landing', $data);
        return $this->content;
    }
}
