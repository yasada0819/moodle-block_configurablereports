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
 * Configurable Reports - Heatmap plugin form (Plotly.js)
 *
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->libdir . '/formslib.php');

/**
 * Class heatmap_form
 *
 * ロング型データ（x列・y列・値列の3列）からヒートマップを生成するプラグインのフォーム。
 * Plotly.js 専用。graphlibrary = 'plotly' のときのみ表示される。
 *
 * データ構造：
 *   x_field      : X軸列（'index,colname' 形式）
 *   y_field      : Y軸列（'index,colname' 形式）
 *   value_field  : 値列（'index,colname' 形式）
 *   value_agg    : 集計方法（同じX×Y組み合わせが複数行ある場合）
 *   nahandling   : NAの扱い（exclude / zero）
 *   colorscale   : Plotlyカラースケール名
 *   reversescale : カラースケールを反転するか
 *
 * @package   block_configurable_reports
 */
class heatmap_form extends moodleform {

    public function definition(): void {
        global $CFG;

        $mform  =& $this->_form;
        $report = $this->_customdata['report'];

        // --- 列の選択肢を構築（tiledchart_form と同じパターン）---
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

        // 集計方法の選択肢（既存のaggregationキーを流用）
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

        // NA処理の選択肢
        $nahandlings = [
            'exclude' => get_string('nahandling_exclude', 'block_configurable_reports'),
            'zero'    => get_string('nahandling_zero',    'block_configurable_reports'),
        ];

        // カラースケールの選択肢（Plotly標準）
        $colorscales = [
            'Viridis'  => 'Viridis',
            'Plasma'   => 'Plasma',
            'RdBu'     => 'RdBu（赤→青）',
            'YlOrRd'   => 'YlOrRd（黄→赤）',
            'Blues'    => 'Blues',
            'Greens'   => 'Greens',
            'Hot'      => 'Hot',
            'Greys'    => 'Greys',
        ];

        // =========================================================
        // セクション1：データ設定
        // =========================================================
        $mform->addElement('header', 'crformheader',
            get_string('head_data', 'block_configurable_reports'));

        // X軸列
        $mform->addElement('select', 'x_field',
            get_string('heatmap_x_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('x_field', 'heatmap_x_field', 'block_configurable_reports');

        // Y軸列
        $mform->addElement('select', 'y_field',
            get_string('heatmap_y_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('y_field', 'heatmap_y_field', 'block_configurable_reports');

        // 値列
        $mform->addElement('select', 'value_field',
            get_string('heatmap_value_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('value_field', 'heatmap_value_field', 'block_configurable_reports');

        // 集計方法
        $mform->addElement('select', 'value_agg',
            get_string('aggregation', 'block_configurable_reports'), $aggregations);
        $mform->setDefault('value_agg', 'avg');
        $mform->addHelpButton('value_agg', 'aggregation', 'block_configurable_reports');

        // NA処理
        $mform->addElement('select', 'nahandling',
            get_string('nahandling', 'block_configurable_reports'), $nahandlings);
        $mform->setDefault('nahandling', 'exclude');
        $mform->addHelpButton('nahandling', 'nahandling', 'block_configurable_reports');

        // =========================================================
        // セクション2：表示設定（Plotly固有）
        // =========================================================
        $mform->addElement('header', 'plotlyoptions',
            get_string('heatmap_head_plotly', 'block_configurable_reports'));

        // カラースケール
        $mform->addElement('select', 'colorscale',
            get_string('heatmap_colorscale', 'block_configurable_reports'), $colorscales);
        $mform->setDefault('colorscale', 'Viridis');
        $mform->addHelpButton('colorscale', 'heatmap_colorscale', 'block_configurable_reports');

        // カラースケール反転
        $mform->addElement('advcheckbox', 'reversescale',
            get_string('heatmap_reversescale', 'block_configurable_reports'));
        $mform->setDefault('reversescale', 0);

        // ボタン
        $this->add_action_buttons(true, get_string('add'));
    }
}
