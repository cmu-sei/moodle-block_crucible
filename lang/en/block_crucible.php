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
 * Strings for component 'block_crucible'.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['crucible:addinstance'] = 'Add a new Application block';
$string['crucible:myaddinstance'] = 'Add a new Application block to Dashboard';
$string['pluginname'] = 'Applications';
$string['issuerid'] = 'Issuer Id';
$string['configissuerid'] = 'OAUTH Issuer Id for Applications';
$string['enabled'] = 'Enabled';
$string['configenabled'] = 'Enable permissions checking via OAUTH';
$string['showallapps'] = 'Show All Apps to Users';
$string['enablecommapps'] = 'Enable Communication Apps';
$string['commsectionheading'] = 'Communication Apps Settings';
$string['commsectiondesc'] = 'Enable Communication Apps to configure application URLs and settings for Roundcube and Rocketchat integrations.';
$string['configappshow'] = 'Enable User Access to all Apps';
$string['configcommshow'] = 'Enable Access to Communication Apps';
$string['customwelcomemessagecb'] = 'Enable Custom Welcome Message';
$string['customwelcomemessagedesc'] = 'Enable custom welcome message to override system message';
$string['customwelcomemessage'] = 'Custom Welcome Message';
$string['configcustomwelcomemessage'] = 'Add custom welcome message for Applications block';
$string['blocktitle'] = 'Disable Block Title';
$string['configblocktitle'] = 'Disable Block Title';

// Player
$string['playerapiurl'] = 'Player API';
$string['playerappurl'] = 'Player UI';
$string['playerdescription'] = 'Crucible\'s Exercise User Interface';
$string['configplayerapiurl'] = 'Player API URL used to pull permissions';
$string['configplayerappurl'] = 'Player UI URL used to redirect participants';
$string['playersectionheading'] = 'Player Settings';
$string['playersectiondesc'] = 'Configure API and application URLs for Player integration.';

// Alloy
$string['alloyapiurl'] = 'Alloy API';
$string['alloyappurl'] = 'Alloy UI';
$string['configalloyapiurl'] = 'Alloy API URL used to pull permissions';
$string['configalloyappurl'] = 'Alloy UI used to redirect content developers';
$string['alloydescription'] = 'Crucible\'s On-Demand Exercise Deployment Dashboard';
$string['alloysectionheading'] = 'Alloy Settings';
$string['alloysectiondesc'] = 'Configure API and application URLs for Alloy integration.';

// Blueprint
$string['blueprintapiurl'] = 'Blueprint API';
$string['blueprintappurl'] = 'Blueprint UI';
$string['configblueprintapiurl'] = 'Blueprint API URL used to pull permissions';
$string['configblueprintappurl'] = 'Blueprint UI used to redirect content developers';
$string['blueprintdescription'] = 'Crucible\'s Exercise Planning Tool';
$string['blueprintsectionheading'] = 'Blueprint Settings';
$string['blueprintsectiondesc'] = 'Configure API and application URLs for Blueprint integration.';

// Caster
$string['casterapiurl'] = 'Caster API';
$string['casterappurl'] = 'Caster UI';
$string['configcasterapiurl'] = 'Caster API URL used to pull permissions';
$string['configcasterappurl'] = 'Caster UI used to redirect content developers';
$string['casterdescription'] = 'Crucible\'s Exercise Topology Builder';
$string['castersectionheading'] = 'Caster Settings';
$string['castersectiondesc'] = 'Configure API and application URLs for Caster integration.';

// CITE
$string['citeapiurl'] = 'CITE API';
$string['citeappurl'] = 'CITE UI';
$string['configciteapiurl'] = 'CITE API URL used to pull permissions';
$string['configciteappurl'] = 'CITE UI URL used to redirect participants';
$string['citedescription'] = 'Crucible\'s Exercise Dashboard and Incident Evaluator';
$string['citesectionheading'] = 'CITE Settings';
$string['citesectiondesc'] = 'Configure API and application URLs for CITE integration.';

// Gallery
$string['galleryapiurl'] = 'Gallery API';
$string['galleryappurl'] = 'Gallery UI';
$string['configgalleryapiurl'] = 'Gallery API URL used to pull permissions';
$string['configgalleryappurl'] = 'Gallery UI URL used to redirect participants';
$string['gallerydescription'] = 'Crucible\'s Exercise Information Sharing Tool';
$string['gallerysectionheading'] = 'Gallery Settings';
$string['gallerysectiondesc'] = 'Configure API and application URLs for Gallery integration.';

// Steamfitter
$string['steamfitterapiurl'] = 'Steamfitter API';
$string['steamfitterappurl'] = 'Steamfitter UI';
$string['configsteamfitterapiurl'] = 'Steamfitter API URL used to pull permissions';
$string['configsteamfitterappurl'] = 'Steamfitter UI URL used to redirect participants';
$string['steamfitterdescription'] = 'Crucible\'s Exercise Inject Automater';
$string['steamfittersectionheading'] = 'Steamfitter Settings';
$string['steamfittersectiondesc'] = 'Configure API and application URLs for Steamfitter integration.';

