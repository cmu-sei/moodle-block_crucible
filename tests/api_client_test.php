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
 * Unit tests for the Crucible API client configuration.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for the Crucible API client configuration.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(crucible::class)]
final class api_client_test extends \advanced_testcase {
    /**
     * Read the private options of a \curl instance.
     *
     * @param \curl $client Client to inspect.
     * @return array The cURL options in force.
     */
    private function curl_options(\curl $client): array {
        $options = \Closure::bind(
            static function (\curl $client): array {
                return (array) $client->options;
            },
            null,
            \curl::class
        );
        return $options($client);
    }

    /**
     * Run the protected configure_api_client() method.
     *
     * @param \curl $client Client to configure.
     * @return \curl The same client.
     */
    private function configure_api_client(\curl $client): \curl {
        $configure = \Closure::bind(
            static function (crucible $crucible, \curl $client): \curl {
                return $crucible->configure_api_client($client);
            },
            null,
            crucible::class
        );
        return $configure(new crucible(), $client);
    }

    public function test_the_api_client_verifies_the_peer_certificate(): void {
        $this->resetAfterTest(true);

        // Core's OAuth client inherits these defaults from \curl: verification off, and up to ten
        // redirects replaying the request headers. This is what the configuration is for.
        $client = new \curl();
        $this->assertSame(0, $this->curl_options($client)['CURLOPT_SSL_VERIFYPEER']);

        $this->configure_api_client($client);

        $options = $this->curl_options($client);
        $this->assertSame(1, $options['CURLOPT_SSL_VERIFYPEER']);
        $this->assertSame(2, $options['CURLOPT_SSL_VERIFYHOST']);
    }

    public function test_the_api_client_requests_are_time_bounded(): void {
        $this->resetAfterTest(true);
        $client = new \curl();

        // These calls run while the block renders, so an unreachable API must not hang the page.
        $this->configure_api_client($client);

        $options = $this->curl_options($client);
        $this->assertSame(crucible::API_CONNECT_TIMEOUT_SECONDS, $options['CURLOPT_CONNECTTIMEOUT']);
        $this->assertSame(crucible::API_TIMEOUT_SECONDS, $options['CURLOPT_TIMEOUT']);
    }
}
