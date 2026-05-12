<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Learning plan template display.
 *
 * @package    block_crucible
 * @copyright  2025 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$tid = required_param('id', PARAM_INT);
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/crucible/template.php', ['id' => $tid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('learningplantitle', 'block_crucible'));
$PAGE->set_heading(format_string($SITE->fullname));

$PAGE->navbar->add(get_string('home'), new moodle_url('/'));
$PAGE->navbar->add(get_string('learning_plan', 'block_crucible'), $PAGE->url);

$action  = optional_param('action', '', PARAM_ALPHANUMEXT);
$fwshort = optional_param('fw', '', PARAM_ALPHANUMEXT);

$svc = new \block_crucible\learningplans();

global $DB;

$fwid   = 0;
$fwname = '';

if ($fwshort !== '') {
    if ($fwrec = $DB->get_record('competency_framework', ['shortname' => $fwshort], 'id, shortname', IGNORE_MISSING)) {
        $fwid = (int)$fwrec->id;
        $fwname = $fwrec->shortname;
    } else if (ctype_digit($fwshort)) {
        if ($fwrec = $DB->get_record('competency_framework', ['id' => (int)$fwshort], 'id, shortname', IGNORE_MISSING)) {
            $fwid = (int)$fwrec->id;
            $fwname = $fwrec->shortname;
            $fwshort = $fwrec->shortname;
        }
    }
}

if ($action === 'selfenrol' && confirm_sesskey()) {
    try {
        $result = $svc->self_enrol_user_to_template($tid, $USER->id);
        if ($result === 'already') {
            $msg  = get_string('plandalreadyexists', 'block_crucible');
            $type = \core\output\notification::NOTIFY_INFO;
        } else {
            $msg  = get_string('planselfenrolled', 'block_crucible');
            $type = \core\output\notification::NOTIFY_SUCCESS;
        }
    } catch (Throwable $e) {
        debugging('LP self-enrol failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        $msg  = get_string('planselfenrolfailed', 'block_crucible');
        $type = \core\output\notification::NOTIFY_ERROR;
    }
    $redir = ['id' => $tid] + ($fwshort !== '' ? ['fw' => $fwshort] : []);
    redirect(new moodle_url('/blocks/crucible/template.php', $redir), $msg, 2, $type);
}

$data = $svc->get_template_view_data($tid, $USER->id, $context, $fwid);

$data->frameworkshortname = $fwname;

if ($plan = $svc->get_user_plan_from_template($tid, $USER->id)) {
    $data->hasplan = true;
    $data->planurl = (new moodle_url('/admin/tool/lp/plan.php', ['id' => $plan->id]))->out(false);
    $data->canselfenrol = false;
} else {
    $data->hasplan = false;
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_crucible/template_view', $data);
echo $OUTPUT->footer();
