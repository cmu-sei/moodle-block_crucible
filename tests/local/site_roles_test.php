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
 * Unit tests for \block_crucible\local\site_roles.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for \block_crucible\local\site_roles.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(site_roles::class)]
final class site_roles_test extends \advanced_testcase {
    /** @var int Id of the site-level role the map grants. */
    private int $siteadmin = 0;

    /** @var int System context id. */
    private int $sysctx = 0;

    /**
     * Provision a site-assignable role and map a group to it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        profile_fields::install();
        org_roles::reset_caches();

        $this->siteadmin = (int)create_role('SSO Administrator', 'ssoadmin', 'Test role');
        set_role_contextlevels($this->siteadmin, [CONTEXT_SYSTEM]);
        $this->sysctx = (int)\context_system::instance()->id;

        set_config('siterolemap', "platform-admins|ssoadmin", 'block_crucible');
    }

    /**
     * A linked OAuth 2 user.
     *
     * @return int user id
     */
    private function sso_user(): int {
        return (int)$this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
    }

    /**
     * Whether a user holds the plugin's site-level grant of a role.
     *
     * @param int $userid
     * @param int|null $roleid defaults to the mapped ssoadmin role
     * @return bool
     */
    private function holds(int $userid, ?int $roleid = null): bool {
        global $DB;

        return $DB->record_exists('role_assignments', [
            'userid' => $userid,
            'roleid' => $roleid ?? $this->siteadmin,
            'contextid' => $this->sysctx,
            'component' => org_roles::COMPONENT,
        ]);
    }

    /**
     * Membership grants the mapped role at the system context, owned by the plugin.
     */
    public function test_membership_grants_the_role_at_site_level(): void {
        $userid = $this->sso_user();

        $result = site_roles::reconcile([$userid => ['platform-admins']]);

        $this->assertSame(1, $result['assigned']);
        $this->assertTrue($this->holds($userid));
    }

    /**
     * Leaving the group takes the role back.
     */
    public function test_leaving_the_group_revokes_the_role(): void {
        $userid = $this->sso_user();
        site_roles::reconcile([$userid => ['platform-admins']]);

        $result = site_roles::reconcile([]);

        $this->assertSame(1, $result['unassigned']);
        $this->assertFalse($this->holds($userid));
    }

    /**
     * A grant the plugin does not own is left alone, whether or not the user is in the group.
     */
    public function test_a_grant_the_plugin_does_not_own_is_untouched(): void {
        global $DB;

        $userid = $this->sso_user();
        role_assign($this->siteadmin, $userid, $this->sysctx);

        site_roles::reconcile([]);

        $this->assertTrue($DB->record_exists('role_assignments', [
            'userid' => $userid, 'roleid' => $this->siteadmin, 'contextid' => $this->sysctx, 'component' => '',
        ]));
    }

    /**
     * Moodle's role UI unassigns without a component, which cannot touch a plugin-owned grant.
     */
    public function test_a_managed_grant_cannot_be_removed_by_hand(): void {
        $userid = $this->sso_user();
        site_roles::reconcile([$userid => ['platform-admins']]);

        role_unassign($this->siteadmin, $userid, $this->sysctx);

        $this->assertTrue($this->holds($userid));
    }

