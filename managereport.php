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
 * Copy reports from another course — included from managereport.php
 *
 * This file is included (not required standalone) from the extension hook
 * at the bottom of managereport.php in the extension_based branch.
 *
 * Expected variables inherited from the caller:
 *   $course   — current course object (copy destination)
 *   $context  — context of the current course
 *   $CFG, $DB, $USER, $OUTPUT — standard Moodle globals
 *
 * @package    block_configurablereports_extension
 * @copyright  2026 yasada0819
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

// ------------------------------------------------------------------ //
// 1. Handle POST: copy selected reports into current course            //
// ------------------------------------------------------------------ //

$doCopy   = optional_param('cr_ext_docopy', 0, PARAM_BOOL);
$fromCourseId = optional_param('cr_ext_fromcourseid', 0, PARAM_INT);

if ($doCopy && confirm_sesskey()) {
    $reportIds = optional_param_array('cr_ext_reportids', [], PARAM_INT);

    if (!empty($reportIds) && $fromCourseId) {
        $copied = 0;
        foreach ($reportIds as $rid) {
            $src = $DB->get_record('block_configurable_reports', ['id' => $rid]);
            if (!$src) {
                continue;
            }

            // Permission check: caller must be able to manage reports in
            // the source course (prevents copying from courses they can't see).
            if ($src->courseid == SITEID) {
                $srcctx = context_system::instance();
            } else {
                $srcctx = context_course::instance($src->courseid);
            }
            if (!has_capability('block/configurable_reports:managereports', $srcctx) &&
                !has_capability('block/configurable_reports:manageownreports', $srcctx)) {
                continue; // silently skip reports they can't access
            }

            // Build the new record.
            $newreport = clone $src;
            unset($newreport->id);
            $newreport->courseid  = $course->id;
            $newreport->ownerid   = $USER->id;
            $newreport->visible   = 1;

            // Append source course name so the copy is distinguishable.
            $srccoursename = $DB->get_field('course', 'shortname', ['id' => $src->courseid]);
            if (!$srccoursename) {
                $srccoursename = get_string('deleted');
            }
            $newreport->name = $src->name . ' (' . $srccoursename . ')';

            // Rewrite courseid inside the serialised SQL component so that
            // %%COURSEID%% style substitutions point to the new course.
            if (!empty($newreport->components)) {
                $components = cr_unserialize($newreport->components);
                if (array_key_exists('customsql', $components)) {
                    $components['customsql']['config']->courseid = $course->id;
                }
                $newreport->components = cr_serialize($components);
            }

            $DB->insert_record('block_configurable_reports', $newreport);
            $copied++;
        }

        if ($copied > 0) {
            \core\notification::success(
                get_string('cr_ext_copied_n', 'block_configurablereports_extension', $copied)
            );
        } else {
            \core\notification::warning(
                get_string('cr_ext_copied_none', 'block_configurablereports_extension')
            );
        }

        // Reload the page cleanly (PRG pattern).
        redirect(new moodle_url('/blocks/configurable_reports/managereport.php',
            ['courseid' => $course->id]));
    }
}

// ------------------------------------------------------------------ //
// 2. Build course selector                                             //
//    Only courses that have at least one report AND where the current  //
//    user has managereports or manageownreports capability.            //
// ------------------------------------------------------------------ //

// Get all course IDs that have at least one report (excluding the
// current course — copying to itself is pointless).
$courseidsWithReports = $DB->get_fieldset_sql(
    'SELECT DISTINCT courseid FROM {block_configurable_reports} WHERE courseid != ?',
    [$course->id]
);

$courseOptions = [];
foreach ($courseidsWithReports as $cid) {
    // System context for SITEID, course context otherwise.
    if ((int)$cid === SITEID) {
        $ctx = context_system::instance();
    } else {
        // context_course::instance() will throw if the course doesn't
        // exist any more; guard with a DB check first.
        if (!$DB->record_exists('course', ['id' => $cid])) {
            continue;
        }
        $ctx = context_course::instance($cid);
    }

    if (!has_capability('block/configurable_reports:managereports', $ctx) &&
        !has_capability('block/configurable_reports:manageownreports', $ctx)) {
        continue; // not allowed to see reports in this course
    }

    if ((int)$cid === SITEID) {
        $label = get_string('site');
    } else {
        $label = $DB->get_field('course', 'fullname', ['id' => $cid]);
        if (!$label) {
            continue; // course deleted
        }
        $label = format_string($label);
    }
    $courseOptions[$cid] = $label;
}

// Sort alphabetically by label.
asort($courseOptions);

// ------------------------------------------------------------------ //
// 3. Build report list for the selected source course (if any)        //
// ------------------------------------------------------------------ //

$reportRows = [];
if ($fromCourseId && isset($courseOptions[$fromCourseId])) {
    $srcReports = $DB->get_records(
        'block_configurable_reports',
        ['courseid' => $fromCourseId],
        'name ASC'
    );

    // Filter to only reports the user may access in the source course.
    if ((int)$fromCourseId === SITEID) {
        $srcctx = context_system::instance();
    } else {
        $srcctx = context_course::instance($fromCourseId);
    }
    $canManageAll = has_capability('block/configurable_reports:managereports', $srcctx);

    foreach ($srcReports as $r) {
        if (!$canManageAll && $r->ownerid != $USER->id) {
            continue;
        }
        $reportRows[] = $r;
    }
}

