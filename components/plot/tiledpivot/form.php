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
 * Class tiledpivot_form
 *
 * ロング形式（縦持ち）のデータを tile_field でタイル分割しつつ、
 * 各タイル内では series_field で色分けした1枚のグラフを生成する。
 *
 * tiledchart との関係：
 *   tiledchart  → ロング形式 → group_field でタイル / 列名を直接系列指定
 *   pivotchart  → ロング形式 → series_field の値で色分け / 1グラフ
 *   tiledpivot  → ロング形式 → tile_field でタイル / series_field の値で色分け
 *
 * 設定項目：
 *   tile_field   : タイル分割の基準列（例：年度、クラス）
 *   x_field      : X軸ラベル列（例：日付、科目）
 *   series_field : 色分け列（例：氏名、グループ）
 *   value_field  : Y軸の値列
 *   value_agg    : 集計方法
 *   charttype    : bar / line / area
 *   tilecolumns  : 1行あたりのタイル数
 *   tilewidth    : タイル幅（px）
 *   tileheight   : タイル高さ（px）
 *   bargrouping  : grouped / stacked（bar 時のみ有効）
 *
 * @package   block_configurable_reports
 */
class tiledpivot_form extends moodleform {

    public function definition(): void {
        global $CFG;

        $mform  =& $this->_form;
        $report = $this->_customdata['report'];

        $options = $this->get_column_options($report, $CFG);

        $aggregations = [
            'none'   => get_string('aggregation_none',   'block_configurable_reports'),
            'count'  => get_string('aggregation_count',  'block_configurable_reports'),
            'sum'    => get_string('aggregation_sum',    'block_configurable_reports'),
            'avg'    => get_string('aggregation_avg',    'block_configurable_reports'),
            'min'    => get_string('aggregation_min',    'block_configurable_reports'),
            'q1'     => get_string('aggregation_q1',     'block_configurable_reports'),
            'median' => get_string('aggregation_median', 'block_configurable_reports'),
            'q3'     => get_string('aggregation_q3',     'block_configurable_reports'),
            'max'    => get_string('aggregation_max',    'block_configurable_reports'),
        ];

        // --- データ設定 ---
        $mform->addElement('header', 'crformheader',
            get_string('head_data', 'block_configurable_reports'), '');

        // タイル分割列
        $mform->addElement('select', 'tile_field',
            get_string('tiledpivot_tile_field', 'block_configurable_reports'), $options);
        $mform->addRule('tile_field', null, 'required', null, 'client');
        $mform->addHelpButton('tile_field', 'tiledpivot_tile_field', 'block_configurable_reports');

        // X軸列
        $mform->addElement('select', 'x_field',
            get_string('pivotchart_x_field', 'block_configurable_reports'), $options);
        $mform->addRule('x_field', null, 'required', null, 'client');
        $mform->addHelpButton('x_field', 'pivotchart_x_field', 'block_configurable_reports');

        // シリーズ列（色分け）
        $mform->addElement('select', 'series_field',
            get_string('pivotchart_series_field', 'block_configurable_reports'), $options);
        $mform->addRule('series_field', null, 'required', null, 'client');
        $mform->addHelpButton('series_field', 'pivotchart_series_field', 'block_configurable_reports');

        // 値列
        $mform->addElement('select', 'value_field',
            get_string('pivotchart_value_field', 'block_configurable_reports'), $options);
        $mform->addRule('value_field', null, 'required', null, 'client');
        $mform->addHelpButton('value_field', 'pivotchart_value_field', 'block_configurable_reports');

        // 集計方法
        $mform->addElement('select', 'value_agg',
            get_string('pivotchart_value_agg', 'block_configurable_reports'), $aggregations);
        $mform->setDefault('value_agg', 'sum');
        $mform->addHelpButton('value_agg', 'pivotchart_value_agg', 'block_configurable_reports');

        // --- グラフ設定 ---
        $mform->addElement('header', 'chartjsoptions',
            get_string('head_chartjs_options', 'block_configurable_reports'));

        // グラフタイプ
        $charttypes = [
            'bar'  => get_string('tiledchart_type_bar',  'block_configurable_reports'),
            'line' => get_string('tiledchart_type_line', 'block_configurable_reports'),
            'area' => get_string('tiledchart_type_area', 'block_configurable_reports'),
        ];
        $mform->addElement('select', 'charttype',
            get_string('tiledchart_charttype', 'block_configurable_reports'), $charttypes);
        $mform->setDefault('charttype', 'bar');

        // グループ分け（bar のみ有効）
        $bargroupings = [
            'grouped' => get_string('bargrouping_grouped', 'block_configurable_reports'),
            'stacked' => get_string('bargrouping_stacked', 'block_configurable_reports'),
        ];
        $mform->addElement('select', 'bargrouping',
            get_string('bargrouping', 'block_configurable_reports'), $bargroupings);
        $mform->setDefault('bargrouping', 'grouped');
        $mform->addHelpButton('bargrouping', 'bargrouping', 'block_configurable_reports');

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
        $mform->setDefault('tileheight', 300);
        $mform->setType('tileheight', PARAM_INT);
        $mform->addHelpButton('tileheight', 'tiledchart_tileheight', 'block_configurable_reports');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

    /**
     * レポートのカラム一覧を取得
     */
    private function get_column_options($report, $CFG): array {
        $options = [];

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
            $reportclass     = new $reportclassname($report);

            $components = cr_unserialize($report->components);
            $config     = $components['customsql']['config'] ?? new stdclass;

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

        return $options;
    }
}
