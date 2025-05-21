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
 * Crucible block plugin
 *
 * @package        block_crucible
 * @copyright      2024 Carnegie Mellon Univeristy
 * @license        http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible;

defined('MOODLE_INTERNAL') || die();
class crucible {

    /**
     * The client used for interacting with external services or APIs.
     *
     * @var object The client object, typically an instance of a class responsible for communication with external services.
     */
    private $client;

    /**
     * Sets up the system by configuring the OAuth client.
     *
     * This method retrieves the issuer ID from the configuration, attempts to obtain the issuer
     * object, and then retrieves the system OAuth client. It performs checks to ensure that
     * the issuer and client are valid and that the necessary user information is available.
     *
     * If the setup is successful, the client is stored in the class property `$client`.
     * Otherwise, the method returns false to indicate that setup failed.
     *
     * @return bool True if the setup is successful, false otherwise.
     */
    public function setup_system() {
        // Retrieve the issuer ID from the configuration
        $issuerid = get_config('block_crucible', 'issuerid');
        if (!$issuerid) {
            debugging("Crucible does not have issuerid set", DEBUG_DEVELOPER);
            return false; // Exit if issuer ID is not set
        }

        // Attempt to get the issuer object
        $issuer = \core\oauth2\api::get_issuer($issuerid);
        if (!$issuer) {
            debugging("Unable to retrieve issuer with the given issuerid", DEBUG_DEVELOPER);
            return false; // Exit if issuer is not found
        }

        try {
            $endpoints = \core\oauth2\api::get_endpoints($issuer);
        } catch (Exception $e) {
            debugging("get_endpoints failed with error: " . $e->getMessage(), DEBUG_NORMAL);
            return false; // Exit if an exception occurs
        }

        try {
            $field_mappings = \core\oauth2\api::get_user_field_mappings($issuer);
        } catch (Exception $e) {
            debugging("get_user_field_mappings failed with error: " . $e->getMessage(), DEBUG_NORMAL);
            return false; // Exit if an exception occurs
        }

        try {
            // Attempt to get the system OAuth client
            $client = \core\oauth2\api::get_system_oauth_client($issuer);
        } catch (Exception $e) {
            debugging("get_system_oauth_client failed with error: " . $e->getMessage(), DEBUG_NORMAL);
            return false; // Exit if an exception occurs
        }

        // Check if the client was successfully created
        if (!$client) { // Notice the use of !$client instead of $client === false
            debugging('Cannot connect as system account', DEBUG_NORMAL);
            return false; // Exit if the client is not valid
        }

        $url = $client->get_issuer()->get_endpoint_url('userinfo');
        $response = $client->get($url);
        $responsearray = json_decode($response, true);
        if (isset($responsearray['sub'])) {
            // Proceed with processing since 'sub' exists
            $userinfo = $client->get_userinfo();
        } else {
            // Handle the case where 'sub' doesn't exist or the response is invalid
            debugging("Error: 'sub' field is missing in the response or failed to connect.", DEBUG_NORMAL);
            return false;
        }

        // Check if 'idnumber' field is present in the user information
        if (!isset($userinfo['idnumber'])) {
            debugging('Identity provider does not have a mapping for idnumber', DEBUG_NORMAL);
            return false; // Exit if 'idnumber' is not found
        }

        // Set the client property if all checks pass
        $this->client = $client;

        return true; // Indicate successful setup
    }