// Rocketchat
$string['rocketchatapiurl'] = 'Rocket.Chat API';
$string['rocketchatappurl'] = 'Rocket.Chat UI';
$string['rocketchatauthtoken'] = 'Rocket.Chat Auth Token';
$string['configrocketchatapiurl'] = 'Rocket.Chat API URL used to pull permissions';
$string['configrocketchatappurl'] = 'Rocket.Chat UI URL used to redirect participants';
$string['configrocketchatauthtoken'] = 'Add Admin\'s Auth Token for API Calls';
$string['rocketchatdescription'] = 'Communications Platform';
$string['rocketchatuserid'] = 'Rocket.Chat User Id';
$string['configrocketchatuserid'] = 'Add Admin\'s User Id for API Calls';

// Roundcube
$string['roundcubeappurl'] = 'Roundcube UI';
$string['configroundcubeappurl'] = 'Roundcube UI URL used to redirect participants';
$string['roundcubedescription'] = 'Webmail';

// TopoMojo
$string['topomojoapiurl'] = 'TopoMojo API';
$string['topomojoappurl'] = 'TopoMojo UI';
$string['topomojoapikey'] = 'TopoMojo API Key';
$string['configtopomojoapiurl'] = 'TopoMojo API URL used to pull permissions';
$string['configtopomojoappurl'] = 'TopoMojo UI URL used to redirect participants';
$string['configtopomojoapikey'] = 'Add Admin\'s API Key for API Calls';
$string['topomojodescription'] = 'Crucible\'s Training Lab Builder and Interface';
$string['topomojosectionheading'] = 'TopoMojo Settings';
$string['topomojosectiondesc'] = 'Configure API, keys, and application URLs for TopoMojo integration.';
$string['showtopomojo'] = 'Show TopoMojo';
$string['configtopomojoshow'] = 'Show TopoMojo application regardless of user permissions';

// Gameboard
$string['gameboardapiurl'] = 'Gameboard API';
$string['gameboardappurl'] = 'Gameboard UI';
$string['configgameboardapiurl'] = 'Gameboard API URL used to pull permissions';
$string['configgameboardappurl'] = 'Gameboard UI URL used to redirect participants';
$string['gameboarddescription'] = 'Crucible\'s Competition Platform';
$string['configgameboardapikey'] = 'Add Admin\'s API Key for API Calls';
$string['gameboardapikey'] = 'Gameboard API Key';
$string['gameboardsectionheading'] = 'Gameboard Settings';
$string['gameboardsectiondesc'] = 'Configure API, keys, and application URLs for Gameboard integration.';
$string['showgameboard'] = 'Show Gameboard';
$string['configgameboardshow'] = 'Show Gameboard application regardless of user permissions';

// Docs
$string['docsappurl'] = 'IMCITE Docs';
$string['configdocsappurl'] = 'IMCITE URL used to redirect participants';
$string['docsdescription'] = 'IMCITE Documentation';
$string['docsectionheading'] = 'IMCITE Docs Settings';
$string['docsectiondesc'] = 'Configure URLs for IMCITE Docs integration.';

// Keycloak
$string['keycloakappurl'] = 'Keycloak UI';
$string['configkeycloakappurl'] = 'Keycloak URL used to redirect users';
$string['keycloakdescription'] = 'Identity and Access Management';
$string['keycloaksectionheading'] = 'Keycloak Settings';
$string['keycloaksectiondesc'] = 'Configure application URLs for Keycloak integration.';
$string['showkeycloak'] = 'Show Keycloak';
$string['configkeycloakshow'] = 'Show Keycloak application regardless of user permissions';
$string['keycloakgroups'] = 'Admin Keycloak Groups';
$string['configkeycloakgroups'] = 'Enter the names of Keycloak Admin Groups, separated by a "|" character as a delimiter.';

// MISP
$string['mispappurl'] = 'MISP UI';
$string['configmispappurl'] = 'MISP UI URL used to redirect participants';
$string['mispdescription'] = 'Threat Intelligence and Sharing Platform';
$string['configmispapikey'] = 'Add Admin\'s API Key for API Calls';
$string['mispapikey'] = 'MISP API Key';
$string['mispsectionheading'] = 'MISP Settings';
$string['mispsectiondesc'] = 'Configure API, keys, and application URLs for MISP integration.';
$string['showmisp'] = 'Show MISP';
$string['configmispshow'] = 'Show MISP application regardless of user permissions';

// privacy
$string['privacy:metadata'] = 'The Crucible block plugin shows data stored in other locations';
