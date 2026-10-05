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
 * Unit tests for the Keycloak group and role lookups.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\RequestInterface;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/crucible/tests/fixtures/testable_crucible.php');

/**
 * Unit tests for the Keycloak group and role lookups.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(crucible::class)]
final class keycloak_client_test extends \advanced_testcase {
    /** @var array<int, array{request: RequestInterface, options: array}> Requests the client made. */
    private array $requests = [];

    /**
     * Configure the block against a Keycloak issuer and sign a user in.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
        $this->requests = [];

        $issuer = new \core\oauth2\issuer(0, (object) [
            'name' => 'Keycloak',
            'image' => 'https://kc.example.test/favicon.ico',
            'baseurl' => 'https://kc.example.test/realms/crucible',
            'clientid' => 'moodle',
            'clientsecret' => 'a-secret',
            'loginscopes' => 'openid',
            'loginscopesoffline' => 'openid offline_access',
            'loginparamsoffline' => '',
            'showonloginpage' => \core\oauth2\issuer::EVERYWHERE,
        ]);
        $issuer->create();

        set_config('issuerid', $issuer->get('id'), 'block_crucible');
        set_config('keycloakadminurl', 'https://kc.example.test/admin/crucible/console', 'block_crucible');

        $this->setUser($this->getDataGenerator()->create_user(['email' => 'learner@example.test']));
    }

    /**
     * Build a client that answers each Keycloak endpoint from the given map.
     *
     * @param array $responses Response keyed by 'token', 'users' and 'records', or by 'roles'
     *                         and 'groups' to answer those two sub-resources differently; each
     *                         is [status, body] or omitted to answer 200 with an empty object.
     * @return testable_crucible
     */
    private function create_crucible(array $responses = []): testable_crucible {
        $handler = function (RequestInterface $request, array $options) use ($responses): PromiseInterface {
            $this->requests[] = ['request' => $request, 'options' => $options];
            $uri = (string) $request->getUri();

            if (str_contains($uri, '/protocol/openid-connect/token')) {
                $answer = $responses['token'] ?? [200, '{"access_token": "kc-token"}'];
            } else if (str_contains($uri, '/users?')) {
                $answer = $responses['users'] ?? [200, '[{"id": "kc-uuid"}]'];
            } else if (str_contains($uri, '/role-mappings/realm') && isset($responses['roles'])) {
                $answer = $responses['roles'];
            } else if (str_contains($uri, '/groups') && isset($responses['groups'])) {
                $answer = $responses['groups'];
            } else {
                $answer = $responses['records'] ?? [200, '[{"name": "operators"}]'];
            }

            return Create::promiseFor(new Response($answer[0], [], $answer[1]));
        };

        $crucible = new testable_crucible();
        $crucible->set_handler($handler);
        return $crucible;
    }

    public function test_group_names_come_from_the_keycloak_group_records(): void {
        $crucible = $this->create_crucible([
            'records' => [200, '[{"name": "operators"}, {"name": "analysts"}, {"id": "no-name"}]'],
        ]);

        $this->assertSame(['operators', 'analysts'], $crucible->get_keycloak_groups());
    }

    public function test_a_render_resolves_each_keycloak_answer_once(): void {
        $crucible = $this->create_crucible();

        // What one block render asks for: roles at three points and groups at one.
        $crucible->get_keycloak_roles();
        $crucible->get_keycloak_groups();
        $crucible->get_keycloak_roles();
        $crucible->get_keycloak_roles();

        // Four requests, not twelve: the token and the user's Keycloak ID are shared, and the
        // repeated role lookups reuse the first answer.
        $this->assertCount(4, $this->requests);
        $uris = array_map(static fn($made) => (string) $made['request']->getUri(), $this->requests);
        $this->assertStringContainsString('/protocol/openid-connect/token', $uris[0]);
        $this->assertStringContainsString('exact=true', $uris[1]);
        $this->assertStringContainsString('/role-mappings/realm', $uris[2]);
        $this->assertStringContainsString('/groups', $uris[3]);
    }

    public function test_an_unreachable_keycloak_is_waited_on_once(): void {
        $crucible = $this->create_crucible(['token' => [503, 'Service Unavailable']]);

        // Each request is bounded separately, so retrying a dead Keycloak once per ask would put
        // twelve timeouts in series in front of the page.
        $this->assertFalse($crucible->get_keycloak_roles());
        $this->assertFalse($crucible->get_keycloak_groups());
        $this->assertFalse($crucible->get_keycloak_roles());

        $this->assertCount(1, $this->requests);
        // One report of the failure, not one per ask.
        $this->assertDebuggingCalledCount(1);
    }

    public function test_no_groups_are_reported_when_keycloak_returns_none(): void {
        $crucible = $this->create_crucible(['records' => [200, '[]']]);

        $this->assertSame(0, $crucible->get_keycloak_groups());
        $this->assertDebuggingCalled();
    }

    public function test_role_names_come_from_the_realm_role_mappings(): void {
        $crucible = $this->create_crucible(['records' => [200, '[{"name": "admin"}]']]);

        $this->assertSame(['admin'], $crucible->get_keycloak_roles());
        $this->assertStringContainsString(
            '/users/kc-uuid/role-mappings/realm',
            (string) end($this->requests)['request']->getUri()
        );
    }

    public function test_the_keycloak_user_search_requires_an_exact_email_match(): void {
        $crucible = $this->create_crucible();
        $crucible->get_keycloak_groups();

        // Keycloak matches a substring of the address unless told otherwise, and the first of
        // several matches would decide which groups this block reports.
        $uri = (string) $this->requests[1]['request']->getUri();
        $this->assertStringContainsString('exact=true', $uri);
        $this->assertStringContainsString('email=' . urlencode('learner@example.test'), $uri);
    }

    public function test_no_groups_are_reported_when_the_token_request_fails(): void {
        $crucible = $this->create_crucible(['token' => [401, '{"error": "unauthorized_client"}']]);

        $this->assertFalse($crucible->get_keycloak_groups());
        // Nothing may be asked of the admin API without a token.
        $this->assertCount(1, $this->requests);
        $this->assertDebuggingCalled();
    }

    public function test_no_groups_are_reported_when_the_user_is_not_found(): void {
        $crucible = $this->create_crucible(['users' => [200, '[]']]);

        $this->assertSame(0, $crucible->get_keycloak_groups());
        $this->assertCount(2, $this->requests);
        $this->assertDebuggingCalled();
    }

    public function test_keycloak_requests_carry_the_token_and_are_time_bounded(): void {
        $crucible = $this->create_crucible();
        $crucible->get_keycloak_groups();

        // These run while a block renders, so an unreachable Keycloak must not hang the page.
        $options = $this->requests[1]['options'];
        $this->assertSame(5, $options[RequestOptions::CONNECT_TIMEOUT]);
        $this->assertSame(10, $options[RequestOptions::TIMEOUT]);
        $this->assertSame('Bearer kc-token', $this->requests[1]['request']->getHeaderLine('Authorization'));
    }

    public function test_the_keycloak_peer_certificate_is_verified(): void {
        $crucible = $this->create_crucible();
        $crucible->get_keycloak_groups();

        // The admin token would otherwise be readable by anyone on the path.
        foreach ($this->requests as $made) {
            $this->assertTrue($made['options'][RequestOptions::VERIFY]);
        }
    }

    /**
     * Sign in a user Keycloak can be asked about.
     */
    private function set_linked_user(): void {
        $this->setUser($this->getDataGenerator()->create_user([
            'email' => 'learner@example.test',
            'idnumber' => 'kc-uuid',
        ]));
    }

    /**
     * Each configured admin role is matched on its own, not as one opaque string.
     *
     * Both settings are "|" separated lists, as their help text says. Compared whole they
     * could only match on a site that had configured exactly one value, because no Keycloak
     * role is named "admin|siteadmin" - so every administrator on a site listing two was told
     * they had no permissions, which hides the app tiles this gates.
     */
    public function test_an_admin_role_is_matched_out_of_a_list(): void {
        $this->set_linked_user();
        set_config('keycloakroles', 'siteadmin|operators', 'block_crucible');
        $crucible = $this->create_crucible(['roles' => [200, '[{"name": "operators"}]']]);

        $this->assertSame('operators', $crucible->get_user_permissions());
    }

    /**
     * A single configured role still behaves exactly as it did.
     */
    public function test_a_single_admin_role_still_matches(): void {
        $this->set_linked_user();
        set_config('keycloakroles', 'operators', 'block_crucible');
        $crucible = $this->create_crucible(['roles' => [200, '[{"name": "operators"}]']]);

        $this->assertSame('operators', $crucible->get_user_permissions());
    }

    /**
     * The group half of the setting is read too.
     *
     * It was read into a variable and then dropped, so "Admin Keycloak Groups" did nothing
     * here however it was filled in, though the docblock has always promised it would.
     */
    public function test_an_admin_group_grants_permissions(): void {
        $this->set_linked_user();
        set_config('keycloakgroups', 'analysts|operators', 'block_crucible');
        $crucible = $this->create_crucible([
            'roles' => [200, '[{"name": "learners"}]'],
            'groups' => [200, '[{"name": "operators"}]'],
        ]);

        $this->assertSame('operators', $crucible->get_user_permissions());
    }

    /**
     * A role match is reported ahead of a group match, as it was before.
     */
    public function test_a_role_match_is_preferred_over_a_group_match(): void {
        $this->set_linked_user();
        set_config('keycloakroles', 'operators', 'block_crucible');
        set_config('keycloakgroups', 'analysts', 'block_crucible');
        $crucible = $this->create_crucible([
            'roles' => [200, '[{"name": "operators"}]'],
            'groups' => [200, '[{"name": "analysts"}]'],
        ]);

        $this->assertSame('operators', $crucible->get_user_permissions());
    }

    /**
     * A user in none of the configured roles or groups is granted nothing.
     */
    public function test_no_match_grants_nothing(): void {
        $this->set_linked_user();
        set_config('keycloakroles', 'siteadmin|operators', 'block_crucible');
        set_config('keycloakgroups', 'analysts', 'block_crucible');
        $crucible = $this->create_crucible([
            'roles' => [200, '[{"name": "learners"}]'],
            'groups' => [200, '[{"name": "students"}]'],
        ]);

        $this->assertSame(0, $crucible->get_user_permissions());
    }

    /**
     * Neither setting configured matches nothing, rather than matching an empty name.
     *
     * explode() on an unset setting yields one empty string rather than no values, and an
     * empty string compares equal to more than it looks like it should.
     */
    public function test_unconfigured_settings_match_nothing(): void {
        $this->set_linked_user();
        $crucible = $this->create_crucible([
            'roles' => [200, '[{"name": ""}]'],
            'groups' => [200, '[{"name": ""}]'],
        ]);

        $this->assertSame(0, $crucible->get_user_permissions());
    }

    /**
     * Resolving permissions costs no more Keycloak requests than it did.
     *
     * The group lookup is new here, but a block render already asked for the groups further
     * down, and the answers are resolved once per render.
     */
    public function test_resolving_permissions_reuses_the_render_answers(): void {
        $this->set_linked_user();
        set_config('keycloakroles', 'operators', 'block_crucible');
        set_config('keycloakgroups', 'analysts', 'block_crucible');
        $crucible = $this->create_crucible([
            'roles' => [200, '[{"name": "learners"}]'],
            'groups' => [200, '[{"name": "students"}]'],
        ]);

        $crucible->get_user_permissions();
        $before = count($this->requests);
        $crucible->get_keycloak_groups();
        $crucible->get_keycloak_roles();

        $this->assertSame($before, count($this->requests));
    }
}
