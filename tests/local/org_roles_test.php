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
 * Unit tests for the org role reconcile.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for \block_crucible\local\org_roles.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(org_roles::class)]
final class org_roles_test extends \advanced_testcase {
    /** @var array Role shortname => roleid for the mapped roles. */
    private array $roleids = [];

    /**
     * Provision the profile fields, roles and categories the reconcile needs.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        profile_fields::install();
        org_roles::reset_caches();
        set_config('enableorgrolesync', 1, 'block_crucible');

        foreach (org_roles::group_role_map() as $group => $shortname) {
            $roleid = create_role(ucwords(str_replace('-', ' ', $shortname)), $shortname, 'Test role');
            set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
            $this->roleids[$shortname] = $roleid;
        }
    }

    /**
     * Create a top-level course category.
     *
     * @param string $name
     * @return int category id
     */
    private function create_org_category(string $name): int {
        $category = $this->getDataGenerator()->create_category(['name' => $name, 'parent' => 0]);
        org_roles::reset_caches();

        return (int)$category->id;
    }

    /**
     * Create an oauth2 user carrying the given org and group lists.
     *
     * @param string[] $orgs
     * @param string[] $groups
     * @param string $auth
     * @return int user id
     */
    private function create_sso_user(array $orgs, array $groups, string $auth = 'oauth2'): int {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $user = $this->getDataGenerator()->create_user(['auth' => $auth]);
        $this->set_sso_lists((int)$user->id, $orgs, $groups);

        return (int)$user->id;
    }

