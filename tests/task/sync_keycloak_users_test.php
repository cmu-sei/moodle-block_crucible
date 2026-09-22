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
}
