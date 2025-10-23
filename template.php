<?php
require_once(__DIR__ . '/../../config.php');

$tid = required_param('id', PARAM_INT);
require_login();

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/blocks/crucible/template.php', ['id' => $tid]));
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('learningplantitle', 'block_crucible'));
$PAGE->set_heading(format_string($SITE->fullname));
// Breadcrumbs
$PAGE->navbar->add(get_string('home'), new moodle_url('/'));
$PAGE->navbar->add(get_string('learning_plan', 'block_crucible'), $PAGE->url);

$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$svc = new \block_crucible\learningplans();

if ($action === 'selfenrol' && confirm_sesskey()) {
    try {
        $result = $svc->self_enrol_user_to_template($tid, $USER->id);
        if ($result === 'already') {
            $msg = get_string('plandalreadyexists', 'block_crucible');
            $type = \core\output\notification::NOTIFY_INFO;
        } else {
            $msg = get_string('planselfenrolled', 'block_crucible');
            $type = \core\output\notification::NOTIFY_SUCCESS;
        }
    } catch (Throwable $e) {
        debugging('LP self-enrol failed: '.$e->getMessage(), DEBUG_DEVELOPER);
        $msg = get_string('planselfenrolfailed', 'block_crucible');
        $type = \core\output\notification::NOTIFY_ERROR;
    }
    redirect(new moodle_url('/blocks/crucible/template.php', ['id' => $tid]), $msg, 2, $type);
}

// Build view data via service.
$data = $svc->get_template_view_data($tid, $USER->id, $context);

// Mark if the user already has a plan from this template.
if ($plan = $svc->get_user_plan_from_template($tid, $USER->id)) {
    $data->hasplan = true;
    $data->planurl = (new moodle_url('/admin/tool/lp/plan.php', ['id' => $plan->id]))->out(false);
    // Optional: hide enrol CTA when a plan already exists.
    $data->canselfenrol = false;
} else {
    $data->hasplan = false;
}

// Render ONCE. Nothing (no whitespace/HTML) before header or after footer.
echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_crucible/template_view', $data);
echo $OUTPUT->footer();
