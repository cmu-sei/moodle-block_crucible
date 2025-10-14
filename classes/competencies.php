<?php
namespace block_crucible;

defined('MOODLE_INTERNAL') || die();

use moodle_url;

class competencies {

    public function list_mapped_via_api(int $limit = 20): array {
        $all   = \core_competency\competency::get_records([], 'shortname', 'ASC');
        $out   = [];
        $ctx   = \context_system::instance();

        foreach ($all as $cobj) {
            if (count($out) >= $limit) {
                break;
            }

            $cid       = (int)$cobj->get('id');
            $shortname = (string)$cobj->get('shortname');
            $idnumber  = (string)$cobj->get('idnumber');

            $frameworkshort = '';
            if ($fwid = $cobj->get('competencyframeworkid')) {
                if ($fw = \core_competency\competency_framework::get_record(['id' => $fwid])) {
                    $frameworkshort = (string)$fw->get('shortname');
                }
            }

            // Courses using this competency
            $courses = \core_competency\api::list_courses_using_competency($cid);
            $coursecount = is_array($courses) ? count($courses) : 0;

            if ($coursecount === 0) {
                continue;
            }

            // Count activities across those courses
            $activitycount = 0;
            if (!empty($courses)) {
                foreach ($courses as $cs) {
                    $cmids = \core_competency\api::list_course_modules_using_competency($cid, (int)$cs->id);
                    if (is_array($cmids)) {
                        $activitycount += count($cmids);
                        if ($activitycount > 0) {
                            break;
                        }
                    }
                }
            }

            if ($activitycount === 0) {
                continue;
            }

            $out[] = (object)[
                'id'            => $cid,
                'name'          => format_string($shortname, true, ['context' => $ctx]),
                'framework'     => s($frameworkshort),
                'idnumber'      => s($idnumber),
                'coursecount'   => $coursecount,
                'activitycount' => $activitycount,
                'url'           => (new \moodle_url('/blocks/crucible/competency.php', ['idnumber' => $idnumber]))->out(false),
            ];
        }

        return $out;
    }

    public function get_view_data(int $limit = 20): \stdClass {
        $rows = $this->list_mapped_via_api($limit);

        // --- mapped grouping (unchanged) ---
        $unknown = get_string('framework_unknown', 'block_crucible');
        $buckets = [];
        foreach ($rows as $r) {
            $fname = trim((string)$r->framework) !== '' ? (string)$r->framework : $unknown;
            $buckets[$fname][] = $r;
        }
        ksort($buckets, SORT_NATURAL | SORT_FLAG_CASE);
        $groups = [];
        foreach ($buckets as $fname => $items) {
            $groups[] = (object)[
                'framework'    => $fname,
                'count'        => count($items),
                'competencies' => $items,
            ];
        }

        // --- NEW: unmapped summary per framework, linking to competency.php?fwid=... ---
        $unmappedbuckets = []; // key: framework label
        $all = \core_competency\competency::get_records([], 'shortname', 'ASC');
        $ctx = \context_system::instance();

        foreach ($all as $cobj) {
            $cid       = (int)$cobj->get('id');
            $shortname = (string)$cobj->get('shortname');
            $idnumber  = (string)$cobj->get('idnumber');

            // Resolve framework label + id
            $fwlabel = $unknown;
            $fwidval = 0;
            if ($fwid = $cobj->get('competencyframeworkid')) {
                if ($fw = \core_competency\competency_framework::get_record(['id' => $fwid])) {
                    $fwlabel = (string)$fw->get('shortname') ?: $unknown;
                    $fwidval = (int)$fw->get('id');
                }
            }

            // Counts
            $courses = \core_competency\api::list_courses_using_competency($cid);
            $coursecount = is_array($courses) ? count($courses) : 0;

            $activitycount = 0;
            if (!empty($courses)) {
                foreach ($courses as $cs) {
                    $cmids = \core_competency\api::list_course_modules_using_competency($cid, (int)$cs->id);
                    if (is_array($cmids)) {
                        $activitycount += count($cmids);
                    }
                }
            }

            // Unmapped = 0 courses OR 0 activities
            if ($coursecount === 0 || $activitycount === 0) {
                if (!isset($unmappedbuckets[$fwlabel])) {
                    $unmappedbuckets[$fwlabel] = (object)[
                        'framework' => $fwlabel,
                        'fwid'      => $fwidval,
                        'count'     => 0,
                    ];
                }
                $unmappedbuckets[$fwlabel]->count++;
            }
        }

        ksort($unmappedbuckets, SORT_NATURAL | SORT_FLAG_CASE);
        $unmapped = [];
        foreach ($unmappedbuckets as $g) {
            $unmapped[] = (object)[
                'framework' => $g->framework,
                'fwid'      => $g->fwid,
                'count'     => $g->count,
                // prebuild the link to reuse competency.php with fwid
                'url'       => (new \moodle_url('/blocks/crucible/competency.php', ['fwid' => $g->fwid]))->out(false),
            ];
        }

        return (object)[
            'hascomps'     => !empty($rows),
            'competencies' => $rows,    // kept for compatibility
            'hasgroups'    => !empty($groups),
            'groups'       => $groups,  // mapped per framework

            // New summary rows at the bottom:
            'hasunmapped'  => !empty($unmapped),
            'unmapped'     => $unmapped,
        ];
    }

