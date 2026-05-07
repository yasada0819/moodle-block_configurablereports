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
 * Class template_form
 *
 * templateeditor 設定に応じて UI を切り替える：
 *   classic : 従来のテキストエリア（header / record / footer）
 *   gui     : GUIビルダー（行×カラム構成をJSONで保存）
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class template_form extends moodleform {

    /**
     * Form definition
     */
    public function definition(): void {
        global $CFG;

        $mform =& $this->_form;
        $report = $this->_customdata['report'];

        // --- 列の選択肢を構築（プレースホルダー表示用）---
        $options = [];
        if ($report->type !== 'sql') {
            $components = cr_unserialize($this->_customdata['report']->components);
            if (is_array($components) && !empty($components['columns']['elements'])) {
                $columns = $components['columns']['elements'];
                foreach ($columns as $c) {
                    $options[] = $c['summary'];
                }
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
                        $i = 0;
                        foreach ($row as $colname => $value) {
                            $options[$i] = str_replace('_', ' ', $colname);
                            $i++;
                        }
                        break;
                    }
                }
            }
        }

        // --- グラフ数を取得（##graph:N## プレースホルダー生成用）---
        $graphcount = 0;
        $components = cr_unserialize($report->components);
        if (!empty($components['plot']['elements'])) {
            $graphcount = count($components['plot']['elements']);
        }

        // --- 有効/無効 ---
        $optionsenabled = [
            0 => get_string('disabled', 'block_configurable_reports'),
            1 => get_string('enabled',  'block_configurable_reports'),
        ];
        $mform->addElement('select', 'enabled',
            get_string('template', 'block_configurable_reports'), $optionsenabled);
        $mform->setDefault('enabled', 0);

        // --- エディタモード（設定に応じてUIを切り替え）---
        $templateeditor = get_config('block_configurable_reports', 'templateeditor');

        if ($templateeditor === 'gui') {
            $this->definition_gui($mform, $graphcount, $options);
        } else {
            $this->definition_classic($mform, $options);
        }

        $this->add_action_buttons();
    }

    /**
     * 従来のテキストエリアUI（classicモード）
     *
     * @param MoodleQuickForm $mform
     * @param array           $options  列名リスト
     */
    protected function definition_classic(MoodleQuickForm $mform, array $options): void {
        $mform->addElement('editor', 'header', get_string('header', 'block_configurable_reports'));
        $mform->disabledIf('header', 'enabled', 'eq', 0);
        $mform->addHelpButton('header', 'template_marks', 'block_configurable_reports');

        $availablemarksrec = '';
        if ($options) {
            foreach ($options as $o) {
                $availablemarksrec .= "[[$o]] => $o <br />";
            }
        }

        $mform->addElement('static', 'statictext',
            get_string('availablemarks', 'block_configurable_reports'), $availablemarksrec);
        $mform->addElement('editor', 'record', get_string('templaterecord', 'block_configurable_reports'));
        $mform->disabledIf('record', 'enabled', 'eq', 0);

        $mform->addElement('editor', 'footer', get_string('footer', 'block_configurable_reports'));
        $mform->disabledIf('footer', 'enabled', 'eq', 0);
        $mform->addHelpButton('footer', 'template_marks', 'block_configurable_reports');

        $mform->setType('header', PARAM_RAW);
        $mform->setType('record', PARAM_RAW);
        $mform->setType('footer', PARAM_RAW);
    }

    /**
     * GUIビルダーUI（guiモード）
     *
     * 行×カラム構成をJSONとして hidden フィールドに保存する。
     * 実際の行追加・セル編集はJavaScript（AMD）が担当する。
     *
     * @param MoodleQuickForm $mform
     * @param int             $graphcount グラフ数（##graph:N## 生成用）
     * @param array           $options    列名リスト（[[colname]] 生成用）
     */
    protected function definition_gui(MoodleQuickForm $mform, int $graphcount, array $options): void {
        global $PAGE, $CFG;

        // GUIビルダーの状態はJSONとしてhiddenフィールドに保存
        $mform->addElement('hidden', 'editormode', 'gui');
        $mform->setType('editormode', PARAM_TEXT);

        $mform->addElement('hidden', 'gui_layout', '');
        $mform->setType('gui_layout', PARAM_RAW);

        // --- 利用可能なプレースホルダー一覧 ---
        $placeholders = [
            '##graphs##'       => get_string('template_ph_graphs',      'block_configurable_reports'),
            '##reporttable##'  => get_string('template_ph_table',       'block_configurable_reports'),
            '##reportname##'   => get_string('template_ph_reportname',  'block_configurable_reports'),
            '##pagination##'   => get_string('template_ph_pagination',  'block_configurable_reports'),
            '##exportoptions##'=> get_string('template_ph_export',      'block_configurable_reports'),
        ];
        // グラフ個別プレースホルダーを動的に追加（1始まり）
        for ($i = 1; $i <= $graphcount; $i++) {
            $placeholders["##graph:$i##"] = "Graph $i";
        }

        // プレースホルダー一覧をJSON化してJSに渡す
        $placeholdersjson = json_encode($placeholders, JSON_UNESCAPED_UNICODE);

        // GUIビルダー本体はJSが生成する（AMD モジュール）
        $mform->addElement('html', '<div id="cr-gui-builder" data-placeholders=\'' . $placeholdersjson . '\'>'
            . '<p class="text-muted">' . get_string('template_gui_loading', 'block_configurable_reports') . '</p>'
            . '</div>');

        // GUIビルダーのJSを読み込む
        $PAGE->requires->js_call_amd('block_configurable_reports/templatebuilder', 'init');
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

        $templateeditor = get_config('block_configurable_reports', 'templateeditor');

        if ($templateeditor === 'gui') {
            // GUIモード：レイアウトが空でないか確認
            if ($data['enabled'] && empty($data['gui_layout'])) {
                $errors['gui_layout'] = get_string('required');
            }
        } else {
            // classicモード：recordが空でないか確認（従来動作）
            if ($data['enabled'] && !$data['record']) {
                $errors['record'] = get_string('required');
            }
        }

        return $errors;
    }

}
