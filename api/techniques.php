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
 * Public JSON endpoint: returns technique IDs that have mapped Moodle content.
 *
 * Usage:
 *   GET /blocks/crucible/api/techniques.php
 *       Returns {"techniques":["T1059","T1566",...]}
 *
 *   GET /blocks/crucible/api/techniques.php?ids=T1059,T1566,T1078
 *       Returns {"techniques":{"T1059":true,"T1566":true,"T1078":false}}
 *
 * No authentication required (read-only, public data).
 * CORS enabled for cross-origin requests from MISP.
 *
 * @package    block_crucible
 * @copyright  2024 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

// CORS preflight.
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(204);
    exit;
}

require_once(__DIR__ . '/../../../config.php');

// Competency API requires an authenticated user context.
\core\session\manager::set_user(get_admin());

require_once($CFG->dirroot . '/blocks/crucible/classes/competencies.php');

// Set headers after config loads (Moodle may output headers too).
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: public, max-age=300');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $svc = new \block_crucible\competencies();

    // Fetch all competencies mapped to at least one course (no limit).
    $mapped = $svc->list_mapped_courses_only(PHP_INT_MAX);
    $mapped_idnumbers = [];
    foreach ($mapped as $comp) {
        $mapped_idnumbers[$comp->idnumber] = true;
    }

    $ids = optional_param('ids', '', PARAM_RAW);

    if ($ids !== '') {
        // Filter mode: check specific technique IDs.
        $requested = array_map('trim', explode(',', $ids));
        $result = new stdClass();
        foreach ($requested as $tid) {
            if (preg_match('/^T\d{4}(?:\.\d{3})?$/', $tid)) {
                $result->$tid = isset($mapped_idnumbers[$tid]);
            }
        }
        echo json_encode(['techniques' => $result]);
    } else {
        // List mode: return all mapped technique IDs.
        echo json_encode(['techniques' => array_keys($mapped_idnumbers)]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
