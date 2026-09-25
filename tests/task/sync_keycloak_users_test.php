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
 * Unit tests for the Keycloak user sync task.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\task;

use block_crucible\local\org_roles;
use block_crucible\local\profile_fields;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/crucible/tests/fixtures/testable_sync_keycloak_users.php');

/**
 * Unit tests for the Keycloak user sync task.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(sync_keycloak_users::class)]
final class sync_keycloak_users_test extends \advanced_testcase {
    /** @var array<int, array{request: RequestInterface, options: array}> Requests the task made. */
    private array $requests = [];

    /**
     * Build a task whose HTTP client answers with a canned response.
     *
     * @param int $status Status code to answer with.
     * @param string $body Body to answer with.
     * @return testable_sync_keycloak_users
     */
    private function create_task_answering(int $status, string $body): testable_sync_keycloak_users {
        $this->requests = [];
        $handler = function (RequestInterface $request, array $options) use ($status, $body): PromiseInterface {
            $this->requests[] = ['request' => $request, 'options' => $options];
            return Create::promiseFor(new Response($status, [], $body));
        };

        $task = new testable_sync_keycloak_users();
        $task->set_handler($handler);
        return $task;
    }

    /**
     * Call the private fetch_token() on a task.
     *
     * @param sync_keycloak_users $task Task to call.
     * @param string $tokenurl Token endpoint to request.
     * @return string|null The access token.
     */
    private function fetch_token(sync_keycloak_users $task, string $tokenurl): ?string {
        $fetchtoken = \Closure::bind(
            static function (sync_keycloak_users $task, string $tokenurl): ?string {
                return $task->fetch_token($tokenurl, 'moodle', 'a-secret');
            },
            null,
            sync_keycloak_users::class
        );
        return $fetchtoken($task, $tokenurl);
    }

    public function test_the_token_request_is_time_bounded(): void {
        $this->resetAfterTest(true);
        $task = $this->create_task_answering(200, '{"access_token": "kc-token"}');

        $token = $this->fetch_token($task, 'https://kc.example.test/realms/crucible/protocol/openid-connect/token');

        $this->assertSame('kc-token', $token);
        // A scheduled task shares Moodle's cron worker, so an unavailable Keycloak must not hold it.
        $options = $this->requests[0]['options'];
        $this->assertSame(5, $options[RequestOptions::CONNECT_TIMEOUT]);
        $this->assertSame(30, $options[RequestOptions::TIMEOUT]);
    }

    public function test_the_peer_certificate_is_verified_for_a_dev_hostname(): void {
        $this->resetAfterTest(true);
        $task = $this->create_task_answering(200, '{"access_token": "kc-token"}');

        // Verification used to be switched off for any URL containing '.dev/', which sent the client
        // secret, and then the realm admin token, over a connection nobody had authenticated.
        $this->fetch_token($task, 'https://keycloak.crucible.dev/realms/crucible/protocol/openid-connect/token');

        $this->assertTrue($this->requests[0]['options'][RequestOptions::VERIFY]);
    }

    public function test_an_error_status_yields_no_token(): void {
        $this->resetAfterTest(true);
        $task = $this->create_task_answering(401, '{"error": "unauthorized_client"}');
        $this->expectOutputRegex('/token HTTP 401/');

        $token = $this->fetch_token($task, 'https://kc.example.test/realms/crucible/protocol/openid-connect/token');

        $this->assertNull($token);
    }

    /**
     * Register a Keycloak issuer with a token endpoint the task can derive an admin base from.
     */
    private function create_issuer(): void {
        $issuer = new \core\oauth2\issuer(0, (object)[
            'name' => 'Keycloak',
            'image' => '',
            'baseurl' => 'https://kc.example.test/realms/crucible',
            'clientid' => 'moodle',
            'clientsecret' => 'a-secret',
        ]);
        $issuer->create();

        (new \core\oauth2\endpoint(0, (object)[
            'issuerid' => $issuer->get('id'),
            'name' => 'token_endpoint',
            'url' => 'https://kc.example.test/realms/crucible/protocol/openid-connect/token',
        ]))->create();

        set_config('issuerid', $issuer->get('id'), 'block_crucible');
    }

    /**
     * A 200 answer carrying a JSON body.
     *
     * @param mixed $data Payload to encode.
     * @return PromiseInterface
     */
    private function json($data): PromiseInterface {
        return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode($data)));
    }

    /**
     * Build a task whose handler answers the realm's admin endpoints from fixtures.
     *
     * @param array $kcusers Records the /users endpoint answers with.
     * @param array|null $grouptree Records /groups answers with; null makes /groups fail.
     * @param array $groupmembers Keycloak group id => member records.
     * @param bool $usersfail Make /users answer with a 500 instead.
     * @return testable_sync_keycloak_users
     */
    private function create_realm_task(
        array $kcusers,
        ?array $grouptree = [],
        array $groupmembers = [],
        bool $usersfail = false
    ): testable_sync_keycloak_users {
        $this->requests = [];
        $fixtures = [$kcusers, $grouptree, $groupmembers, $usersfail];
        $handler = function (RequestInterface $request, array $options) use ($fixtures): PromiseInterface {
            [$kcusers, $grouptree, $groupmembers, $usersfail] = $fixtures;
            $this->requests[] = ['request' => $request, 'options' => $options];
            $path = $request->getUri()->getPath();

            if (str_ends_with($path, '/protocol/openid-connect/token')) {
                return $this->json(['access_token' => 'kc-token']);
            }
            if (preg_match('#/groups/([^/]+)/members$#', $path, $matches)) {
                return $this->json($groupmembers[urldecode($matches[1])] ?? []);
            }
            if (str_ends_with($path, '/groups')) {
                if ($grouptree === null) {
                    return Create::promiseFor(new Response(500, [], 'boom'));
                }
                // Page the collection the way Keycloak does, so a caller that ignores
                // first/max is caught here rather than in production.
                $query = [];
                parse_str($request->getUri()->getQuery(), $query);
                $first = (int)($query['first'] ?? 0);
                $max = isset($query['max']) ? (int)$query['max'] : count($grouptree);

                return $this->json(array_values(array_slice($grouptree, $first, $max)));
            }
            if (str_ends_with($path, '/users')) {
                return $usersfail
                    ? Create::promiseFor(new Response(500, [], 'boom'))
                    : $this->json($kcusers);
            }

            return Create::promiseFor(new Response(404, [], '[]'));
        };

        $task = new testable_sync_keycloak_users();
        $task->set_handler($handler);

        return $task;
    }

    /**
     * A Keycloak user representation.
     *
     * @param string $kcid Keycloak user id.
     * @param array $attributes Keycloak attributes.
     * @return array
     */
    private function kc_user(string $kcid, array $attributes = []): array {
        return [
            'id' => $kcid,
            'username' => $kcid,
            'email' => $kcid . '@example.test',
            'firstName' => 'Test',
            'lastName' => ucfirst($kcid),
            'enabled' => true,
            'attributes' => $attributes,
        ];
    }

    /**
     * Run a task, discarding its trace output.
     *
     * @param sync_keycloak_users $task Task to run.
     * @return string The trace output.
     */
    private function run_task(sync_keycloak_users $task): string {
        ob_start();
        $task->execute();

        return (string)ob_get_clean();
    }

    /**
     * Read one profile field off the user with the given Keycloak id.
     *
     * @param string $kcid Keycloak user id, stored as the Moodle idnumber.
     * @param string $shortname Profile field shortname.
     * @return string
     */
    private function profile_value(string $kcid, string $shortname): string {
        global $DB;

        $userid = $DB->get_field('user', 'id', ['idnumber' => $kcid, 'deleted' => 0]);
        $this->assertNotEmpty($userid, "no Moodle user for Keycloak id {$kcid}");
        $record = profile_user_record((int)$userid, false);

        return isset($record->$shortname) ? (string)$record->$shortname : '';
    }

    /**
     * Provision the profile fields the sync writes into.
     */
    private function prepare_site(): void {
        global $CFG;
        require_once($CFG->dirroot . '/user/profile/lib.php');

        $this->resetAfterTest(true);
        profile_fields::install();
        org_roles::reset_caches();
        $this->create_issuer();
    }

    /**
     * Every value of a multi-valued attribute is stored, not just the first.
     */
    public function test_a_multi_valued_organization_is_stored_in_full(): void {
        $this->prepare_site();
        $task = $this->create_realm_task([
            $this->kc_user('kc-1', ['organization' => ['Demo Org', 'Second Org']]),
        ]);

        $this->run_task($task);

        $this->assertSame(',Demo Org,Second Org,', $this->profile_value('kc-1', profile_fields::ORG));
    }

    /**
     * An attribute removed in Keycloak is cleared in Moodle, rather than left in place.
     */
    public function test_a_deleted_attribute_is_cleared(): void {
        $this->prepare_site();
        $this->run_task($this->create_realm_task([
            $this->kc_user('kc-1', ['organization' => ['Demo Org'], 'team' => ['Blue']]),
        ]));
        $this->assertSame(',Demo Org,', $this->profile_value('kc-1', profile_fields::ORG));
        $this->assertSame('Blue', $this->profile_value('kc-1', profile_fields::TEAM));

        // The attributes are gone from Keycloak on the next run.
        $this->run_task($this->create_realm_task([$this->kc_user('kc-1')]));

        $this->assertSame('', $this->profile_value('kc-1', profile_fields::ORG));
        $this->assertSame('', $this->profile_value('kc-1', profile_fields::TEAM));
    }

    /**
     * Group membership comes from the groups endpoints, because /users does not carry it.
     */
    public function test_group_membership_is_read_from_the_groups_endpoints(): void {
        $this->prepare_site();
        $task = $this->create_realm_task(
            [$this->kc_user('kc-1'), $this->kc_user('kc-2')],
            [
                ['id' => 'g-other', 'name' => 'crucible'],
                ['id' => 'g-1', 'name' => 'cyber-managers'],
                ['id' => 'g-2', 'name' => 'lab-builders'],
            ],
            [
                'g-1' => [['id' => 'kc-1']],
                'g-2' => [['id' => 'kc-1'], ['id' => 'kc-2']],
            ]
        );

        $this->run_task($task);

        // The first user is in both mapped groups; the unmapped one is never asked about.
        $this->assertSame(',cyber-managers,lab-builders,', $this->profile_value('kc-1', profile_fields::GROUPS));
        $this->assertSame(',lab-builders,', $this->profile_value('kc-2', profile_fields::GROUPS));
    }

    /**
     * A mapped group past the first page of /groups still resolves.
     *
     * Keycloak pages this collection like any other, and reading only the first page lost
     * the mapped groups that sort last in a realm with many of them.
     */
    public function test_group_membership_reads_every_page_of_groups(): void {
        $this->prepare_site();

        // Fill the first page with groups nothing maps to, so the mapped one is only
        // reachable by asking for the page after it.
        $groups = [];
        for ($i = 0; $i < testable_sync_keycloak_users::PAGE_SIZE; $i++) {
            $groups[] = ['id' => 'g-filler-' . $i, 'name' => 'filler-' . $i];
        }
        $groups[] = ['id' => 'g-1', 'name' => 'cyber-managers'];

        $output = $this->run_task($this->create_realm_task(
            [$this->kc_user('kc-1')],
            $groups,
            ['g-1' => [['id' => 'kc-1']]]
        ));

        // The other mapped groups really are absent from this fixture, so only the paged
        // one matters here: it must not be reported missing.
        $this->assertStringNotContainsString("'cyber-managers' does not exist", $output);
        $this->assertSame(',cyber-managers,', $this->profile_value('kc-1', profile_fields::GROUPS));
    }

    /**
     * Re-enabling a user in Keycloak gives them their Moodle account back.
     *
     * The suspend is only half a policy if nothing ever undoes it: disabling a user in
     * Keycloak once would otherwise lock them out of Moodle permanently.
     */
    public function test_a_user_re_enabled_in_keycloak_is_unsuspended(): void {
        global $DB;

        $this->prepare_site();
        set_config('suspendmissingusers', 1, 'block_crucible');

        $this->run_task($this->create_realm_task(
            [$this->kc_user('kc-1')],
            [['id' => 'g-1', 'name' => 'cyber-managers']],
            ['g-1' => [['id' => 'kc-1']]]
        ));
        $this->assertSame(0, (int)$DB->get_field('user', 'suspended', ['idnumber' => 'kc-1']));

        // Disabled in Keycloak: the user drops out of the enabled-only listing entirely,
        // so deprovisioning clears the fields and suspends the account.
        $disabled = $this->kc_user('kc-1');
        $disabled['enabled'] = false;
        $this->run_task($this->create_realm_task([$disabled]));

        $this->assertSame(1, (int)$DB->get_field('user', 'suspended', ['idnumber' => 'kc-1']));
        $this->assertSame('', $this->profile_value('kc-1', profile_fields::GROUPS));

        // Enabled again.
        $this->run_task($this->create_realm_task(
            [$this->kc_user('kc-1')],
            [['id' => 'g-1', 'name' => 'cyber-managers']],
            ['g-1' => [['id' => 'kc-1']]]
        ));

        $this->assertSame(0, (int)$DB->get_field('user', 'suspended', ['idnumber' => 'kc-1']));
        $this->assertSame(',cyber-managers,', $this->profile_value('kc-1', profile_fields::GROUPS));
    }

    /**
     * A deployment that never opted into suspending keeps its manual suspensions.
     */
    public function test_manual_suspensions_survive_when_the_setting_is_off(): void {
        global $DB;

        $this->prepare_site();
        set_config('suspendmissingusers', 0, 'block_crucible');

        $this->run_task($this->create_realm_task([$this->kc_user('kc-1')]));
        $DB->set_field('user', 'suspended', 1, ['idnumber' => 'kc-1']);

        $this->run_task($this->create_realm_task([$this->kc_user('kc-1')]));

        $this->assertSame(1, (int)$DB->get_field('user', 'suspended', ['idnumber' => 'kc-1']));
    }

    /**
     * A failed group fetch leaves the stored membership alone: treating it as "no groups"
     * would revoke every role in the realm on a transient error.
     */
    public function test_a_failed_group_fetch_leaves_membership_untouched(): void {
        $this->prepare_site();
        $this->run_task($this->create_realm_task(
            [$this->kc_user('kc-1')],
            [['id' => 'g-1', 'name' => 'cyber-managers']],
            ['g-1' => [['id' => 'kc-1']]]
        ));
        $this->assertSame(',cyber-managers,', $this->profile_value('kc-1', profile_fields::GROUPS));

        $output = $this->run_task($this->create_realm_task([$this->kc_user('kc-1')], null));

        $this->assertStringContainsString('group membership fetch failed', $output);
        $this->assertSame(',cyber-managers,', $this->profile_value('kc-1', profile_fields::GROUPS));
    }

    /**
     * A user Keycloak no longer lists loses the Keycloak-derived state, and the roles it
     * granted, without the account being suspended by default.
     */
    public function test_a_user_missing_from_keycloak_is_deprovisioned(): void {
        global $DB;

        $this->prepare_site();
        set_config('enableorgrolesync', 1, 'block_crucible');
        $roleid = create_role('Cyber Manager', 'cyber-manager', 'Test role');
        set_role_contextlevels($roleid, [CONTEXT_COURSECAT]);
        $category = $this->getDataGenerator()->create_category(['name' => 'Demo Org', 'parent' => 0]);
        org_roles::reset_caches();

        $this->run_task($this->create_realm_task(
            [$this->kc_user('kc-1', ['organization' => ['Demo Org']])],
            [['id' => 'g-1', 'name' => 'cyber-managers']],
            ['g-1' => [['id' => 'kc-1']]]
        ));
        $userid = (int)$DB->get_field('user', 'id', ['idnumber' => 'kc-1']);
        $context = \context_coursecat::instance((int)$category->id);
        $this->assertTrue($DB->record_exists('role_assignments', [
            'roleid' => $roleid,
            'userid' => $userid,
            'contextid' => $context->id,
            'component' => org_roles::COMPONENT,
        ]));

        // The user is gone from Keycloak on the next run.
        $this->run_task($this->create_realm_task([]));

        $this->assertSame('', $this->profile_value('kc-1', profile_fields::ORG));
        $this->assertSame('', $this->profile_value('kc-1', profile_fields::GROUPS));
        $this->assertFalse($DB->record_exists('role_assignments', [
            'userid' => $userid,
            'component' => org_roles::COMPONENT,
        ]));
        $this->assertSame('0', (string)$DB->get_field('user', 'suspended', ['id' => $userid]));
    }

    /**
     * Suspending the account as well is opt-in, because some deployments keep it for its
     * grades and logs.
     */
    public function test_a_missing_user_is_suspended_only_when_configured(): void {
        global $DB;

        $this->prepare_site();
        set_config('suspendmissingusers', 1, 'block_crucible');
        $this->run_task($this->create_realm_task([$this->kc_user('kc-1', ['organization' => ['Demo Org']])]));

        $this->run_task($this->create_realm_task([]));

        $userid = (int)$DB->get_field('user', 'id', ['idnumber' => 'kc-1']);
        $this->assertSame('1', (string)$DB->get_field('user', 'suspended', ['id' => $userid]));
    }

    /**
     * A failed /users page is not read as "every user is gone from Keycloak".
     */
    public function test_a_failed_users_page_skips_the_deprovision_pass(): void {
        $this->prepare_site();
        $this->run_task($this->create_realm_task([$this->kc_user('kc-1', ['organization' => ['Demo Org']])]));

        $output = $this->run_task($this->create_realm_task([], [], [], true));

        $this->assertStringContainsString('skipping the deprovision pass', $output);
        $this->assertSame(',Demo Org,', $this->profile_value('kc-1', profile_fields::ORG));
    }
}
