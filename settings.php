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
 * Settings file for the Crucible block in Moodle.
 *
 * This file contains the configuration settings for the Crucible block.
 * @package   block_crucible
 * @copyright 2024 Carnegie Mellon University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// This line protects the file from being accessed by a URL directly.
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // General Settings
    $options = [];
    $issuers = core\oauth2\api::get_all_issuers();
    if ($issuers) {
        foreach ($issuers as $issuer) {
                $options[$issuer->get('id')] = s($issuer->get('name'));
        }
    }

    // Enable/Disable plugin
    $settings->add(new admin_setting_configcheckbox('block_crucible/enabled',
        get_string('enabled', 'block_crucible'), get_string('configenabled', 'block_crucible'), 0, 1, 0));

    // OAUTH
    $settings->add(new admin_setting_configselect('block_crucible/issuerid',
        get_string('issuerid', 'block_crucible'), get_string('configissuerid', 'block_crucible'), 0, $options));

    // Checkbox
    $settings->add(new admin_setting_configcheckbox('block_crucible/showallapps',
        get_string('showallapps', 'block_crucible'), get_string('configappshow', 'block_crucible'), 0, 1, 0));

    //Block Title
    $settings->add(new admin_setting_configcheckbox('block_crucible/blocktitle',
        get_string('blocktitle', 'block_crucible'), get_string('configblocktitle', 'block_crucible'), 0, 1, 0));

    // Welcome Message
    $settings->add(new admin_setting_configcheckbox(
        'block_crucible/customwelcomemessagecb',
        get_string('customwelcomemessagecb', 'block_crucible'), 
        get_string('customwelcomemessagedesc', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/customwelcomemessage',
        get_string('customwelcomemessage', 'block_crucible'), get_string('configcustomwelcomemessage', 'block_crucible'),
         "", PARAM_RAW, 60));
    

    // Alloy
    $settings->add(new admin_setting_heading(
        'block_crucible/alloysectionheading',
        get_string('alloysectionheading', 'block_crucible'), 
        get_string('alloysectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showalloy',
        get_string('showalloy', 'block_crucible'), get_string('configalloyshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/alloyapiurl',
        get_string('alloyapiurl', 'block_crucible'), get_string('configalloyapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/alloyappurl',
        get_string('alloyappurl', 'block_crucible'), get_string('configalloyappurl', 'block_crucible'), "", PARAM_URL, 60));

    // Blueprint
    $settings->add(new admin_setting_heading(
        'block_crucible/blueprintsectionheading',
        get_string('blueprintsectionheading', 'block_crucible'), 
        get_string('blueprintsectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showblueprint',
        get_string('showblueprint', 'block_crucible'), get_string('configblueprintshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/blueprintapiurl',
        get_string('blueprintapiurl', 'block_crucible'), get_string('configblueprintapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/blueprintappurl',
        get_string('blueprintappurl', 'block_crucible'), get_string('configblueprintappurl', 'block_crucible'), "", PARAM_URL, 60));

    // Caster
    $settings->add(new admin_setting_heading(
        'block_crucible/castersectionheading',
        get_string('castersectionheading', 'block_crucible'), 
        get_string('castersectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showcaster',
        get_string('showcaster', 'block_crucible'), get_string('configcastershow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/casterapiurl',
        get_string('casterapiurl', 'block_crucible'), get_string('configcasterapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/casterappurl',
        get_string('casterappurl', 'block_crucible'), get_string('configcasterappurl', 'block_crucible'), "", PARAM_URL, 60));

    // CITE
    $settings->add(new admin_setting_heading(
        'block_crucible/citesectionheading',
        get_string('citesectionheading', 'block_crucible'), 
        get_string('citesectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showcite',
        get_string('showcite', 'block_crucible'), get_string('configciteshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/citeapiurl',
        get_string('citeapiurl', 'block_crucible'), get_string('configciteapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/citeappurl',
        get_string('citeappurl', 'block_crucible'), get_string('configciteappurl', 'block_crucible'), "", PARAM_URL, 60));

    // Docs
    $settings->add(new admin_setting_heading(
        'block_crucible/docsectionheading',
        get_string('docsectionheading', 'block_crucible'),
        get_string('docsectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configtext('block_crucible/docsappurl',
      get_string('docsappurl', 'block_crucible'), get_string('configdocsappurl', 'block_crucible'), "", PARAM_URL, 60));


    // Gallery
    $settings->add(new admin_setting_heading(
        'block_crucible/gallerysectionheading',
        get_string('gallerysectionheading', 'block_crucible'), 
        get_string('gallerysectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showgallery',
        get_string('showgallery', 'block_crucible'), get_string('configgalleryshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/galleryapiurl',
        get_string('galleryapiurl', 'block_crucible'), get_string('configgalleryapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/galleryappurl',
        get_string('galleryappurl', 'block_crucible'), get_string('configgalleryappurl', 'block_crucible'), "", PARAM_URL, 60));

    // Gameboard
    $settings->add(new admin_setting_heading(
        'block_crucible/gameboardsectionheading',
        get_string('gameboardsectionheading', 'block_crucible'), 
        get_string('gameboardsectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showgameboard',
        get_string('showgameboard', 'block_crucible'), get_string('configgameboardshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/gameboardapiurl',
      get_string('gameboardapiurl', 'block_crucible'), get_string('configgameboardapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/gameboardappurl',
      get_string('gameboardappurl', 'block_crucible'), get_string('configgameboardappurl', 'block_crucible'), "", PARAM_URL, 60));

    // Keycloak
    $settings->add(new admin_setting_heading(
        'block_crucible/keycloaksectionheading',
        get_string('keycloaksectionheading', 'block_crucible'), 
        get_string('keycloaksectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showkeycloak',
        get_string('showkeycloak', 'block_crucible'), get_string('configkeycloakshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configcheckbox('block_crucible/userredirect',
        get_string('userredirect', 'block_crucible'), get_string('configuserredirect', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/keycloakuserurl',
      get_string('keycloakuserurl', 'block_crucible'), get_string('configkeycloakuserurl', 'block_crucible'), "", PARAM_URL, 60));
    
    $settings->add(new admin_setting_configtext('block_crucible/keycloakadminurl',
      get_string('keycloakadminurl', 'block_crucible'), get_string('configkeycloakadminurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/keycloakgroups',
      get_string('keycloakgroups', 'block_crucible'), get_string('configkeycloakgroups', 'block_crucible'),
       "", PARAM_RAW, 60));

    $settings->add(new admin_setting_configtext('block_crucible/keycloakroles',
    get_string('keycloakroles', 'block_crucible'), get_string('configkeycloakroles', 'block_crucible'),
    "", PARAM_RAW, 60));

      // MISP
    $settings->add(new admin_setting_heading(
        'block_crucible/mispsectionheading',
        get_string('mispsectionheading', 'block_crucible'), 
        get_string('mispsectiondesc', 'block_crucible')
    ));

    // Checkbox
    $settings->add(new admin_setting_configcheckbox('block_crucible/showmisp',
        get_string('showmisp', 'block_crucible'), get_string('configmispshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/mispappurl',
      get_string('mispappurl', 'block_crucible'), get_string('configmispappurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/mispapikey',
      get_string('mispapikey', 'block_crucible'), get_string('configmispapikey', 'block_crucible'), "", PARAM_RAW, 60));

    // Player
    $settings->add(new admin_setting_heading(
        'block_crucible/playersectionheading',
        get_string('playersectionheading', 'block_crucible'),
        get_string('playersectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showplayer',
        get_string('showplayer', 'block_crucible'), get_string('configplayershow', 'block_crucible'), 0, 1, 0));
    
    $settings->add(new admin_setting_configtext('block_crucible/playerapiurl',
        get_string('playerapiurl', 'block_crucible'), get_string('configplayerapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/playerappurl',
        get_string('playerappurl', 'block_crucible'), get_string('configplayerappurl', 'block_crucible'), "", PARAM_URL, 60));

    // Rocket.Chat
    $settings->add(new admin_setting_heading(
        'block_crucible/rocketchatsectionheading',
        get_string('rocketchatsectionheading', 'block_crucible'),
        get_string('rocketchatsectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showrocketchat',
        get_string('showrocketchat', 'block_crucible'), get_string('configrocketchatshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/rocketchatapiurl',
        get_string('rocketchatapiurl', 'block_crucible'), get_string('configrocketchatapiurl', 'block_crucible'),
        "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/rocketchatappurl',
        get_string('rocketchatappurl', 'block_crucible'), get_string('configrocketchatappurl', 'block_crucible'),
        "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/rocketchatauthtoken',
        get_string('rocketchatauthtoken', 'block_crucible'), get_string('configrocketchatauthtoken', 'block_crucible'),
        "", PARAM_RAW, 60));

    $settings->add(new admin_setting_configtext('block_crucible/rocketchatuserid',
        get_string('rocketchatuserid', 'block_crucible'), get_string('configrocketchatuserid', 'block_crucible'),
        "", PARAM_RAW, 60));

    // Roundcube
    $settings->add(new admin_setting_heading(
        'block_crucible/roundcubesectionheading',
        get_string('roundcubesectionheading', 'block_crucible'),
        get_string('roundcubesectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showroundcube',
        get_string('showroundcube', 'block_crucible'), get_string('configroundcubeshow', 'block_crucible'), 0, 1, 0));
    
    $settings->add(new admin_setting_configtext('block_crucible/roundcubeappurl',
        get_string('roundcubeappurl', 'block_crucible'), get_string('configroundcubeappurl', 'block_crucible'),
         "", PARAM_URL, 60));


    // Steamfitter
    $settings->add(new admin_setting_heading(
        'block_crucible/steamfittersectionheading',
        get_string('steamfittersectionheading', 'block_crucible'),
        get_string('steamfittersectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showsteamfitter',
        get_string('showsteamfitter', 'block_crucible'), get_string('configsteamfittershow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/steamfitterapiurl',
      get_string('steamfitterapiurl', 'block_crucible'), get_string('configsteamfitterapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/steamfitterappurl',
      get_string('steamfitterappurl', 'block_crucible'), get_string('configsteamfitterappurl', 'block_crucible'), "", PARAM_URL, 60));

    // Topomojo
    $settings->add(new admin_setting_heading(
        'block_crucible/topomojosectionheading',
        get_string('topomojosectionheading', 'block_crucible'),
        get_string('topomojosectiondesc', 'block_crucible')
    ));

    $settings->add(new admin_setting_configcheckbox('block_crucible/showtopomojo',
        get_string('showtopomojo', 'block_crucible'), get_string('configtopomojoshow', 'block_crucible'), 0, 1, 0));

    $settings->add(new admin_setting_configtext('block_crucible/topomojoapiurl',
      get_string('topomojoapiurl', 'block_crucible'), get_string('configtopomojoapiurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->add(new admin_setting_configtext('block_crucible/topomojoappurl',
      get_string('topomojoappurl', 'block_crucible'), get_string('configtopomojoappurl', 'block_crucible'), "", PARAM_URL, 60));

    $settings->hide_if('block_crucible/customwelcomemessage', 'block_crucible/customwelcomemessagecb', 'notchecked', 1);
}
