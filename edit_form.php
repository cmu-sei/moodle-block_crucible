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
        $mform->addHelpButton('config_showheader', 'showheader', 'block_crucible');
        $mform->setDefault('config_showheader', 0);

        $mform->addElement('select', 'config_viewtype',
            get_string('config_viewtype', 'block_crucible'),
            [
                'apps'          => get_string('view_apps', 'block_crucible'),
                'learningplan'  => get_string('view_learningplan', 'block_crucible'),
                'competencies'  => get_string('view_competencies', 'block_crucible'),
                'reports'       => get_string('view_reports', 'block_crucible'),
            ]
        );
        $mform->setDefault('config_viewtype', 'apps');

        // --- Framework selector (shown only for "learningplan") ---
        $frameworkopts = [];
        if (class_exists('\core_competency\competency_framework')) {
            $records = \core_competency\competency_framework::get_records([], 'shortname', 'ASC');
            foreach ($records as $fw) {
                $id = (int)$fw->get('id');
                $label = $fw->get('shortname');
                $frameworkopts[$id] = format_string($label ?: (string)$id);
            }
        }
        if (empty($frameworkopts)) {
            // No frameworks found
            $frameworkopts = ['' => get_string('none')];
        }

        $mform->addElement(
            'select',
            'config_frameworkid',
            get_string('config_frameworkid', 'block_crucible'),
            $frameworkopts
        );
        $mform->setType('config_frameworkid', PARAM_INT);
        $mform->hideIf('config_frameworkid', 'config_viewtype', 'neq', 'learningplan');

        $mform->addHelpButton('config_frameworkid', 'config_frameworkid', 'block_crucible');
    }

    public static function display_form_when_adding(): bool {
        return true;
    }
}
