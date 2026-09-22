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

use core\http_client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

defined('MOODLE_INTERNAL') || die();

/**
 * Main Crucible API client class.
 */
class crucible
{
    /** @var int Maximum time to establish a connection to Keycloak. */
    const KEYCLOAK_CONNECT_TIMEOUT_SECONDS = 5;

    /** @var int Maximum total duration of a Keycloak request. */
    const KEYCLOAK_TIMEOUT_SECONDS = 10;

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

    /**
     * Retrieves the user's permission level based on Keycloak roles or groups.
     *
     * This method checks if the current user has a matching role or group (defined in
     * the plugin configuration settings `keycloakroles` and `keycloakgroups`). If either match
     * is found in the user's Keycloak roles or groups, the corresponding value is returned.
     *
     * The function returns early if the OAuth client is not set up or the user lacks an ID number.
     * If no match is found, the function returns 0.
     *
     * @global \stdClass $USER The current Moodle user object.
     * @return string|int The matching role or group name if found; otherwise 0.
     */
    public function get_user_permissions() {
        global $USER;
        $userid = $USER->idnumber;

        $roles = get_config('block_crucible', 'keycloakroles');
        $groups = get_config('block_crucible', 'keycloakgroups');

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return null;
        }
        if (!$userid) {
            debugging("User has no idnumber.", DEBUG_DEVELOPER);
            return null;
        }

        // Check Keycloak roles and groups for Administrator.
        $userRoles = $this->get_keycloak_roles();
        if (is_array($userRoles) && in_array($roles, $userRoles)) {
            return $roles;
        }

