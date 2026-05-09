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
 * Configurable Reports a Moodle block for creating customizable reports
 *
 * @copyright  2020 Juan Leyva <juan@moodle.com>
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');

/**
 * Class coursecustomfield_form
 *
 * @package   block_configurable_reports
 */
class coursecustomfield_form extends moodleform {

    /**
     * Form definition
     */
    public function definition(): void {
        global $DB;

        $mform =& $this->_form;

        $mform->addElement('header', 'crformheader', get_string('coursecustomfield', 'block_configurable_reports'), '');

        // customfield_field からフィールド一覧を取得（コースカテゴリ = 'course'）.
        $fields = [];
        $sql = 'SELECT f.shortname, f.name, f.type
                  FROM {customfield_field} f
                  JOIN {customfield_category} c ON c.id = f.categoryid
                 WHERE c.component = :component
              ORDER BY c.sortorder, f.sortorder';
        $records = $DB->get_records_sql($sql, ['component' => 'core_course']);

        foreach ($records as $r) {
            $fields[$r->shortname] = $r->name . ' (' . $r->shortname . ')';
        }

        if (empty($fields)) {
            // カスタムフィールドが未定義の場合はテキスト入力にフォールバック.
            $mform->addElement('text', 'fieldshortname', get_string('coursecustomfield_shortname', 'block_configurable_reports'));
            $mform->setType('fieldshortname', PARAM_ALPHANUMEXT);
            $mform->addRule('fieldshortname', get_string('required'), 'required');
        } else {
            $mform->addElement('select', 'fieldshortname',
                get_string('coursecustomfield_shortname', 'block_configurable_reports'), $fields);
        }

        $mform->addElement('text', 'fieldvalue',
            get_string('coursecustomfield_value', 'block_configurable_reports'));
        $mform->setType('fieldvalue', PARAM_RAW);
        $mform->addRule('fieldvalue', get_string('required'), 'required');
        $mform->addHelpButton('fieldvalue', 'coursecustomfield_value', 'block_configurable_reports');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

    /**
     * Validation
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = parent::validation($data, $files);

        if (!empty($data['fieldshortname'])) {
            $exists = $DB->record_exists('customfield_field', ['shortname' => $data['fieldshortname']]);
            if (!$exists) {
                $errors['fieldshortname'] = get_string('coursecustomfield_error_field', 'block_configurable_reports');
            }
        }

        return $errors;
    }

}
