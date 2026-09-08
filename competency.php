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
 * Competency lookup endpoint.
 *
 * @package    block_crucible
 * @copyright  2025 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$idnumber  = optional_param('idnumber', '', PARAM_RAW_TRIMMED);
$fwid = optional_param('fwid', 0, PARAM_INT);
$framework = optional_param('framework', '', PARAM_RAW_TRIMMED);

require_login();
$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/crucible/competency.php'));

$svc = new \block_crucible\competencies();

// Resolve framework shortname to ID if provided.
$frameworkid = null;
if ($framework !== '') {
    $fw = \core_competency\competency_framework::get_record(['shortname' => $framework]);
    if (!$fw) {
        throw new moodle_exception('invalidcompetencyframework', 'block_crucible');
    }
    $frameworkid = (int)$fw->get('id');
} else if ($fwid > 0) {
    $fw = \core_competency\competency_framework::get_record(['id' => $fwid]);
    if (!$fw) {
        throw new moodle_exception('invalidcompetencyframework', 'block_crucible');
    }
    $frameworkid = (int)$fw->get('id');
}

if ($idnumber) {
    // When no framework was supplied and the idnumber is shared across frameworks
    // (e.g. ATT&CK T1005 vs NICE T1005), show each framework's competency in full
    // under its own header instead of silently resolving to an arbitrary one.
    if ($frameworkid === null) {
        $matches = $svc->get_idnumber_matches($idnumber);
        if (count($matches) > 1) {
            $groups = [];
            foreach ($matches as $m) {
                $detail = $svc->get_competency_detail_data($idnumber, $m->fwid);
                $groups[] = (object)[
                    'framework'     => $detail->framework,
                    // competency_view.mustache is rendered per group, so it needs the
                    // competency name as its card title.
                    'cardtitle'     => $detail->name,
                    'idnumber'      => $detail->idnumber,
                    'hascourses'    => $detail->hascourses,
                    'courses'       => $detail->courses,
                    'hasactivities' => $detail->hasactivities,
                    'bycourse'      => $detail->bycourse,
                ];
            }

            $PAGE->set_url(new moodle_url('/blocks/crucible/competency.php', ['idnumber' => $idnumber]));
            $PAGE->set_title($idnumber);
            $PAGE->set_heading(format_string($SITE->fullname));
            $PAGE->navbar->add(get_string('home'), new moodle_url('/'));
            $PAGE->navbar->add(get_string('col_competency', 'block_crucible'), $PAGE->url);

            echo $OUTPUT->header();
            echo $OUTPUT->render_from_template('block_crucible/competency_disambiguation', (object)[
                'cardtitle' => $idnumber,
                'idnumber'  => $idnumber,
                'groups'    => $groups,
            ]);
            echo $OUTPUT->footer();
            exit;
        }
    }

    $urlparams = ['idnumber' => $idnumber];
    if ($framework !== '') {
        $urlparams['framework'] = $framework;
    }
    $PAGE->set_url(new moodle_url('/blocks/crucible/competency.php', $urlparams));
    $data = $svc->get_competency_detail_data($idnumber, $frameworkid);

    $PAGE->set_title($data->name);
    $PAGE->set_heading(format_string($SITE->fullname));

    // Breadcrumbs
    $PAGE->navbar->add(get_string('home'), new moodle_url('/'));
    $PAGE->navbar->add(get_string('col_competency', 'block_crucible'), $PAGE->url);

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('block_crucible/competency_view', (object)[
        'cardtitle'     => $data->name,
        'idnumber'      => $data->idnumber,
        'framework'     => $data->framework,
        'hascourses'    => $data->hascourses,
        'courses'       => $data->courses,
        'hasactivities' => $data->hasactivities,
        'bycourse'      => $data->bycourse,
    ]);
    echo $OUTPUT->footer();
    exit;
}

if ($frameworkid !== null) {
    $PAGE->set_url(new moodle_url('/blocks/crucible/competency.php', ['fwid' => $frameworkid]));
    $data = $svc->get_unmapped_for_framework($frameworkid);

    $PAGE->set_title(get_string('unmapped_for_framework_title', 'block_crucible', $data->framework));
    $PAGE->set_heading(format_string($SITE->fullname));
    // Breadcrumbs
    $PAGE->navbar->add(get_string('home'), new moodle_url('/'));
    $PAGE->navbar->add(get_string('framework', 'block_crucible'), $PAGE->url);

    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('block_crucible/framework_unmapped', (object)[
        'framework' => $data->framework,
        'count'     => $data->count,
        'hasitems'  => $data->hasitems,
        'items'     => $data->items,
    ]);
    echo $OUTPUT->footer();
    exit;
}

throw new moodle_exception('missingcompetencyparams', 'block_crucible');
