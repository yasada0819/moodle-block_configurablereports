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
 * グループキー列でデータを仕分けし、グループごとに1つのグラフを生成して
 * タイル状に並べて表示するプラグイン。
 *
 * 対応グラフタイプ: bar / pie / doughnut
 * X軸（ラベル列）は1列固定。Y系列は最大5行で各行に
 *   - 列選択（series_field）
 *   - 集計方法（series_agg）
 *   - 凡例ラベル（series_label、任意）
 * を設定する。
 *
 * @package   block_configurable_reports
 */
class tiledchart_form extends moodleform {

    /**
     * Form definition
     */
    public function definition(): void {
        global $CFG;

        $mform  =& $this->_form;
        $report = $this->_customdata['report'];

        // --- 列の選択肢を構築 ---
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

        // 集計方法の選択肢
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

        // グループキー列（タイルの分割単位）
        $mform->addElement('select', 'group_field',
            get_string('tiledchart_group_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('group_field', 'tiledchart_group_field', 'block_configurable_reports');

        // X軸 / ラベル列（1列固定）
        $mform->addElement('select', 'x_field',
            get_string('tiledchart_col1', 'block_configurable_reports'), $options);
        $mform->addHelpButton('x_field', 'tiledchart_col1', 'block_configurable_reports');

        // 列選択に「なし」を追加（未使用系列用）
        $fieldoptions = array_merge(
            ['' => get_string('choose')],
            $options
        );

        // --- Y系列（固定5行・横並び） ---
        $mform->addElement('html',
            '<div class="form-group row">'
            . '<div class="col-md-3"><strong>' . get_string('line_series_field', 'block_configurable_reports') . '</strong></div>'
            . '<div class="col-md-3"><strong>' . get_string('line_series_agg',   'block_configurable_reports') . '</strong></div>'
            . '<div class="col-md-4"><strong>' . get_string('line_series_label', 'block_configurable_reports') . '</strong></div>'
            . '</div>'
        );

        for ($i = 0; $i < 5; $i++) {
            $mform->addElement('html', '<div class="form-group row"><div class="col-md-1"><strong>' . ($i + 1) . '</strong></div><div class="col-md-3">');
            $mform->addElement('select', "series_field[$i]", '', $fieldoptions);
            $mform->addElement('html', '</div><div class="col-md-3">');
            $mform->addElement('select', "series_agg[$i]", '', $aggregations);
            $mform->addElement('html', '</div><div class="col-md-4">');
            $mform->addElement('text', "series_label[$i]", '', ['size' => 20]);
            $mform->addElement('html', '</div></div>');

            $mform->setType("series_label[$i]", PARAM_TEXT);
        }

        // --- グラフ設定 ---
        $mform->addElement('header', 'chartjsoptions',
            get_string('head_chartjs_options', 'block_configurable_reports'));

        // グラフタイプ
        $charttypes = [
            'bar'      => get_string('tiledchart_type_bar',      'block_configurable_reports'),
            'line'     => get_string('tiledchart_type_line',     'block_configurable_reports'),
            'area'     => get_string('tiledchart_type_area',     'block_configurable_reports'),
            'pie'      => get_string('tiledchart_type_pie',      'block_configurable_reports'),
            'doughnut' => get_string('tiledchart_type_doughnut', 'block_configurable_reports'),
            'radar'    => get_string('tiledchart_type_radar',    'block_configurable_reports'),
        ];
        $mform->addElement('select', 'charttype',
            get_string('tiledchart_charttype', 'block_configurable_reports'), $charttypes);
        $mform->setDefault('charttype', 'bar');

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

        // NA処理
        $nahandlings = [
            'exclude' => get_string('nahandling_exclude', 'block_configurable_reports'),
            'zero'    => get_string('nahandling_zero',    'block_configurable_reports'),
        ];
        $mform->addElement('select', 'nahandling',
            get_string('nahandling', 'block_configurable_reports'), $nahandlings);
        $mform->setDefault('nahandling', 'exclude');
        $mform->addHelpButton('nahandling', 'nahandling', 'block_configurable_reports');

        $mform->addElement('advcheckbox', 'show_legend',
            get_string('show_legend', 'block_configurable_reports'));
        $mform->setDefault('show_legend', 1);
        $mform->addHelpButton('show_legend', 'show_legend', 'block_configurable_reports');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

}