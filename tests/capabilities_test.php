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

/*
Crucible Applications Landing Page Block for Moodle

Copyright 2024 Carnegie Mellon University.

NO WARRANTY. THIS CARNEGIE MELLON UNIVERSITY AND SOFTWARE ENGINEERING INSTITUTE MATERIAL IS FURNISHED ON AN "AS-IS" BASIS.
CARNEGIE MELLON UNIVERSITY MAKES NO WARRANTIES OF ANY KIND, EITHER EXPRESSED OR IMPLIED, AS TO ANY MATTER INCLUDING, BUT NOT LIMITED TO,
WARRANTY OF FITNESS FOR PURPOSE OR MERCHANTABILITY, EXCLUSIVITY, OR RESULTS OBTAINED FROM USE OF THE MATERIAL.
CARNEGIE MELLON UNIVERSITY DOES NOT MAKE ANY WARRANTY OF ANY KIND WITH RESPECT TO FREEDOM FROM PATENT, TRADEMARK, OR COPYRIGHT INFRINGEMENT.
Licensed under a GNU GENERAL PUBLIC LICENSE - Version 3, 29 June 2007-style license, please see license.txt or contact permission@sei.cmu.edu for full terms.

[DISTRIBUTION STATEMENT A] This material has been approved for public release and unlimited distribution. Please see Copyright notice for non-US Government use and distribution.

This Software includes and/or makes use of Third-Party Software each subject to its own license.

DM24-1176
*/

/**
 * Unit tests for the capabilities declared by the Crucible block.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for block_crucible capability definitions.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversNothing]
final class capabilities_test extends \basic_testcase {
    /**
     * Capabilities loaded from the plugin's access.php file.
     *
     * @var array
     */
    private $capabilities;

    /**
     * Load the capability definitions straight from the plugin's db/access.php.
     */
    protected function setUp(): void {
        parent::setUp();

        global $CFG;
        require($CFG->dirroot . '/blocks/crucible/db/access.php');
        $this->capabilities = $capabilities;
    }

    /**
     * The block declares exactly the two block instance capabilities.
     */
    public function test_capabilities_are_declared(): void {
        $this->assertArrayHasKey('block/crucible:myaddinstance', $this->capabilities);
        $this->assertArrayHasKey('block/crucible:addinstance', $this->capabilities);
        $this->assertCount(2, $this->capabilities);
    }

    /**
     * The my-dashboard capability is a system level write capability cloned from my:manageblocks.
     */
    public function test_myaddinstance_structure(): void {
        $c = $this->capabilities['block/crucible:myaddinstance'];

        $this->assertSame('write', $c['captype']);
        $this->assertSame(CONTEXT_SYSTEM, $c['contextlevel']);
        $this->assertSame(['user' => CAP_ALLOW], $c['archetypes']);
        $this->assertSame('moodle/my:manageblocks', $c['clonepermissionsfrom']);
    }

    /**
     * The add-instance capability is a block level write capability cloned from site:manageblocks.
     */
    public function test_addinstance_structure(): void {
        $c = $this->capabilities['block/crucible:addinstance'];

        $this->assertSame('write', $c['captype']);
        $this->assertSame(CONTEXT_BLOCK, $c['contextlevel']);
        $this->assertSame(['manager' => CAP_ALLOW], $c['archetypes']);
        $this->assertSame('moodle/site:manageblocks', $c['clonepermissionsfrom']);
    }

    /**
     * Neither capability is handed to students or guests by default.
     */
    public function test_archetypes_do_not_include_students_or_guests(): void {
        foreach ($this->capabilities as $capability => $definition) {
            $this->assertArrayNotHasKey('student', $definition['archetypes'], $capability);
            $this->assertArrayNotHasKey('guest', $definition['archetypes'], $capability);
        }
    }
}
