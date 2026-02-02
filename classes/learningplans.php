<?php
namespace block_crucible;

defined('MOODLE_INTERNAL') || die();

use core_text;
use moodle_url;

class learningplans {

    public function get_user_workrole_string(int $userid): ?string {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $custom = profile_user_record($userid, false);

        // Try your intended field first, then the WRL you showed in session dump.
        if (!empty($custom->ssoworkrole)) { return (string)$custom->ssoworkrole; }
        return null;
    }

    /**
     * Fetch learning plan templates. Uses API if present; otherwise queries
     * the 'competency_template' table (which has: id, shortname, description...).
     */
    protected function list_templates(?int $frameworkid = null): array {
        global $DB;

        // If a framework is specified, pull only templates that have at least one comp in it.
        if (!empty($frameworkid)) {
            $rows = $DB->get_records_sql("
                SELECT DISTINCT t.id, t.shortname, t.description, t.visible, t.contextid
                FROM {competency_template} t
                JOIN {competency_templatecomp} tc ON tc.templateid = t.id
                JOIN {competency} c ON c.id = tc.competencyid
                WHERE c.competencyframeworkid = :fwid
                ORDER BY t.shortname ASC",
                ['fwid' => $frameworkid]
            );

            return array_map(function($t) {
                return (object)[
                    'id'        => (int)$t->id,
                    'shortname' => (string)$t->shortname,
                    'description' => (string)($t->description ?? ''),
                    'visible'   => (int)($t->visible ?? 1),
                    'contextid' => (int)($t->contextid ?? 1),
                ];
            }, $rows ?: []);
        }

        // No framework selected
        if (class_exists('\core_competency\template')) {
            $records = \core_competency\template::get_records([], 'shortname', 'ASC');
            return array_map(function($t) {
                return (object)[
                    'id'        => (int)$t->get('id'),
                    'shortname' => (string)$t->get('shortname'),
                    'description' => (string)$t->get('description'),
                    'visible'   => (int)$t->get('visible'),
                    'contextid' => (int)$t->get('contextid'),
                ];
            }, $records);
        }

        return [];
    }

    /**
     * Suggest templates by token-matching user's work role to template fields.
     */
    public function suggest_templates_for_user(int $userid, int $limit = 8, ?int $frameworkid = null): array {
        global $DB;

        $role = $this->get_user_workrole_string($userid);
        if (!$role) { return []; }

        $tokens = preg_split('/[^\p{L}\p{N}\+]+/u', core_text::strtolower($role), -1, PREG_SPLIT_NO_EMPTY);
        if (!$tokens) { return []; }

        $fwshort = '';
        if (!empty($frameworkid)) {
            $fwshort = (string)$DB->get_field('competency_framework', 'shortname', ['id' => $frameworkid]) ?: '';
        }

        // Filtered templates
        $templates = $this->list_templates($frameworkid);
        if (!$templates) { return []; }

        $matches = [];
        foreach ($templates as $t) {
            $display = $t->shortname ?? 'Template';
            $desc    = isset($t->description) ? core_text::strtolower(strip_tags($t->description)) : '';
            $hay     = core_text::strtolower($display.' '.$desc);

            $score = 0;
            foreach ($tokens as $tok) {
                if (core_text::strlen($tok) < 3) { continue; }
                if (mb_stripos($hay, $tok) !== false) { $score++; }
            }

            if ($score > 0) {
                $params = ['id' => $t->id];
                if ($fwshort !== '') {
                    $params['fw'] = $fwshort;
                }
                $matches[] = (object)[
                    'id'        => (int)$t->id,
                    'name'      => $display,
                    'shortname' => $t->shortname ?? '',
                    'score'     => $score,
                    'url'       => (new moodle_url('/blocks/crucible/template.php', $params))->out(false),
                ];
            }
        }

        usort($matches, fn($a,$b) => $b->score <=> $a->score);
        $matches = array_slice($matches, 0, $limit);

        $counts = $this->counts_for_templates(array_map(fn($m) => (int)$m->id, $matches));
        foreach ($matches as $m) {
            $m->coursecount   = $counts[$m->id]['courses']    ?? 0;
            $m->activitycount = $counts[$m->id]['activities'] ?? 0;
        }
        return $matches;
    }

