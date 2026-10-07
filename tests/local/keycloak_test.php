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
 * Unit tests for the single-user Keycloak read.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\local;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/crucible/tests/fixtures/testable_keycloak.php');

/**
 * Unit tests for \block_crucible\local\keycloak.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(keycloak::class)]
final class keycloak_test extends \advanced_testcase {
    /** @var array<int, array{request: RequestInterface, options: array}> Requests the client made. */
    private array $requests = [];

    /**
     * Provision the profile fields and a Keycloak issuer.
     */
    protected function setUp(): void {
        global $CFG;

        parent::setUp();
        $this->resetAfterTest(true);
        require_once($CFG->dirroot . '/user/profile/lib.php');

        profile_fields::install();
        org_roles::reset_caches();
        keycloak::purge_cache();

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
     * A client answering the admin endpoints for one account.
     *
     * @param array|null $kcuser The user representation, or null to answer 404.
     * @param array $groups Group representations the user is a member of.
     * @param bool $groupsfail Make the groups endpoint answer with a 500.
     * @return testable_keycloak
     */
    private function create_client(?array $kcuser, array $groups = [], bool $groupsfail = false): testable_keycloak {
        $this->requests = [];
        $handler = function (RequestInterface $request, array $options) use ($kcuser, $groups, $groupsfail) {
            $this->requests[] = ['request' => $request, 'options' => $options];
            $path = $request->getUri()->getPath();

            if (str_ends_with($path, '/protocol/openid-connect/token')) {
                return $this->json(['access_token' => 'kc-token', 'expires_in' => 60]);
            }
            if (str_ends_with($path, '/groups')) {
                return $groupsfail
                    ? Create::promiseFor(new Response(500, [], 'boom'))
                    : $this->json($groups);
            }

            return $kcuser === null
                ? Create::promiseFor(new Response(404, [], '{"error":"User not found"}'))
                : $this->json($kcuser);
        };

        $client = new testable_keycloak();
        $client->set_handler($handler);

        return $client;
    }

    /**
     * An oauth2 user linked to a Keycloak account.
     *
     * @param string $kcid
     * @return int user id
     */
    private function create_linked_user(string $kcid = 'kc-1'): int {
        $user = $this->getDataGenerator()->create_user(['auth' => 'oauth2', 'idnumber' => $kcid]);

        return (int)$user->id;
    }

    /**
     * Read one of the user's profile fields.
     *
     * @param int $userid
     * @param string $shortname
     * @return string
     */
    private function profile_value(int $userid, string $shortname): string {
        $record = profile_user_record($userid, false);

        return isset($record->$shortname) ? (string)$record->$shortname : '';
    }

    /**
     * How many requests went to the user endpoint, ignoring the token request.
     *
     * @return int
     */
    private function user_request_count(): int {
        $count = 0;
        foreach ($this->requests as $made) {
            if (!str_ends_with($made['request']->getUri()->getPath(), '/protocol/openid-connect/token')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A read writes both halves of each list field: the matching one and the readable one.
     */
    public function test_a_read_writes_the_matching_and_the_readable_field(): void {
        $userid = $this->create_linked_user();
        $client = $this->create_client(
            ['id' => 'kc-1', 'attributes' => ['organization' => ['Demo Org', 'Second Org']]],
            [['name' => 'cyber-managers'], ['name' => 'crucible']]
        );

        $this->assertTrue($client->refresh_user($userid));

        $this->assertSame('|Demo Org|Second Org|', $this->profile_value($userid, profile_fields::ORGLIST));
        $this->assertSame('Demo Org, Second Org', $this->profile_value($userid, profile_fields::ORG));
        // Only the mapped groups: a Keycloak realm has many groups that mean nothing here.
        $this->assertSame('|cyber-managers|', $this->profile_value($userid, profile_fields::GROUPSLIST));
        $this->assertSame('cyber-managers', $this->profile_value($userid, profile_fields::GROUPS));
    }

    /**
     * An absent organization attribute leaves the stored one alone.
     *
     * The scheduled task can read an absent attribute as a removal, because it sees whether
     * any user in the realm carries it. One record is no evidence of that, and clearing the
     * field revokes every role the organization granted.
     */
    public function test_an_absent_organization_attribute_is_not_a_removal(): void {
        $userid = $this->create_linked_user();
        $this->create_client(['id' => 'kc-1', 'attributes' => ['organization' => ['Demo Org']]])
            ->refresh_user($userid);
        $this->assertSame('|Demo Org|', $this->profile_value($userid, profile_fields::ORGLIST));

        $this->create_client(['id' => 'kc-1', 'attributes' => []])->refresh_user($userid);

        $this->assertSame('|Demo Org|', $this->profile_value($userid, profile_fields::ORGLIST));
        $this->assertSame('Demo Org', $this->profile_value($userid, profile_fields::ORG));
    }

    /**
     * No group membership is a fact rather than a silence, so it is written.
     *
     * Membership is not an attribute: Keycloak answers with the groups the user is in, and an
     * empty answer means they are in none. The reconcile that follows takes the roles back.
     */
    public function test_losing_every_group_clears_the_group_fields(): void {
        $userid = $this->create_linked_user();
        $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']])->refresh_user($userid);
        $this->assertSame('|cyber-managers|', $this->profile_value($userid, profile_fields::GROUPSLIST));

        $this->create_client(['id' => 'kc-1'], [])->refresh_user($userid);

        $this->assertSame('', $this->profile_value($userid, profile_fields::GROUPSLIST));
        $this->assertSame('', $this->profile_value($userid, profile_fields::GROUPS));
    }

    /**
     * An account Keycloak does not know leaves the profile exactly as it is.
     */
    public function test_an_unknown_account_changes_nothing(): void {
        $userid = $this->create_linked_user();
        $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']])->refresh_user($userid);

        $this->assertFalse($this->create_client(null)->refresh_user($userid));

        $this->assertSame('|cyber-managers|', $this->profile_value($userid, profile_fields::GROUPSLIST));
    }

    /**
     * A 404 is not a Keycloak failure, so it does not stop the next login asking.
     *
     * One account missing from the realm says nothing about the rest. Treating it as a
     * failure would let a single stale link suppress the read for everybody.
     */
    public function test_an_unknown_account_does_not_suppress_the_next_read(): void {
        $userid = $this->create_linked_user();
        $this->create_client(null)->refresh_user($userid);

        $client = $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']]);
        $client->refresh_user($userid);

        $this->assertSame('|cyber-managers|', $this->profile_value($userid, profile_fields::GROUPSLIST));
    }

    /**
     * After a failure the next logins do not ask again.
     *
     * Without this, a Keycloak refusing connections adds the connect timeout to every login
     * on the site for as long as it stays down.
     */
    public function test_a_failure_suppresses_the_next_read(): void {
        $userid = $this->create_linked_user();

        $this->create_client(['id' => 'kc-1'], [], true)->refresh_user($userid);
        $this->assertDebuggingCalled();

        $client = $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']]);
        $this->assertFalse($client->refresh_user($userid));

        $this->assertSame(0, $this->user_request_count());
        $this->assertSame('', $this->profile_value($userid, profile_fields::GROUPSLIST));
    }

    /**
     * A user who did not arrive through OAuth 2, or is not linked, is never asked about.
     */
    public function test_an_unlinked_user_is_not_asked_about(): void {
        $manual = (int)$this->getDataGenerator()->create_user(['auth' => 'manual', 'idnumber' => 'kc-1'])->id;
        $unlinked = (int)$this->getDataGenerator()->create_user(['auth' => 'oauth2'])->id;
        $client = $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']]);

        $this->assertFalse($client->refresh_user($manual));
        $this->assertFalse($client->refresh_user($unlinked));

        $this->assertSame([], $this->requests);
    }

    /**
     * The token is reused, so one login's read is one round trip rather than two.
     */
    public function test_the_token_is_cached_between_reads(): void {
        $first = $this->create_linked_user('kc-1');
        $second = $this->create_linked_user('kc-2');

        $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']])->refresh_user($first);
        $client = $this->create_client(['id' => 'kc-2'], [['name' => 'lab-builders']]);
        $client->refresh_user($second);

        foreach ($this->requests as $made) {
            $this->assertStringNotContainsString('/protocol/openid-connect/token', $made['request']->getUri()->getPath());
        }
        $this->assertSame('|lab-builders|', $this->profile_value($second, profile_fields::GROUPSLIST));
    }

    /**
     * Changing the issuer drops the cached token, which belonged to the old one.
     */
    public function test_changing_the_issuer_drops_the_cached_token(): void {
        $userid = $this->create_linked_user();
        $this->create_client(['id' => 'kc-1'])->refresh_user($userid);

        keycloak::purge_cache();
        $client = $this->create_client(['id' => 'kc-1']);
        $client->refresh_user($userid);

        $paths = [];
        foreach ($this->requests as $made) {
            $paths[] = $made['request']->getUri()->getPath();
        }
        $this->assertContains('/realms/crucible/protocol/openid-connect/token', $paths);
    }

    /**
     * These requests run while a user waits for a login, so they are bounded in seconds.
     */
    public function test_the_requests_are_time_bounded_and_verified(): void {
        $userid = $this->create_linked_user();
        $client = $this->create_client(['id' => 'kc-1']);

        $client->refresh_user($userid);

        $options = $this->requests[0]['options'];
        $this->assertGreaterThan(0, $options[RequestOptions::CONNECT_TIMEOUT]);
        $this->assertLessThanOrEqual(keycloak::CONNECT_TIMEOUT_SECONDS, $options[RequestOptions::CONNECT_TIMEOUT]);
        $this->assertGreaterThan(0, $options[RequestOptions::TIMEOUT]);
        $this->assertLessThanOrEqual(keycloak::TIMEOUT_SECONDS, $options[RequestOptions::TIMEOUT]);
        // The request carries the client secret and then a realm admin token.
        $this->assertTrue($options[RequestOptions::VERIFY]);
    }

    /**
     * One read is up to three requests, and they share one deadline rather than each
     * carrying its own timeout.
     *
     * A per-request timeout bounds a request, not a login: a token request, a user request
     * and a groups request carrying three seconds each cost nine. That only happens when
     * Keycloak's admin API is slow while its login still works, which is exactly the
     * situation a login-time read has to survive.
     */
    public function test_the_whole_read_shares_one_deadline(): void {
        $userid = $this->create_linked_user();
        $client = $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']]);

        $client->refresh_user($userid);

        $this->assertCount(3, $this->requests);
        $previous = null;
        foreach ($this->requests as $made) {
            $timeout = $made['options'][RequestOptions::TIMEOUT];
            $this->assertLessThanOrEqual(keycloak::BUDGET_SECONDS, $timeout);
            if ($previous !== null) {
                $this->assertLessThanOrEqual($previous, $timeout);
            }
            $previous = $timeout;
        }
    }

    /**
     * A read that changed something announces it, so realtime cohort rules see the change.
     *
     * profile_save_data() fires no event of its own, and tool_dynamic_cohorts' realtime
     * processing reacts to user_created and user_updated only - not to the user_loggedin this
     * runs under. Without the event the roles are granted during the login but cohort
     * membership, and any enrolment made through a cohort, waits for that plugin's own run.
     */
    public function test_a_change_triggers_user_updated(): void {
        $userid = $this->create_linked_user();
        $client = $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']]);

        $sink = $this->redirectEvents();
        $this->assertTrue($client->refresh_user($userid));

        $updates = 0;
        foreach ($sink->get_events() as $event) {
            if ($event instanceof \core\event\user_updated && (int)$event->objectid === $userid) {
                $updates++;
            }
        }
        $this->assertSame(1, $updates);
    }

    /**
     * An ordinary login, where Keycloak says what Moodle already holds, announces nothing.
     *
     * The event is observed by every realtime rule with a profile condition, so firing it on
     * every login would spend that work on nothing.
     */
    public function test_a_read_that_changes_nothing_triggers_no_event(): void {
        $userid = $this->create_linked_user();
        $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']])->refresh_user($userid);

        $client = $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']]);
        $sink = $this->redirectEvents();
        $this->assertFalse($client->refresh_user($userid));

        foreach ($sink->get_events() as $event) {
            $this->assertNotInstanceOf(\core\event\user_updated::class, $event);
        }
    }

    /**
     * The realm's group list is read for the picker, sorted and de-duplicated.
     */
    public function test_the_group_list_is_read_for_the_picker(): void {
        $client = $this->create_client(null, [['name' => 'range-staff'], ['name' => 'analysts']]);

        $this->assertSame(['analysts', 'range-staff'], $client->group_names());
    }

    /**
     * The group list is cached, because it is read while a settings page renders.
     */
    public function test_the_group_list_is_cached(): void {
        $this->create_client(null, [['name' => 'range-staff']])->group_names();

        $client = $this->create_client(null, [['name' => 'something-else']]);

        $this->assertSame(['range-staff'], $client->group_names());
        $this->assertSame([], $this->requests);
    }

    /**
     * The refresh button ignores the cache, which is the only reason it exists.
     */
    public function test_a_refresh_ignores_the_cached_group_list(): void {
        $this->create_client(null, [['name' => 'range-staff']])->group_names();

        $client = $this->create_client(null, [['name' => 'something-else']]);

        $this->assertSame(['something-else'], $client->group_names(true));
    }

    /**
     * An unreadable realm gives no list rather than an empty one.
     *
     * An empty list is indistinguishable from a realm with no groups, and a picker built
     * from it would offer nothing while looking like it had asked successfully.
     */
    public function test_an_unreadable_realm_gives_no_group_list(): void {
        $client = new testable_keycloak();
        $client->set_handler(function (RequestInterface $request, array $options): PromiseInterface {
            $this->requests[] = ['request' => $request, 'options' => $options];
            if (str_ends_with($request->getUri()->getPath(), '/protocol/openid-connect/token')) {
                return $this->json(['access_token' => 'kc-token', 'expires_in' => 60]);
            }

            return Create::promiseFor(new Response(500, [], 'boom'));
        });

        $this->assertNull($client->group_names());
        $this->assertDebuggingCalled();
    }

    /**
     * Reading the group list does not suppress the login-time read.
     *
     * They are different concerns. Sharing the failure stamp would let one administrator
     * opening a settings page stop every login reading Keycloak for the next few minutes.
     */
    public function test_a_group_list_failure_does_not_suppress_the_login_read(): void {
        $userid = $this->create_linked_user();
        $failing = new testable_keycloak();
        $failing->set_handler(function (RequestInterface $request, array $options): PromiseInterface {
            if (str_ends_with($request->getUri()->getPath(), '/protocol/openid-connect/token')) {
                return $this->json(['access_token' => 'kc-token', 'expires_in' => 60]);
            }

            return Create::promiseFor(new Response(500, [], 'boom'));
        });
        $this->assertNull($failing->group_names());
        $this->assertDebuggingCalled();

        $client = $this->create_client(['id' => 'kc-1'], [['name' => 'cyber-managers']]);

        $this->assertTrue($client->refresh_user($userid));
        $this->assertSame('|cyber-managers|', $this->profile_value($userid, profile_fields::GROUPSLIST));
    }

    /**
     * The admin API base is derived from the issuer's token endpoint, not configured twice.
     */
    public function test_the_admin_base_comes_from_the_issuer(): void {
        $realm = keycloak::realm();

        $this->assertSame('https://kc.example.test/admin/realms/crucible', $realm['adminbase']);
        $this->assertSame('moodle', $realm['clientid']);
    }

    /**
     * An issuer that is not a Keycloak realm is reported rather than guessed at.
     */
    public function test_a_non_realm_issuer_is_rejected(): void {
        global $DB;

        $DB->set_field('oauth2_endpoint', 'url', 'https://idp.example.test/oauth/token');

        $this->assertNull(keycloak::realm());
        $this->assertDebuggingCalled();
    }
}
