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
 * Class combo_form
 *
 * @package   block_configurable_reports
 */
class combo_form extends moodleform {

    /**
     * Form definition
     */
    public function definition(): void {
        global $CFG;

        $mform =& $this->_form;
        $options = [];
        $report = $this->_customdata['report'];

        // --- 列の選択肢を構築（barと同じロジック） ---
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

        // X軸（ラベル列）
        $mform->addElement('select', 'label_field',
            get_string('label_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('label_field', 'label_field', 'block_configurable_reports');

        // 棒グラフ系列（複数選択）
        $barselect = $mform->addElement('select', 'bar_fields',
            get_string('combo_bar_fields', 'block_configurable_reports'), $options);
        $barselect->setMultiple(true);
        $mform->addHelpButton('bar_fields', 'combo_bar_fields', 'block_configurable_reports');

        // 折れ線系列（複数選択）
        $lineselect = $mform->addElement('select', 'line_fields',
            get_string('combo_line_fields', 'block_configurable_reports'), $options);
        $lineselect->setMultiple(true);
        $mform->addHelpButton('line_fields', 'combo_line_fields', 'block_configurable_reports');

        // --- サイズ設定 ---
        $mform->addElement('header', 'size', get_string('head_size', 'block_configurable_reports'));

        $mform->addElement('text', 'width', get_string('width', 'block_configurable_reports'));
        $mform->setDefault('width', 900);
        $mform->setType('width', PARAM_INT);

        $mform->addElement('text', 'height', get_string('height', 'block_configurable_reports'));
        $mform->setDefault('height', 500);
        $mform->setType('height', PARAM_INT);

        // --- Chart.js オプション ---
        $mform->addElement('header', 'chartjsoptions', get_string('head_chartjs_options', 'block_configurable_reports'));

        // 棒グラフのグループ分け
        $bargroupings = [
            'grouped' => get_string('bargrouping_grouped', 'block_configurable_reports'),
            'stacked' => get_string('bargrouping_stacked', 'block_configurable_reports'),
        ];
        $mform->addElement('select', 'bargrouping',
            get_string('combo_bargrouping', 'block_configurable_reports'), $bargroupings);
        $mform->setDefault('bargrouping', 'grouped');
        $mform->addHelpButton('bargrouping', 'combo_bargrouping', 'block_configurable_reports');

        // 折れ線：スムーズ
        $mform->addElement('advcheckbox', 'smooth',
            get_string('line_smooth', 'block_configurable_reports'));
        $mform->setDefault('smooth', 0);
        $mform->addHelpButton('smooth', 'line_smooth', 'block_configurable_reports');

        // 折れ線：エリア塗りつぶし
        $mform->addElement('advcheckbox', 'filled',
            get_string('line_filled', 'block_configurable_reports'));
        $mform->setDefault('filled', 0);
        $mform->addHelpButton('filled', 'line_filled', 'block_configurable_reports');

        // Y軸を分ける（棒=左軸、折れ線=右軸）
        $mform->addElement('advcheckbox', 'dualaxis',
            get_string('combo_dualaxis', 'block_configurable_reports'));
        $mform->setDefault('dualaxis', 0);
        $mform->addHelpButton('dualaxis', 'combo_dualaxis', 'block_configurable_reports');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

}
