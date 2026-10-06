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
     * Write the org and group lists onto a user, as the sync writes them.
     *
     * The matching fields carry the wrapped form everything matches on, and the display
     * fields carry the same values for a person to read.
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
            'profile_field_' . profile_fields::ORGLIST => org_roles::join_list($orgs),
            'profile_field_' . profile_fields::ORG => implode(', ', $orgs),
            'profile_field_' . profile_fields::GROUPSLIST => org_roles::join_list($groups),
            'profile_field_' . profile_fields::GROUPS => implode(', ', $groups),
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
     * An organization whose name contains a comma is stored whole and resolves.
     *
     * This is why the delimiter is a vertical bar. Under the old comma an organization like
     * "Acme, Holdings" could not be stored at all - it was dropped, and the user was granted
     * nothing anywhere - and the only deployment this feature runs on has one.
     */
    public function test_an_org_containing_a_comma_resolves(): void {
        $categoryid = $this->create_org_category('Acme, Holdings');
        $userid = $this->create_sso_user(['Acme, Holdings'], ['cyber-managers']);

        org_roles::reconcile_user($userid);

        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * A login that writes the raw claim onto the display fields changes nothing.
     *
     * An OAuth 2 issuer field mapping writes the raw token claim onto ssoorg or ssogroups at
     * every login. That used to be the same field the reconcile matched on, so one login
     * could leave a user matching nothing and revoke everything they had. The display fields
     * are nobody's input now, which is the whole reason for the split.
     */
    public function test_a_login_writing_the_raw_claim_changes_nothing(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['cyber-managers', 'lab-builders']);
        org_roles::reconcile_user($userid);

        // Exactly what the mapping writes: the claim, unwrapped, under the readable names.
        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::ORG => 'Demo Org',
            'profile_field_' . profile_fields::GROUPS => 'cyber-managers,lab-builders',
        ]);
        org_roles::reconcile_user($userid);

        $matching = $DB->get_field_sql(
            "SELECT d.data
               FROM {user_info_data} d
               JOIN {user_info_field} f ON f.id = d.fieldid
              WHERE d.userid = :userid AND f.shortname = :shortname",
            ['userid' => $userid, 'shortname' => profile_fields::GROUPSLIST]
        );
        $this->assertSame('|cyber-managers|lab-builders|', $matching);
        $this->assertSame(['cyber-manager', 'lab-builder'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Stored values parse back to the elements they were made from.
     *
     * @param string|null $stored
     * @param string[] $expected
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('list_provider')]
    public function test_split_list_reads_the_stored_form(?string $stored, array $expected): void {
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
            'wrapped' => ['|a|b|', ['a', 'b']],
            'bare list' => ['a|b', ['a', 'b']],
            // A delimiter followed by a space is the marker for "one name that happens to
            // contain the delimiter", and join_list() can never produce it, so it is read
            // whole. Under the legacy comma this is what preserved "Acme, Holdings".
            'spaced' => ['a| b', ['a| b']],
            'single' => ['a', ['a']],
            // A comma is an ordinary character now, so an organization may contain one.
            'comma inside one name' => ['|Acme, Holdings|', ['Acme, Holdings']],
            'duplicates' => ['|a|a|b|', ['a', 'b']],
            'only delimiters' => ['|||', []],
        ];
    }

    /**
     * The legacy delimiter is still readable, which is what the upgrade reads with.
     */
    public function test_split_list_still_reads_the_legacy_delimiter(): void {
        $legacy = org_roles::LEGACY_DELIM;

        $this->assertSame(['a', 'b'], org_roles::split_list(',a,b,', $legacy));
        $this->assertSame(['a', 'b'], org_roles::split_list('a,b', $legacy));
        $this->assertSame(['Acme, Holdings'], org_roles::split_list('Acme, Holdings', $legacy));
    }

    /**
     * The canonical form wraps a non-empty list and leaves an empty one empty.
     */
    public function test_join_list_wraps_only_non_empty_lists(): void {
        $this->assertSame('', org_roles::join_list([]));
        $this->assertSame('|a|', org_roles::join_list(['a']));
        $this->assertSame('|a|b|', org_roles::join_list(['a', 'b']));
        $this->assertSame('|a|b|', org_roles::join_list(['a', 'b', 'a']));
    }

    /**
     * A value containing the delimiter is dropped, not split into elements that match nothing.
     *
     * "Acme|Inc." is one organization. Storing it as "|Acme|Inc.|" would silently turn it
     * into two, neither of which resolves to a category or a cohort rule.
     */
    public function test_join_list_drops_values_containing_the_delimiter(): void {
        $this->assertSame('|Army|', org_roles::join_list(['Army', 'Acme|Inc.']));
        $this->assertDebuggingCalled();

        $this->assertSame('', org_roles::join_list(['Acme|Inc.']));
        $this->assertDebuggingCalled();
    }

    /**
     * A comma is an ordinary character in a stored value.
     */
    public function test_join_list_stores_a_value_containing_a_comma(): void {
        $this->assertSame('|Acme, Holdings|', org_roles::join_list(['Acme, Holdings']));
        $this->assertSame([], org_roles::unstorable_values(['Acme, Holdings']));
    }

    /**
     * Each array element stays whole, so a multi-valued Keycloak attribute still works.
     */
    public function test_join_list_keeps_separate_values_separate(): void {
        $this->assertSame('|Demo Org|Second Org|', org_roles::join_list(['Demo Org', 'Second Org']));
    }

    /**
     * The readable form is the same values, joined for a person rather than for matching.
     */
    public function test_join_display_reads_plainly(): void {
        $this->assertSame('', org_roles::join_display(''));
        $this->assertSame('Demo Org', org_roles::join_display('|Demo Org|'));
        $this->assertSame('Demo Org, Second Org', org_roles::join_display('|Demo Org|Second Org|'));
        $this->assertSame('Acme, Holdings', org_roles::join_display('|Acme, Holdings|'));
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
        $this->assertSame(['Acme|Inc.'], org_roles::unstorable_values(['Army', 'Acme|Inc.']));
        $this->assertSame(['Acme|Inc.'], org_roles::unstorable_values([' Acme|Inc. ']));
        $this->assertSame(
            ['A|B', 'C|D'],
            org_roles::unstorable_values(['A|B', 'Fine', 'C|D'])
        );
    }

    /**
     * An all-unstorable list is distinguishable from an empty one, which is the whole point.
     */
    public function test_an_all_unstorable_list_is_distinguishable_from_an_empty_one(): void {
        $this->assertSame('', org_roles::join_list(['Acme|Inc.']));
        $this->assertDebuggingCalled();
        $this->assertSame(['Acme|Inc.'], org_roles::unstorable_values(['Acme|Inc.']));

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
        $this->create_org_category('Acme');
        $this->set_aliases(['Globex Holdings' => 'No Such Category']);
        $userid = $this->create_sso_user(['Globex Holdings'], ['cyber-managers']);

        $resolved = org_roles::resolve_org('Globex Holdings');
        org_roles::reconcile_user($userid);

        $this->assertNull($resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIASMISSING, $resolved['how']);
        $this->assertSame(0, $this->managed_grant_count($userid));
    }

    /**
     * An alias whose target names several categories grants nothing.
     *
     * Easier to hit than the top-level case, because an alias matches at any depth: one plain
     * name like "Demo" can exist under several parents. Picking one would be the same guess
     * the name match stopped making.
     */
    public function test_an_alias_matching_several_categories_grants_nothing(): void {
        $first = $this->create_org_category('Programs');
        $second = $this->create_org_category('Projects');
        $this->getDataGenerator()->create_category(['name' => 'Acme', 'parent' => $first]);
        $this->getDataGenerator()->create_category(['name' => 'Acme', 'parent' => $second]);
        $this->set_aliases(['Globex Holdings' => 'Acme']);
        $userid = $this->create_sso_user(['Globex Holdings'], ['cyber-managers']);

        $resolved = org_roles::resolve_org('Globex Holdings');
        org_roles::reconcile_user($userid);

        $this->assertNull($resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIASAMBIGUOUS, $resolved['how']);
        $this->assertSame(0, $this->managed_grant_count($userid));
    }

    /**
     * Naming the ID number instead is how the administrator resolves that ambiguity.
     */
    public function test_an_alias_by_idnumber_resolves_several_same_named_categories(): void {
        $parent = $this->create_org_category('Programs');
        $this->getDataGenerator()->create_category(['name' => 'Acme', 'parent' => $parent]);
        $wanted = $this->getDataGenerator()->create_category([
            'name' => 'Acme',
            'parent' => 0,
            'idnumber' => 'acme-real',
        ]);
        $this->set_aliases(['Globex Holdings' => 'acme-real']);

        $resolved = org_roles::resolve_org('Globex Holdings');

        $this->assertSame((int)$wanted->id, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIAS, $resolved['how']);
    }

    /**
     * The ID number wins over a category merely named the same string.
     *
     * Otherwise the advice the settings page gives - "use the ID number" - fails on exactly
     * the sites that need it: matching the name and the ID number in one query left the alias
     * ambiguous however precisely it was written, and nothing else to try.
     */
    public function test_an_alias_prefers_the_idnumber_over_a_category_named_the_same(): void {
        $wanted = $this->getDataGenerator()->create_category([
            'name' => 'Acme Holdings Group',
            'parent' => 0,
            'idnumber' => 'acme-real',
        ]);
        $this->getDataGenerator()->create_category(['name' => 'acme-real', 'parent' => 0]);
        $this->set_aliases(['Globex Holdings' => 'acme-real']);

        $resolved = org_roles::resolve_org('Globex Holdings');

        $this->assertSame((int)$wanted->id, $resolved['categoryid']);
        $this->assertSame(org_roles::RESOLVE_ALIAS, $resolved['how']);
    }

    /**
     * How many managed grants a user holds anywhere.
     *
     * @param int $userid
     * @return int
     */
    private function managed_grant_count(int $userid): int {
        global $DB;

        return $DB->count_records('role_assignments', [
            'userid' => $userid,
            'component' => org_roles::COMPONENT,
        ]);
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
     * An alias matches an organization whose name contains a comma.
     *
     * The reason the alias is keyed on one whole organization rather than on fragments.
     * Matching per fragment would see "Acme" and "Holdings" and never the configured key, so
     * exactly the values most likely to need an alias would be the ones it could not reach.
     */
    public function test_an_alias_matches_an_org_name_containing_a_comma(): void {
        $categoryid = $this->create_org_category('Acme Holdings Group');
        $this->set_aliases(['Acme, Holdings' => 'Acme Holdings Group']);
        $userid = $this->create_sso_user(['Acme, Holdings'], ['cyber-managers']);

        org_roles::reconcile_user($userid);

        $this->assertSame(['Acme, Holdings'], org_roles::org_values('|Acme, Holdings|'));
        $this->assertSame(['cyber-manager'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Without an alias the same value resolves to nothing rather than to its fragments.
     */
    public function test_an_unaliased_comma_name_still_grants_nothing(): void {
        $acme = $this->create_org_category('Acme');
        $holdings = $this->create_org_category('Holdings');
        $userid = $this->create_sso_user(['Acme, Holdings'], ['cyber-managers']);

        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $acme));
        $this->assertSame([], $this->managed_roles($userid, $holdings));
    }

    /**
     * The report lists an aliased comma-containing name once, not as two fragments.
     */
    public function test_distinct_orgs_counts_an_aliased_comma_name_once(): void {
        $this->create_org_category('Acme Holdings Group');
        $this->set_aliases(['Acme, Holdings' => 'Acme Holdings Group']);
        $this->create_sso_user(['Acme, Holdings'], ['cyber-managers']);

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

    /**
     * Write the group role mapping setting.
     *
     * @param array<string, string> $pairs group => role shortname
     */
    private function set_group_roles(array $pairs): void {
        $lines = [];
        foreach ($pairs as $group => $role) {
            $lines[] = $group . '|' . $role;
        }
        set_config('grouprolemap', implode("\n", $lines), 'block_crucible');
        org_roles::reset_caches();
    }

    /**
     * An unset setting means the mappings the plugin shipped with.
     *
     * Every site already running was on those, and an upgrade must not change who holds what.
     */
    public function test_an_unset_setting_keeps_the_shipped_mappings(): void {
        unset_config('grouprolemap', 'block_crucible');

        $this->assertSame(org_roles::DEFAULT_GROUP_ROLES, org_roles::group_role_map());
    }

    /**
     * A configured mapping replaces the shipped ones rather than adding to them.
     */
    public function test_a_configured_mapping_replaces_the_defaults(): void {
        $this->set_group_roles(['range-staff' => 'lab-builder']);

        $this->assertSame(['range-staff' => 'lab-builder'], org_roles::group_role_map());
    }

    /**
     * A group named only in the setting grants its role, which is the point of the setting.
     */
    public function test_a_configured_group_grants_its_role(): void {
        $this->set_group_roles(['range-staff' => 'lab-builder']);
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['range-staff']);

        org_roles::reconcile_user($userid);

        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $categoryid));
    }

    /**
     * A group that was mapped by default no longer grants once the setting names others.
     */
    public function test_an_unmapped_default_group_grants_nothing(): void {
        $this->set_group_roles(['range-staff' => 'lab-builder']);
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['lab-builders']);

        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * Removing a mapping takes the role it granted back.
     *
     * The reconcile is authoritative over what it granted, so this follows from the setting
     * being read on every run rather than needing its own mechanism.
     */
    public function test_removing_a_mapping_revokes_the_role_it_granted(): void {
        $categoryid = $this->create_org_category('Demo Org');
        $userid = $this->create_sso_user(['Demo Org'], ['lab-builders']);
        org_roles::reconcile_user($userid);
        $this->assertSame(['lab-builder'], $this->managed_roles($userid, $categoryid));

        $this->set_group_roles(['cyber-managers' => 'cyber-manager']);
        org_roles::reconcile_user($userid);

        $this->assertSame([], $this->managed_roles($userid, $categoryid));
    }

    /**
     * A cleared setting means no mappings, not the defaults back.
     *
     * "No mappings" has to be expressible. If a cleared box restored the defaults, the only
     * way to stop granting one role would be to turn the whole feature off, which revokes
     * the others too. The settings page warns about it instead.
     */
    public function test_a_cleared_setting_means_no_mappings(): void {
        set_config('grouprolemap', '', 'block_crucible');

        $this->assertSame([], org_roles::group_role_map());
    }

    /**
     * Lines that do not name a pair are skipped, and the halves are trimmed.
     */
    public function test_malformed_mapping_lines_are_skipped(): void {
        set_config(
            'grouprolemap',
            "  range-staff | lab-builder  \n\nnot-a-mapping\n|lab-builder\nrange-leads|\n",
            'block_crucible'
        );

        $this->assertSame(['range-staff' => 'lab-builder'], org_roles::group_role_map());
    }

    /**
     * The default the setting shows is the default the code uses.
     *
     * The box is pre-filled rather than empty, so an administrator can see what is in force;
     * these must not be able to drift apart.
     */
    public function test_the_setting_default_parses_back_to_the_shipped_mappings(): void {
        set_config('grouprolemap', org_roles::default_group_role_setting(), 'block_crucible');

        $this->assertSame(org_roles::DEFAULT_GROUP_ROLES, org_roles::group_role_map());
    }

    /**
     * The report says a mapping is in force when its role exists and is allowed in a category.
     */
    public function test_the_report_confirms_a_working_mapping(): void {
        $this->set_group_roles(['range-staff' => 'lab-builder']);

        $this->assertSame(
            [['group' => 'range-staff', 'role' => 'lab-builder', 'state' => org_roles::ROLE_OK]],
            org_roles::group_role_report()
        );
    }

    /**
     * A mapping naming no role grants nothing, and the report says so.
     *
     * The sync only mentions this in cron output, where nobody looks.
     */
    public function test_the_report_names_a_missing_role(): void {
        $this->set_group_roles(['range-staff' => 'no-such-role']);

        $report = org_roles::group_role_report();

        $this->assertSame(org_roles::ROLE_MISSING, $report[0]['state']);
    }

    /**
     * A role nobody allowed in a category cannot be granted in one, and the report says so.
     */
    public function test_the_report_names_a_role_not_allowed_in_a_category(): void {
        $roleid = create_role('Course Only', 'course-only', 'Test role');
        set_role_contextlevels($roleid, [CONTEXT_COURSE]);
        $this->set_group_roles(['range-staff' => 'course-only']);

        $report = org_roles::group_role_report();

        $this->assertSame(org_roles::ROLE_NOTINCATEGORY, $report[0]['state']);
    }
}
