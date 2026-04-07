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
$string['appswelcome'] = 'Welcome';
$string['issuerid'] = 'Issuer Id';
$string['configissuerid'] = 'OAUTH Issuer Id for Applications';
$string['enabled'] = 'Enabled';
$string['configenabled'] = 'Enable permissions checking via OAUTH';
$string['showallapps'] = 'Show All Apps to Users';
$string['configappshow'] = 'Enable User Access to all Apps';
$string['customwelcomemessagecb'] = 'Enable Custom Welcome Message';
$string['customwelcomemessagedesc'] = 'Enable custom welcome message to override system message';
$string['customwelcomemessage'] = 'Custom Welcome Message';
$string['configcustomwelcomemessage'] = 'Add custom welcome message for Applications block';
$string['blocktitle'] = 'Disable Block Title';
$string['configblocktitle'] = 'Disable Block Title';

// Player
$string['showplayer'] = 'Show Player';
$string['configplayershow'] = 'Show Player application regardless of user permissions';
$string['playerapiurl'] = 'Player API';
$string['playerappurl'] = 'Player UI';
$string['playerdescription'] = 'Exercise User Interface';
$string['configplayerapiurl'] = 'Player API URL used to pull permissions';
$string['configplayerappurl'] = 'Player UI URL used to redirect participants';
$string['playersectionheading'] = 'Player Settings';
$string['playersectiondesc'] = 'Configure API and application URLs for Player integration.';

// Alloy
$string['showalloy'] = 'Show Alloy';
$string['configalloyshow'] = 'Show Alloy application regardless of user permissions';
$string['alloyapiurl'] = 'Alloy API';
$string['alloyappurl'] = 'Alloy UI';
$string['configalloyapiurl'] = 'Alloy API URL used to pull permissions';
$string['configalloyappurl'] = 'Alloy UI used to redirect content developers';
$string['alloydescription'] = 'On-Demand Exercise Deployment Dashboard';
$string['alloysectionheading'] = 'Alloy Settings';
$string['alloysectiondesc'] = 'Configure API and application URLs for Alloy integration.';

// Blueprint
$string['showblueprint'] = 'Show Blueprint';
$string['configblueprintshow'] = 'Show Blueprint application regardless of user permissions';
$string['blueprintapiurl'] = 'Blueprint API';
$string['blueprintappurl'] = 'Blueprint UI';
$string['configblueprintapiurl'] = 'Blueprint API URL used to pull permissions';
$string['configblueprintappurl'] = 'Blueprint UI used to redirect content developers';
$string['blueprintdescription'] = 'Exercise Planning Tool';
$string['blueprintsectionheading'] = 'Blueprint Settings';
$string['blueprintsectiondesc'] = 'Configure API and application URLs for Blueprint integration.';

// Caster
$string['showcaster'] = 'Show Caster';
$string['configcastershow'] = 'Show Caster application regardless of user permissions';
$string['casterapiurl'] = 'Caster API';
$string['casterappurl'] = 'Caster UI';
$string['configcasterapiurl'] = 'Caster API URL used to pull permissions';
$string['configcasterappurl'] = 'Caster UI used to redirect content developers';
$string['casterdescription'] = 'Exercise Topology Builder';
$string['castersectionheading'] = 'Caster Settings';
$string['castersectiondesc'] = 'Configure API and application URLs for Caster integration.';

// CITE
$string['showcite'] = 'Show CITE';
$string['configciteshow'] = 'Show CITE application regardless of user permissions';
$string['citeapiurl'] = 'CITE API';
$string['citeappurl'] = 'CITE UI';
$string['configciteapiurl'] = 'CITE API URL used to pull permissions';
$string['configciteappurl'] = 'CITE UI URL used to redirect participants';
$string['citedescription'] = 'Exercise Dashboard and Incident Evaluator';
$string['citesectionheading'] = 'CITE Settings';
$string['citesectiondesc'] = 'Configure API and application URLs for CITE integration.';

// Gallery
$string['showgallery'] = 'Show Gallery';
$string['configgalleryshow'] = 'Show Gallery application regardless of user permissions';
$string['galleryapiurl'] = 'Gallery API';
$string['galleryappurl'] = 'Gallery UI';
$string['configgalleryapiurl'] = 'Gallery API URL used to pull permissions';
$string['configgalleryappurl'] = 'Gallery UI URL used to redirect participants';
$string['gallerydescription'] = 'Exercise Information Sharing Tool';
$string['gallerysectionheading'] = 'Gallery Settings';
$string['gallerysectiondesc'] = 'Configure API and application URLs for Gallery integration.';

