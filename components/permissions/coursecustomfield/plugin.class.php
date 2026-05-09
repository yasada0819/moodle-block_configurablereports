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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');

/**
 * Class plugin_coursecustomfield
 *
 * Grants access when the current course has a specific custom field value.
 * Useful for restricting global reports to certain course types
 * (e.g. only show in clinical practice courses, or only in a specific faculty).
 *
 * @package   block_configurable_reports
 */
class plugin_coursecustomfield extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->form = true;
        $this->unique = false;
        $this->fullname = get_string('coursecustomfield', 'block_configurable_reports');
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    /**
     * Summary
     *
     * @param object $data
     * @return string
     */
    public function summary(object $data): string {
        global $DB;

        $name = $DB->get_field('customfield_field', 'name', ['shortname' => $data->fieldshortname]);
        $label = $name ?: $data->fieldshortname;

        return $label . ' = ' . $data->fieldvalue;
    }

    /**
     * Execute
     *
     * Returns true when the course associated with $context has a custom field
     * whose shortname matches $data->fieldshortname and whose value matches
     * $data->fieldvalue.
     *
     * Falls back to false for system context (e.g. Moodle dashboard with no
     * specific course), so global reports are hidden there unless another
     * permission element grants access.
     *
     * @param int    $userid
     * @param context $context
     * @param object $data   formdata: fieldshortname, fieldvalue
     * @return bool
     */
    public function execute($userid, $context, $data): bool {
        global $DB;

        // コースコンテキストを取得（コースモジュール・ブロック等の場合は親を辿る）.
        $coursecontext = $context->get_course_context(false);
        if (!$coursecontext) {
            // システムコンテキスト（Moodleダッシュボード等）はfalse.
            return false;
        }

        $courseid = $coursecontext->instanceid;

        // SITEIDの場合はグローバルスコープなので対象外.
        if ($courseid == SITEID) {
            return false;
        }

        // customfield_field からフィールドIDを取得.
        $field = $DB->get_record('customfield_field', ['shortname' => $data->fieldshortname]);
        if (!$field) {
            return false;
        }

        // customfield_data からコース・フィールドに対応する値を取得.
        $record = $DB->get_record('customfield_data', [
            'fieldid'   => $field->id,
            'instanceid' => $courseid,
        ]);

        if (!$record) {
            return false;
        }

        // チェックボックス型（datatype=checkbox）は value が '1'/'0'.
        // テキスト・セレクト等は charvalue に文字列が入る.
        // Moodle の customfield は datatype によって保存カラムが異なるため両方確認する.
        $actual = '';
        if ($field->type === 'checkbox') {
            $actual = (string) $record->intvalue;
        } else if (!empty($record->charvalue)) {
            $actual = $record->charvalue;
        } else if (!empty($record->value)) {
            $actual = $record->value;
        }

        return ($actual === (string) $data->fieldvalue);
    }

}
