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

    /**
     * @param callable|null $onrefresh Called with the user id, or null to read nothing.
     */
    public function __construct(?callable $onrefresh = null) {
        $this->onrefresh = $onrefresh;
    }

    /**
     * Do whatever the test said a read does.
     *
     * @param int $userid
     * @return bool whether anything was written
     */
    public function refresh_user(int $userid): bool {
        if ($this->onrefresh === null) {
            return false;
        }
        ($this->onrefresh)($userid);

        return true;
    }
}