// Steamfitter
$string['showsteamfitter'] = 'Show Steamfitter';
$string['configsteamfittershow'] = 'Show Steamfitter application regardless of user permissions';
$string['steamfitterapiurl'] = 'Steamfitter API';
$string['steamfitterappurl'] = 'Steamfitter UI';
$string['configsteamfitterapiurl'] = 'Steamfitter API URL used to pull permissions';
$string['configsteamfitterappurl'] = 'Steamfitter UI URL used to redirect participants';
$string['steamfitterdescription'] = 'Exercise Inject Automater';
$string['steamfittersectionheading'] = 'Steamfitter Settings';
$string['steamfittersectiondesc'] = 'Configure API and application URLs for Steamfitter integration.';


// TopoMojo
$string['showtopomojo'] = 'Show TopoMojo';
$string['configtopomojoshow'] = 'Show TopoMojo application regardless of user permissions';
$string['topomojoapiurl'] = 'TopoMojo API';
$string['topomojoappurl'] = 'TopoMojo UI';
$string['configtopomojoapiurl'] = 'TopoMojo API URL used to pull permissions';
$string['configtopomojoappurl'] = 'TopoMojo UI URL used to redirect participants';
$string['topomojodescription'] = 'Training Lab Builder and Interface';
$string['topomojosectionheading'] = 'TopoMojo Settings';
$string['topomojosectiondesc'] = 'Configure API, keys, and application URLs for TopoMojo integration.';

// Gameboard
$string['showgameboard'] = 'Show Gameboard';
$string['configgameboardshow'] = 'Show Gameboard application regardless of user permissions';
$string['gameboardapiurl'] = 'Gameboard API';
$string['gameboardappurl'] = 'Gameboard UI';
$string['configgameboardapiurl'] = 'Gameboard API URL used to pull permissions';
$string['configgameboardappurl'] = 'Gameboard UI URL used to redirect participants';
$string['gameboarddescription'] = 'Competition Platform';
$string['gameboardsectionheading'] = 'Gameboard Settings';
$string['gameboardsectiondesc'] = 'Configure API, keys, and application URLs for Gameboard integration.';

// Docs
$string['docsappurl'] = 'Docs UI';
$string['configdocsappurl'] = 'Docs URL used to redirect participants';
$string['docsdescription'] = 'Documentation';
$string['docsectionheading'] = 'Docs Settings';
$string['docsectiondesc'] = 'Configure URLs for Docs integration.';

// Keycloak
$string['showkeycloak'] = 'Show Keycloak';
$string['configkeycloakshow'] = 'Show Keycloak application regardless of user permissions';
$string['keycloakuserurl'] = 'Keycloak User URL';
$string['configkeycloakuserurl'] = 'Specifies the Keycloak URL to which regular users are redirected. Ensure the URL includes the realm component without trailing /.';
$string['keycloakadminurl'] = 'Keycloak Admin URL';
$string['configkeycloakadminurl'] = 'Specifies the Keycloak URL to which admins are redirected. Ensure the URL includes the realm component without trailing /.';
$string['keycloakdescription'] = 'Identity and Access Management';
$string['keycloaksectionheading'] = 'Keycloak Settings';
$string['keycloaksectiondesc'] = 'Configure application URLs for Keycloak integration.';
$string['keycloakgroups'] = 'Admin Keycloak Groups';
$string['configkeycloakgroups'] = 'Enter the names of Keycloak Admin Groups, separated by a "|" character as a delimiter.';
$string['keycloakroles'] = 'Admin Keycloak Roles';
$string['configkeycloakroles'] = 'Enter the names of Keycloak Admin Roles, separated by a "|" character as a delimiter.';
$string['userredirect'] = "User Account Redirect";
$string['configuserredirect'] = 'When enabled, redirects all users to the same page used for user account management.';


// privacy
$string['privacy:metadata'] = 'The Crucible block plugin shows data stored in other locations';

