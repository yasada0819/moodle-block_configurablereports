<?php
defined('MOODLE_INTERNAL') || die;
require_once($CFG->libdir . '/formslib.php');

class radar_form extends moodleform {

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

        // --- データ設定 ---
        $mform->addElement('header', 'crformheader',
            get_string('head_data', 'block_configurable_reports'), '');

        // ラベル列（軸の名前）
        $mform->addElement('select', 'label_field',
            get_string('label_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('label_field', 'label_field', 'block_configurable_reports');

        // 系列：固定5行、横並び
        // ヘッダー行（ラベル）
        $mform->addElement('html',
            '<div class="form-group row">'
            . '<div class="col-md-3"><strong>' . get_string('radar_series_field', 'block_configurable_reports') . '</strong></div>'
            . '<div class="col-md-3"><strong>' . get_string('radar_series_agg',   'block_configurable_reports') . '</strong></div>'
            . '<div class="col-md-4"><strong>' . get_string('radar_series_label', 'block_configurable_reports') . '</strong></div>'
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

        // --- サイズ設定 ---
        $mform->addElement('header', 'size',
            get_string('head_size', 'block_configurable_reports'));

        $mform->addElement('text', 'width', get_string('width', 'block_configurable_reports'));
        $mform->setDefault('width', 500);
        $mform->setType('width', PARAM_INT);

        $mform->addElement('text', 'height', get_string('height', 'block_configurable_reports'));
        $mform->setDefault('height', 500);
        $mform->setType('height', PARAM_INT);

        // --- Chart.js オプション ---
        $mform->addElement('header', 'chartjsoptions',
            get_string('head_chartjs_options', 'block_configurable_reports'));

        $mform->addElement('text', 'scalemin',
            get_string('radar_scalemin', 'block_configurable_reports'));
        $mform->setDefault('scalemin', '');
        $mform->setType('scalemin', PARAM_RAW);
        $mform->addHelpButton('scalemin', 'radar_scalemin', 'block_configurable_reports');

        $mform->addElement('text', 'scalemax',
            get_string('radar_scalemax', 'block_configurable_reports'));
        $mform->setDefault('scalemax', '');
        $mform->setType('scalemax', PARAM_RAW);
        $mform->addHelpButton('scalemax', 'radar_scalemax', 'block_configurable_reports');

        $nahandlings = [
            'exclude' => get_string('nahandling_exclude', 'block_configurable_reports'),
            'zero'    => get_string('nahandling_zero',    'block_configurable_reports'),
        ];
        $mform->addElement('select', 'nahandling',
            get_string('nahandling', 'block_configurable_reports'), $nahandlings);
        $mform->setDefault('nahandling', 'exclude');
        $mform->addHelpButton('nahandling', 'nahandling', 'block_configurable_reports');

        $this->add_action_buttons(true, get_string('add'));
    }

}