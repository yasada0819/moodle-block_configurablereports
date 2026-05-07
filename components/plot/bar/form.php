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
 * @author     Juan leyva <http://www.twitter.com/jleyvadelgado>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');

/**
 * Class bar_form
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class bar_form extends moodleform {

    /**
     * Form definition
     */
    public function definition(): void {
        global $CFG;

        $mform =& $this->_form;
        $options = [];
        $report = $this->_customdata['report'];

        if ($report->type !== 'sql') {
            $components = cr_unserialize($this->_customdata['report']->components);

            if (!is_array($components) || empty($components['columns']['elements'])) {
                throw new moodle_exception('nocolumns');
            }

            $columns = $components['columns']['elements'];
            $i = 0;
            foreach ($columns as $c) {
                if (!empty($c['summary'])) {
                    $key = "$i," . $c['summary'];
                    $options[$key] = str_replace('_', ' ', $c['summary']);
                    $i++;
                }
            }
        } else {
            require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');
            require_once($CFG->dirroot . '/blocks/configurable_reports/reports/' . $report->type . '/report.class.php');

            $reportclassname = 'report_' . $report->type;
            $reportclass = new $reportclassname($report);

            $components = cr_unserialize($report->components);
            $config = $components['customsql']['config'] ?? new stdclass;

            if (isset($config->querysql)) {
                $sql = $config->querysql;
                $sql = $reportclass->prepare_sql($sql);
                if ($rs = $reportclass->execute_query($sql)) {
                    foreach ($rs as $row) {
                        $i = 0;
                        foreach ($row as $colname => $value) {
                            $key = "$i,$colname";
                            $options[$key] = str_replace('_', ' ', $colname);
                            $i++;
                        }
                        break;
                    }
                    $rs->close();
                }
            }
        }

        $mform->addElement('header', 'crformheader', get_string('head_data', 'block_configurable_reports'), '');

        $mform->addElement('select', 'label_field', get_string('label_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('label_field', 'label_field', 'block_configurable_reports');
        $valuefieldsstr = get_string('value_fields', 'block_configurable_reports');
        $valueselect = $mform->addElement('select', 'value_fields', $valuefieldsstr, $options);
        $valueselect->setMultiple(true);
        $mform->addHelpButton('value_fields', 'value_fields', 'block_configurable_reports');

        $this->add_formatting_elements($mform);
    }

    /**
     * add_formatting_elements
     *
     * @param moodleform $mform
     * @return void
     */
    public function add_formatting_elements($mform): void {
        $mform->addElement('header', 'size', get_string('head_size', 'block_configurable_reports'));

        $mform->addElement('text', 'width', get_string('width', 'block_configurable_reports'));
        $mform->setDefault('width', 900);
        $mform->setType("width", PARAM_INT);
        $mform->addElement('text', 'height', get_string('height', 'block_configurable_reports'));
        $mform->setDefault('height', 500);
        $mform->setType("height", PARAM_INT);

        // Chart.js オプション（pChart では無視される）
        $mform->addElement('header', 'chartjsoptions', get_string('head_chartjs_options', 'block_configurable_reports'));

        // 表示の向き（縦 / 横）
        $bardirections = [
            'vertical'   => get_string('bardirection_vertical',   'block_configurable_reports'),
            'horizontal' => get_string('bardirection_horizontal',  'block_configurable_reports'),
        ];
        $mform->addElement('select', 'bardirection',
            get_string('bardirection', 'block_configurable_reports'), $bardirections);
        $mform->setDefault('bardirection', 'vertical');
        $mform->addHelpButton('bardirection', 'bardirection', 'block_configurable_reports');

        // グループ分け（なし＝横並べ / 積み上げ）
        $bargroupings = [
            'grouped' => get_string('bargrouping_grouped', 'block_configurable_reports'),
            'stacked' => get_string('bargrouping_stacked', 'block_configurable_reports'),
        ];
        $mform->addElement('select', 'bargrouping',
            get_string('bargrouping', 'block_configurable_reports'), $bargroupings);
        $mform->setDefault('bargrouping', 'grouped');
        $mform->addHelpButton('bargrouping', 'bargrouping', 'block_configurable_reports');

        // 系列の順番を逆にする
        $mform->addElement('advcheckbox', 'reversedatasets', get_string('reversedatasets', 'block_configurable_reports'));
        $mform->setDefault('reversedatasets', 0);
        $mform->addHelpButton('reversedatasets', 'reversedatasets', 'block_configurable_reports');

        // ヒストグラムモード（棒の隙間をなくす）
        $mform->addElement('advcheckbox', 'histogram', get_string('histogram', 'block_configurable_reports'));
        $mform->setDefault('histogram', 0);
        $mform->addHelpButton('histogram', 'histogram', 'block_configurable_reports');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

}