    //////////////////////PLAYER//////////////////////
    /**
     * Retrieves the number of views for a specific user from the player API.
     *
     * This method sends a request to the configured player API endpoint to get the view count
     * for the user identified by their `idnumber`. It handles various HTTP response codes to
     * provide appropriate debugging information and returns the view count if successful
     *
     * If the client is not set up, the user ID is not available, or the URL is not configured,
     * or if there are HTTP errors or no response, the method returns 0.
     *
     * @return mixed The number of views as an integer if successful, or 0 in case of failure.
     */
    public function get_player_views() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up.", DEBUG_DEVELOPER);
            return;
        }

        if (!$userid) {
            debugging("User has no idnumber.", DEBUG_DEVELOPER);
            return;
        }

        // Check if the URL is configured
        $url = get_config('block_crucible', 'playerapiurl');
        if (empty($url)) {
            return 0;
        }

        // Web request
        $url .= "/users/" . $userid . "/views";

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Player Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . "is Unable to Connect to Player Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from Player endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
    }

    /**
     * Retrieves the permissions for a specific user from the player API.
     *
     * This method sends a request to the configured player API endpoint to get the permissions
     * for the user identified by their `idnumber`. It handles different HTTP response codes to
     * provide debugging information and returns permissions data if successful
     *
     * If the client is not set up, the user ID is not available, or the URL is not configured,
     * or if there are HTTP errors, the method returns 0.
     *
     * @return mixed The permissions data as an object if successful, or 0 in case of failure.
     */
    public function get_player_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber.", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'playerapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/users/" . $userid;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Blueprint Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . "is Unable to Connect to Blueprint Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);
        if (empty($r->permissions)) {
            return 0;
        } else {
            // Iterate through permissions array to find "SystemAdmin" key with value "true"
            foreach ($r->permissions as $permission) {
                if ($permission->key === "SystemAdmin") {
                    return $r->permissions;
                }
            }
            return 0;
        }

        return 0;
    }

   //////////////////////BLUEPRINT//////////////////////
    /**
     * Retrieves the MSELs (Modeling and Simulation Events List) for a specific user from the blueprint API.
     *
     * This method sends a request to the configured blueprint API endpoint to get the MSELs for the user
     * identified by their `idnumber`. It handles various HTTP response codes to provide appropriate debugging
     * information and returns MSEL data if successful.
     *
     * If the client is not set up, the user ID is not available, or the URL is not configured, or if there
     * are HTTP errors or no response, the method returns 0.
     *
     * @return mixed The MSEL data as an object if successful, or 0 in case of failure.
     */
    public function get_blueprint_msels() {

        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }

        if (!$userid) {
            debugging("User has no idnumber.", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'blueprintapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/users/" . $userid . "/msels";
        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) for User: ". $userid . " on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Blueprint Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . "is Unable to Connect to Blueprint Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from Blueprint endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
    }

    /**
     * Retrieves the permissions for a specific user from the blueprint API.
     *
     * This method sends a request to the configured blueprint API endpoint to get the permissions
     * for the user identified by their `idnumber`. It handles various HTTP response codes to
     * provide debugging information and returns the permissions data if available.
     *
     * If the client is not set up, the user ID is not available, or the URL is not configured, or if there
     * are HTTP errors, no response, or no permissions data, the method returns 0.
     *
     * @return mixed The permissions data if available as an object, or 0 in case of failure or if permissions are empty.
     */
    public function get_blueprint_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber.", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'blueprintapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/users/" . $userid;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Blueprint Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . "is Unable to Connect to Blueprint Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);

        if (empty($r->permissions)) {
            return 0;
        } else {
            return $r->permissions;
        }

        // User exists but no special perms
        return 0;
    }

    //////////////////////CASTER//////////////////////
    /**
     * Retrieves the permissions for a specific user from the caster API.
     *
     * This method sends a request to the configured caster API endpoint to get the permissions
     * for the user identified by their `idnumber`. It handles various HTTP response codes to
     * provide debugging information and returns the permissions data if available.
     *
     * If the client is not set up, the user ID is not available, or the URL is not configured, or if there
     * are HTTP errors, no response, or empty data, the method returns 0.
     *
     * @return mixed The permissions data if available as an object, or 0 in case of failure or if no data is found.
     */
    public function get_caster_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber.", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'casterapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/users/" . $userid . "/permissions";
        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Caster Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . "is Unable to Connect to Caster Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);

        if (empty($r)) {
            return 0;
        } else {
            return $r;
        }

        // User exists but no special perms
        return 0;
    }

    //////////////////////CITE//////////////////////
    /**
     * Retrieves the permissions for a specific user from the CITE API.
     *
     * This method sends a request to the configured CITE API endpoint to get the permissions
     * for the user identified by their `idnumber`. It handles various HTTP response codes to
     * provide debugging information and returns the permissions data if available.
     *
     * If the client is not set up, the user ID is not available, or the URL is not configured, or if there
     * are HTTP errors, no response, or empty data, the method returns 0.
     *
     * @return mixed The permissions data if available as an object, or 0 in case of failure or if no data is found.
     */
    public function get_cite_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'citeapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/users/" . $USER->idnumber;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("CITE Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . " is Unable to Connect to CITE Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);

        if (empty($r->permissions)) {
            return 0;
        } else {
            return $r->permissions;
        }

        // User exists but no special perms
        return 0;
    }

    /**
     * Retrieves the evaluations for a specific user from the CITE API.
     *
     * This method sends a request to the configured CITE API endpoint to get evaluations
     * for the user identified by their `idnumber`. It handles various HTTP response codes to
     * provide debugging information and returns the evaluations data if available.
     *
     * The URL is configured to fetch evaluations using the user ID as a query parameter.
     *
     * If the client is not set up, the user ID is not available, or if the URL is not configured, or if there
     * are HTTP errors, no response, or if the response is not valid JSON, the method returns 0.
     *
     * @return mixed The evaluations data if available as an object, or 0 in case of failure or if no data is found.
     */
    public function get_cite_evaluations() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }

        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'citeapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/evaluations?userid=" . $userid;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("CITE Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . " is Unable to Connect to CITE Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from CITE endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
    }

    //////////////////////GALLERY//////////////////////
    /**
     * Retrieves the permissions for a specific user from the Gallery API.
     *
     * This method sends a request to the configured Gallery API endpoint to get permissions
     * for the user identified by their `idnumber`. It handles various HTTP response codes to
     * provide debugging information and returns the permissions data if available.
     *
     * The URL is configured to fetch permissions using the user ID as a path parameter.
     *
     * If the client is not set up, the user ID is not available, or if the URL is not configured, or if there
     * are HTTP errors, no response, or if the response is not valid JSON, the method returns 0.
     *
     * @return mixed The permissions data if available as an object, or 0 in case of failure or if no data is found.
     */
    public function get_gallery_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'galleryapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/users/" . $userid;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Gallery Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . " is Unable to Connect to Gallery Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from Gallery endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);

        if (empty($r->permissions)) {
            return 0;
        } else {
            return $r->permissions;
        }

        // User exists but no special perms
        return 0;
    }

    /**
     * Retrieves the exhibits for a specific user from the Gallery API.
     *
     * This method sends a request to the configured Gallery API endpoint to get exhibits
     * for the user identified by their `idnumber`. It handles various HTTP response codes
     * to provide debugging information and returns the exhibits data if available.
     *
     * The URL is configured to fetch exhibits using the user ID as a path parameter.
     *
     * If the client is not set up, the user ID is not available, or if the URL is not configured, or if there
     * are HTTP errors, no response, or if the response is not valid JSON, the method returns 0.
     *
     * @return mixed The exhibits data if available as an object, or 0 in case of failure or if no data is found.
     */
    public function get_gallery_exhibits() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'galleryapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/users/" . $userid . '/exhibits';
        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Gallery Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . "is Unable to Connect to Gallery Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from Gallery endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
    }

    //////////////////////Rocket.Chat//////////////////////
    /**
     * Retrieves user information from Rocket.Chat based on the current user's username.
     *
     * This method sends a request to the Rocket.Chat API to get information about the user
     * identified by their username. It uses authentication headers configured in the system
     * to make the API request and returns the user information if successful.
     *
     * The function requires the Rocket.Chat API URL, an authentication token, and an admin user ID,
     * all of which are configured in the system. It performs error handling for various scenarios
     * including network errors, API errors, and invalid responses.
     *
     * @return mixed The user information as an object if the request is successful and valid,
     *               `false` if the request fails due to network issues, or `0` if the user exists but
     *               no special permissions are found or if an API error occurs.
     */
    public function get_rocketchat_user_info() {
        global $USER;
        $userid = $USER->idnumber;

        $username = $USER->username;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$username) {
            debugging("User has no username", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'rocketchatapiurl');
        $authtoken = get_config('block_crucible', 'rocketchatauthtoken');
        $adminuserid = get_config('block_crucible', 'rocketchatuserid');

        if (empty($url) || empty($authtoken) || empty($adminuserid)) {
            return -1;
        }

        $url .= "/users.info?username=" . $username;

        $headers = [
            'X-Auth-Token: ' . $authtoken,
            'X-User-Id: ' . $adminuserid,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            debugging('Rocket Chat API request failed: ' . curl_error($ch), DEBUG_DEVELOPER);
            return false;
        }

        curl_close($ch);

        $r = json_decode($response);

        if ($r->success === false) {
            debugging($r->error, DEBUG_DEVELOPER);
        } else if (property_exists($r, 'status') && $r->status === "error") {
            debugging($r->message, DEBUG_DEVELOPER);
        } else {
            return $r;
        }

        // User exists but no special perms
        return 0;
    }

    //////////////////////STEAMFITTER//////////////////////
    /**
     * Retrieves user permissions from the Steamfitter service based on the current user's ID number.
     *
     * This method sends a request to the Steamfitter API to get permissions associated with the
     * user identified by their ID number. It uses the configured API URL to make the request and
     * returns the user's permissions if the request is successful.
     *
     * The function performs various checks including whether the session is set up, the user ID
     * is available, and handles different HTTP response codes such as unauthorized access, forbidden
     * access, and not found errors. It also handles the case where no response is received or the
     * response is empty.
     *
     * @return mixed The user's permissions if the request is successful and valid,
     *               `0` if the request fails due to network issues, HTTP errors, or if no permissions are found.
     */
    public function get_steamfitter_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'steamfitterapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/users/" . $userid;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Steamfitter Not Found (404) " . $url, DEBUG_DEVELOPER);
            return 0;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("User: " . $userid . "is unable to Connect to Steamfitter Endpoint " . $url, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$response) {
            debugging("No response received from Steamfitter endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);

        if (empty($r->permissions)) {
            return 0;
        } else {
            return $r->permissions;
        }

        // User exists but no special perms
        return 0;
    }

    //////////////////////TopoMojo//////////////////////
    /**
     * Retrieves user permissions from the Topomojo service based on the current user's ID number.
     *
     * This method sends a request to the Topomojo API to get permissions associated with the
     * user identified by their ID number. The API request is made using either an API key or
     * a default client, depending on the configuration
     *
     * The function performs various checks including whether the session is set up, the user ID
     * is available, and handles different HTTP response codes such as unauthorized access, forbidden
     * access, and not found errors. It also handles the case where no response is received or the
     * response does not contain relevant permission information.
     *
     * @return mixed The user's permissions if the request is successful and valid,
     *               `0` if the request fails due to network issues, HTTP errors, or if no permissions are found.
     */
    public function get_topomojo_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'topomojoapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/user/" . $userid;
        $apikey = get_config('block_crucible', 'topomojoapikey');

        if ($apikey != null) {
            $headers = [
                'x-api-key: ' . $apikey,
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                debugging('Topomojo API request failed: ' . curl_error($ch), DEBUG_DEVELOPER);
                return false;
            }

            curl_close($ch);
        } else {
            $response = $this->client->get($url);

            if ($this->client->info['http_code'] === 401) {
                debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] === 403) {
                debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] === 404) {
                debugging("Topomojo Not Found (404) " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] !== 200) {
                debugging("User: " . $userid . "is unable to Connect to Topomojo Endpoint " . $url, DEBUG_DEVELOPER);
                return 0;
            }
        }

        if (!$response) {
            debugging("No response received from Topomojo endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);

        if (isset($r->message) && strpos($r->message, "ResourceNotFound") !== false) {
            debugging("Topomojo exception: " . $r->message, DEBUG_DEVELOPER);
            return 0;
        }

        if ($r->isAdmin || $r->isObserver || $r->isCreator || $r->isBuilder) {
            return $r;
        }
        return 0;

    }
    //////////////////////Gameboard//////////////////////
    /**
     * Retrieves user permissions from the Gameboard service based on the current user's ID number.
     *
     * This method sends a request to the Gameboard API to obtain permissions associated with the
     * user identified by their ID number. The API request is made using either an API key or
     * a default client, depending on the configuration.
     *
     * The function performs various checks including whether the session is set up, the user ID
     * is available, and handles different HTTP response codes such as unauthorized access, forbidden
     * access, and not found errors. It also handles cases where no response is received or the
     * response does not contain relevant permission information.
     *
     * @return mixed The user's permissions if the request is successful and valid,
     *               `0` if the request fails due to network issues, HTTP errors, or if no permissions are found.
     */
    public function get_gameboard_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'gameboardapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/user/" . $userid;
        $apikey = get_config('block_crucible', 'gameboardapikey');

        if ($apikey != null) {
            $headers = [
                'x-api-key: ' . $apikey,
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                debugging('Gameboard API request failed: ' . curl_error($ch), DEBUG_DEVELOPER);
                return false;
            }

            curl_close($ch);
        } else {
            $response = $this->client->get($url);

            if ($this->client->info['http_code'] === 401) {
                debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] === 403) {
                debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] === 404) {
                debugging("Gameboard Not Found (404) " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] !== 200) {
                debugging("User: " . $userid . "is unable to Connect to Gameboard Endpoint " . $url, DEBUG_DEVELOPER);
                return 0;
            }
        }

        if (!$response) {
            debugging("No response received from Gamebaord endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);
        
        if (isset($r->message) && strpos($r->message, "Couldn't find resource") !== false) {
            debugging("Gameboard validation exception: " . $r->message, DEBUG_DEVELOPER);
            return 0;
        }

        if (
            (isset($r->isAdmin) && $r->isAdmin) ||
            (isset($r->isDirector) && $r->isDirector) ||
            (isset($r->isDesigner) && $r->isDesigner) ||
            (isset($r->isObserver) && $r->isObserver) ||
            (isset($r->isTester) && $r->isTester) ||
            (isset($r->isSupport) && $r->isSupport) ||
            (isset($r->isRegistrar) && $r->isRegistrar)
        ) {
            return $r;
        }        
        return 0;

    }

    /**
     * Retrieves active challenges for the current user from the Gameboard service.
     *
     * This method sends a request to the Gameboard API to obtain the list of active challenges
     * associated with the user identified by their ID number. The API request is made using either
     * an API key or a default client, depending on the configuration.
     *
     * The function performs various checks including whether the session is set up, the user ID
     * is available, and handles different HTTP response codes such as unauthorized access, forbidden
     * access, and not found errors. It also handles cases where no response is received or the
     * response is not valid JSON.
     *
     * @return mixed The list of active challenges if the request is successful and the response
     *               is valid, `0` if the request fails due to network issues, HTTP errors, or if no
     *               challenges are found or the response is invalid.
     */
    public function get_active_challenges() {
        global $USER;
        $userid = $USER->idnumber;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'gameboardapiurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/user/" . $userid . "/challenges/active";
        $apikey = get_config('block_crucible', 'gameboardapikey');

        if ($apikey != null) {
            $headers = [
                'x-api-key: ' . $apikey,
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

            $response = curl_exec($ch);

            if (curl_errno($ch)) {
                debugging('Gameboard API request failed: ' . curl_error($ch), DEBUG_DEVELOPER);
                return false;
            }

            curl_close($ch);
        } else {
            $response = $this->client->get($url);

            if ($this->client->info['http_code'] === 401) {
                debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] === 403) {
                debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] === 404) {
                debugging("Gameboard Not Found (404) " . $url, DEBUG_DEVELOPER);
                return 0;
            } else if ($this->client->info['http_code'] !== 200) {
                debugging("User: " . $userid . "is unable to Connect to Gameboard Endpoint " . $url, DEBUG_DEVELOPER);
                return 0;
            }
        }

        if (!$response) {
            debugging("No response received from Gamebaord endpoint.", DEBUG_DEVELOPER);
            return 0;
        }

        $r = json_decode($response);
        if (isset($r->message) && strpos($r->message, 'GAMEBOARD VALIDATION EXCEPTION') !== false) {
            debugging("Gameboard validation exception: " . $r->message, DEBUG_DEVELOPER);
            return 0;
        }

        if (!$r) {
            return 0;
        }
        return $r;
    }

    /**
     * Retrieves permissions for the current user from the MISP (Malware Information Sharing Platform) service.
     *
     * This method queries the MISP API to obtain user information, specifically checking if the
     * current user (identified by their email) has an admin role. The API request uses an API key
     * for authentication and includes appropriate headers for JSON content.
     *
     * The function performs various checks including whether the session is set up and if the user
     * email is available. It handles the HTTP response and parses the JSON data to identify the
     * user's role. If the user is found and has the 'admin' role, their information is returned.
     * If the user is not found or has a different role, appropriate debugging messages are logged
     * and `0` is returned.
     *
     * @return array|int The user information with admin role if found; otherwise, returns `0` if
     *                   the user is not found, does not have an admin role, or if any issues
     *                   occur during the request or response parsing.
     */
    public function get_misp_permissions() {
        global $USER;
        $email = $USER->email;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$email) {
            debugging("User has no email", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'mispappurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/admin/users";
        $apikey = get_config('block_crucible', 'mispapikey');

        $headers = [
            'Authorization: ' . $apikey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);

        $users = json_decode($response, true);
        $userfound = false;

        if (is_array($users) && !empty($users)) {
            foreach ($users as $user) {
                if (isset($user['User']['email']) && $user['User']['email'] === $email) {
                    $userfound = true;
                    if (isset($user['Role']['name']) && $user['Role']['name'] === 'admin') {
                        return $user;
                    }
                }
            }
        }

        if (!$userfound) {
            debugging("User with email {$email} not found.", DEBUG_DEVELOPER);
            return 0;
        }
        return 0;
    }

    /**
     * Retrieves the current user's information from the MISP (Malware Information Sharing Platform) service.
     *
     * This method queries the MISP API to obtain user details based on the current user's email address.
     * The API request uses an API key for authentication and includes headers for JSON content.
     *
     * The function performs checks to ensure the session is set up and that the user's email is available.
     * It then makes a request to the MISP API to retrieve a list of users, parses the JSON response, and
     * searches for the user whose email matches the current user's email. If found, it returns the user's
     * information. If the user is not found, appropriate debugging messages are logged and `0` is returned.
     *
     * @return array|int The user information if found; otherwise, returns `0` if the user is not found
     *                   or if any issues occur during the request or response parsing.
     */
    public function get_misp_user() {
        global $USER;
        $email = $USER->email;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }
        if (!$email) {
            debugging("User has no email", DEBUG_DEVELOPER);
            return;
        }

        // Web request
        $url = get_config('block_crucible', 'mispappurl');
        if (empty($url)) {
            return 0;
        }

        $url .= "/admin/users";
        $apikey = get_config('block_crucible', 'mispapikey');

        $headers = [
            'Authorization: ' . $apikey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        $response = curl_exec($ch);

        $users = json_decode($response, true);
        $userfound = false;

        if (is_array($users) && !empty($users)) {
            foreach ($users as $user) {
                if (isset($user['User']['email']) && $user['User']['email'] === $email) {
                    $userfound = true;
                    return $user;
                }
            }
        }

        if (!$userfound) {
            debugging("User with email {$email} not found.", DEBUG_DEVELOPER);
            return 0;
        }
        return 0;
    }

    public function get_keycloak_groups() {
        global $USER;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }

        //Web request
        $url = get_config('block_crucible', 'keycloakadminurl');
        if (empty($url)) {
            return 0;
        }

        // Convert /admin/{realm}/console → /admin/realms/{realm}
        $url = preg_replace('#/admin/([^/]+)/console$#', '/realms/$1', rtrim($url, '/'));

        $url .= "/protocol/openid-connect/token";

        $issuerid = get_config('block_crucible', 'issuerid');
        if (!$issuerid) {
            debugging("Crucible does not have issuerid set", DEBUG_DEVELOPER);
            return false; // Exit if issuer ID is not set
        }

        $issuer = \core\oauth2\api::get_issuer($issuerid);
        $clientid = $issuer->get('clientid');
        $clientsecret = $issuer->get('clientsecret');

        // Prepare the POST data as a URL-encoded string.
        $data = "client_id=" . urlencode($clientid) . "&client_secret=" . urlencode($clientsecret) . "&grant_type=client_credentials";
        // Set headers.
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        // Initialize cURL.
        $ch = curl_init();

        // Set cURL options to replicate the exact `curl` command structure.
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Execute the request and capture the response.
        $response = curl_exec($ch);

        // Close the cURL session.
        curl_close($ch);

        $tokenData = json_decode($response, true);

        if (isset($tokenData['access_token'])) {
            $accessToken = $tokenData['access_token'];
        } else {
            debugging("Failed to obtain access token from Keycloak.", DEBUG_DEVELOPER);
            return false; // Exit early if token not set
        }

        // Initialize cURL.
        $ch = curl_init();

        // Set the headers with the Authorization token.
        $headers = [
            "Authorization: Bearer $accessToken",
        ];

        $realmUrl = get_config('block_crucible', 'keycloakadminurl');
        if (empty($realmUrl)) {
            return 0;
        }

        $realmUrl = preg_replace('#/admin/([^/]+)/console$#', '/admin/realms/$1', rtrim($realmUrl, '/'));

        $email = $USER->email;

        // Build user search URL
        $userSearchUrl = $realmUrl . '/users?email=' . urlencode($email);

        // Prepare request
        curl_setopt($ch, CURLOPT_URL, $userSearchUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $response = curl_exec($ch);
        curl_close($ch);

        $userlist = json_decode($response, true);

        // Defensive check
        if (!is_array($userlist) || empty($userlist)) {
            debugging("No users found in Keycloak matching email: $email", DEBUG_DEVELOPER);
            return 0;
        }

        // Get the Keycloak UUID
        $keycloakUserid = $userlist[0]['id'];

        $groupUrl = $realmUrl . '/users/' . urlencode($keycloakUserid) . '/groups';

        // Set cURL options for a GET request.
        curl_setopt($ch, CURLOPT_URL, $groupUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        // Execute the request and capture the response.
        $response = curl_exec($ch);

        curl_close($ch);

        // Check for errors and close the session.
        if (curl_errno($ch)) {
            echo 'cURL error: ' . curl_error($ch);
        } else {
            // Decode the JSON response to an associative array.
            $groups = json_decode($response, true);

            // Check if decoding was successful and if there are groups in the response.
            if (is_array($groups) && !empty($groups)) {
                // Initialize an array to store group names.
                $groupNames = [];
            
                // Loop through each group and collect the 'name' value.
                foreach ($groups as $group) {
                    if (isset($group['name'])) {
                        $groupNames[] = $group['name'];
                    }
                }
            
                // Output or return the array of group names.
                return $groupNames;
            } else {
                debugging("No groups found or invalid response format.", DEBUG_DEVELOPER);
            }
        }

        return 0;
    }

    public function get_keycloak_roles() {
        global $USER;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return;
        }

        //Web request
        $url = get_config('block_crucible', 'keycloakadminurl');
        if (empty($url)) {
            return 0;
        }

        $url = preg_replace('#/admin/([^/]+)/console$#', '/realms/$1', rtrim($url, '/'));

        $url .= "/protocol/openid-connect/token";

        $issuerid = get_config('block_crucible', 'issuerid');
        if (!$issuerid) {
            debugging("Crucible does not have issuerid set", DEBUG_DEVELOPER);
            return false; // Exit if issuer ID is not set
        }

        $issuer = \core\oauth2\api::get_issuer($issuerid);
        $clientid = $issuer->get('clientid');
        $clientsecret = $issuer->get('clientsecret');

        // Prepare the POST data as a URL-encoded string.
        $data = "client_id=" . urlencode($clientid) . "&client_secret=" . urlencode($clientsecret) . "&grant_type=client_credentials";

        // Set headers.
        $headers = ['Content-Type: application/x-www-form-urlencoded'];

        // Initialize cURL.
        $ch = curl_init();

        // Set cURL options to replicate the exact `curl` command structure.
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Execute the request and capture the response.
        $response = curl_exec($ch);

        // Close the cURL session.
        curl_close($ch);

        $tokenData = json_decode($response, true);

        if (isset($tokenData['access_token'])) {
            $accessToken = $tokenData['access_token'];
        }

        // Initialize cURL.
        $ch = curl_init();

        $realmUrl = get_config('block_crucible', 'keycloakadminurl');
        if (empty($realmUrl)) {
            return 0;
        }

        $realmUrl = preg_replace('#/admin/([^/]+)/console$#', '/admin/realms/$1', rtrim($realmUrl, '/'));

        // Set the headers with the Authorization token.
        $headers = [
            "Authorization: Bearer $accessToken",
        ];

        $email = $USER->email;

        // Build user search URL
        $userSearchUrl = $realmUrl . '/users?email=' . urlencode($email);

        // Prepare request
        curl_setopt($ch, CURLOPT_URL, $userSearchUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        $response = curl_exec($ch);
        curl_close($ch);

        $userlist = json_decode($response, true);

        // Defensive check
        if (!is_array($userlist) || empty($userlist)) {
            debugging("No users found in Keycloak matching email: $email", DEBUG_DEVELOPER);
            return 0;
        }

        // Get the Keycloak UUID
        $keycloakUserid = $userlist[0]['id'];

        $roleUrl = $realmUrl . '/users/' . urlencode($keycloakUserid) . '/role-mappings/realm';

        // Set cURL options for a GET request.
        curl_setopt($ch, CURLOPT_URL, $roleUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

        // Execute the request and capture the response.
        $response = curl_exec($ch);
        curl_close($ch);

        // Check for errors and close the session.
        if (curl_errno($ch)) {
            echo 'cURL error: ' . curl_error($ch);
        } else {
            // Decode the JSON response to an associative array.
            $roles = json_decode($response, true);

            // Check if decoding was successful and if there are roles in the response.
            if (is_array($roles) && !empty($roles)) {
                // Initialize an array to store role names.
                $roleNames = [];
            
                // Loop through each role and collect the 'name' value.
                foreach ($roles as $role) {
                    if (isset($role['name'])) {
                        $roleNames[] = $role['name'];
                    }
                }
            
                // Output or return the array of role names.
                return $roleNames;
            } else {
                debugging("No roles found or invalid response format.", DEBUG_DEVELOPER);
            }
        }

        return 0;
    }
}
