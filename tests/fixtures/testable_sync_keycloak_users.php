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
 * Test double exposing the user sync task's HTTP seam.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\task;

use core\http_client;

defined('MOODLE_INTERNAL') || die();

/**
 * User sync task that answers HTTP requests from a supplied handler.
 *
 * The production client configuration is reused, so tests assert against the real timeouts and
 * verification settings rather than values they supplied themselves.
 */
class testable_sync_keycloak_users extends sync_keycloak_users {
    /** @var callable Guzzle handler answering every request. */
    private $handler;

    /**
     * Set the handler answering requests.
     *
     * @param callable $handler Guzzle handler.
     */
    public function set_handler(callable $handler): void {
        $this->handler = $handler;
    }

    /**
     * Create the client, routing it at the supplied handler.
     *
     * @param array $extraconfig Client configuration to apply over the defaults.
     * @return http_client
     */
    protected function create_http_client(array $extraconfig = []): http_client {
        return parent::create_http_client(['mock' => $this->handler] + $extraconfig);
    }
}