    public function get_competency_detail_data(string $idnumber): \stdClass {
        global $CFG;
        require_once($CFG->dirroot.'/course/lib.php');

        $ctxsys = \context_system::instance();

        // Load competencies
        $c = \core_competency\competency::get_record(['idnumber' => $idnumber]);
        if (!$c) {
            throw new \moodle_exception('invalidrecord', 'error');
        }

        $cid      = (int)$c->get('id');
        $name     = (string)$c->get('shortname');
        $idnumber = (string)$c->get('idnumber');
        $fwshort  = '';
        if ($fwid = $c->get('competencyframeworkid')) {
            if ($fw = \core_competency\competency_framework::get_record(['id' => $fwid])) {
                $fwshort = (string)$fw->get('shortname');
            }
        }

        // Courses using this competency.
        $courses = \core_competency\api::list_courses_using_competency($cid);

        $coursecards  = [];
        $activitysets = [];

        if (!empty($courses)) {
            foreach ($courses as $cs) {
                $courseid = (int)$cs->id;
                $course   = get_course($courseid);
                $cctx     = \context_course::instance($courseid);

                $catname = '';
                if (!empty($course->category)) {
                    try {
                        $cat = \core_course_category::get($course->category, IGNORE_MISSING);
                        if ($cat) $catname = $cat->get_formatted_name();
                    } catch (\Throwable $e) {}
                }

                // Activities mapped to this competency in this course.
                $cmids   = \core_competency\api::list_course_modules_using_competency($cid, $courseid);
                $modinfo = get_fast_modinfo($courseid);
                $acts    = [];

                if (!empty($cmids)) {
                    foreach ($cmids as $cmid) {
                        $cm = $modinfo->get_cm($cmid, IGNORE_MISSING);
                        if (!$cm) continue;
                        $acts[] = (object)[
                            'name'    => $cm->get_formatted_name(),
                            'url'     => $cm->url ? $cm->url->out(false)
                                                : (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                            'modname' => $cm->modname,
                        ];
                    }
                }

                $coursecards[] = (object)[
                    'id'        => $courseid,
                    'fullname'  => format_string($course->fullname, true, ['context' => $cctx]),
                    'url'       => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                    'category'  => $catname,
                    'actcount'  => count($acts),
                ];

                if ($acts) {
                    $activitysets[] = (object)[
                        'courseid'   => $courseid,
                        'courseurl'  => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
                        'coursename' => format_string($course->fullname, true, ['context' => $cctx]),
                        'activities' => $acts,
                    ];
                }
            }
        }

        return (object)[
            'id'           => $cid,
            'name'         => format_string($name, true, ['context' => $ctxsys]),
            'idnumber'     => s($idnumber),
            'framework'    => s($fwshort),
            'hascourses'   => !empty($coursecards),
            'courses'      => $coursecards,
            'hasactivities'=> !empty($activitysets),
            'bycourse'     => $activitysets,
        ];
    }

    public function get_unmapped_for_framework(int $fwid): \stdClass {
        $unknown = get_string('framework_unknown', 'block_crucible');
        $ctx     = \context_system::instance();

        $fwrec = \core_competency\competency_framework::get_record(['id' => $fwid]);
        $fwname = $fwrec ? (string)$fwrec->get('shortname') : $unknown;

        // All comps in this framework
        $comps = \core_competency\competency::get_records(['competencyframeworkid' => $fwid], 'shortname', 'ASC');

        $items = [];
        foreach ($comps as $cobj) {
            $cid       = (int)$cobj->get('id');
            $shortname = (string)$cobj->get('shortname');
            $idnumber  = (string)$cobj->get('idnumber');

            // counts (same logic you already use)
            $courses = \core_competency\api::list_courses_using_competency($cid);
            $coursecount = is_array($courses) ? count($courses) : 0;

            $activitycount = 0;
            if (!empty($courses)) {
                foreach ($courses as $cs) {
                    $cmids = \core_competency\api::list_course_modules_using_competency($cid, (int)$cs->id);
                    if (is_array($cmids)) {
                        $activitycount += count($cmids);
                    }
                }
            }

            // unmapped = zero courses OR zero activities
            if ($coursecount === 0 || $activitycount === 0) {
                $items[] = (object)[
                    'id'       => $cid,
                    'name'     => format_string($shortname, true, ['context' => $ctx]),
                    'idnumber' => s($idnumber),
                    'url'      => (new \moodle_url('/blocks/crucible/competency.php', ['idnumber' => $idnumber]))->out(false),
                ];
            }
        }

        return (object)[
            'framework'  => s($fwname),
            'count'      => count($items),
            'hasitems'   => !empty($items),
            'items'      => $items,
        ];
    }
}
