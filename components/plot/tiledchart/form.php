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
 * Class tiledchart_form
 *
 * @package   block_configurable_reports
 */
class tiledchart_form extends moodleform {

    /**
     * Form definition
     */
    public function definition(): void {
        global $CFG;

        $mform =& $this->_form;
        $options = [];
        $report = $this->_customdata['report'];

        // --- 列の選択肢を構築（bar/lineと同じロジック） ---
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

        // グループキー列（例：courseid, fullname）
        $mform->addElement('select', 'group_field',
            get_string('tiledchart_group_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('group_field', 'tiledchart_group_field', 'block_configurable_reports');

        // 列1（X軸 / ラベル / 軸名）
        // bar/line/area のときは X軸ラベル
        // pie/doughnut のときはスライスのラベル
        // radar のときは軸の名前
        $mform->addElement('select', 'x_field',
            get_string('tiledchart_col1', 'block_configurable_reports'), $options);
        $mform->addHelpButton('x_field', 'tiledchart_col1', 'block_configurable_reports');

        // 列2（Y軸 / 値）
        // すべてのタイプで値（Y軸の値・スライスの大きさ・軸のスコア）
        $mform->addElement('select', 'y_field',
            get_string('tiledchart_col2', 'block_configurable_reports'), $options);
        $mform->addHelpButton('y_field', 'tiledchart_col2', 'block_configurable_reports');

        // --- グラフ設定 ---
        $mform->addElement('header', 'chartjsoptions', get_string('head_chartjs_options', 'block_configurable_reports'));

        // グラフタイプ（bar / line / area / pie / doughnut / radar）
        $charttypes = [
            'line'     => get_string('tiledchart_type_line',     'block_configurable_reports'),
            'bar'      => get_string('tiledchart_type_bar',      'block_configurable_reports'),
            'area'     => get_string('tiledchart_type_area',     'block_configurable_reports'),
            'pie'      => get_string('tiledchart_type_pie',      'block_configurable_reports'),
            'doughnut' => get_string('tiledchart_type_doughnut', 'block_configurable_reports'),
            'radar'    => get_string('tiledchart_type_radar',    'block_configurable_reports'),
        ];
        $mform->addElement('select', 'charttype',
            get_string('tiledchart_charttype', 'block_configurable_reports'), $charttypes);
        $mform->setDefault('charttype', 'line');

        // 1行あたりのタイル数
        $columnsoptions = [
            'auto' => get_string('tiledchart_columns_auto', 'block_configurable_reports'),
            '1'    => '1',
            '2'    => '2',
            '3'    => '3',
            '4'    => '4',
            '5'    => '5',
            '6'    => '6',
        ];
        $mform->addElement('select', 'tilecolumns',
            get_string('tiledchart_columns', 'block_configurable_reports'), $columnsoptions);
        $mform->setDefault('tilecolumns', 'auto');
        $mform->addHelpButton('tilecolumns', 'tiledchart_columns', 'block_configurable_reports');

        // タイルサイズ
        $mform->addElement('text', 'tilewidth',
            get_string('tiledchart_tilewidth', 'block_configurable_reports'));
        $mform->setDefault('tilewidth', 400);
        $mform->setType('tilewidth', PARAM_INT);
        $mform->addHelpButton('tilewidth', 'tiledchart_tilewidth', 'block_configurable_reports');

        $mform->addElement('text', 'tileheight',
            get_string('tiledchart_tileheight', 'block_configurable_reports'));
        $mform->setDefault('tileheight', 280);
        $mform->setType('tileheight', PARAM_INT);
        $mform->addHelpButton('tileheight', 'tiledchart_tileheight', 'block_configurable_reports');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

}