     public function self_enrol_user_to_template(int $templateid, int $userid): string {
        global $DB;

        // Already has a plan?
        $exists = $DB->record_exists('competency_plan', [
            'userid' => $userid, 'templateid' => $templateid
        ]);
        if ($exists) {
            return 'already';
        }

        $apiclass = class_exists('\tool_lp\api')
            ? '\tool_lp\api'
            : (class_exists('\core_competency\api') ? '\core_competency\api' : null);

        if (!$apiclass) {
            throw new \moodle_exception('competencyapimissing', 'error');
        }

        // Create.
        $plan = $apiclass::create_plan_from_template($templateid, $userid);
        $planid = is_object($plan) ? $plan->get('id') : (int)$plan;

        // Activate if available; otherwise set status to Active.
        if (method_exists($apiclass, 'activate_plan')) {
            $apiclass::activate_plan($planid);
        } else {
            $DB->set_field('competency_plan', 'status', 1, ['id' => $planid]);
        }
        return 'created';
    }

    public function get_template_view_data(int $templateid, int $userid, \context $context, int $frameworkid = 0): \stdClass {
        global $DB, $CFG, $SITE, $USER;
        require_once($CFG->dirroot . '/course/lib.php');

        // 1) Template via API (visibility etc.).
        $template = \core_competency\template::get_record(['id' => $templateid]);
        if (!$template || !$template->get('visible')) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        $fwsql = '';
        $params = ['tid' => $templateid];
        if ($frameworkid > 0) {
            $fwsql = ' AND c.competencyframeworkid = :fwid ';
            $params['fwid'] = $frameworkid;
        }

        $comps = $DB->get_records_sql("
            SELECT
                c.id, c.shortname, c.idnumber, c.description, c.descriptionformat,
                c.parentid,
                p.shortname AS parentname,
                cf.shortname AS frameworkshortname,
                tc.sortorder
            FROM {competency_templatecomp} tc
            JOIN {competency} c                 ON c.id = tc.competencyid
            LEFT JOIN {competency} p            ON p.id = c.parentid
            LEFT JOIN {competency_framework} cf ON cf.id = c.competencyframeworkid
            WHERE tc.templateid = :tid
              $fwsql
            ORDER BY tc.sortorder, c.shortname
        ", $params);

        // Early exit if no rows.
        if (empty($comps)) {
            $templatedesc = format_text(
                (string)$template->get('description'),
                (int)$template->get('descriptionformat'),
                ['context' => $context]
            );
            $alreadyhas = $DB->record_exists('competency_plan', ['userid' => $userid, 'templateid' => $templateid]);

            return (object)[
                'templateid'   => (int)$template->get('id'),
                'shortname'    => format_string($template->get('shortname'), true, ['context' => $context]),
                'description'  => $templatedesc,
                'hascomps'     => false,
                'competencies' => [],
                'compgroups'   => [],
                'canselfenrol' => !$alreadyhas,
                'selfenrolurl' => (new \moodle_url('/blocks/crucible/template.php', [
                    'id' => $templateid, 'action' => 'selfenrol', 'sesskey' => sesskey()
                ]))->out(false),
            ];
        }

        // 3) Build items + course/activity mappings (unchanged logic).
        $items = [];
        $coursecache = [];

        foreach ($comps as $c) {
            $cid = (int)$c->id;

            // Courses using this competency.
            $courseSummaries = \core_competency\api::list_courses_using_competency($cid);
            $courseitems = [];

            foreach ($courseSummaries as $cs) {
                $courseid = (int)$cs->id;

                if (!isset($coursecache[$courseid])) {
                    $coursecache[$courseid] = get_course($courseid);
                }
                $course = $coursecache[$courseid];
                $cctx   = \context_course::instance($course->id);

                // Activities mapped to this competency in this course.
                $cmids   = \core_competency\api::list_course_modules_using_competency($cid, $courseid);
                $modinfo = get_fast_modinfo($courseid);
                $acts = [];
                foreach ($cmids as $cmid) {
                    if ($cm = $modinfo->get_cm($cmid, IGNORE_MISSING)) {
                        $acts[] = (object)[
                            'name' => $cm->get_formatted_name(),
                            'url'  => $cm->url
                                ? $cm->url->out(false)
                                : (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                        ];
                    }
                }

                $courseitems[] = (object)[
                    'id'         => (int)$course->id,
                    'fullname'   => format_string($course->fullname, true, ['context' => $cctx]),
                    'shortname'  => $course->shortname ?? '',
                    'url'        => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
                    'actcount'   => count($acts),
                    'hasacts'    => !empty($acts),
                    'activities' => $acts,
                ];
            }

            // Decide the group label: parent shortname if exists; otherwise framework shortname or "Ungrouped".
            $parentid   = (int)($c->parentid ?? 0);
            $parentname = $c->parentname ?? '';
            $grouplabel = $parentid
                ? format_string($parentname, true, ['context' => $context])
                : (!empty($c->frameworkshortname)
                    ? format_string($c->frameworkshortname, true, ['context' => $context])
                    : get_string('ungrouped', 'block_crucible'));

            $items[] = (object)[
                'id'         => $cid,
                'shortname'  => format_string($c->shortname, true, ['context' => $context]),
                'idnumber'   => $c->idnumber ?? '',
                'framework'  => $c->frameworkshortname ?? '',
                'desc'       => format_text((string)$c->description, (int)$c->descriptionformat, ['context' => $context]),
                'hascourses' => !empty($courseitems),
                'courses'    => $courseitems,

                // for grouping:
                'parentid'   => $parentid,
                'parentname' => $grouplabel,
            ];
        }

        // 4) Group by parentid → compgroups for collapsible sections.
        $byparent = [];
        foreach ($items as $it) {
            $pid = $it->parentid ?: 0;
            if (!isset($byparent[$pid])) {
                $byparent[$pid] = [
                    'groupname' => $it->parentname ?: get_string('ungrouped', 'block_crucible'),
                    'items'     => [],
                ];
            }
            $byparent[$pid]['items'][] = $it;
        }

        // Sort groups by label; sort items by idnumber then shortname (natural, case-insensitive).
        uasort($byparent, function($a, $b) {
            return strnatcasecmp($a['groupname'], $b['groupname']);
        });

        $compgroups = [];
        foreach ($byparent as $g) {
            usort($g['items'], function($a, $b) {
                $ka = $a->idnumber ?? $a->shortname ?? '';
                $kb = $b->idnumber ?? $b->shortname ?? '';
                $cmp = strnatcasecmp($ka, $kb);
                if ($cmp !== 0) return $cmp;
                return strnatcasecmp($a->shortname ?? '', $b->shortname ?? '');
            });
            $compgroups[] = (object)[
                'groupname' => $g['groupname'],
                'count'     => count($g['items']),
                'items'     => $g['items'],
            ];
        }

        // 5) Header/desc + enrol bits.
        $templatedesc = format_text(
            (string)$template->get('description'),
            (int)$template->get('descriptionformat'),
            ['context' => $context]
        );
        $alreadyhas = $DB->record_exists('competency_plan', ['userid' => $userid, 'templateid' => $templateid]);

        return (object)[
            'templateid'   => (int)$template->get('id'),
            'shortname'    => format_string($template->get('shortname'), true, ['context' => $context]),
            'description'  => $templatedesc,
            'hascomps'     => !empty($compgroups),
            'compgroups'   => $compgroups,
            'competencies' => $items,
            'canselfenrol' => !$alreadyhas,
            'selfenrolurl' => (new \moodle_url('/blocks/crucible/template.php', [
                'id' => $templateid, 'action' => 'selfenrol', 'sesskey' => sesskey()
            ]))->out(false),
        ];
    }

    protected function counts_for_templates(array $templateids): array {
        global $DB;
        if (empty($templateids)) {
            return [];
        }
        list($insql, $inparams) = $DB->get_in_or_equal($templateids, SQL_PARAMS_NAMED);

        // Distinct counts across all competencies in each template.
        $sql = "
            SELECT tc.templateid,
                COUNT(DISTINCT cc.courseid) AS coursecount,
                COUNT(DISTINCT mc.cmid)     AS activitycount
            FROM {competency_templatecomp} tc
        LEFT JOIN {competency_coursecomp}  cc ON cc.competencyid = tc.competencyid
        LEFT JOIN {competency_modulecomp}  mc ON mc.competencyid = tc.competencyid
            WHERE tc.templateid $insql
        GROUP BY tc.templateid
        ";

        $rows = $DB->get_records_sql($sql, $inparams);

        $out = [];
        foreach ($rows as $r) {
            $out[(int)$r->templateid] = [
                'courses'    => (int)$r->coursecount,
                'activities' => (int)$r->activitycount,
            ];
        }
        return $out;
    }

    public function get_user_plan_from_template(int $templateid, int $userid) : ?\stdClass {
        global $DB;
        // Get the most recent matching plan (if duplicates exist).
        $plans = $DB->get_records(
            'competency_plan',
            ['templateid' => $templateid, 'userid' => $userid],
            'timecreated DESC', // newest first
            '*',
            0, 1
        );
        return $plans ? reset($plans) : null;
    }
}
