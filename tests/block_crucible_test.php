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
 * Unit tests for the block_crucible block class.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/blocks/moodleblock.class.php');
require_once($CFG->dirroot . '/blocks/crucible/block_crucible.php');

/**
 * Unit tests for the block_crucible block class.
 *
 * @package    block_crucible
 * @category   test
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\block_crucible::class)]
final class block_crucible_test extends \advanced_testcase {
    /**
     * The constructor calls init(), which titles the block with the plugin name.
     */
    public function test_init_sets_the_plugin_name_as_title(): void {
        $block = new \block_crucible();

        $this->assertSame(get_string('pluginname', 'block_crucible'), $block->title);
    }

    /**
     * The block is offered on the dashboard and the site home only.
     */
    public function test_applicable_formats(): void {
        $block = new \block_crucible();

        $this->assertSame([
            'admin' => false,
            'site-index' => true,
            'course-view' => false,
            'mod' => false,
            'my' => true,
        ], $block->applicable_formats());
    }

    /**
     * Several instances of the block may live on the same page.
     */
    public function test_instance_allow_multiple(): void {
        $block = new \block_crucible();

        $this->assertTrue($block->instance_allow_multiple());
    }

    /**
     * The header visibility matrix of site default versus per-instance config.
     *
     * @param int $sitedefault Value stored in the showheader_default site setting.
     * @param int|null $instanceshowheader Per-instance showheader config, or null when the instance has none.
     * @param bool $expectedhidden Expected return value of hide_header().
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('hide_header_provider')]
    public function test_hide_header(int $sitedefault, ?int $instanceshowheader, bool $expectedhidden): void {
        $this->resetAfterTest();

        set_config('showheader_default', $sitedefault, 'block_crucible');

        $block = new \block_crucible();
        if ($instanceshowheader !== null) {
            $block->config = (object)['showheader' => $instanceshowheader];
        }

        $this->assertSame($expectedhidden, $block->hide_header());
    }

    /**
     * Data provider for test_hide_header().
     *
     * @return array[]
     */
    public static function hide_header_provider(): array {
        return [
            'site default on, no instance config' => [1, null, false],
            'site default off, no instance config' => [0, null, true],
            'instance shows header over site default off' => [0, 1, false],
            'instance hides header over site default on' => [1, 0, true],
        ];
    }

    /**
     * Each view type gets its own block heading.
     *
     * @param string $viewtype Value of the viewtype instance config.
     * @param string $stringkey Language string key the block is expected to use as the title.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('viewtype_title_provider')]
    public function test_specialization_titles_the_block_per_viewtype(string $viewtype, string $stringkey): void {
        $this->resetAfterTest();

        $block = new \block_crucible();
        $block->config = (object)['viewtype' => $viewtype];
        $block->specialization();

        $this->assertSame(get_string($stringkey, 'block_crucible'), $block->title);
    }

    /**
     * Data provider for test_specialization_titles_the_block_per_viewtype().
     *
     * @return array[]
     */
    public static function viewtype_title_provider(): array {
        return [
            'apps' => ['apps', 'applicationsheader'],
            'learningplan' => ['learningplan', 'blockheading'],
            'competencies' => ['competencies', 'competenciesheader'],
            'reports' => ['reports', 'reportsheader'],
        ];
    }

    /**
     * A per-instance title wins over the view type heading.
     */
    public function test_specialization_instance_title_overrides_viewtype_title(): void {
        $this->resetAfterTest();

        $block = new \block_crucible();
        $block->config = (object)[
            'viewtype' => 'apps',
            'title' => 'Crucible Ops',
        ];
        $block->specialization();

        $this->assertSame('Crucible Ops', $block->title);
        $this->assertNotSame(get_string('applicationsheader', 'block_crucible'), $block->title);
    }
}