//lp
$string['config_viewtype'] = 'Choose view';
$string['view_apps'] = 'Applications';
$string['view_learningplan'] = 'Learning Plans';
$string['pleaseconfigure'] = 'This block needs to be configured. Choose a view from the block settings.';
$string['configureblock'] = 'Configure this block';
$string['suggestedforrole'] = 'Suggestions based on your role';
$string['noplanssuggested'] = 'No learning plans matched your role yet';
$string['learningplantitle'] = 'Learning plan';
$string['blockheading'] = 'Suggested Learning Plans';
$string['competencies'] = 'Competencies';
$string['nocompetenciesintemplate'] = 'This learning plan has no competencies yet.';
$string['courses'] = 'Courses';
$string['activities'] = 'activities';
$string['addtomylearningplans'] = 'Enroll in this learning plan';
$string['planselfenrolled'] = 'Learning plan added to your plans.';
$string['plandalreadyexists'] = 'You already have this learning plan.';
$string['planselfenrolfailed'] = 'Could not add the learning plan. Please try again or contact support.';
$string['currentrole'] = 'Your work role';
$string['emptyrolemsg'] = 'Your work role is not set. This field is empty—please contact the system administrator.';
$string['suggestedtemplates'] = 'Suggested learning plans';
$string['nosuggestions'] = 'No suggestions available.';
$string['suggestedlearningplans'] = 'Suggested Learning Plans';
$string['suggestedforrole'] = 'Suggestions based on your role';
$string['emptyrolemsg'] = 'Your work role is not set. Please contact your system administrator.';
$string['noplanssuggested'] = 'No learning plans are suggested right now.';
$string['trydifferentrole'] = 'If this seems wrong, update your role or contact your administrator.';
$string['defaulttitle'] = 'Default block title';
$string['configtitle']  = 'Custom title';
$string['confighideheader'] = 'Hide block header';
$string['course'] = 'Course';
$string['mappedactivities'] = 'Mapped Activities';
$string['lpname_header'] = 'Learning plan';
$string['lpcoursecount_header'] = 'Courses';
$string['lpactivitycount_header'] = 'Activities';
$string['noapps_heading'] = 'Applications Unavailable';
$string['hello_user_site_inline'] = 'Hello';
$string['crucible_logo_alt'] = 'Crucible logo';
$string['app_config_problem_title'] = 'No applications are available for your account';
$string['app_config_problem_body'] =
    'No applications are configured or match your current permissions. If you believe this is an error, please contact your system administrator.';
$string['oauth_error_heading']   = 'OAuth connection required';
$string['oauth_error_subtitle']  = 'Authentication is not configured or currently unavailable';
$string['oauth_error_title']     = 'There is a problem with OAuth configuration';
$string['oauth_error_body']      = 'Please connect OAuth to enable this plugin, or contact the system administrator for assistance.';
$string['oauth_logo_alt']        = 'Crucible logo';
$string['showheader'] = 'Show header';
$string['showheader_help'] = 'Display the decorative header (icon, title, and subtitle) at the top of this block. Turn this off for a more compact look.';
$string['notenabled_heading']   = 'Applications plugin disabled';
$string['notenabled_subtitle']  = 'This feature is currently turned off on your site';
$string['notenabled_title']     = 'The Applications plugin has not been enabled';
$string['notenabled_body']      = 'Please contact your system administrator to configure and enable the plugin.';
$string['notenabled_logo_alt']  = 'Crucible logo';
$string['applicationsheader'] = 'Crucible Applications';
$string['configsection_appearance'] = 'Appearance';
$string['youalreadyhavethisplan'] = 'You are already enrolled in this learning plan';
$string['openmyplan'] = 'Open my plan';
$string['view_competencies']     = 'Competencies';
$string['competenciesheader']    = 'Mapped Competencies';
$string['competencies_subtitle'] = 'Competencies linked to courses and activities';
$string['col_competency']        = 'Competency';
$string['col_courses']           = 'Courses';
$string['col_activities']        = 'Activities';
$string['nocompsmapped']         = 'No competencies are currently mapped to courses or activities.';
$string['activitiesandresources'] = 'Activities and resources';
$string['activities']             = 'Activities';
$string['courses']                = 'Courses';
$string['category']               = 'Category';
$string['nocoursesmapped']        = 'No courses are mapped to this competency.';
$string['noactivitiesmapped']     = 'No activities are mapped to this competency.';
$string['framework_unknown'] = 'Unassigned framework';
$string['unmapped_summary']  = 'Unmapped {$a} Competencies';
$string['unmapped_for_framework_title'] = 'Unmapped Competencies — {$a}';
$string['unmapped_list_empty'] = 'No unmapped competencies in this framework.';
$string['task_sync_keycloak_users'] = 'Sync Keycloak Users to Moodle';
$string['task_sync_org_roles'] = 'Sync Org Group Roles';
$string['orgrolsyncsectionheading'] = 'Organization Role Sync';
$string['orgrolsyncsectiondesc'] = 'Configure automatic syncing of Keycloak group memberships to Moodle category-scoped roles based on user organization.';
$string['enableorgrolesync'] = 'Enable Organization Role Sync';
$string['configenableorgrolesync'] = 'When enabled, automatically assigns organization-scoped roles to users based on their Keycloak group membership. This runs as a scheduled task hourly and also on user login.';
$string['learning_plan'] = 'Learning Plan';
$string['framework'] = 'Framework';

