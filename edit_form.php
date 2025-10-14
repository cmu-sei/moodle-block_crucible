<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/edit_form.php');

class block_crucible_edit_form extends block_edit_form {
    protected function specific_definition($mform) {
        $mform->addElement('header', 'crucibleappearance',
            get_string('configsection_appearance', 'block_crucible'));
        $mform->setExpanded('crucibleappearance', true);

        $mform->addElement('text', 'config_title', get_string('configtitle', 'block_crucible'));
        $mform->setType('config_title', PARAM_TEXT);

        $mform->addElement(
            'advcheckbox',
            'config_showheader',
            get_string('showheader', 'block_crucible'),
            null, null, [0, 1]
        );

        $default = (int)get_config('block_crucible', 'showheader_default');
        $mform->setDefault('config_showheader', $default ?: 1); // default to ON if unset
        $mform->addHelpButton('config_showheader', 'showheader', 'block_crucible');

        // Default for NEW instances: show header.
        $mform->setDefault('config_showheader', 1);

        $mform->addElement('select', 'config_viewtype',
            get_string('config_viewtype', 'block_crucible'),
            [
                'apps'          => get_string('view_apps', 'block_crucible'),
                'learningplan'  => get_string('view_learningplan', 'block_crucible'),
                'competencies'  => get_string('view_competencies', 'block_crucible'), // NEW
            ]
        );
        $mform->setDefault('config_viewtype', 'apps');
    }
}
