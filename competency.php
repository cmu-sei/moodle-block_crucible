// blocks/crucible/competency.php
<?php
require_once(__DIR__.'/../../config.php');

$idnumber  = optional_param('idnumber','', PARAM_RAW_TRIMMED);
$fwid = optional_param('fwid', 0, PARAM_INT);

require_login();
$context = context_system::instance();
$PAGE->set_context($context);

$svc = new \block_crucible\competencies();

if ($idnumber) {
    $PAGE->set_url(new moodle_url('/blocks/crucible/competency.php', ['idnumber'=>$idnumber]));
    $data = $svc->get_competency_detail_data($idnumber);

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

if ($fwid) {
    $PAGE->set_url(new moodle_url('/blocks/crucible/competency.php', ['fwid'=>$fwid]));
    $data = $svc->get_unmapped_for_framework($fwid);

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