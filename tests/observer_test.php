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
 * Unit tests for the login observer.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible;

use block_crucible\local\org_roles;
use block_crucible\local\profile_fields;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for \block_crucible\observer.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
final class observer_test extends \advanced_testcase {
    /** @var array Role shortname => roleid for the mapped roles. */
    private array $roleids = [];

    /** @var int Id of the org category used by every test here. */
    private int $categoryid = 0;

    /**
     * Provision the profile fields, roles and org category.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        profile_fields::install();
        org_roles::reset_caches();
        set_config('enableorgrolesync', 1, 'block_crucible');

        foreach (org_roles::group_role_map() as $shortname) {
            $roleid = create_role(ucwords(str_replace('-', ' ', $shortname)), $shortname, 'Test role');
            set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
            $this->roleids[$shortname] = $roleid;
        }

        $category = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        $this->categoryid = (int)$category->id;
        org_roles::reset_caches();
    }

    /**
     * Create an oauth2 user carrying the given group list.
     *
     * @param string[] $groups
     * @return \stdClass user record
     */
    private function create_sso_user(array $groups): \stdClass {
        $user = $this->getDataGenerator()->create_user(['auth' => 'oauth2']);
        $this->set_groups((int)$user->id, $groups);

        return $user;
    }

    /**
     * Write the org and group lists onto a user.
     *
     * @param int $userid
     * @param string[] $groups
     */
    private function set_groups(int $userid, array $groups): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        profile_save_data((object)[
            'id' => $userid,
            'profile_field_' . profile_fields::ORG => org_roles::join_list(['Demo Org']),
            'profile_field_' . profile_fields::GROUPS => org_roles::join_list($groups),
        ]);
    }

    /**
     * Fire user_loggedin for a user, through the real event registration.
     *
     * @param \stdClass $user
     */
    private function log_in(\stdClass $user): void {
        \core\event\user_loggedin::create([
            'userid' => $user->id,
            'objectid' => $user->id,
            'other' => ['username' => $user->username],
        ])->trigger();
    }

    /**
     * The role shortnames this plugin has granted a user in the org category.
     *
     * @param int $userid
     * @return string[] sorted
     */
    private function managed_roles(int $userid): array {
        global $DB;

        $context = \context_coursecat::instance($this->categoryid);
        $roleids = $DB->get_fieldset_select(
            'role_assignments',
            'roleid',
            'userid = ? AND contextid = ? AND component = ?',
            [$userid, $context->id, org_roles::COMPONENT]
        );

        $shortnames = array_keys(array_intersect($this->roleids, array_map('intval', $roleids)));
        sort($shortnames);

        return $shortnames;
    }

    /**
     * Clear the throttle so a test can log the same user in twice.
     *
     * @param int $userid
     */
    private function clear_throttle(int $userid): void {
        \cache::make('block_crucible', 'org_role_sync')->delete('org_role_sync_' . $userid);
    }

    /**
     * Logging in grants the roles the user's groups map to.
     */
    public function test_login_grants_missing_roles(): void {
        $user = $this->create_sso_user(['cyber-managers']);

        $this->log_in($user);

        $this->assertSame(['cyber-manager'], $this->managed_roles((int)$user->id));
    }

    /**
     * Logging in also removes roles the user's groups no longer back.
     */
    public function test_login_revokes_roles_no_longer_backed_by_a_group(): void {
        $user = $this->create_sso_user(['cyber-managers', 'lab-builders']);
        $this->log_in($user);
        $this->assertSame(['cyber-manager', 'lab-builder'], $this->managed_roles((int)$user->id));

        $this->set_groups((int)$user->id, ['lab-builders']);
        $this->clear_throttle((int)$user->id);
        $this->log_in($user);

        $this->assertSame(['lab-builder'], $this->managed_roles((int)$user->id));
    }

    /**
     * The observer and the scheduled task agree on the same fixture, because they run
     * the same reconcile.
     */
    public function test_the_observer_and_the_task_agree(): void {
        $viaobserver = $this->create_sso_user(['ex-cyber-managers', 'lab-builders']);
        $viatask = $this->create_sso_user(['ex-cyber-managers', 'lab-builders']);

        $this->log_in($viaobserver);

        ob_start();
        (new \block_crucible\task\sync_org_roles())->execute();
        ob_get_clean();

        $this->assertSame($this->managed_roles((int)$viaobserver->id), $this->managed_roles((int)$viatask->id));
        $this->assertSame(['lab-builder'], $this->managed_roles((int)$viatask->id));
    }

    /**
     * A second login inside the throttle window does no work.
     */
    public function test_the_throttle_suppresses_a_second_login(): void {
        $user = $this->create_sso_user(['cyber-managers']);
        $this->log_in($user);

        // Grant a second group but do not clear the throttle.
        $this->set_groups((int)$user->id, ['cyber-managers', 'lab-builders']);
        $this->log_in($user);

        $this->assertSame(['cyber-manager'], $this->managed_roles((int)$user->id));
    }

    /**
     * With the feature off the observer does nothing at all - revoking site-wide is the
     * scheduled task's job, not something every login should trigger.
     */
    public function test_a_disabled_feature_makes_the_observer_inert(): void {
        $user = $this->create_sso_user(['cyber-managers']);
        $this->log_in($user);
        $this->assertSame(['cyber-manager'], $this->managed_roles((int)$user->id));

        set_config('enableorgrolesync', 0, 'block_crucible');
        $this->clear_throttle((int)$user->id);
        $this->log_in($user);

        $this->assertSame(['cyber-manager'], $this->managed_roles((int)$user->id));
    }
}
