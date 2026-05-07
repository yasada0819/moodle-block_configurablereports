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

        // [0 => 'Choose...'] を先頭に置くことで「未選択 = 任意」を実現
        $options = [0 => get_string('choose')];

        $report = $this->_customdata['report'];

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

        // --- データ設定 ---
        $mform->addElement('header', 'crformheader', get_string('line', 'block_configurable_reports'), '');

        // X軸（必須）
        $mform->addElement('select', 'xaxis', get_string('xaxis', 'block_configurable_reports'), $options);
        $mform->addRule('xaxis', null, 'required', null, 'client');

        // Y軸1（必須）
        $mform->addElement('select', 'yaxis', get_string('yaxis', 'block_configurable_reports'), $options);
        $mform->addRule('yaxis', null, 'required', null, 'client');

        // Y軸1のグループ列（任意）：0='Choose...' のまま = 未選択扱い
        $mform->addElement('select', 'serieid',
            get_string('line_serieid', 'block_configurable_reports'), $options);

        // Y軸2（任意）
        $mform->addElement('select', 'yaxis2',
            get_string('line_yaxis2', 'block_configurable_reports'), $options);

        // Y軸2のグループ列（任意）
        $mform->addElement('select', 'serieid2',
            get_string('line_serieid2', 'block_configurable_reports'), $options);

        // --- サイズ設定 ---
        $mform->addElement('header', 'size', get_string('head_size', 'block_configurable_reports'));

        $mform->addElement('text', 'width', get_string('width', 'block_configurable_reports'));
        $mform->setDefault('width', 900);
        $mform->setType('width', PARAM_INT);

        $mform->addElement('text', 'height', get_string('height', 'block_configurable_reports'));
        $mform->setDefault('height', 500);
        $mform->setType('height', PARAM_INT);

        // --- Chart.js オプション（pChart では無視される） ---
        $mform->addElement('header', 'chartjsoptions', get_string('head_chartjs_options', 'block_configurable_reports'));

        // スムーズ曲線
        $mform->addElement('advcheckbox', 'smooth', get_string('line_smooth', 'block_configurable_reports'));
        $mform->setDefault('smooth', 0);
        $mform->addHelpButton('smooth', 'line_smooth', 'block_configurable_reports');

        // エリア塗りつぶし
        $mform->addElement('advcheckbox', 'filled', get_string('line_filled', 'block_configurable_reports'));
        $mform->setDefault('filled', 0);
        $mform->addHelpButton('filled', 'line_filled', 'block_configurable_reports');

        // Y軸を分ける（Y1=左軸、Y2=右軸）
        $mform->addElement('advcheckbox', 'dualaxis', get_string('line_dualaxis', 'block_configurable_reports'));
        $mform->setDefault('dualaxis', 0);
        $mform->addHelpButton('dualaxis', 'line_dualaxis', 'block_configurable_reports');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
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

        if ($data['xaxis'] == $data['yaxis']) {
            $errors['yaxis'] = get_string('xandynotequal', 'block_configurable_reports');
        }

        return $errors;
    }

}