        return 0;
    }

    // PLAYER//////////////////////
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
            return null;
        }

        if (!$userid) {
            debugging("User has no idnumber.", DEBUG_DEVELOPER);
            return null;
        }

        // Check if the URL is configured
        $url = get_config('block_crucible', 'playerapiurl');
        if (empty($url)) {
            return null;
        }

        // Web request
        $url .= "/users/" . $userid . "/view-memberships";

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Player API endpoint not found (404) " . $url . ". Check API URL configuration.", DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("Unable to connect to Player API (HTTP " . $this->client->info['http_code'] . ") at " . $url . ". Check network connectivity and API status.", DEBUG_DEVELOPER);
            return false;
        }

        if (!$response) {
            debugging("No response received from Player endpoint. Check network connectivity.", DEBUG_DEVELOPER);
            return false;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
    }

    // BLUEPRINT//////////////////////
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
            return null;
        }

        if (!$userid) {
            debugging("User has no idnumber.", DEBUG_DEVELOPER);
            return null;
        }

        // Web request
        $url = get_config('block_crucible', 'blueprintapiurl');
        if (empty($url)) {
            return null;
        }

        $url .= "/users/" . $userid . "/msels";
        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) for User: " . $userid . " on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Blueprint API endpoint not found (404) " . $url . ". Check API URL configuration.", DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("Unable to connect to Blueprint API (HTTP " . $this->client->info['http_code'] . ") at " . $url . ". Check network connectivity and API status.", DEBUG_DEVELOPER);
            return false;
        }

        if (!$response) {
            debugging("No response received from Blueprint endpoint. Check network connectivity.", DEBUG_DEVELOPER);
            return false;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
    }

    // CITE//////////////////////
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
            return null;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return null;
        }

        // Web request
        $url = get_config('block_crucible', 'citeapiurl');
        if (empty($url)) {
            return null;
        }

        $url .= "/users/" . $USER->idnumber;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("CITE API endpoint not found (404) " . $url . ". Check API URL configuration.", DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("Unable to connect to CITE API (HTTP " . $this->client->info['http_code'] . ") at " . $url . ". Check network connectivity and API status.", DEBUG_DEVELOPER);
            return false;
        }

        if (!$response) {
            debugging("No response received from endpoint. Check network connectivity.", DEBUG_DEVELOPER);
            return false;
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
            return null;
        }

        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return null;
        }

        // Web request
        $url = get_config('block_crucible', 'citeapiurl');
        if (empty($url)) {
            return null;
        }

        $url .= "/evaluations?userid=" . $userid;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("CITE API endpoint not found (404) " . $url . ". Check API URL configuration.", DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("Unable to connect to CITE API (HTTP " . $this->client->info['http_code'] . ") at " . $url . ". Check network connectivity and API status.", DEBUG_DEVELOPER);
            return false;
        }

        if (!$response) {
            debugging("No response received from CITE endpoint. Check network connectivity.", DEBUG_DEVELOPER);
            return false;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
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
            return null;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return null;
        }

        // Web request
        $url = get_config('block_crucible', 'galleryapiurl');
        if (empty($url)) {
            return null;
        }

        $url .= "/users/" . $userid . '/exhibits';
        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Gallery API endpoint not found (404) " . $url . ". Check API URL configuration.", DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("Unable to connect to Gallery API (HTTP " . $this->client->info['http_code'] . ") at " . $url . ". Check network connectivity and API status.", DEBUG_DEVELOPER);
            return false;
        }

        if (!$response) {
            debugging("No response received from Gallery endpoint. Check network connectivity.", DEBUG_DEVELOPER);
            return false;
        }

        $r = json_decode($response);
        if (!$r) {
            return 0;
        }
        return $r;
    }

    // TopoMojo//////////////////////
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
            return null;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return null;
        }

        // Web request
        $url = get_config('block_crucible', 'topomojoapiurl');
        if (empty($url)) {
            return null;
        }

        $url .= "/user/" . $userid;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Topomojo API endpoint not found (404) " . $url . ". Check API URL configuration.", DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("Unable to connect to Topomojo API (HTTP " . $this->client->info['http_code'] . ") at " . $url . ". Check network connectivity and API status.", DEBUG_DEVELOPER);
            return false;
        }

        if (!$response) {
            debugging("No response received from Topomojo endpoint. Check network connectivity.", DEBUG_DEVELOPER);
            return false;
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
    // Gameboard//////////////////////
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
            return null;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return null;
        }

        // Web request
        $url = get_config('block_crucible', 'gameboardapiurl');
        if (empty($url)) {
            return null;
        }

        $url .= "/user/" . $userid;

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Gameboard API endpoint not found (404) " . $url . ". Check API URL configuration.", DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("Unable to connect to Gameboard API (HTTP " . $this->client->info['http_code'] . ") at " . $url . ". Check network connectivity and API status.", DEBUG_DEVELOPER);
            return false;
        }

        if (!$response) {
            debugging("No response received from Gamebaord endpoint. Check network connectivity.", DEBUG_DEVELOPER);
            return false;
        }

        $r = json_decode($response);

        if (isset($r->message) && strpos($r->message, "Couldn't find resource") !== false) {
            debugging("Gameboard validation exception: " . $r->message, DEBUG_DEVELOPER);
            return 0;
        }

        if (
            isset($r->role) && in_array($r->role, [
                'admin',
                'director',
                'support',
            ])
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
            return null;
        }
        if (!$userid) {
            debugging("User has no idnumber", DEBUG_DEVELOPER);
            return null;
        }

        // Web request
        $url = get_config('block_crucible', 'gameboardapiurl');
        if (empty($url)) {
            return null;
        }

        $url .= "/user/" . $userid . "/challenges/active";

        $response = $this->client->get($url);

        if ($this->client->info['http_code'] === 401) {
            debugging("Unauthorized access (401) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 403) {
            debugging("Forbidden (403) on " . $url, DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] === 404) {
            debugging("Gameboard API endpoint not found (404) " . $url . ". Check API URL configuration.", DEBUG_DEVELOPER);
            return false;
        } else if ($this->client->info['http_code'] !== 200) {
            debugging("Unable to connect to Gameboard API (HTTP " . $this->client->info['http_code'] . ") at " . $url . ". Check network connectivity and API status.", DEBUG_DEVELOPER);
            return false;
        }

        if (!$response) {
            debugging("No response received from Gamebaord endpoint. Check network connectivity.", DEBUG_DEVELOPER);
            return false;
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
     * Get Keycloak groups for current user.
     *
     * @return array|null Group names or null on error
     */
    public function get_keycloak_groups() {
        $names = $this->get_keycloak_user_collection('groups');
        if (!is_array($names)) {
            return $names;
        }
        if (empty($names)) {
            debugging("No groups found or invalid response format.", DEBUG_DEVELOPER);
            return 0;
        }
        return $names;
    }

    /**
     * Get Keycloak roles for current user.
     *
     * @return array|null Role names or null on error
     */
    public function get_keycloak_roles() {
        $names = $this->get_keycloak_user_collection('role-mappings/realm');
        if (!is_array($names)) {
            return $names;
        }
        if (empty($names)) {
            debugging("No roles found or invalid response format.", DEBUG_DEVELOPER);
            return 0;
        }
        return $names;
    }

    /**
     * Get the 'name' of every record under a Keycloak user sub-resource.
     *
     * @param string $subresource Path below the user, such as 'groups' or 'role-mappings/realm'.
     * @return array|int|bool|null Names found, 0 when none resolved, false on misconfiguration,
     *                             null when the session or admin URL is unavailable.
     */
    private function get_keycloak_user_collection(string $subresource) {
        global $USER;

        if ($this->client == null) {
            debugging("Session not set up", DEBUG_DEVELOPER);
            return null;
        }

        $adminurl = get_config('block_crucible', 'keycloakadminurl');
        if (empty($adminurl)) {
            return null;
        }
        $adminurl = rtrim($adminurl, '/');

        $issuerid = get_config('block_crucible', 'issuerid');
        if (!$issuerid) {
            debugging("Crucible does not have issuerid set", DEBUG_DEVELOPER);
            return false; // Exit if issuer ID is not set
        }

        // Convert /admin/{realm}/console to the realm and admin-realm bases.
        $realmurl = preg_replace('#/admin/([^/]+)/console$#', '/realms/$1', $adminurl);
        $adminrealmurl = preg_replace('#/admin/([^/]+)/console$#', '/admin/realms/$1', $adminurl);

        $token = $this->get_keycloak_token($realmurl . '/protocol/openid-connect/token', $issuerid);
        if ($token === null) {
            return false;
        }

        // A single Keycloak account, so the email has to match exactly rather than be contained in
        // the account's address: Keycloak searches by substring unless told otherwise, and the
        // first of several matches decides which groups and roles this block reports.
        $userlist = $this->get_keycloak_json(
            $adminrealmurl . '/users?exact=true&email=' . urlencode($USER->email),
            $token
        );
        if (!is_array($userlist) || empty($userlist) || empty($userlist[0]['id'])) {
            debugging("No users found in Keycloak matching email: {$USER->email}", DEBUG_DEVELOPER);
            return 0;
        }

        $records = $this->get_keycloak_json(
            $adminrealmurl . '/users/' . urlencode($userlist[0]['id']) . '/' . $subresource,
            $token
        );
        if (!is_array($records)) {
            return 0;
        }

        $names = [];
        foreach ($records as $record) {
            if (isset($record['name'])) {
                $names[] = $record['name'];
            }
        }
        return $names;
    }

    /**
     * Get a client credentials access token from Keycloak.
     *
     * @param string $tokenurl Token endpoint.
     * @param string $issuerid OAuth 2 issuer holding the client credentials.
     * @return string|null Access token, or null when one could not be obtained.
     */
    private function get_keycloak_token(string $tokenurl, string $issuerid): ?string {
        $issuer = \core\oauth2\api::get_issuer($issuerid);

        try {
            $response = $this->create_http_client()->post($tokenurl, [
                RequestOptions::FORM_PARAMS => [
                    'client_id' => $issuer->get('clientid'),
                    'client_secret' => $issuer->get('clientsecret'),
                    'grant_type' => 'client_credentials',
                ],
            ]);
        } catch (GuzzleException $e) {
            debugging("Keycloak token request failed: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            debugging("Keycloak token request returned HTTP " . $response->getStatusCode(), DEBUG_DEVELOPER);
            return null;
        }

        $tokendata = json_decode((string) $response->getBody(), true);
        if (!isset($tokendata['access_token']) || !is_string($tokendata['access_token'])) {
            debugging("Failed to obtain access token from Keycloak. Check Keycloak configuration.", DEBUG_DEVELOPER);
            return null;
        }
        return $tokendata['access_token'];
    }

    /**
     * Get and decode JSON from the Keycloak admin API.
     *
     * @param string $url Admin API URL.
     * @param string $token Bearer token.
     * @return array|null Decoded array, or null on any failure.
     */
    private function get_keycloak_json(string $url, string $token): ?array {
        try {
            $response = $this->create_http_client()->get($url, [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            debugging("Keycloak request failed: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }

        if ($response->getStatusCode() !== 200) {
            debugging("Keycloak request to {$url} returned HTTP " . $response->getStatusCode(), DEBUG_DEVELOPER);
            return null;
        }

        $decoded = json_decode((string) $response->getBody(), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Create the HTTP client used for Keycloak requests.
     *
     * Core's Guzzle client rather than raw PHP cURL or the older \curl wrapper: these requests
     * carry a realm admin bearer token, and this client verifies the peer certificate by default,
     * drops the Authorization header on a cross-origin redirect, and honours the site's proxy and
     * blocked-host settings. Raw cURL honours none of those, and \curl does not verify.
     *
     * @param array $extraconfig Client configuration to apply over the defaults below.
     * @return http_client
     */
    protected function create_http_client(array $extraconfig = []): http_client {
        return new http_client($extraconfig + [
            // These run while a block renders, so an unreachable Keycloak must not hang the page.
            RequestOptions::CONNECT_TIMEOUT => self::KEYCLOAK_CONNECT_TIMEOUT_SECONDS,
            RequestOptions::TIMEOUT => self::KEYCLOAK_TIMEOUT_SECONDS,
            // Report an error status through the same path as every other failure here.
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }
}
