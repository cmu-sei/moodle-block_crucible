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
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

	/* data for template */
        $data = new stdClass();

        $crucible = new \block_crucible\crucible();
        $crucible->setup_system();
	$views = $crucible->get_player_views();
	if ($views) {
	    echo "we got views!<br>";
	    $data->player = get_config('block_crucible', 'playerappurl');
        } else if ($views == 0) {
	    echo "no views in player<br>";
	} else if ($views == -1) {
	    echo "error from player<br>";
	}
/*
	$msels = $crucible->get_blueprint_msels();
	if ($msels) {
	    echo "we got msels!<br>";
	    $data->blueprint = get_config('block_crucible', 'blueprintappurl');
	} else if ($msels == 0) {
	    echo "no msels in blueprint<br>";
	} else if ($msels = -1) {
	    echo "error from blueprint<br>";
        }
 */
	$permsBlueprint = $crucible->get_blueprint_permissions();
    if ($permsBlueprint) {
        echo "we got permissions!<br>";
        $data->blueprint = get_config('block_crucible', 'blueprintappurl');
    } else if ($permsBlueprint == 0) {
        echo "no perms for user in blueprint<br>";
    } else if ($permsBlueprint = -1) {
        echo "error from blueprint<br>";
    }
    
    
    $permsCite = $crucible->get_cite_permissions();
    if ($permsCite) {
        echo "we got permissions!<br>";
        $data->cite = get_config('block_crucible', 'citeappurl');
    } else if ($permsCite == 0) {
        echo "no perms for user in cite<br>";
    } else if ($permsCite = -1) {
        echo "error from cite<br>";
    }
    
    $permsGallery = $crucible->get_gallery_permissions();
    if ($permsGallery) {
        echo "we got permissions!<br>";
        $data->gallery = get_config('block_crucible', 'galleryappurl');
    } else if ($permsCite == 0) {
        echo "no perms for user in gallery<br>";
    } else if ($permsCite = -1) {
        echo "error from gallery<br>";
    }

    $permsSteam = $crucible->get_steamfitter_permissions();
    if ($permsSteam) {
        echo "we got permissions!<br>";
        $data->steamfitter = get_config('block_crucible', 'steamfitterappurl');
    } else if ($permsSteam == 0) {
        echo "no perms for user in steamfitter<br>";
    } else if ($permsSteam = -1) {
        echo "error from steamfitter<br>";
    }

	$this->content->text = $OUTPUT->render_from_template('block_crucible/landing', $data);

        return $this->content;
    }
}
