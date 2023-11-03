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
 * Global search block.
 *
 * @package    block_crucible
 * @copyright  Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Global search block.
 *
 * @package    block_crucible
 * @copyright  Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_crucible extends block_base {

    /**
     * Initialises the block.
     *
     * @return void
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_crucible');
    }

    /**
     * Gets the block contents.
     *
     * If we can avoid it better not check the server status here as connecting
     * to the server will slow down the whole page load.
     *
     * @return string The block HTML.
     */
    public function get_content() {
        global $OUTPUT;
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->footer = '';

	/*
        if (\core_search\manager::is_crucible_enabled() === false) {
            $this->content->text = get_string('crucibledisabled', 'search');
            return $this->content;
        }
	 */
	/*
        $data = [
            'action' => new moodle_url('/search/index.php'),
            'inputname' => 'q',
            'searchstring' => get_string('search'),
        ];

        if ($this->page->context && $this->page->context->contextlevel !== CONTEXT_SYSTEM) {
            $data['hiddenfields'] = (object) ['name' => 'context', 'value' => $this->page->context->id];
        }
	 */
        //$this->content->text = $OUTPUT->render_from_template('core/search_input', $data);
        $this->content->text = $OUTPUT->render_from_template('block_crucible/landing', $data);

        return $this->content;
    }
}
