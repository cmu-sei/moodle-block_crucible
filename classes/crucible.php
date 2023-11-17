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
 * @package     block_crucible
 * @copyright   2023 Carnegie Mellon Univeristy
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
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

function setup_system() {

    $issuerid = get_config('crucible', 'issuerid');
    if (!$issuerid) {
        debugging("crucible does not have issuerid set", DEBUG_DEVELOPER);
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
        //throw new \Exception($details);
        return false;
    }
    return $client;
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
            //print_error('please re-authenticate your session');
        }
    }

    return $client;
}

function get_player_views($client) {

    if ($client == null) {
        print_error('could not setup session');
    }

    // web request
    $url = get_config('crucible', 'playerapiurl') . "/me/views/";
    //echo "GET $url<br>";

    $response = $client->get($url);

    if ($client->info['http_code'] !== 200) {
        debugging('response code ' . $client->info['http_code'] . " for $url", DEBUG_DEVELOPER);
        print_error($client->info['http_code'] . " for $url " . $client->response['www-authenticate']);
    }

    if (!$response) {
        debugging('no response received by get_player_views', DEBUG_DEVELOPER);
    }
    //echo "response:<br><pre>$response</pre>";
    $r = json_decode($response);

    if (!$r) {
        debugging("could not find item by id", DEBUG_DEVELOPER);
        return;
    }

    return $r;
}
}
