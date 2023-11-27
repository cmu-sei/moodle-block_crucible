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

namespace block_crucible;

defined('MOODLE_INTERNAL') || die();

/**
 * Crucible block plugin
 *
 * @package        block_crucible
 * @copyright      2023 Carnegie Mellon Univeristy
 * @license        http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/*
Crucible Plugin for Moodle
Copyright 2020 Carnegie Mellon University.
NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS. CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO, WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL. CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Released under a GNU GPL 3.0-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.
[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution.  Please see Copyright notice for non-US Government use and distribution.
This Software includes and/or makes use of the following Third-Party Software subject to its own license:
1. Moodle (https://docs.moodle.org/dev/License) Copyright 1999 Martin Dougiamas.
DM20-0196
 */

class crucible {

    private $client;

    const NOTIFY_TYPE = \core\output\notification::NOTIFY_ERROR;

    function setup_system() {

        $issuerid = get_config('block_crucible', 'issuerid');
        if (!$issuerid) {
            debugging("Crucible does not have issuerid set", DEBUG_DEVELOPER);
            return;
        }
        $issuer = \core\oauth2\api::get_issuer($issuerid);

        try {
            $client = \core\oauth2\api::get_system_oauth_client($issuer);
        } catch (Exception $e) {
            debugging("get_system_oauth_client failed with $e->errorcode", DEBUG_NORMAL);
            $client = false;
        }
        if ($client === false) {
            debugging('Cannot connect as system account', DEBUG_NORMAL);
            $details = 'Cannot connect as system account';
            throw new \Exception($details);
            return false;
        }
	$this->client = $client;
    }


    function setup() {
        global $PAGE;
        $issuerid = get_config('crucible', 'issuerid');
        if (!$issuerid) {
            //print_error('no issuer set for plugin');
        }
        $issuer = \core\oauth2\api::get_issuer($issuerid);

        $wantsurl = $PAGE->url;
        $returnparams = ['wantsurl' => $wantsurl, 'sesskey' => sesskey(), 'id' => $issuerid];
        $returnurl = new \moodle_url('/auth/oauth2/login.php', $returnparams);

        $client = \core\oauth2\api::get_user_oauth_client($issuer, $returnurl);

        if ($client) {
            if (!$client->is_logged_in()) {
                debugging('not logged in', DEBUG_DEVELOPER);
                print_error('please re-authenticate your session');
            }
	}
	debugging("setup client", DEBUG_DEVELOPER);

        $this->client = $client;
    }

    //////////////////////PLAYER//////////////////////

