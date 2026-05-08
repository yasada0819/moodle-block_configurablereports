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
 * Class line_form
 *
 * graphlibrary 設定に応じてフォームを切り替える：
 *   - pChart モード  : 従来通り（xaxis / serieid / yaxis / group）
 *   - Chart.js モード: xaxis + Y系列5行（field/agg/label）+ nahandling + 描画オプション
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class line_form extends moodleform {

    /**
     * Form definition
     */
    public function definition(): void {
        global $CFG;

        $mform =& $this->_form;
        $report = $this->_customdata['report'];

        $graphlibrary = get_config('block_configurable_reports', 'graphlibrary');

        if ($graphlibrary === 'chartjs') {
            $this->definition_chartjs($mform, $report, $CFG);
        } else {
            $this->definition_pchart($mform, $report, $CFG);
        }

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

    // -------------------------------------------------------------------------
    // pChart フォーム（従来通り・変更なし）
    // -------------------------------------------------------------------------

    /**
     * pChart モード用フォーム定義
     */
    private function definition_pchart($mform, $report, $CFG): void {
        $options = [0 => get_string('choose')];

        if ($report->type !== 'sql') {
            $components = cr_unserialize($this->_customdata['report']->components);

            if (!is_array($components) || empty($components['columns']['elements'])) {
                throw new moodle_exception('nocolumns');
            }

            $columns = $components['columns']['elements'];
            foreach ($columns as $c) {
                $options[] = $c['summary'];
            }
        } else {
            require_once($CFG->dirroot . '/blocks/configurable_reports/report.class.php');
            require_once($CFG->dirroot . '/blocks/configurable_reports/reports/' . $report->type . '/report.class.php');

            $reportclassname = 'report_' . $report->type;
            $reportclass = new $reportclassname($report);

            $components = cr_unserialize($report->components);
            $config = (isset($components['customsql']['config'])) ? $components['customsql']['config'] : new stdclass;

            if (isset($config->querysql)) {
                $sql = $config->querysql;
                $sql = $reportclass->prepare_sql($sql);
                if ($rs = $reportclass->execute_query($sql)) {
                    foreach ($rs as $row) {
                        $i = 1;
                        foreach ($row as $colname => $value) {
                            $options[$i] = str_replace('_', ' ', $colname);
                            $i++;
                        }
                        break;
                    }
                    $rs->close();
                }
            }
        }

        $mform->addElement('header', 'crformheader', get_string('line', 'block_configurable_reports'), '');

        $mform->addElement('select', 'xaxis', get_string('xaxis', 'block_configurable_reports'), $options);
        $mform->addRule('xaxis', null, 'required', null, 'client');

        $mform->addElement('select', 'serieid', get_string('serieid', 'block_configurable_reports'), $options);
        $mform->addRule('serieid', null, 'required', null, 'client');

        $mform->addElement('select', 'yaxis', get_string('yaxis', 'block_configurable_reports'), $options);
        $mform->addRule('yaxis', null, 'required', null, 'client');

        $mform->addElement('checkbox', 'group', get_string('groupseries', 'block_configurable_reports'));
    }

    // -------------------------------------------------------------------------
    // Chart.js フォーム（新設計）
    // -------------------------------------------------------------------------

    /**
     * Chart.js モード用フォーム定義
     *
     * X軸は1列固定。Y系列は最大5行で、各行に
     *   - 列選択（series_field）
     *   - 集計方法（series_agg）：none / count / sum / avg / min / q1 / median / q3 / max
     *   - 凡例ラベル（series_label、任意）
     * を設定する。空行はplugin.class.php側でスキップ。
     *
     * dualaxis が ON のとき、series_y2[] チェックボックスで
     * 系列ごとにY2軸（右軸）に割り当てられる。
     */
    private function definition_chartjs($mform, $report, $CFG): void {

        // --- 列選択肢を構築（radarと同じ形式：'index,colname'） ---
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

        // 列選択に「なし」を追加（未使用系列用）
        $fieldoptions = array_merge(
            ['' => get_string('choose')],
            $options
        );

        // X軸選択用（必須なので「なし」なし）
        $xoptions = $options;

        // --- データ設定 ---
        $mform->addElement('header', 'crformheader',
            get_string('line', 'block_configurable_reports'), '');

        // X軸（必須・1列固定）
        $mform->addElement('select', 'xaxis',
            get_string('xaxis', 'block_configurable_reports'), $xoptions);
        $mform->addRule('xaxis', null, 'required', null, 'client');

        // --- Y系列（固定5行・横並び） ---
        $mform->addElement('html',
            '<div class="form-group row">'
            . '<div class="col-md-3"><strong>' . get_string('line_series_field', 'block_configurable_reports') . '</strong></div>'
            . '<div class="col-md-3"><strong>' . get_string('line_series_agg',   'block_configurable_reports') . '</strong></div>'
            . '<div class="col-md-3"><strong>' . get_string('line_series_label', 'block_configurable_reports') . '</strong></div>'
            . '<div class="col-md-2"><strong>' . get_string('line_series_y2',    'block_configurable_reports') . '</strong></div>'
            . '</div>'
        );

        for ($i = 0; $i < 5; $i++) {
            $mform->addElement('html', '<div class="form-group row"><div class="col-md-1"><strong>' . ($i + 1) . '</strong></div><div class="col-md-3">');
            $mform->addElement('select', "series_field[$i]", '', $fieldoptions);
            $mform->addElement('html', '</div><div class="col-md-3">');
            $mform->addElement('select', "series_agg[$i]", '', $aggregations);
            $mform->addElement('html', '</div><div class="col-md-3">');
            $mform->addElement('text', "series_label[$i]", '', ['size' => 20]);
            $mform->addElement('html', '</div><div class="col-md-2">');
            $mform->addElement('checkbox', "series_y2[$i]", '');
            $mform->addElement('html', '</div></div>');

            $mform->setType("series_label[$i]", PARAM_TEXT);
            $mform->setDefault("series_y2[$i]", 0);
        }

        // --- サイズ設定 ---
        $mform->addElement('header', 'size',
            get_string('head_size', 'block_configurable_reports'));

        $mform->addElement('text', 'width', get_string('width', 'block_configurable_reports'));
        $mform->setDefault('width', 900);
        $mform->setType('width', PARAM_INT);

        $mform->addElement('text', 'height', get_string('height', 'block_configurable_reports'));
        $mform->setDefault('height', 500);
        $mform->setType('height', PARAM_INT);

        // --- Chart.js オプション ---
        $mform->addElement('header', 'chartjsoptions',
            get_string('head_chartjs_options', 'block_configurable_reports'));

        // スムーズ曲線
        $mform->addElement('advcheckbox', 'smooth',
            get_string('line_smooth', 'block_configurable_reports'));
        $mform->setDefault('smooth', 0);
        $mform->addHelpButton('smooth', 'line_smooth', 'block_configurable_reports');

        // エリア塗りつぶし
        $mform->addElement('advcheckbox', 'filled',
            get_string('line_filled', 'block_configurable_reports'));
        $mform->setDefault('filled', 0);
        $mform->addHelpButton('filled', 'line_filled', 'block_configurable_reports');

        // デュアル軸（Y1=左、Y2=右）
        $mform->addElement('advcheckbox', 'dualaxis',
            get_string('line_dualaxis', 'block_configurable_reports'));
        $mform->setDefault('dualaxis', 0);
        $mform->addHelpButton('dualaxis', 'line_dualaxis', 'block_configurable_reports');

        // NA処理
        $nahandlings = [
            'exclude' => get_string('nahandling_exclude', 'block_configurable_reports'),
            'zero'    => get_string('nahandling_zero',    'block_configurable_reports'),
        ];
        $mform->addElement('select', 'nahandling',
            get_string('nahandling', 'block_configurable_reports'), $nahandlings);
        $mform->setDefault('nahandling', 'exclude');
        $mform->addHelpButton('nahandling', 'nahandling', 'block_configurable_reports');
    }

    /**
     * Server side rules
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        // pChart モードのときのみ xaxis/yaxis 重複チェック
        $graphlibrary = get_config('block_configurable_reports', 'graphlibrary');
        if ($graphlibrary !== 'chartjs') {
            if ($data['xaxis'] == $data['yaxis']) {
                $errors['yaxis'] = get_string('xandynotequal', 'block_configurable_reports');
            }
        }

        return $errors;
    }

}