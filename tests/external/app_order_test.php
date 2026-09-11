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
 * Unit tests for the application order external functions.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\external;

defined('MOODLE_INTERNAL') || die();

/**
 * Unit tests for save_app_order and get_app_order.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_crucible\external\save_app_order::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\block_crucible\external\get_app_order::class)]
final class app_order_test extends \advanced_testcase {
    /**
     * The name of the user preference the two external functions share.
     */
    const PREFERENCE = 'block_crucible_app_order';

    /**
     * Log in as a throwaway user, since both functions work on user preferences.
     */
    protected function setUp(): void {
        parent::setUp();

        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
    }

    /**
     * What save_app_order stores, get_app_order reads back unchanged.
     */
    public function test_save_then_get_round_trip(): void {
        $order = '["alloy","player"]';

        $saved = save_app_order::execute($order);
        $this->assertTrue($saved['success']);

        $fetched = get_app_order::execute();
        $this->assertSame($order, $fetched['order']);
    }

    /**
     * A user who has never reordered anything gets the empty JSON array.
     */
    public function test_get_returns_the_default_when_no_preference_is_set(): void {
        $this->assertSame('[]', get_app_order::execute()['order']);
    }

    /**
     * Anything that is not a JSON array is rejected before it reaches the preference.
     */
    public function test_save_rejects_invalid_json(): void {
        $this->expectException(\invalid_parameter_exception::class);

        save_app_order::execute('not json');
    }

    /**
     * The order is stored verbatim in the documented user preference.
     */
    public function test_save_writes_the_documented_user_preference(): void {
        $order = '["alloy","player"]';

        save_app_order::execute($order);

        $this->assertSame($order, get_user_preferences(self::PREFERENCE));
    }

    /**
     * save_app_order::execute_returns() describes exactly what execute() returns.
     */
    public function test_save_return_description_matches_the_real_result(): void {
        $result = save_app_order::execute('["alloy","player"]');

        $cleaned = \core_external\external_api::clean_returnvalue(
            save_app_order::execute_returns(),
            $result
        );

        $this->assertSame(['success', 'message'], array_keys($cleaned));
        $this->assertSame(array_keys($result), array_keys($cleaned));
        $this->assertTrue((bool)$cleaned['success']);
        $this->assertNotEmpty($cleaned['message']);
    }

    /**
     * get_app_order::execute_returns() describes exactly what execute() returns.
     */
    public function test_get_return_description_matches_the_real_result(): void {
        save_app_order::execute('["alloy","player"]');

        $result = get_app_order::execute();

        $cleaned = \core_external\external_api::clean_returnvalue(
            get_app_order::execute_returns(),
            $result
        );

        $this->assertSame(['order'], array_keys($cleaned));
        $this->assertSame(array_keys($result), array_keys($cleaned));
        $this->assertSame('["alloy","player"]', $cleaned['order']);
    }
}
