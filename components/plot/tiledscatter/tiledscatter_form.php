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
 * Class tiledscatter_form
 *
 * グループキー列でデータを仕分けし、グループごとに1つの散布図／バブルチャートを
 * タイル状に並べて表示するプラグイン。
 *
 * データ構造：
 *   group_field  : タイルの分割キー列
 *   label_field  : 色分け用ラベル列（任意、'none'=全点同色）
 *   x_field      : X軸の数値列
 *   y_field      : Y軸の数値列
 *   r_field      : バブルサイズの数値列（任意、未選択=固定値）
 *
 * r_field が未選択のときは全点同サイズの散布図として描画される。
 *
 * @package   block_configurable_reports
 */
class tiledscatter_form extends moodleform {

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

        // 任意列用（「なし」付き）
        $noneoptions  = array_merge(['none' => get_string('scatter_label_none', 'block_configurable_reports')], $options);

        // --- データ設定 ---
        $mform->addElement('header', 'crformheader',
            get_string('head_data', 'block_configurable_reports'), '');

        // グループキー列（タイルの分割単位）
        $mform->addElement('select', 'group_field',
            get_string('tiledchart_group_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('group_field', 'tiledchart_group_field', 'block_configurable_reports');

        // 色分けラベル列（任意）
        $mform->addElement('select', 'label_field',
            get_string('scatter_label_field', 'block_configurable_reports'), $noneoptions);
        $mform->setDefault('label_field', 'none');
        $mform->addHelpButton('label_field', 'scatter_label_field', 'block_configurable_reports');

        // X軸列（数値）
        $mform->addElement('select', 'x_field',
            get_string('scatter_x_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('x_field', 'scatter_x_field', 'block_configurable_reports');

        // Y軸列（数値）
        $mform->addElement('select', 'y_field',
            get_string('scatter_y_field', 'block_configurable_reports'), $options);
        $mform->addHelpButton('y_field', 'scatter_y_field', 'block_configurable_reports');

        // バブルサイズ列（任意）
        $mform->addElement('select', 'r_field',
            get_string('tiledscatter_r_field', 'block_configurable_reports'), $noneoptions);
        $mform->setDefault('r_field', 'none');
        $mform->addHelpButton('r_field', 'tiledscatter_r_field', 'block_configurable_reports');

        // バブルサイズのスケーリング
        $rscaleoptions = [
            'auto'   => get_string('tiledscatter_rscale_auto',   'block_configurable_reports'),
            'manual' => get_string('tiledscatter_rscale_manual', 'block_configurable_reports'),
        ];
        $mform->addElement('select', 'rscale',
            get_string('tiledscatter_rscale', 'block_configurable_reports'), $rscaleoptions);
        $mform->setDefault('rscale', 'auto');

        // 固定サイズ（r_field未選択 or manual=固定値のとき）
        $mform->addElement('text', 'rdefault',
            get_string('tiledscatter_rdefault', 'block_configurable_reports'));
        $mform->setDefault('rdefault', 5);
        $mform->setType('rdefault', PARAM_INT);
        $mform->addHelpButton('rdefault', 'tiledscatter_rdefault', 'block_configurable_reports');

        // --- グラフ設定 ---
        $mform->addElement('header', 'chartjsoptions',
            get_string('head_chartjs_options', 'block_configurable_reports'));

        // 1行あたりのタイル数
        $columnsoptions = [
            'auto' => get_string('tiledchart_columns_auto', 'block_configurable_reports'),
            '1' => '1', '2' => '2', '3' => '3',
            '4' => '4', '5' => '5', '6' => '6',
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
        $mform->setDefault('tileheight', 400);
        $mform->setType('tileheight', PARAM_INT);
        $mform->addHelpButton('tileheight', 'tiledchart_tileheight', 'block_configurable_reports');

        // Buttons.
        $this->add_action_buttons(true, get_string('add'));
    }

}
