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
 * Unit tests for the block_crucible privacy provider.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\types\external_location;
use core_privacy\local\metadata\types\subsystem_link;
use core_privacy\local\metadata\types\user_preference;
use core_privacy\local\request\writer;

/**
 * Unit tests for the block_crucible privacy provider.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(provider::class)]
final class provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * The metadata names the preference, every service the user is identified to, and the
     * subsystems the syncs write through.
     */
    public function test_get_metadata(): void {
        $items = provider::get_metadata(new collection('block_crucible'))->get_collection();

        $byname = [];
        foreach ($items as $item) {
            $byname[$item->get_name()] = $item;
        }

        $this->assertInstanceOf(user_preference::class, $byname['block_crucible_app_order']);

        $this->assertInstanceOf(external_location::class, $byname['keycloak']);
        $this->assertSame(['idnumber', 'email'], array_keys($byname['keycloak']->get_privacy_fields()));

        foreach (['player', 'blueprint', 'cite', 'gallery', 'topomojo', 'gameboard'] as $app) {
            $this->assertInstanceOf(external_location::class, $byname[$app]);
            $this->assertSame(['idnumber'], array_keys($byname[$app]->get_privacy_fields()));
        }

        foreach (['core_user', 'core_role', 'core_cohort'] as $subsystem) {
            $this->assertInstanceOf(subsystem_link::class, $byname[$subsystem]);
        }

        $this->assertCount(11, $items);
    }

    /**
     * A user who has arranged their tiles gets the order exported.
     */
    public function test_export_user_preferences(): void {
        $this->resetAfterTest();

        $user = $this->getDataGenerator()->create_user();
        $order = json_encode(['player', 'cite', 'gallery']);
        set_user_preference('block_crucible_app_order', $order, $user);

        provider::export_user_preferences($user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertTrue($writer->has_any_data());
        $prefs = $writer->get_user_preferences('block_crucible');
        $this->assertSame($order, $prefs->block_crucible_app_order->value);
        $this->assertSame(
            get_string('privacy:metadata:preference:apporder', 'block_crucible'),
            $prefs->block_crucible_app_order->description
        );
    }

    /**
     * A user who never arranged their tiles has nothing exported, even when another user has.
     */
    public function test_export_user_preferences_without_preference(): void {
        $this->resetAfterTest();

        $other = $this->getDataGenerator()->create_user();
        set_user_preference('block_crucible_app_order', json_encode(['player']), $other);
        $user = $this->getDataGenerator()->create_user();

        provider::export_user_preferences($user->id);

        $writer = writer::with_context(\context_system::instance());
        $this->assertFalse($writer->has_any_data());
        $this->assertEmpty((array)$writer->get_user_preferences('block_crucible'));
    }
}
