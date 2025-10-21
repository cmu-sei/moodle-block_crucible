<?php
namespace block_crucible;

defined('MOODLE_INTERNAL') || die();

use context_system;
use core_user\fields;

class reports {

    public function get_cohort_roster_all(int $userid): \stdClass {
        global $DB;

        $info = (object)[
            'hascohorts'  => false,
            'cohortcount' => 0,
            'cohorts'     => [],
        ];

        // Get cohort the user is in
        $usercohortids = $this->get_user_cohort_ids($userid);
        if (empty($usercohortids)) {
            return $info;
        }

        list($cInSql, $cInParams) = $DB->get_in_or_equal($usercohortids, SQL_PARAMS_NAMED);
        $cohortRows = $DB->get_records_sql(
            "SELECT id, name FROM {cohort} WHERE id {$cInSql} ORDER BY name ASC",
            $cInParams
        );
        if (!$cohortRows) {
            return $info;
        }

        $namefields  = \core_user\fields::for_name()->get_sql('u', true, '', 'user');
        $nameselects = trim($namefields->selects);
        if ($nameselects !== '' && $nameselects[0] === ',') {
            $nameselects = ltrim($nameselects, ", \t\n\r\0\x0B");
        }

        // Get user custom fields
        $orgfieldid  = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'ssoorg'], IGNORE_MISSING) ?: 0;
        $workfieldid = (int)$DB->get_field('user_info_field', 'id', ['shortname' => 'ssoworkrole'], IGNORE_MISSING) ?: 0;

        // Hit db
        $sql = "
            SELECT
                c.id   AS cohortid,
                c.name AS cohortname,
                u.id   AS userid,
                {$nameselects},
                org.data  AS org,
                work.data AS workrole
            FROM {cohort} c
            JOIN {cohort_members} cm ON cm.cohortid = c.id
            JOIN {user} u            ON u.id = cm.userid
    LEFT JOIN {user_info_data} org  ON (org.userid  = u.id AND org.fieldid  = :orgid)
    LEFT JOIN {user_info_data} work ON (work.userid = u.id AND work.fieldid = :workid)
        WHERE c.id {$cInSql}
            AND u.deleted = 0
            AND u.confirmed = 1
        ORDER BY c.name ASC, u.lastname ASC, u.firstname ASC
        ";

        $params = array_merge($cInParams, $namefields->params, [
            'orgid'  => $orgfieldid,
            'workid' => $workfieldid,
        ]);
        $rows = $DB->get_recordset_sql($sql, $params);

        // Group rows by cohort and collect user IDs.
        $byCohort   = [];
        $allUserIds = [];

        foreach ($rows as $r) {
            $cid = (int)$r->cohortid;
            if (!isset($byCohort[$cid])) {
                $byCohort[$cid] = [
                    'id'    => $cid,
                    'name'  => (string)$r->cohortname,
                    'users' => [],
                ];
            }
            $uid = (int)$r->userid;
            if (!isset($byCohort[$cid]['users'][$uid])) {
                $user = (object)[
                    'id'           => $uid,
                    'fullname'     => fullname($r),
                    'organization' => s((string)($r->org ?? '')),
                    'workrole'     => s((string)($r->workrole ?? '')),
                    'cohortroles'  => '',
                    'profileurl'   => (new \moodle_url('/user/profile.php', ['id' => $uid]))->out(false),
                ];
                $byCohort[$cid]['users'][$uid] = $user;
                $allUserIds[$uid] = true;
            }
        }
        $rows->close();

        if (empty($byCohort)) {
            return $info;
        }

        // Fetch cohort-configured roles
        $cohortrolesByCU = [];
        {
            // Cohort IDs
            $cohortids = array_map('intval', array_keys($byCohort));
            // User IDs
            $userids = array_map('intval', array_keys($allUserIds));

            if ($cohortids && $userids) {
                list($cInSql2, $cParams2) = $DB->get_in_or_equal($cohortids, SQL_PARAMS_NAMED, 'c');
                list($uInSql2, $uParams2) = $DB->get_in_or_equal($userids,   SQL_PARAMS_NAMED, 'u');

                $sql2 = "
                    SELECT
                        tcr.cohortid,
                        tcr.userid,
                        COALESCE(NULLIF(r.name, ''), r.shortname) AS roledisplay
                    FROM {tool_cohortroles} tcr
                    JOIN {role} r ON r.id = tcr.roleid
                    WHERE tcr.cohortid {$cInSql2}
                    AND tcr.userid  {$uInSql2}
                ";
                $rows2 = $DB->get_records_sql($sql2, $cParams2 + $uParams2);

                $cohortrolesByCU = [];
                foreach ($rows2 as $row) {
                    $cohortrolesByCU[(int)$row->cohortid][(int)$row->userid][] = trim($row->roledisplay);
                }
            }
        }

        foreach ($byCohort as &$c) {
            foreach ($c['users'] as $uid => $u) {
                $cr = $cohortrolesByCU[$c['id']][$uid] ?? [];
                if ($cr) {
                    $cr = array_values(array_unique($cr));
                    sort($cr, SORT_NATURAL | SORT_FLAG_CASE);
                    $u->cohortroles = s(implode(', ', $cr));
                } else {
                    $u->cohortroles = '';
                }
            }
        }
        unset($c);



        // Build final information
        foreach ($byCohort as $cid => $c) {
            $info->cohorts[] = (object)[
                'id'       => $c['id'],
                'name'     => $c['name'],
                'hasusers' => !empty($c['users']),
                'users'    => array_values($c['users']),
            ];
        }
        $info->hascohorts  = !empty($info->cohorts);
        $info->cohortcount = count($info->cohorts);

        return $info;
    }

    private function get_user_cohort_ids(int $userid): array {
        global $CFG;
        require_once($CFG->dirroot . '/cohort/lib.php');
        $cohorts = cohort_get_user_cohorts($userid);
        return array_map('intval', array_keys($cohorts));
    }
}