// ------------------------------------------------------------------ //
// 4. Render                                                            //
// ------------------------------------------------------------------ //

// This feature is restricted to Manager / Admin only.
if (!has_capability('block/configurable_reports:managereports', $context)) {
    return;
}

echo html_writer::tag('hr', '');
echo html_writer::start_tag('div', ['class' => 'cr-ext-copy-from-course mt-4']);
echo html_writer::tag('h4',
    get_string('cr_ext_copy_heading', 'block_configurablereports_extension'));

if (empty($courseOptions)) {
    echo $OUTPUT->notification(
        get_string('cr_ext_no_source_courses', 'block_configurablereports_extension'),
        'info'
    );
    echo html_writer::end_tag('div');
    return;
}

// --- Step 1: course selector form ---
$step1url = new moodle_url('/blocks/configurable_reports/managereport.php',
    ['courseid' => $course->id]);

echo html_writer::start_tag('form', [
    'method' => 'get',
    'action' => $step1url->out(false),
    'class'  => 'form-inline mb-3',
]);
echo html_writer::empty_tag('input', [
    'type'  => 'hidden',
    'name'  => 'courseid',
    'value' => $course->id,
]);

echo html_writer::tag('label',
    get_string('cr_ext_source_course', 'block_configurablereports_extension'),
    ['for' => 'cr_ext_fromcourseid', 'class' => 'mr-2']
);

$selectOptions = ['' => get_string('choosedots')] + $courseOptions;
echo html_writer::select(
    $selectOptions,
    'cr_ext_fromcourseid',
    $fromCourseId ?: '',
    false,
    ['id' => 'cr_ext_fromcourseid', 'class' => 'custom-select mr-2']
);

echo html_writer::empty_tag('input', [
    'type'  => 'submit',
    'value' => get_string('cr_ext_show_reports', 'block_configurablereports_extension'),
    'class' => 'btn btn-secondary',
]);
echo html_writer::end_tag('form');

// --- Step 2: report checklist + copy button ---
if ($fromCourseId && isset($courseOptions[$fromCourseId])) {
    if (empty($reportRows)) {
        echo $OUTPUT->notification(
            get_string('cr_ext_no_reports_in_course', 'block_configurablereports_extension'),
            'info'
        );
    } else {
        $copyurl = new moodle_url('/blocks/configurable_reports/managereport.php',
            ['courseid' => $course->id]);

        echo html_writer::start_tag('form', [
            'method' => 'post',
            'action' => $copyurl->out(false),
        ]);
        echo html_writer::empty_tag('input', [
            'type'  => 'hidden',
            'name'  => 'courseid',
            'value' => $course->id,
        ]);
        echo html_writer::empty_tag('input', [
            'type'  => 'hidden',
            'name'  => 'cr_ext_fromcourseid',
            'value' => $fromCourseId,
        ]);
        echo html_writer::empty_tag('input', [
            'type'  => 'hidden',
            'name'  => 'cr_ext_docopy',
            'value' => '1',
        ]);
        echo html_writer::empty_tag('input', [
            'type'  => 'hidden',
            'name'  => 'sesskey',
            'value' => sesskey(),
        ]);

        echo html_writer::tag('p',
            get_string('cr_ext_select_reports', 'block_configurablereports_extension',
                format_string($courseOptions[$fromCourseId]))
        );

        // "Select all" checkbox.
        echo html_writer::start_tag('div', ['class' => 'mb-2']);
        echo html_writer::tag('label',
            html_writer::empty_tag('input', [
                'type'    => 'checkbox',
                'id'      => 'cr_ext_selectall',
                'class'   => 'mr-1',
                'onclick' => "document.querySelectorAll('.cr-ext-reportcheck').forEach(function(c){c.checked=this.checked;},this)",
            ]) . get_string('selectall')
        );
        echo html_writer::end_tag('div');

        foreach ($reportRows as $r) {
            $label = format_string($r->name)
                . ' <small class="text-muted">('
                . get_string('report_' . $r->type, 'block_configurable_reports')
                . ')</small>';

            echo html_writer::start_tag('div', ['class' => 'form-check']);
            echo html_writer::tag('label',
                html_writer::empty_tag('input', [
                    'type'  => 'checkbox',
                    'name'  => 'cr_ext_reportids[]',
                    'value' => $r->id,
                    'class' => 'cr-ext-reportcheck form-check-input mr-1',
                ]) . $label,
                ['class' => 'form-check-label']
            );
            echo html_writer::end_tag('div');
        }

        echo html_writer::empty_tag('input', [
            'type'  => 'submit',
            'value' => get_string('cr_ext_copy_selected', 'block_configurablereports_extension'),
            'class' => 'btn btn-primary mt-3',
        ]);
        echo html_writer::end_tag('form');
    }
}

echo html_writer::end_tag('div'); // .cr-ext-copy-from-course