    function get_player_views() {
        global $USER;
    
        if ($this->client == null) {
            \core\notification::add("Session not set up", self::NOTIFY_TYPE);
            return;
        }
    
        if (!$USER->idnumber) {
            \core\notification::add("User has no idnumber", self::NOTIFY_TYPE);
            return;
        }
    
        // Check if the URL is configured
        $url = get_config('block_crucible', 'playerapiurl');
        if (empty($url)) {
            return 0; 
        }
    
        // Web request
        $url .= "/users/" . $USER->idnumber . "/views";
    
        $response = $this->client->get($url);
    
        if ($this->client->info['http_code'] === 401) {
            \core\notification::add("Unauthorized access (401) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            \core\notification::add("Forbidden (403) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            \core\notification::add("Player Not Found (404) " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            \core\notification::add("Unable to Connect to Player Endpoint " . $url, self::NOTIFY_TYPE);
            return 0;
        }
    
        if (!$response) {
            \core\notification::add("No response received from Player endpoint.", self::NOTIFY_TYPE);
            return 0;
        }
    
        $r = json_decode($response);
        if (!$r) {
            \core\notification::add("No views found on Player.", self::NOTIFY_TYPE);
            return 0;
        }
    
        return $r;
    }

   //////////////////////BLUEPRINT//////////////////////
    function get_blueprint_msels() {
	global $USER;

        if ($this->client == null) {
            \core\notification::add("Session not set up", self::NOTIFY_TYPE);
            return;
        }
        if (!$USER->idnumber) {
            \core\notification::add("User has no idnumber", self::NOTIFY_TYPE);
            return;
        }

        // web request
        $url = get_config('block_crucible', 'blueprintapiurl');
        if (empty($url)) {
            return 0; 
        }

        //$url .= "/msels?UserId=" . $USER->idnumber;
        $url .= "/my-msels";
        //echo "GET $url<br>";

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            \core\notification::add("Unauthorized access (401) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            \core\notification::add("Forbidden (403) for " . $url, self::NOTIFY_TYPE);
            return 0;
	    } else if ($this->client->info['http_code'] === 404) {
            \core\notification::add("Blueprint Not Found (404) " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            \core\notification::add("Unable to Connect to Blueprint Endpoint " . $url, self::NOTIFY_TYPE);
            return 0;
        }

        if (!$response) {
            \core\notification::add("No response received from Blueprint endpoint.", self::NOTIFY_TYPE);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
    }

    function get_blueprint_permissions() {
        global $USER;

        if ($this->client == null) {
            \core\notification::add("Session not set up", self::NOTIFY_TYPE);
            return;
        }
        if (!$USER->idnumber) {
            print_error('user has no idnumber');
            return;
        }
        
        // web request
        $url = get_config('block_crucible', 'blueprintapiurl');
        if (empty($url)) {
            return 0; 
        }

        $url .= "/users/" . $USER->idnumber;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            \core\notification::add("Unauthorized access (401) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            \core\notification::add("Forbidden (403) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            \core\notification::add("Blueprint Not Found (404) " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            \core\notification::add("Unable to Connect to Blueprint Endpoint" . $url, self::NOTIFY_TYPE);
            return 0;
        }

        
        if (!$response) {
            \core\notification::add("No response received from endpoint.", self::NOTIFY_TYPE);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            \core\notification::add("No user data found on Blueprint.", self::NOTIFY_TYPE);
            return 0;
	    }
        if (empty($response->permissions)) {
            return 0;
        } else {
            return $r->permissions;
        }

	/* user exists but no special perms */
        return 0;
    }

    //////////////////////CITE//////////////////////
    function get_cite_permissions() {
        global $USER;

        if ($this->client == null) {
            \core\notification::add("Session not set up", self::NOTIFY_TYPE);
            return;
        }
        if (!$USER->idnumber) {
            \core\notification::add("User has no idnumber", self::NOTIFY_TYPE);
            return;
        }

        // web request
        $url = get_config('block_crucible', 'citeapiurl');
        if (empty($url)) {
            return 0; 
        }

        $url .= "/users/" . $USER->idnumber;
        //echo "GET $url<br>";

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            \core\notification::add("Unauthorized access (401) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            \core\notification::add("Forbidden (403) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
	       \core\notification::add("CITE Not Found (404) " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            \core\notification::add("Unable to Connect to CITE Endpoint " . $url, self::NOTIFY_TYPE);
            return 0;
        }

        if (!$response) {
            \core\notification::add("No response received from endpoint.", self::NOTIFY_TYPE);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            \core\notification::add("No user data found on CITE.", self::NOTIFY_TYPE);
            return 0;
	}
	if (count($r->permissions)) {
	    return $r->permissions;
	}

	/* user exists but no special perms */
        return 0;
    }

    //////////////////////GALLERY//////////////////////
    function get_gallery_permissions() {
        global $USER;

        if ($this->client == null) {
            \core\notification::add("Session not set up", self::NOTIFY_TYPE);
            return;
        }
        if (!$USER->idnumber) {
            \core\notification::add("User has no idnumber", self::NOTIFY_TYPE);
            return;
        }

        // web request
        $url = get_config('block_crucible', 'galleryapiurl');
        if (empty($url)) {
            return 0; 
        }

        $url .= "/users/" . $USER->idnumber;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            \core\notification::add("Unauthorized access (401) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            \core\notification::add("Forbidden (403) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            \core\notification::add("Gallery Not Found (404) " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            \core\notification::add("Unable to Connect to Gallery Endpoint " . $url, self::NOTIFY_TYPE);
            return 0;
        }

        if (!$response) {
            \core\notification::add("No response received from Gallery endpoint.", self::NOTIFY_TYPE);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            \core\notification::add("No user data found on Gallery.", self::NOTIFY_TYPE);
            return 0;
	}
	if (count($r->permissions)) {
	    return $r->permissions;
	}

	/* user exists but no special perms */
        return 0;
    }

    //////////////////////STEAMFITTER//////////////////////
    function get_steamfitter_permissions() {
        global $USER;

        if ($this->client == null) {
            \core\notification::add("Session not set up", self::NOTIFY_TYPE);
            return;
        }
        if (!$USER->idnumber) {
            \core\notification::add("User has no idnumber", self::NOTIFY_TYPE);
            return;
        }

        // web request
        $url = get_config('block_crucible', 'steamfitterapiurl');
        if (empty($url)) {
            return 0; 
        }

        $url .= "/users/" . $USER->idnumber;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            \core\notification::add("Unauthorized access (401) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            \core\notification::add("Forbidden (403) for " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            \core\notification::add("Steamfitter Not Found (404) " . $url, self::NOTIFY_TYPE);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            \core\notification::add("Unable to Connect to Steamfitter Endpoint " . $url, self::NOTIFY_TYPE);
            return 0;
        }

        if (!$response) {
            \core\notification::add("No response received from Steamfitter endpoint.", self::NOTIFY_TYPE);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            \core\notification::add("No user data found on Steamfitter.", self::NOTIFY_TYPE);
            return 0;
	}
	if (count($r->permissions)) {
	    return $r->permissions;
	}

	/* user exists but no special perms */
        return 0;
    }
}
