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
     * @param array $responses Response keyed by 'token', 'users' and 'records'; each is
     *                         [status, body] or omitted to answer 200 with an empty object.
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
}