// Reports
$string['reportnotsetmessage'] = 'No report is available for you right now.';
$string['view_reports'] = 'Reports';
$string['organization'] = 'Organization';
$string['workrole'] = 'Work Role';
$string['roles'] = 'Moodle Role';
$string['nousersfound'] = 'No users found.';
$string['nocohorts'] = 'You are not in any cohorts.';
$string['cohortroles'] = 'Team Role';
$string['reportsheader'] = 'My Team';
$string['alreadyenrolled'] = 'You are already enrolled in this learning plan.';
$string['alreadyenrolled_short'] = 'Enrolled';
$string['viewplan'] = 'View plan';
$string['search_comp_course_activity'] = 'Search competencies, courses, activities…';
$string['clear'] = 'Clear';
$string['noresults'] = 'No matches found';
$string['config_frameworkid'] = 'Competency framework';
$string['config_frameworkid_help'] = 'Choose which competency framework this block should use.';

// Application management.
$string['customapps']       = 'Crucible Applications';
$string['manageapps']       = 'Manage Applications';
$string['manageappslink']   = 'Add or edit applications →';
$string['addnewapp']        = 'Add new application';
$string['editapp']          = 'Edit application';

$string['appname']          = 'App name';
$string['appname_help']     = 'The display name shown on the application card, for example "My Tool". This is what users will see as the card title.';


$string['appdescription']   = 'Description';
$string['appdescription_help'] = 'A short tagline or description displayed below the app name on the card. Keep it brief — one sentence works best.';

$string['appurl']           = 'App URL';
$string['appurl_help']      = 'The full URL users are taken to when they click the application card (e.g. "https://player.example.com"). Must include the protocol (https://).';

$string['applogo']          = 'Logo';
$string['applogo_help']     = 'Upload an image to display as the application logo on the card. Accepted formats: PNG, SVG, JPEG. Maximum file size: 2 MB. A square image of at least 64 × 64 px is recommended for best results.';

$string['appuseapi']        = 'Enable API integration';
$string['appuseapi_help']   = 'Check this if the application exposes an API that this plugin should communicate with. When enabled, you can provide the API base URL below. The URL will be used for permission checks and data retrieval.';

$string['appapiurl']        = 'API URL';
$string['appapiurl_help']   = 'The base URL of the application\'s API (e.g. "https://api.player.example.com"). Do not include a trailing slash. This is used internally by the plugin and is not shown to users.';

$string['appuseapikey']     = 'API requires authentication key';
$string['appuseapikey_help']= 'Check this if the API endpoint requires an authentication key or token to be sent with requests. When enabled, you can provide the key below. It will be stored securely and included in API calls made by the plugin.';

$string['appapikey']        = 'API key';
$string['appapikey_help']   = 'The secret key or token used to authenticate requests to this application\'s API. Treat this value like a password — do not share it. It is stored in the database and never shown to end users.';

$string['appenabled']       = 'Enabled';
$string['appenabled_help']  = 'When checked, this application is visible to users on the applications block. Uncheck to hide it temporarily without deleting it.';

$string['appkeycloakenabled']      = 'Keycloak Role Mapping Enabled?';
$string['appkeycloakenabled_help'] = 'When checked, this application will only be shown to users who have a specific Keycloak realm role on their token. Enter the required role name in the field below.';

$string['appkeycloakrole']         = 'Required Keycloak role(s)';
$string['appkeycloakrole_help']    = 'One or more Keycloak realm role names, separated by "|" (e.g. "operator|content-developer"). The app will be shown if the user has at least one of the listed roles on their token. Leave blank to show the app to any authenticated user when role mapping is enabled.';

$string['appoverriderole']         = 'Override role permissions';
$string['appoverriderole_help']    = 'When checked, the required Keycloak role check is bypassed and the application is shown to all users regardless of their token roles. Useful for testing or for temporarily making an app universally visible.';

$string['actions']          = 'Actions';
$string['noapps']           = 'No applications have been added yet.';
$string['appnamereserved']  = 'This name conflicts with a built-in Crucible application. Please choose a different name, or configure the built-in application under Site Administration > Plugins > Crucible Applications.';
$string['appnameexists']    = 'An application with this name already exists. Please choose a different name.';
$string['appadded']         = 'Application added successfully.';
$string['appupdated']       = 'Application updated successfully.';
$string['appdeleted']       = 'Application deleted.';
$string['confirmdelete']    = 'Are you sure you want to delete this application? This cannot be undone.';
