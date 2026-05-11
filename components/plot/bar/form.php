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

        $mform  =& $this->_form;
        $report = $this->_customdata['report'];

        $graphlibrary = get_config('block_configurable_reports', 'graphlibrary');

        if ($graphlibrary === 'chartjs') {
            $this->definition_chartjs($mform, $report, $CFG);
        } else {
            $this->definition_pchart($mform, $report, $CFG);
        }
    }

    // -------------------------------------------------------------------------
    // pChart フォーム（従来通り・変更なし）
    // -------------------------------------------------------------------------

    private function definition_pchart($mform, $report, $CFG): void {
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

        $mform->addElement('header', 'crformheader', get_string('head_data', 'block_configurable_reports'), '');

        $mform->addElement('select', 'label_field', get_string('label_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('label_field', 'label_field', 'block_configurable_reports');
        $valueselect = $mform->addElement('select', 'value_fields', get_string('value_fields', 'block_configurable_reports'), $options);
        $valueselect->setMultiple(true);
        $mform->addHelpButton('value_fields', 'value_fields', 'block_configurable_reports');

        $this->add_formatting_elements($mform);
    }

    // -------------------------------------------------------------------------
    // Chart.js フォーム（lineと同じ5系列+集計構成）
    // -------------------------------------------------------------------------

    private function definition_chartjs($mform, $report, $CFG): void {
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

        $fieldoptions = array_merge(['' => get_string('choose')], $options);

        $mform->addElement('header', 'crformheader', get_string('head_data', 'block_configurable_reports'), '');

        // X軸（必須・1列固定）
        $mform->addElement('select', 'xaxis', get_string('xaxis', 'block_configurable_reports'), $options);
        $mform->addRule('xaxis', null, 'required', null, 'client');

        // Y系列（固定5行）
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

        // NA処理
        $nahandlings = [
            'exclude' => get_string('nahandling_exclude', 'block_configurable_reports'),
            'zero'    => get_string('nahandling_zero',    'block_configurable_reports'),
        ];
        $mform->addElement('select', 'nahandling',
            get_string('nahandling', 'block_configurable_reports'), $nahandlings);
        $mform->setDefault('nahandling', 'exclude');
        $mform->addHelpButton('nahandling', 'nahandling', 'block_configurable_reports');

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