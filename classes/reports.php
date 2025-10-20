<?php
namespace block_crucible;

defined('MOODLE_INTERNAL') || die();

use core_reportbuilder\manager as rb_manager;
use core_reportbuilder\permission as rb_permission;

class reports {

    public function render_for_user(?\stdClass $config, int $userid): string {
        $reportids = $this->find_reportids_from_cohort_audiences($userid);
        if (empty($reportids)) {
            debugging('reports: no reports matched by cohort audiences', DEBUG_DEVELOPER);
            return '';
        }
        foreach ($reportids as $rid) {
            try {
                $report = rb_manager::get_report_from_id($rid);
                if (!rb_permission::can_view_report($report->get_report_persistent())) {
                    debugging("reports: cannot view report id={$rid} (audience mismatch?)", DEBUG_DEVELOPER);
                    continue;
                }

                $html = $this->render_report_html($report);
                if ($html !== '') {
                    debugging("reports: rendering report id={$rid} from audience lookup", DEBUG_DEVELOPER);
                    return $html;
                } else {
                    debugging("reports: report id={$rid} failed to render (broken columns?)", DEBUG_DEVELOPER);
                }
            } catch (\Throwable $e) {
                debugging("reports: load failed for id={$rid}: " . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return '';
    }

    // Read reportbuilder_audience for cohort-member audiences and match against user's cohort IDs.
    private function find_reportids_from_cohort_audiences(int $userid): array {
        global $DB;

        $usercohortids = $this->get_user_cohort_ids($userid);
        if (empty($usercohortids)) {
            debugging('reports: user has no cohorts', DEBUG_DEVELOPER);
            return [];
        }

        // Only rows for the cohort-member audience class.
        $rows = $DB->get_records(
            'reportbuilder_audience',
            ['classname' => 'core_cohort\\reportbuilder\\audience\\cohortmember'],
            'timecreated ASC',
            'id, reportid, configdata'
        );

        $matched = [];
        foreach ($rows as $row) {
            $conf = json_decode($row->configdata ?? '', true);
            $cfgcohorts = array_map('intval', $conf['cohorts'] ?? []);
            if (!$cfgcohorts) {
                continue;
            }
            if (array_intersect($cfgcohorts, $usercohortids)) {
                $matched[] = (int)$row->reportid;
            }
        }
        $matched = array_values(array_unique($matched));
        debugging('reports: audience-matched report ids = ' . json_encode($matched), DEBUG_DEVELOPER);
        return $matched;
    }

    // Get the user's system-level cohort IDs.
    private function get_user_cohort_ids(int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $cohorts = cohort_get_user_cohorts($userid);
        return array_map('intval', array_keys($cohorts));
    }

    // Render a Report builder report to HTML (defensive against broken configs).
    public function render_report_html(\core_reportbuilder\local\report\base $report, ?\stdClass $config = null): string {
        global $PAGE;

        try {
            $outputpage = new \core_reportbuilder\output\custom_report($report->get_report_persistent(), false);
            $output = $PAGE->get_renderer('core_reportbuilder');
            $export = $outputpage->export_for_template($output);
            return $output->render_from_template('core_reportbuilder/report', $export);
        } catch (\Throwable $e) {
            $rid = 0;
            try {
                $rid = (int)$report->get_report_persistent()->get('id');
            } catch (\Throwable $ignored) {
            }
            debugging("reports: render failed for report id={$rid}: " . $e->getMessage(), DEBUG_DEVELOPER);
            return '';
        }
    }
}
