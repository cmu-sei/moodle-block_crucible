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
     * A value the upgrade preserved grants nothing in the categories its fragments name.
     *
     * The 2026092300 upgrade leaves "Acme, Holdings" exactly as it is, because splitting it is
     * irreversible and it is more likely one name than two. If the reconcile then split it,
     * a user whose organization is "Acme, Holdings" would be granted roles in a category called
     * "Acme" or "Holdings" - real organizations they have nothing to do with. Granting nothing is
     * the safe reading of an ambiguous value.
     */
    public function test_a_preserved_legacy_value_grants_nothing_in_its_fragments(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $acme = $this->create_org_category('Acme');
        $holdings = $this->create_org_category('Holdings');
        $userid = $this->create_sso_user([], ['cyber-managers']);
        // Written the way the upgrade leaves it: unwrapped, comma and space intact.
        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::ORG => 'Acme, Holdings',
        ]);

        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $acme));
        $this->assertSame([], $this->managed_roles($userid, $holdings));
    }

    /**
     * A preserved value does resolve against a category named exactly that.
     *
     * Not the point of the change, but worth pinning: reading the value whole is what makes
     * this possible at all, and it is the correct outcome - the administrator named the
     * category after the organization. One deployment has a category named this way that
     * nothing could previously match.
     */
    public function test_a_preserved_legacy_value_matches_a_category_named_exactly_that(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $categoryid = $this->create_org_category('Acme, Holdings');
        $userid = $this->create_sso_user([], ['cyber-managers']);
        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::ORG => 'Acme, Holdings',
        ]);

        org_roles::reconcile_user($userid);

        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * A bare comma separated list still splits, so the OAuth 2 mapping path keeps working.
     *
     * That mapping writes the raw claim unwrapped at every login, and in one deployment it
     * is the only writer of ssogroups. Reading every unwrapped value whole would stop a
     * multi-group user matching any group and revoke the roles of the most privileged
     * accounts on the site.
     */
    public function test_a_bare_group_list_still_splits(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], []);
        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::GROUPS => 'cyber-managers,lab-builders',
        ]);

        org_roles::reconcile_user($userid);

        $this->assertSame(['cyber-manager', 'lab-builder'], $this->managed_roles($userid, $categoryid));
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
            // A bare list still splits: the OAuth 2 login field mapping writes the raw
            // claim unwrapped at every login, so this is not only a pre-upgrade form.
            'bare csv' => ['a,b', ['a', 'b']],
            // A comma followed by a space is the upgrade's marker for "one name that happens
            // to contain a comma", and join_list() can never produce it, so it is read whole.
            'spaced' => ['a, b', ['a, b']],
            'preserved legacy org' => ['Acme, Holdings', ['Acme, Holdings']],
            'spaced inside a longer list' => ['Globex, Ltd.', ['Globex, Ltd.']],
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

    /**
     * unstorable_values() tells a caller which values join_list() will not store.
     *
     * Without it, "" back from join_list() is ambiguous: no values, or no storable ones.
     * A caller that stores the result has to be able to tell those apart, because writing
     * "" over a real organization destroys it.
     */
    public function test_unstorable_values_names_the_values_that_cannot_be_stored(): void {
        $this->assertSame([], org_roles::unstorable_values([]));
        $this->assertSame([], org_roles::unstorable_values(['Demo Org', 'Second Org']));
        $this->assertSame(['Acme, Inc.'], org_roles::unstorable_values(['Army', 'Acme, Inc.']));
        $this->assertSame(['Acme, Inc.'], org_roles::unstorable_values([' Acme, Inc. ']));
        $this->assertSame(
            ['A,B', 'C,D'],
            org_roles::unstorable_values(['A,B', 'Fine', 'C,D'])
        );
    }

    /**
     * An all-unstorable list is distinguishable from an empty one, which is the whole point.
     */
    public function test_an_all_unstorable_list_is_distinguishable_from_an_empty_one(): void {
        $this->assertSame('', org_roles::join_list(['Acme, Inc.']));
        $this->assertDebuggingCalled();
        $this->assertSame(['Acme, Inc.'], org_roles::unstorable_values(['Acme, Inc.']));

        $this->assertSame('', org_roles::join_list([]));
        $this->assertSame([], org_roles::unstorable_values([]));
    }

    /**
     * Configure the alias map the way an administrator or a deployment script would.
     *
     * @param array<string, string> $pairs org value => category name or idnumber
     */
    private function set_aliases(array $pairs): void {
        $lines = [];
        foreach ($pairs as $org => $target) {
            $lines[] = $org . '|' . $target;
        }
        set_config('orgcategoryaliases', implode("\n", $lines), 'block_crucible');
        org_roles::reset_caches();
    }

    /**
     * An alias resolves an org whose name matches no category at all.
     *
     * This is the case the whole mechanism exists for: a deployment whose categories neither
     * share a name with the organization nor carry org- idnumbers resolves nothing without it.
     */
    public function test_an_alias_resolves_an_org_with_no_matching_category(): void {
        $categoryid = $this->create_org_category('Acme');
        $this->set_aliases(['Globex Holdings' => 'Acme']);
        $userid = $this->create_sso_user(['Globex Holdings'], ['cyber-managers']);

        $resolved = org_roles::resolve_org('Globex Holdings');
        org_roles::reconcile_user($userid);

        $this->assertSame($categoryid, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIAS, $resolved['how']);
        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * An alias may name its category by ID number instead.
     */
    public function test_an_alias_resolves_a_category_by_idnumber(): void {
        $category = $this->getDataGenerator()->create_category([
            'name' => 'Acme',
            'parent' => 0,
            'idnumber' => 'acme-9',
        ]);
        $this->set_aliases(['Globex Holdings' => 'acme-9']);

        $resolved = org_roles::resolve_org('Globex Holdings');

        $this->assertSame((int)$category->id, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIAS, $resolved['how']);
    }

    /**
     * An alias is explicit instruction, so it beats both conventions.
     */
    public function test_an_alias_wins_over_a_category_of_the_same_name(): void {
        $samename = $this->create_org_category('Globex Holdings');
        $aliased = $this->create_org_category('Acme');
        $this->set_aliases(['Globex Holdings' => 'Acme']);

        $resolved = org_roles::resolve_org('Globex Holdings');

        $this->assertSame($aliased, $resolved['categoryid']);
        $this->assertNotSame($samename, $resolved['categoryid']);
    }

    /**
     * An alias may point at a category that is not top level.
     *
     * The conventions are guesses and stay restricted to the top level, but an administrator
     * naming a category explicitly has already made the decision. This is what makes the
     * separate "which parent holds the orgs" setting unnecessary.
     */
    public function test_an_alias_may_name_a_category_below_the_top_level(): void {
        $parent = $this->create_org_category('Organizations');
        $child = $this->getDataGenerator()->create_category(['name' => 'Acme', 'parent' => $parent]);
        $this->set_aliases(['Globex Holdings' => 'Acme']);

        $resolved = org_roles::resolve_org('Globex Holdings');

        $this->assertSame((int)$child->id, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIAS, $resolved['how']);
    }

    /**
     * An alias naming a category that does not exist is distinguishable from no alias.
     *
     * Both grant nothing, but only one of them is a typo in the configuration, and the
     * settings report has to be able to say which.
     */
    public function test_an_alias_pointing_at_nothing_is_reported_as_such(): void {
        global $DB;

        $this->create_org_category('Acme');
        $this->set_aliases(['Globex Holdings' => 'No Such Category']);
        $userid = $this->create_sso_user(['Globex Holdings'], ['cyber-managers']);

        $resolved = org_roles::resolve_org('Globex Holdings');
        org_roles::reconcile_user($userid);

        $this->assertNull($resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIASMISSING, $resolved['how']);
        $this->assertSame(0, $DB->count_records('role_assignments', [
            'userid' => $userid,
            'component' => org_roles::COMPONENT,
        ]));
    }

    /**
     * Two top-level categories of the same name grant nothing instead of one at random.
     *
     * IGNORE_MULTIPLE used to pick one, so a user could be granted roles in a category
     * belonging to a different organization with nothing logged.
     */
    public function test_an_ambiguous_category_name_grants_nothing(): void {
        $first = $this->create_org_category('Acme');
        $this->create_org_category('Acme');
        $userid = $this->create_sso_user(['Acme'], ['cyber-managers']);

        $resolved = org_roles::resolve_org('Acme');
        org_roles::reconcile_user($userid);

        $this->assertNull($resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_AMBIGUOUS, $resolved['how']);
        $this->assertSame([], $this->managed_roles($userid, $first));
    }

    /**
     * An alias rescues an ambiguous name, which is the remedy the report suggests.
     */
    public function test_an_alias_resolves_an_otherwise_ambiguous_name(): void {
        $this->create_org_category('Acme');
        $this->create_org_category('Acme');
        $wanted = $this->getDataGenerator()->create_category([
            'name' => 'Acme',
            'parent' => 0,
            'idnumber' => 'acme-real',
        ]);
        $this->set_aliases(['Acme' => 'acme-real']);

        $resolved = org_roles::resolve_org('Acme');

        $this->assertSame((int)$wanted->id, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIAS, $resolved['how']);
    }

    /**
     * The stable key beats the renameable label.
     */
    public function test_an_idnumber_match_beats_a_name_match(): void {
        $named = $this->create_org_category('Acme');
        $stamped = $this->getDataGenerator()->create_category([
            'name' => 'Something Else',
            'parent' => 0,
            'idnumber' => 'org-acme',
        ]);
        org_roles::reset_caches();

        $resolved = org_roles::resolve_org('Acme');

        $this->assertSame((int)$stamped->id, $resolved['categoryid']);
        $this->assertNotSame($named, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_IDNUMBER, $resolved['how']);
    }

    /**
     * An org that matches nothing says so, and grants nothing.
     */
    public function test_an_unmatched_org_is_reported_as_unmatched(): void {
        $resolved = org_roles::resolve_org('Globex Holdings');

        $this->assertNull($resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_UNMATCHED, $resolved['how']);
    }

    /**
     * An alias matches an organization whose name contains the delimiter.
     *
     * The reason the alias is keyed on the whole field and looked up before it is split.
     * Matching per split element would see "Acme" and "Holdings" and never the configured
     * key, so exactly the values that need an alias would be the ones it could not reach.
     */
    public function test_an_alias_matches_an_org_name_containing_the_delimiter(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $categoryid = $this->create_org_category('Acme Holdings Group');
        $this->set_aliases(['Acme, Holdings' => 'Acme Holdings Group']);
        $userid = $this->create_sso_user([], ['cyber-managers']);
        // Stored the way the upgrade preserved it: unwrapped, comma and space intact.
        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::ORG => 'Acme, Holdings',
        ]);

        org_roles::reconcile_user($userid);

        $this->assertSame(['Acme, Holdings'], org_roles::org_values('Acme, Holdings'));
        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Without an alias the same value still resolves to nothing rather than to its fragments.
     */
    public function test_an_unaliased_delimiter_name_still_grants_nothing(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $acme = $this->create_org_category('Acme');
        $userid = $this->create_sso_user([], ['cyber-managers']);
        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::ORG => 'Acme, Holdings',
        ]);

        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $acme));
    }

    /**
     * The report lists an aliased delimiter-containing name once, not as two fragments.
     */
    public function test_distinct_orgs_counts_an_aliased_delimiter_name_once(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->create_org_category('Acme Holdings Group');
        $this->set_aliases(['Acme, Holdings' => 'Acme Holdings Group']);
        $userid = $this->create_sso_user([], ['cyber-managers']);
        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::ORG => 'Acme, Holdings',
        ]);

        $this->assertSame(['Acme, Holdings'], org_roles::distinct_orgs());
    }

    /**
     * A blank or malformed alias line is skipped rather than breaking the map.
     *
     * The setting is a textarea that a human or a deployment script edits, so a stray blank
     * line or a line without a separator has to be survivable.
     */
    public function test_malformed_alias_lines_are_ignored(): void {
        set_config(
            'orgcategoryaliases',
            "\n  \nno separator here\nGlobex Holdings|Acme\n|missing org\nmissing target|\n",
            'block_crucible'
        );
        org_roles::reset_caches();

        $this->assertSame(['globex holdings' => 'Acme'], org_roles::org_aliases());
    }

    /**
     * An alias line typed in a different case than Keycloak sends still matches.
     *
     * The key is a value no human ever sees, so making an administrator reproduce its
     * capitalisation exactly buys nothing and costs a silent miss.
     */
    public function test_an_alias_key_matches_regardless_of_case(): void {
        $categoryid = $this->create_org_category('Acme');
        $this->set_aliases(['globex HOLDINGS' => 'acme']);

        $resolved = org_roles::resolve_org('Globex Holdings');

        $this->assertSame($categoryid, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIAS, $resolved['how']);
    }

    /**
     * Renaming a category's capitalisation does not stop it resolving.
     *
     * It used to: the name match was exact, and because an org that resolves to nothing
     * revokes what it granted, the next run took every role in that category away. A replay
     * against one site's live data showed 12 of its 13 grants going that way, from a rename
     * made days after the grants.
     */
    public function test_a_category_renamed_to_another_case_keeps_its_grants(): void {
        global $DB;

        $categoryid = $this->create_org_category('Acme Holdings');
        $userid = $this->create_sso_user(['Acme Holdings'], ['lab-builders']);
        org_roles::reconcile_user($userid);
        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $categoryid));

        // What tidying the category up in the UI does to the name.
        $DB->set_field('course_categories', 'name', 'ACME HOLDINGS ', ['id' => $categoryid]);
        org_roles::reset_caches();
        org_roles::reconcile_user($userid);

        $this->assertSame(org_roles::RESOLVE_NAME, org_roles::resolve_org('Acme Holdings')['how']);
        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Surrounding whitespace on either side is ignored too.
     */
    public function test_surrounding_whitespace_does_not_stop_a_name_match(): void {
        global $DB;

        $categoryid = $this->create_org_category('Acme Holdings');
        $DB->set_field('course_categories', 'name', '  Acme Holdings  ', ['id' => $categoryid]);
        org_roles::reset_caches();

        $resolved = org_roles::resolve_org('  Acme Holdings  ');

        $this->assertSame($categoryid, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_NAME, $resolved['how']);
    }

    /**
     * Two top-level categories differing only in case are ambiguous, so neither is used.
     *
     * The case-insensitive match makes this pair collide where an exact match did not, so it
     * has to land on "grant nothing" rather than on whichever row came back first.
     */
    public function test_two_categories_differing_only_by_case_grant_nothing(): void {
        $first = $this->create_org_category('Acme Holdings');
        $this->create_org_category('ACME HOLDINGS');
        $userid = $this->create_sso_user(['Acme Holdings'], ['lab-builders']);

        $resolved = org_roles::resolve_org('Acme Holdings');
        org_roles::reconcile_user($userid);

        $this->assertNull($resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_AMBIGUOUS, $resolved['how']);
        $this->assertSame([], $this->managed_roles($userid, $first));
    }

    /**
     * A revocation says how many grants it took and which category they were in.
     *
     * Without it the first evidence of a category or org rename is users reporting lost
     * access, because the removal itself is silent.
     */
    public function test_a_revocation_is_logged_with_its_category_and_count(): void {
        $categoryid = $this->create_org_category('Acme');
        $userid = $this->create_sso_user(['Acme'], ['cyber-managers', 'lab-builders']);
        org_roles::reconcile_user($userid);

        // The org stops resolving, exactly as a rename on either side would leave it.
        $this->set_sso_lists($userid, ['Globex Holdings'], ['cyber-managers', 'lab-builders']);
        $lines = [];
        org_roles::reconcile_user($userid, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $log = implode("\n", $lines);
        $this->assertStringContainsString("revoked 2 managed grant(s) in category 'Acme'", $log);
        $this->assertStringContainsString("org 'Globex Holdings' resolved to no category", $log);
        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * An org that resolves to nothing is reported once, however many users carry it.
     */
    public function test_an_unresolved_org_is_reported_once_per_run(): void {
        $userids = [
            $this->create_sso_user(['Globex Holdings'], ['cyber-managers']),
            $this->create_sso_user(['Globex Holdings'], ['cyber-managers']),
            $this->create_sso_user(['Globex Holdings'], ['lab-builders']),
        ];

        $lines = [];
        org_roles::reconcile_users($userids, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $reported = array_filter($lines, static function (string $line): bool {
            return strpos($line, "org 'Globex Holdings' resolved to no category") !== false;
        });
        $this->assertCount(1, $reported);
    }
}