    /**
     * A run that would revoke more than the limit changes nothing, grants included.
     */
    public function test_too_many_revocations_change_nothing(): void {
        $holders = [$this->sso_user(), $this->sso_user(), $this->sso_user()];
        site_roles::reconcile(array_fill_keys($holders, ['platform-admins']));
        $newcomer = $this->sso_user();

        $lines = [];
        $result = site_roles::reconcile([$newcomer => ['platform-admins']], null, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertTrue($result['blocked']);
        foreach ($holders as $userid) {
            $this->assertTrue($this->holds($userid));
        }
        $this->assertFalse($this->holds($newcomer));
        $this->assertMatchesRegularExpression('/would revoke 3 .* limit of 2/', implode("\n", $lines));
    }

    /**
     * The limit is a setting.
     */
    public function test_the_revocation_limit_is_configurable(): void {
        $holders = [$this->sso_user(), $this->sso_user(), $this->sso_user()];
        site_roles::reconcile(array_fill_keys($holders, ['platform-admins']));
        set_config('siterolemaxrevoke', 3, 'block_crucible');

        $result = site_roles::reconcile([]);

        $this->assertFalse($result['blocked']);
        $this->assertSame(3, $result['unassigned']);
    }

    /**
     * A role not allowed in the system context grants nothing, and says so.
     */
    public function test_a_role_not_allowed_at_site_level_grants_nothing(): void {
        set_role_contextlevels($this->siteadmin, [CONTEXT_COURSECAT]);
        $userid = $this->sso_user();

        $lines = [];
        site_roles::reconcile([$userid => ['platform-admins']], null, function (string $line) use (&$lines) {
            $lines[] = $line;
        });

        $this->assertFalse($this->holds($userid));
        $this->assertStringContainsString('cannot be assigned at system level', implode("\n", $lines));
        $this->assertStringContainsString('making no site-level change', implode("\n", $lines));
        $this->assertSame(site_roles::ROLE_NOTINSYSTEM, site_roles::report()[0]['state']);
    }

    /**
     * Only linked OAuth 2 accounts are granted anything.
     */
    public function test_a_manual_account_is_not_granted(): void {
        $userid = (int)$this->getDataGenerator()->create_user(['auth' => 'manual'])->id;

        site_roles::reconcile([$userid => ['platform-admins']]);

        $this->assertFalse($this->holds($userid));
    }

    /**
     * A scoped reconcile, as at login, leaves everyone else's grants alone.
     */
    public function test_a_scoped_reconcile_touches_only_its_users(): void {
        $other = $this->sso_user();
        $self = $this->sso_user();
        site_roles::reconcile([$other => ['platform-admins'], $self => ['platform-admins']]);

        site_roles::reconcile([$self => []], [$self]);

        $this->assertTrue($this->holds($other));
        $this->assertFalse($this->holds($self));
    }

    /**
     * The category reconcile neither reads the site groups nor touches site-level grants.
     *
     * The site map exists because the profile fields can be edited inside Moodle: writing a
     * site group into ssogroupslist must grant nothing, and the category reconcile treating a
     * system-context grant as "not desired" would take every one of them away.
     */
    public function test_profile_fields_and_the_category_reconcile_cannot_reach_site_roles(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        set_config('enableorgrolesync', 1, 'block_crucible');
        $holder = $this->sso_user();
        site_roles::reconcile([$holder => ['platform-admins']]);

        $editor = $this->sso_user();
        profile_save_data((object)[
            'id' => $editor,
            'profile_field_' . profile_fields::GROUPSLIST => org_roles::join_list(['platform-admins']),
            'profile_field_' . profile_fields::ROLE => 'moodle-admin',
        ]);

        org_roles::reconcile_users([$holder, $editor]);

        $this->assertTrue($this->holds($holder));
        $this->assertFalse($this->holds($editor));
    }

    /**
     * Turning org role sync off revokes category grants only.
     */
    public function test_revoking_every_category_grant_leaves_site_grants(): void {
        $userid = $this->sso_user();
        site_roles::reconcile([$userid => ['platform-admins']]);

        $this->assertSame(0, org_roles::revoke_all());
        $this->assertTrue($this->holds($userid));
    }

    /**
     * With no map and no grants there is nothing to do.
     */
    public function test_an_empty_map_with_no_grants_is_inactive(): void {
        $this->assertTrue(site_roles::is_active());
        $userid = $this->sso_user();
        site_roles::reconcile([$userid => ['platform-admins']]);

        set_config('siterolemap', '', 'block_crucible');
        // Still active: there is a grant to take back.
        $this->assertTrue(site_roles::is_active());

        site_roles::reconcile([]);
        $this->assertFalse(site_roles::is_active());
    }

    /**
     * The report states each line, and a group the realm lacks is flagged.
     */
    public function test_the_report_flags_each_problem(): void {
        set_config('siterolemap', "platform-admins|ssoadmin\nsupport-admins|nosuchrole\nghosts|ssoadmin\n"
            . "moodle-managers|ssoadmin\nmoodle-managers|ssoadmin", 'block_crucible');
        site_roles::record_missing_groups(['ghosts']);

        $states = array_column(site_roles::report(), 'state');

        $this->assertSame([
            org_roles::ROLE_OK,
            org_roles::ROLE_MISSING,
            org_roles::ROLE_NOGROUP,
            org_roles::ROLE_OVERRIDDEN,
            org_roles::ROLE_OK,
        ], $states);
    }

    /**
     * The holders report separates managed grants from every other way of holding the role.
     */
    public function test_holders_lists_managed_and_other_grants(): void {
        $managed = $this->sso_user();
        $manual = $this->sso_user();
        $both = $this->sso_user();
        site_roles::reconcile([$managed => ['platform-admins'], $both => ['platform-admins']]);
        role_assign($this->siteadmin, $manual, $this->sysctx);
        role_assign($this->siteadmin, $both, $this->sysctx);

        $holders = site_roles::holders()['ssoadmin'];

        $ids = static fn(array $users): array => array_map(static fn($u) => (int)$u->id, $users);
        $this->assertEqualsCanonicalizing([$managed, $both], $ids($holders['managed']));
        // Someone holding both kinds is in the group, so not clean-up work.
        $this->assertSame([$manual], $ids($holders['other']));
    }

    /**
     * Storing the map uses the same text format and leaves a config log entry.
     */
    public function test_set_map_stores_the_setting_text(): void {
        global $DB;

        site_roles::set_map(['platform-admins' => 'ssoadmin', 'support-admins' => 'manager']);

        $this->assertSame("platform-admins|ssoadmin\nsupport-admins|manager", get_config('block_crucible', 'siterolemap'));
        $this->assertSame(['platform-admins' => 'ssoadmin', 'support-admins' => 'manager'], site_roles::map());
        $this->assertTrue($DB->record_exists('config_log', ['plugin' => 'block_crucible', 'name' => 'siterolemap']));
    }

    /**
     * A mapped role that cannot be granted stops the pass instead of revoking its holders.
     */
    public function test_an_unusable_mapped_role_changes_nothing(): void {
        $userid = $this->sso_user();
        site_roles::reconcile([$userid => ['platform-admins']]);
        // The shortname is renamed under the map.
        set_config('siterolemap', 'platform-admins|sso-admin', 'block_crucible');

        $result = site_roles::reconcile([$userid => ['platform-admins']]);

        $this->assertTrue($result['blocked']);
        $this->assertTrue($this->holds($userid));
    }

    /**
     * A grant-only reconcile adds what is missing and takes nothing away.
     */
    public function test_a_grant_only_reconcile_revokes_nothing(): void {
        $holder = $this->sso_user();
        $newcomer = $this->sso_user();
        site_roles::reconcile([$holder => ['platform-admins']]);

        site_roles::reconcile([$newcomer => ['platform-admins']], null, null, true);

        $this->assertTrue($this->holds($holder));
        $this->assertTrue($this->holds($newcomer));
    }
}
