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
 * Keycloak client whose single-user read is whatever a test says it is.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\local;

defined('MOODLE_INTERNAL') || die();

/**
 * Stands in for the Keycloak client where the test is about what happens around the read.
 *
 * The observer tests are about the login path, not about Keycloak's wire format, so they say
 * what the read does - writes these groups, or throws - and leave the HTTP out of it.
 */
class stub_keycloak extends keycloak {
    /** @var callable|null Called with the user id, or null to read nothing. */
    private $onrefresh;

    /** @var callable|null Returns what a read of the user returns, or null for a failed read. */
    private $onread;

    /**
     * Build a stub that does what the test says a login-time read does.
     *
     * @param callable|null $onrefresh Called with the user id, or null to read nothing.
     * @param callable|null $onread Called with the user id, returning the read's state - an
     *        array carrying 'sitegroups' - or null for a read that failed.
     */
    public function __construct(?callable $onrefresh = null, ?callable $onread = null) {
        $this->onrefresh = $onrefresh;
        $this->onread = $onread;
    }

    /**
     * Do whatever the test said a read does.
     *
     * @param int $userid
     * @param array|null $state set to what read_user() returns
     * @return bool whether anything was written
     */
    public function refresh_user(int $userid, ?array &$state = null): bool {
        $state = $this->read_user($userid);
        if ($this->onrefresh === null) {
            return false;
        }
        ($this->onrefresh)($userid);

        return true;
    }

    /**
     * Return whatever the test said a read returns.
     *
     * @param int $userid
     * @return array|null
     */
    public function read_user(int $userid): ?array {
        return $this->onread === null ? null : ($this->onread)($userid);
    }
}
