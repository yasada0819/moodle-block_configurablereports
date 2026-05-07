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
 * Class bubble_form
 *
 * @package   block_configurable_reports
 */
class bubble_form extends moodleform {

    /**
     * Form definition
     */
    public function definition(): void {
        global $CFG;

        $mform =& $this->_form;
        $options = [];
        $report = $this->_customdata['report'];

        // --- 列の選択肢を構築 ---
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

        // --- データ設定 ---
        $mform->addElement('header', 'crformheader', get_string('head_data', 'block_configurable_reports'), '');

        // ラベル列（任意）
        $labeloptions = array_merge(
            ['none' => get_string('bubble_label_none', 'block_configurable_reports')],
            $options
        );
        $mform->addElement('select', 'label_field',
            get_string('bubble_label_field', 'block_configurable_reports'), $labeloptions);
        $mform->setDefault('label_field', 'none');
        $mform->addHelpButton('label_field', 'bubble_label_field', 'block_configurable_reports');

        // X軸列
        $mform->addElement('select', 'x_field',
            get_string('bubble_x_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('x_field', 'bubble_x_field', 'block_configurable_reports');

        // Y軸列
        $mform->addElement('select', 'y_field',
            get_string('bubble_y_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('y_field', 'bubble_y_field', 'block_configurable_reports');

        // r列（バブルサイズ）
        $mform->addElement('select', 'r_field',
            get_string('bubble_r_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('r_field', 'bubble_r_field', 'block_configurable_reports');

        // --- サイズ設定 ---
        $mform->addElement('header', 'size', get_string('head_size', 'block_configurable_reports'));

        $mform->addElement('text', 'width', get_string('width', 'block_configurable_reports'));
        $mform->setDefault('width', 700);
        $mform->setType('width', PARAM_INT);

        $mform->addElement('text', 'height', get_string('height', 'block_configurable_reports'));
        $mform->setDefault('height', 500);
        $mform->setType('height', PARAM_INT);

        // --- バブルサイズのスケーリング ---
        $mform->addElement('header', 'chartjsoptions', get_string('head_chartjs_options', 'block_configurable_reports'));

        // スケーリング方式
        $rscalingoptions = [
            'auto'   => get_string('bubble_rscaling_auto',   'block_configurable_reports'),
            'manual' => get_string('bubble_rscaling_manual', 'block_configurable_reports'),
        ];
        $mform->addElement('select', 'rscaling',
            get_string('bubble_rscaling', 'block_configurable_reports'), $rscalingoptions);
        $mform->setDefault('rscaling', 'auto');
        $mform->addHelpButton('rscaling', 'bubble_rscaling', 'block_configurable_reports');

        // 最大バブルサイズ（auto モード時の上限）
        $mform->addElement('text', 'maxbubblesize',
            get_string('bubble_maxbubblesize', 'block_configurable_reports'));
        $mform->setDefault('maxbubblesize', 40);
        $mform->setType('maxbubblesize', PARAM_FLOAT);
        $mform->addHelpButton('maxbubblesize', 'bubble_maxbubblesize', 'block_configurable_reports');
        $mform->hideIf('maxbubblesize', 'rscaling', 'eq', 'manual');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

}