    /**
     * Write the org and group lists onto a user, in the canonical wrapped form.
     *
     * @param int $userid
     * @param string[] $orgs
     * @param string[] $groups
     */
    private function set_sso_lists(int $userid, array $orgs, array $groups): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::ORG => org_roles::join_list($orgs),
            'profile_field_' . profile_fields::GROUPS => org_roles::join_list($groups),
        ]);
    }

    /**
     * The role shortnames this plugin has granted a user in a category context.
     *
     * @param int $userid
     * @param int $categoryid
     * @return string[] sorted
     */
    private function managed_roles(int $userid, int $categoryid): array {
        global $DB;

        $context = \context_coursecat::instance($categoryid);
        $roleids = $DB->get_fieldset_select(
            'role_assignments',
            'roleid',
            'userid = ? AND contextid = ? AND component = ?',
            [$userid, $context->id, org_roles::COMPONENT]
        );

        $shortnames = array_intersect($this->roleids, array_map('intval', $roleids));
        $shortnames = array_keys($shortnames);
        sort($shortnames);

        return $shortnames;
    }

    /**
     * A user in one mapped group gets that group's role in their org category.
     */
    public function test_one_group_grants_one_role(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['lab-builders']);

        org_roles::reconcile_user($userid);

        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Several mapped groups grant every corresponding role, with no precedence.
     */
    public function test_every_mapped_group_grants_its_role(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(
            ['Demo Org'],
            ['cyber-managers', 'lab-builders', 'curriculum-developers']
        );

        org_roles::reconcile_user($userid);

        $this->assertSame(
            ['curriculum-developer', 'cyber-manager', 'lab-builder'],
            $this->managed_roles($userid, $categoryid)
        );
    }

    /**
     * A group whose name merely contains a mapped group name grants nothing.
     */
    public function test_a_group_containing_a_mapped_name_grants_nothing(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['ex-cyber-managers', 'lab-builders-readonly']);

        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * An org whose name merely contains another org's name grants only in its own category.
     */
    public function test_an_org_containing_another_org_name_grants_only_its_own(): void {
        $army = $this->create_org_category('Army');
        $reserve = $this->create_org_category('Army Reserve');
        $userid = $this->create_sso_user(['Army Reserve'], ['cyber-managers']);

        org_roles::reconcile_user($userid);

        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, $reserve));
        $this->assertSame([], $this->managed_roles($userid, $army));
    }

    /**
     * A user carrying two orgs gets their roles in both categories.
     */
    public function test_a_multi_org_user_is_granted_in_every_org(): void {
        $first = $this->create_org_category('Demo Org');
        $second = $this->create_org_category('Second Org');
        $userid = $this->create_sso_user(['Demo Org', 'Second Org'], ['lab-builders']);

        org_roles::reconcile_user($userid);

        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $first));
        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $second));
    }

    /**
     * Losing a group in Keycloak removes the role it granted, keeping the others.
     */
    public function test_a_removed_group_loses_only_its_role(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers', 'lab-builders']);
        org_roles::reconcile_user($userid);

        $this->set_sso_lists($userid, ['Demo Org'], ['lab-builders']);
        org_roles::reconcile_user($userid);

        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Clearing the org attribute in Keycloak revokes every role it granted.
     */
    public function test_a_cleared_org_revokes_everything(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers', 'lab-builders']);
        org_roles::reconcile_user($userid);

        $this->set_sso_lists($userid, [], ['cyber-managers', 'lab-builders']);
        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Renaming the org category away revokes the assignments left in it.
     */
    public function test_renaming_the_org_category_revokes_its_assignments(): void {
        global $DB;

        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers']);
        org_roles::reconcile_user($userid);
        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, $categoryid));

        $DB->set_field('course_categories', 'name', 'Renamed Org', ['id' => $categoryid]);
        org_roles::reset_caches();
        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * An assignment made by hand, without our component, is never touched.
     */
    public function test_a_manual_assignment_is_left_alone(): void {
        global $DB;

        $categoryid = $this->create_org_category('Demo Org');
        $context = \context_coursecat::instance($categoryid);
        $userid = $this->create_sso_user([], []);
        role_assign($this->roleids['cyber-manager'], $userid, $context->id);

        org_roles::reconcile_user($userid);

        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $this->roleids['cyber-manager'],
            'userid' => $userid,
            'contextid' => $context->id,
            'component' => '',
        ]));
    }

    /**
     * Turning the feature off gives back every assignment it granted.
     */
    public function test_disabling_the_feature_revokes_everything(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers']);
        org_roles::reconcile_user($userid);

        set_config('enableorgrolesync', 0, 'block_crucible');
        $revoked = org_roles::revoke_all();

        $this->assertSame(1, $revoked);
        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * A missing role shortname does not stop the other groups from syncing.
     */
    public function test_a_missing_role_does_not_block_the_others(): void {
        global $DB;

        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers', 'lab-builders']);
        $DB->delete_records('role', ['id' => $this->roleids['cyber-manager']]);

        org_roles::reconcile_user($userid);

        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Users who did not arrive through OAuth2 are out of scope.
     */
    public function test_a_non_oauth2_user_gets_nothing(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers'], 'manual');

        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * An org with no category of its own grants nothing anywhere.
     */
    public function test_an_org_without_a_category_grants_nothing(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Unknown Org'], ['cyber-managers']);

        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * users_to_reconcile() finds users by their assignments as well as their org data,
     * so a user whose org was cleared is still visited.
     */
    public function test_users_to_reconcile_includes_users_with_no_org_left(): void {
        $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers']);
        org_roles::reconcile_user($userid);

        $this->set_sso_lists($userid, [], []);

        $this->assertContains($userid, org_roles::users_to_reconcile());
    }

    /**
     * The category idnumber convention is honoured when the name does not match.
     */
    public function test_a_category_is_found_by_its_org_idnumber(): void {
        $category = $this->getDataGenerator()->create_category([
            'name' => 'Something Else',
            'idnumber' => 'org-demo-org',
            'parent' => 0,
        ]);
        org_roles::reset_caches();
        $userid = $this->create_sso_user(['Demo Org'], ['lab-builders']);

        org_roles::reconcile_user($userid);

        $this->assertSame(['lab-builder'], $this->managed_roles($userid, (int)$category->id));
    }

    /**
     * Values stored before the wrapping convention still parse.
     *
     * @param string|null $stored
     * @param string[] $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('list_provider')]
    public function test_split_list_accepts_both_conventions(?string $stored, array $expected): void {
        $this->assertSame($expected, org_roles::split_list($stored));
    }

    /**
     * Stored values and the elements they should parse to.
     *
     * @return array
     */
    public static function list_provider(): array {
        return [
            'null' => [null, []],
            'empty' => ['', []],
            'wrapped' => [',a,b,', ['a', 'b']],
            'bare csv' => ['a,b', ['a', 'b']],
            'spaced' => ['a, b', ['a', 'b']],
            'single' => ['a', ['a']],
            'duplicates' => [',a,a,b,', ['a', 'b']],
            'only delimiters' => [',,,', []],
        ];
    }

    /**
     * The canonical form wraps a non-empty list and leaves an empty one empty.
     */
    public function test_join_list_wraps_only_non_empty_lists(): void {
        $this->assertSame('', org_roles::join_list([]));
        $this->assertSame(',a,', org_roles::join_list(['a']));
        $this->assertSame(',a,b,', org_roles::join_list(['a', 'b']));
        $this->assertSame(',a,b,', org_roles::join_list(['a', 'b', 'a']));
    }

    /**
     * A value containing the delimiter is dropped, not split into elements that match nothing.
     *
     * "Acme, Inc." is one organization. Storing it as ",Acme,Inc.," would silently turn it
     * into two, neither of which resolves to a category or a cohort rule.
     */
    public function test_join_list_drops_values_containing_the_delimiter(): void {
        $this->assertSame(',Army,', org_roles::join_list(['Army', 'Acme, Inc.']));
        $this->assertDebuggingCalled();

        $this->assertSame('', org_roles::join_list(['Acme, Inc.']));
        $this->assertDebuggingCalled();
    }

    /**
     * Each array element stays whole, so a multi-valued Keycloak attribute still works.
     */
    public function test_join_list_keeps_separate_values_separate(): void {
        $this->assertSame(',Demo Org,Second Org,', org_roles::join_list(['Demo Org', 'Second Org']));
    }
}
