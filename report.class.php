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
require_once($CFG->dirroot . '/lib/evalmath/evalmath.class.php');
require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');

/**
 * Class report_base
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class report_base {

    /**
     * @var int
     */
    public int $id = 0;

    /**
     * @var array
     */
    public array $components = [];

    /**
     * @var object
     */
    public $finalreport;

    /**
     * @var int
     */
    public int $totalrecords = 0;

    /**
     * @var object|null
     */
    public ?object $currentuser;

    /**
     * @var int
     */
    public int $currentcourse = 0;

    /**
     * @var int
     */
    public int $starttime = 0;

    /**
     * @var int
     */
    public int $endtime = 0;

    /**
     * @var string
     */
    public string $sql = '';

    /**
     * @var null
     */
    public $filterform = null;

    /**
     * @var int
     */
    private int $currentcourseid = 0;

    /**
     * @var false|mixed|stdClass
     */
    public ?object $config;

    /**
     * reports_base
     *
     * @param object|int $report
     * @return void
     */
    public function reports_base($report): void {
        global $DB, $CFG, $USER, $remotedb;

        if (is_numeric($report)) {
            $this->config = $DB->get_record('block_configurable_reports', ['id' => $report]);
        } else {
            $this->config = $report;
        }

        $this->currentuser = $USER;
        $this->currentcourseid = $this->config->courseid;
        $this->init();

        // Use a custom $DB (and not current system's $DB)
        // TODO: major security issue.
        $remotedbhost = get_config('block_configurable_reports', 'dbhost');
        $remotedbname = get_config('block_configurable_reports', 'dbname');
        $remotedbuser = get_config('block_configurable_reports', 'dbuser');
        $remotedbpass = get_config('block_configurable_reports', 'dbpass');

        if (!empty($remotedbhost) && !empty($remotedbname) && !empty($remotedbuser) && !empty($remotedbpass) &&
            $this->config->remote) {
            $dbclass = get_class($DB);
            $remotedb = new $dbclass();
            $remotedb->connect($remotedbhost, $remotedbuser, $remotedbpass, $remotedbname, $CFG->prefix);
        } else {
            $remotedb = $DB;
        }

    }

    /**
     * __construct
     *
     * @param object|int $report
     */
    public function __construct($report) {
        $this->reports_base($report);
    }

    /**
     * Get component path
     *
     * useextension が ON で、対応するカテゴリ（plot/permissions/template）も
     * Extension側で有効になっている場合は Extension のパスを返す。
     * Extension ディレクトリが存在しない場合、またはカテゴリが無効の場合は
     * 本家のパスにフォールバックする。
     *
     * @param string $type       'plot' | 'permissions' | 'template'
     * @param string $pluginname プラグイン名（例: 'bar', 'coursecustomfield'）
     * @return string            plugin.class.php が格納されているディレクトリの絶対パス
     */
    public static function get_component_path(string $type, string $pluginname): string {
        global $CFG;

        $base = $CFG->dirroot . '/blocks/configurable_reports';

        if (get_config('block_configurable_reports', 'useextension')) {
            $extname = get_config('block_configurable_reports', 'activeextension');
            if (empty($extname)) {
                $extname = 'extension';
            }

            // カテゴリごとの有効フラグを確認.
            $flagmap = [
                'plot'        => 'use_plot',
                'permissions' => 'use_permissions',
                'template'    => 'use_template',
            ];
            $flag = $flagmap[$type] ?? null;
            $enabled = $flag && get_config('block_configurablereports_' . $extname, $flag);

            if ($enabled) {
                $extpath = $CFG->dirroot . '/blocks/configurablereports_' . $extname
                         . '/components/' . $type . '/' . $pluginname;
                if (is_dir($extpath)) {
                    return $extpath;
                }
            }
        }

        // Fallback: 本家の pChart / 組み込みプラグイン.
        return $base . '/components/' . $type . '/' . $pluginname;
    }

    /**
     * Check permissions
     *
     * @param int $userid
     * @param context $context
     * @return bool|mixed|null
     */
    public function check_permissions(int $userid, context $context) {
        global $CFG;

        if (has_capability('block/configurable_reports:manageownreports', $context, $userid) && $this->config->ownerid == $userid) {
            return true;
        }

        if (has_capability('block/configurable_reports:managereports', $context, $userid)) {
            return true;
        }

        if (empty($this->config->visible)) {
            return false;
        }

        $components = cr_unserialize($this->config->components);
        $permissions = $components['permissions'] ?? [];

        if (empty($permissions['elements'])) {
            return has_capability('block/configurable_reports:viewreports', $context);
        }

        $i = 1;
        $cond = [];
        foreach ($permissions['elements'] as $p) {

            require_once(self::get_component_path('permissions', $p['pluginname']) . '/plugin.class.php');
            $classname = 'plugin_' . $p['pluginname'];
            $class = new $classname($this->config);
            $cond[$i] = $class->execute($userid, $context, $p['formdata']);
            $i++;
        }

        if (count($cond) === 1) {
            return $cond[1];
        }

        $m = new EvalMath;
        $orig = $dest = [];

        if (isset($permissions['config']->conditionexpr)) {
            $logic = trim($permissions['config']->conditionexpr);
            // Security
            // No more than: conditions * 10 chars.
            $logic = substr($logic, 0, count($permissions['elements']) * 10);
            $logic = str_replace(['and', 'or'], ['&&', '||'], strtolower($logic));
            // More Security Only allowed chars.
            $logic = preg_replace('/[^&c\d\s|()]/i', '', $logic);
            $logic = str_replace(['&&', '||'], ['*', '+'], $logic);

            for ($j = $i - 1; $j > 0; $j--) {
                $orig[] = 'c' . $j;
                $dest[] = ($cond[$j]) ? 1 : 0;
            }

            return $m->evaluate(str_replace($orig, $dest, $logic));
        }

        return false;
    }

    /**
     * add_filter_elements
     *
     * @param MoodleQuickForm $mform
     * @return void
     */
    public function add_filter_elements(MoodleQuickForm $mform): void {
        global $CFG;

        $components = cr_unserialize($this->config->components);
        $filters = $components['filters']['elements'] ?? [];

        require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');
        foreach ($filters as $f) {

            if (is_array($f['pluginname'])) {
                $f['pluginname'] = $f['pluginname'][0];
            }

            $filename = clean_filename($f['pluginname']);
            require_once($CFG->dirroot . '/blocks/configurable_reports/components/filters/' . $filename . '/plugin.class.php');
            $classname = 'plugin_' . $filename;
            $class = new $classname($this->config);

            $finalelements = $class->print_filter($mform, $f['formdata']);

        }
    }

    /**
     * check_filters_request
     *
     * @return void
     */
    public function check_filters_request(): void {

        $components = cr_unserialize($this->config->components);
        $filters = $components['filters']['elements'] ?? [];

        if (!empty($filters)) {

            $formdata = new stdclass;
            $request = array_merge($_POST, $_GET);
            if ($request) {
                foreach ($request as $key => $val) {
                    if (strpos($key, 'filter_') !== false) {
                        $key = clean_param($key, PARAM_CLEANHTML);
                        if (is_array($val)) {
                            $val = clean_param_array($val, PARAM_CLEANHTML);
                        } else {
                            $val = clean_param($val, PARAM_CLEANHTML);
                        }
                        $formdata->{$key} = $val;
                    }
                }
            }

            require_once('filter_form.php');
            $filterform = new report_edit_form(null, $this);

            $filterform->set_data($formdata);

            if ($filterform->is_cancelled()) {
                $params = ['id' => $this->config->id, 'courseid' => $this->config->courseid];
                redirect(new moodle_url('/blocks/configurable_reports/viewreport.php', $params));
                die;
            }
            $this->filterform = $filterform;
        }
    }

    /**
     * print_filters
     *
     * @return void
     */
    public function print_filters(): void {
        if ($this->filterform !== null) {
            $this->filterform->display();
        }
    }

    /**
     * print_graphs
     *
     * グラフライブラリの設定に応じて出力を切り替える。
     *   - pChart モード : execute() が返す URL を <img src="..."> として出力（既存動作）
     *   - Chart.js モード: execute() が返す HTML 文字列をそのまま出力し、
     *                      $PAGE->requires->js_call_amd() で chartrenderer を呼ぶ
     *
     * @param bool $return
     * @return string|true
     */
    public function print_graphs(bool $return = false) {
        global $PAGE;

        $output = '';
        $graphs = $this->get_graphs($this->finalreport->table->data);
        $haschartjs = false;

        if ($graphs) {
            foreach ($graphs as $g) {
                $output .= '<div class="centerpara">';
                if (str_starts_with(trim($g), '<')) {
                    // Chart.js モード: execute() が HTML 文字列を返している
                    $output .= $g . '<br />';
                    $haschartjs = true;
                } else {
                    // pChart モード: execute() が URL を返している
                    $output .= '<img src="' . $g . '" alt="' . s($this->config->name) . '"><br />';
                }
                $output .= '</div>';
            }
        }

        // Chart.js グラフが 1 つ以上あるときだけ AMD モジュールを登録する。
        // js_call_amd() は Moodle が適切なタイミング（RequireJS ロード後）に
        // initAll() を呼び出すため、require is not defined エラーが発生しない。
        if ($haschartjs) {
            $PAGE->requires->js_call_amd('block_configurable_reports/chartrenderer', 'initAll');
        }

        if ($return) {
            return $output;
        }

        echo $output;

        return true;
    }

    /**
     * print_export_options
     *
     * @param bool $return
     * @return string|true
     */
    public function print_export_options(bool $return = false) {
        global $CFG;

        $wwwpath = $CFG->wwwroot;

        // TODO move to more Moodle approach.
        $request = array_merge($_POST, $_GET);

        if ($request) {
            $id = clean_param($request['id'], PARAM_INT);
            $wwwpath = 'viewreport.php?id=' . $id;
            unset($request['id']);

            foreach ($request as $key => $val) {

                $key = s(clean_param($key, PARAM_CLEANHTML));

                if (is_array($val)) {
                    foreach ($val as $k => $v) {
                        $k = s(clean_param($k, PARAM_CLEANHTML));
                        $v = s(clean_param($v, PARAM_CLEANHTML));
                        $wwwpath .= "&{$key}[$k]=" . $v;
                    }
                } else {
                    $val = clean_param($val, PARAM_CLEANHTML);
                    $wwwpath .= "&$key=" . s($val);
                }
            }
        }

        $output = '';
        $export = explode(',', $this->config->export);

        if (!empty($this->config->export)) {
            $output .= '<br /><div class="centerpara">';
            $output .= get_string('downloadreport', 'block_configurable_reports') . ': ';

            foreach ($export as $e) {

                if (empty($e)) {
                    continue;
                }

                // TODO Use moodle_url.
                $output .= '<a href="' . s($wwwpath) . '&download=1&format=' . s($e) . '">
                                    <img src="' . $CFG->wwwroot . '/blocks/configurable_reports/export/' . s($e) . '/pix.gif"
                                     alt="' . s($e) . '">
                                    &nbsp;' . (s(strtoupper($e))) .
                    '</a>&nbsp;';
            }
            $output .= '</div>';
        }

        if ($return) {
            return $output;
        }

        echo $output;

        return true;
    }

    /**
     * Update conditions
     *
     * @param array $data
     * @param string $logic
     * @return bool|mixed|null
     */
    public function evaluate_conditions(array $data, string $logic) {
        global $CFG;

        require_once($CFG->dirroot . '/blocks/configurable_reports/reports/evalwise.class.php');

        $logic = strtolower(trim($logic));
        $logic = substr($logic, 0, count($data) * 10);
        $logic = str_replace(['or', 'and', 'not'], ['+', '*', '-'], $logic);
        $logic = preg_replace('/[^\*c\d\s\+\-()]/i', '', $logic);

        $orig = $dest = [];
        for ($j = count($data); $j > 0; $j--) {
            $orig[] = 'c' . $j;
            $dest[] = $j;
        }
        $logic = str_replace($orig, $dest, $logic);

        $m = new EvalWise();
        $m->set_data($data);

        return $m->evaluate($logic);
    }

    /**
     * get_graphs
     *
     * @param array $finalreport
     * @return array
     */
    public function get_graphs($finalreport): array {
        global $CFG;

        $components = cr_unserialize($this->config->components);
        $graphs = $components['plot']['elements'] ?? [];

        $reportgraphs = [];

        if (!empty($graphs)) {
            $series = [];

            foreach ($graphs as $g) {
                require_once(self::get_component_path('plot', $g['pluginname']) . '/plugin.class.php');
                $classname = 'plugin_' . $g['pluginname'];
                $class = new $classname($this->config);
                $reportgraphs[] = $class->execute($g['id'], $g['formdata'], $finalreport);
            }
        }

        return $reportgraphs;
    }

    /**
     * get_calcs
     *
     * @param array $finaltable
     * @param array $tablehead
     * @return array
     */
    public function get_calcs(array $finaltable, array $tablehead): array {
        global $CFG;

        $components = cr_unserialize($this->config->components);
        $calcs = $components['calcs']['elements'] ?? [];

        // Calcs doesn't work with multi-rows so far.
        $columnscalcs = [];
        $finalcalcs = [];
        if (!empty($calcs)) {
            foreach ($calcs as $calc) {

                if (!isset($calc['formdata']->column)) {
                    continue;
                }

                $columnscalcs[$calc['formdata']->column] = [];
            }

            $columnstostore = array_keys($columnscalcs);

            foreach ($finaltable as $r) {
                foreach ($columnstostore as $c) {
                    if (isset($r[$c])) {
                        $columnscalcs[$c][] = $r[$c];
                    }
                }
            }

            foreach ($calcs as $calc) {

                if (is_array($calc['pluginname'])) {
                    $calc['pluginname'] = $calc['pluginname'][0];
                }

                $filename = clean_filename($calc['pluginname']);
                require_once($CFG->dirroot . '/blocks/configurable_reports/components/calcs/' . $filename . '/plugin.class.php');
                $classname = 'plugin_' . $filename;

                $class = new $classname($this->config);
                $result = $class->execute($columnscalcs[$calc['formdata']->column]);
                $finalcalcs[$calc['formdata']->column] = $result;
            }

            for ($i = 0, $imax = count($tablehead); $i < $imax; $i++) {
                if (!isset($finalcalcs[$i])) {
                    $finalcalcs[$i] = '';
                }
            }

            ksort($finalcalcs);

        }

        return $finalcalcs;
    }

    /**
     * elements_by_conditions
     *
     * @param array $conditions
     * @return bool|mixed|null
     */
    public function elements_by_conditions($conditions) {
        global $CFG;

        if (empty($conditions['elements'])) {
            return $this->get_all_elements();
        }

        $finalelements = [];
        $i = 1;
        foreach ($conditions['elements'] as $c) {
            require_once($CFG->dirroot . '/blocks/configurable_reports/components/conditions/' . $c['pluginname'] .
                '/plugin.class.php');
            $classname = 'plugin_' . $c['pluginname'];
            $class = new $classname($this->config);
            $elements[$i] = $class->execute($c['formdata'], $this->currentuser, $this->currentcourseid);
            $i++;
        }

        if (count($conditions['elements']) === 1) {
            $finalelements = $elements[1];
        } else {
            $logic = $conditions['config']->conditionexpr;
            $finalelements = $this->evaluate_conditions($elements, $logic);
            if ($finalelements === false) {
                return false;
            }
        }

        return $finalelements;
    }

    /**
     * Returns a report object
     */
    public function create_report(): bool {
        global $CFG;

        // Conditions.
        $components = cr_unserialize($this->config->components);

        $conditions = $components['conditions']['elements'] ?? [];
        $filters = $components['filters']['elements'] ?? [];
        $columns = $components['columns']['elements'] ?? [];
        $ordering = $components['ordering']['elements'] ?? [];

        $finalelements = [];

        if (!empty($conditions)) {
            $finalelements = $this->elements_by_conditions($components['conditions']);
        } else {
            // All elements.
            $finalelements = $this->get_all_elements();
        }

        // Filters.
        if (!empty($filters)) {
            foreach ($filters as $f) {
                require_once($CFG->dirroot . '/blocks/configurable_reports/components/filters/' . $f['pluginname'] .
                    '/plugin.class.php');
                $classname = 'plugin_' . $f['pluginname'];
                $class = new $classname($this->config);
                $finalelements = $class->execute($finalelements, $f['formdata']);
            }
        }

        // Ordering.

        $sqlorder = '';

        $orderingdata = [];
        if (!empty($ordering)) {
            foreach ($ordering as $o) {
                require_once($CFG->dirroot . '/blocks/configurable_reports/components/ordering/' . $o['pluginname'] .
                    '/plugin.class.php');
                $classname = 'plugin_' . $o['pluginname'];
                $classorder = new $classname($this->config);
                $orderingdata = $o['formdata'];
                if ($classorder->sql) {
                    $sqlorder = $classorder->execute($orderingdata);
                }
            }
        }

        // COLUMNS - FIELDS.

        $rows = $this->get_rows($finalelements, $sqlorder);

        if (!$sqlorder && isset($classorder)) {
            $rows = $classorder->execute($rows, $orderingdata);
        }

        $reporttable = [];
        $tablehead = [];
        $tablealign = [];
        $tablesize = [];
        $tablewrap = [];
        $firstrow = true;

        $pluginscache = [];

        if ($rows) {
            foreach ($rows as $r) {

                $tempcols = [];
                foreach ($columns as $c) {
                    if (empty($c)) {
                        continue;
                    }

                    require_once($CFG->dirroot . '/blocks/configurable_reports/components/columns/' . $c['pluginname'] .
                        '/plugin.class.php');
                    $classname = 'plugin_' . $c['pluginname'];

                    if (!isset($pluginscache[$classname])) {
                        $class = new $classname($this->config, $c);
                        $pluginscache[$classname] = $class;
                    } else {
                        $class = $pluginscache[$classname];
                    }

                    $tempcols[] = $class->execute(
                        $c['formdata'],
                        $r,
                        $this->currentuser,
                        $this->currentcourseid,
                        $this->starttime,
                        $this->endtime
                    );

                    if ($firstrow) {
                        $tablehead[] = $class->summary($c['formdata']);
                        [$align, $size, $wrap] = $class->colformat($c['formdata']);
                        $tablealign[] = $align;
                        $tablesize[] = $size;
                        $tablewrap[] = $wrap;
                    }

                }
                $firstrow = false;
                $reporttable[] = $tempcols;
            }
        }

        // EXPAND ROWS.
        $finaltable = [];

        foreach ($reporttable as $row) {
            $col = [];
            $multiple = false;
            $nrows = 0;
            $mrowsi = [];

            foreach ($row as $key => $cell) {
                if (!is_array($cell)) {
                    $col[] = $cell;
                } else {
                    $multiple = true;
                    $nrows = count($cell);
                    $mrowsi[] = $key;
                }
            }
            if ($multiple) {
                $newrows = [];
                for ($i = 0; $i < $nrows; $i++) {
                    $newrows[$i] = $row;
                    foreach ($mrowsi as $index) {
                        $newrows[$i][$index] = $row[$index][$i];
                    }
                }
                foreach ($newrows as $r) {
                    $finaltable[] = $r;
                }
            } else {
                $finaltable[] = $col;
            }
        }

        // CALCS.
        $finalcalcs = $this->get_calcs($finaltable, $tablehead);

        // Make the table, head, columns, etc...

        $table = new stdClass;
        $table->id = 'reporttable';
        $table->data = $finaltable;
        $table->head = $tablehead;
        $table->size = $tablesize;
        $table->align = $tablealign;
        $table->wrap = $tablewrap;
        $table->width = (isset($components['columns']['config'])) ? $components['columns']['config']->tablewidth : '';
        $table->summary = $this->config->summary;
        $table->tablealign = (isset($components['columns']['config'])) ? $components['columns']['config']->tablealign : 'center';
        $table->cellpadding = (isset($components['columns']['config'])) ? $components['columns']['config']->cellpadding : '5';
        $table->cellspacing = (isset($components['columns']['config'])) ? $components['columns']['config']->cellspacing : '1';
        $table->class = (isset($components['columns']['config'])) ? $components['columns']['config']->class : 'generaltable';

        $calcs = new html_table();
        $calcs->data = [$finalcalcs];
        $calcs->head = $tablehead;
        $calcs->size = $tablesize;
        $calcs->align = $tablealign;
        $calcs->wrap = $tablewrap;
        $calcs->summary = $this->config->summary;
        $calcs->attributes['class'] =
            (isset($components['columns']['config'])) ? $components['columns']['config']->class : 'generaltable';

        if (!$this->finalreport) {
            $this->finalreport = new stdClass;
        }
        $this->finalreport->name = $this->config->name;
        $this->finalreport->table = $table;
        $this->finalreport->calcs = $calcs;

        return true;

    }

    /**
     * add_jsordering
     *
     * @param moodle_page $moodlepage
     * @return void
     */
    public function add_jsordering(moodle_page $moodlepage): void {
        switch (get_config('block_configurable_reports', 'reporttableui')) {
            case 'datatables':
                cr_add_jsdatatables('#reporttable', $moodlepage);
                break;
            case 'jquery':
                cr_add_jsordering('#reporttable', $moodlepage);
                echo html_writer::tag(
                    'style',
                    '#page-blocks-configurable_reports-viewreport .generaltable {
                    overflow: auto;
                    width: 100%;
                    display: block;}'
                );
                break;
            case 'html':
                echo html_writer::tag(
                    'style',
                    '#page-blocks-configurable_reports-viewreport .generaltable {
                    overflow: auto;
                    width: 100%;
                    display: block;}'
                );
                break;
            default:
                break;
        }
    }

    /**
     * print_template
     *
     * @param object $config
     * @param moodle_page $moodlepage
     * @return void
     */
    /**
     * print_template
     *
     * ##graphs## および ##graph:N## プレースホルダーを含むテンプレートを処理する。
     *
     * Chart.js が生成する <canvas> タグは format_text() の HTML Purifier で
     * 削除されてしまうため、グラフ HTML をいったん HTMLコメントトークンに退避し、
     * format_text() 通過後にトークンを実際の HTML に戻す方式を採用している。
     *
     * 対応プレースホルダー（header / footer のみ。record 部分は対象外）：
     *   ##graphs##    : 全グラフを縦1列で出力（従来動作）
     *   ##graph:0##   : 0番目のグラフのみ出力
     *   ##graph:1##   : 1番目のグラフのみ出力
     *   ##graph:N##   : N番目のグラフのみ出力（動的に対応）
     *
     * @param object $config
     * @param moodle_page $moodlepage
     * @return void
     */
    /**
     * print_template
     *
     * ##graphs## および ##graph:N## プレースホルダーを含むテンプレートを処理する。
     *
     * グラフHTMLは format_text() / HTML Purifier で <canvas> が除去されるため、
     * preg_split でプレースホルダーを区切りにテンプレートを分割し、
     * グラフ部分だけ format_text() を通さずにそのまま出力する。
     *
     * 対応プレースホルダー（header / footer のみ。record 部分は対象外）：
     *   ##graphs##    : 全グラフを縦1列で出力（従来動作）
     *   ##graph:0##   : 0番目のグラフのみ出力
     *   ##graph:N##   : N番目のグラフのみ出力（動的に対応）
     *
     * @param object $config
     * @param moodle_page $moodlepage
     * @return void
     */
    /**
     * print_template
     *
     * templateeditor 設定と config->editormode に応じて
     * classic / gui の処理を振り分ける。
     *
     * @param object $config
     * @param moodle_page $moodlepage
     * @return void
     */
    public function print_template($config, moodle_page $moodlepage): void {
        global $CFG;

        // Extension の template renderer に委譲（use_template=ON かつ renderer.php が存在する場合）.
        $rendererpath = self::get_component_path('template', 'renderer') . '/renderer.php';
        if (file_exists($rendererpath)) {
            require_once($rendererpath);
            if (function_exists('print_template_extension')) {
                print_template_extension($this, $config, $moodlepage);
                return;
            }
        }

        $extname = get_config('block_configurable_reports', 'activeextension') ?: 'extension';
        $templateeditor = get_config('block_configurablereports_' . $extname, 'templateeditor');

        if ($templateeditor === 'gui'
            && !empty($config->editormode)
            && $config->editormode === 'gui'
            && !empty($config->gui_layout)) {
            $this->print_template_gui($config, $moodlepage);
        } else {
            $this->print_template_classic($config, $moodlepage);
        }
    }

    /**
     * print_template_classic
     *
     * 従来のテキストエリアで作成されたテンプレートを処理する。
     * ##graphs## および ##graph:N## プレースホルダーを含む
     * header / footer を preg_split で分割し、グラフ部分だけ
     * format_text() を通さずに出力する。
     *
     * @param object $config
     * @param moodle_page $moodlepage
     * @return void
     */
    public function print_template_classic($config, moodle_page $moodlepage): void {

        $pagecontents = [];
        $pagecontents['header'] = (isset($config->header) && $config->header) ? $config->header : '';
        $pagecontents['footer'] = (isset($config->footer) && $config->footer) ? $config->footer : '';

        $recordtpl = (isset($config->record) && $config->record) ? $config->record : '';

        $calculations = '';

        if (!empty($this->finalreport->calcs->data[0])) {
            $calculations = html_writer::table($this->finalreport->calcs);
        }

        $pagination = '';
        if ($this->config->pagination) {
            $page = optional_param('page', 0, PARAM_INT);
            $postfiltervars = '';
            $request = array_merge($_POST, $_GET);
            if ($request) {
                foreach ($request as $key => $val) {
                    if (strpos($key, 'filter_') !== false) {
                        $key = s(clean_param($key, PARAM_CLEANHTML));
                        if (is_array($val)) {
                            foreach ($val as $k => $v) {
                                $k = s(clean_param($k, PARAM_CLEANHTML));
                                $v = s(clean_param($v, PARAM_CLEANHTML));
                                $postfiltervars .= "&amp;{$key}[$k]=" . $v;
                            }
                        } else {
                            $val = s(clean_param($val, PARAM_CLEANHTML));
                            $postfiltervars .= "&amp;$key=" . $val;
                        }
                    }
                }
            }

            $this->totalrecords = count($this->finalreport->table->data);
            $pagingbar = new paging_bar(
                $this->totalrecords,
                $page,
                $this->config->pagination,
                "viewreport.php?id=" . s($this->config->id) . "&courseid=" . ((int) $this->config->courseid) .
                "$postfiltervars&amp;"
            );
            $pagingbar->pagevar = 'page';
            $pagination = $OUTPUT->render($pagingbar);
        }

        // --- グラフHTML を事前に生成 ---
        // グラフHTMLはformat_text()を通さないため、
        // プレースホルダーをキーにしたマップとして保持する。
        $graphmap = [];

        // 全グラフまとめて（##graphs##用）
        $graphmap['##graphs##'] = $this->print_graphs(true);

        // 個別グラフ（##graph:N##用）動的に生成
        $allgraphs = $this->get_graphs($this->finalreport->table->data);
        foreach ($allgraphs as $n => $g) {
            $graphhtml  = '<div class="centerpara">';
            if (str_starts_with(trim($g), '<')) {
                $graphhtml .= $g . '<br />';
            } else {
                $graphhtml .= '<img src="' . $g . '" alt="' . s($this->config->name) . '"><br />';
            }
            $graphhtml .= '</div>';
            $graphmap['##graph:' . ($n + 1) . '##'] = $graphhtml;
        }

        // グラフ以外のプレースホルダーを先に展開
        $search = [
            '##reportname##',
            '##reportsummary##',
            '##exportoptions##',
            '##calculationstable##',
            '##pagination##',
        ];
        $replace = [
            format_string($this->config->name),
            format_text($this->config->summary),
            $this->print_export_options(true),
            $calculations,
            $pagination,
        ];

        foreach ($pagecontents as $key => $p) {
            if ($p) {
                $pagecontents[$key] = str_ireplace($search, $replace, $p);
            }
        }

        if ($this->config->jsordering) {
            $this->add_jsordering($moodlepage);
        }
        $this->print_filters();

        // Chart.js AMD モジュールを登録
        if (!empty($allgraphs)) {
            $haschartjs = false;
            foreach ($allgraphs as $g) {
                if (str_starts_with(trim($g), '<')) {
                    $haschartjs = true;
                    break;
                }
            }
            if ($haschartjs) {
                global $PAGE;
                $PAGE->requires->js_call_amd('block_configurable_reports/chartrenderer', 'initAll');
            }
        }

        echo "<div id=\"printablediv\">\n";

        // header と footer をグラフプレースホルダーで分割して出力
        foreach (['header', 'footer'] as $section) {
            if (!$pagecontents[$section]) {
                if ($section === 'footer') {
                    // footer が空でも処理を続ける
                }
                continue;
            }

            $text = is_array($pagecontents[$section])
                ? $pagecontents[$section]['text']
                : $pagecontents[$section];
            $fmt = is_array($pagecontents[$section])
                ? $pagecontents[$section]['format']
                : FORMAT_HTML;

            // グラフプレースホルダーのパターンで分割
            $pattern = '/(##graphs##|##graph:\d+##)/';
            $parts   = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

            if ($section === 'header' && $this->config->displaytotalrecords) {
                // totalrecords は header の直後に出すので、分割前に追加
            }

            foreach ($parts as $part) {
                if (isset($graphmap[$part])) {
                    // グラフプレースホルダー → format_text()を通さずそのまま出力
                    echo $graphmap[$part];
                } else {
                    // 通常テキスト → format_text()でサニタイズして出力
                    echo format_text($part, $fmt);
                }
            }

            if ($section === 'header') {
                if ($this->config->displaytotalrecords) {
                    $a = new \stdClass();
                    $a->totalrecords = $this->totalrecords;
                    echo \html_writer::tag('div', get_string('totalrecords', 'block_configurable_reports', $a), ['id' => 'totalrecords']);
                }

                // レコード部分を出力
                if ($recordtpl) {
                    if ($this->config->pagination) {
                        $page = optional_param('page', 0, PARAM_INT);
                        $this->totalrecords = count($this->finalreport->table->data);
                        $this->finalreport->table->data =
                            array_slice($this->finalreport->table->data, $page * $this->config->pagination, $this->config->pagination);
                    }

                    foreach ($this->finalreport->table->data as $r) {
                        if (is_array($recordtpl)) {
                            $recordtext = $recordtpl['text'];
                        } else {
                            $recordtext = $recordtpl;
                        }

                        foreach ($this->finalreport->table->head as $key => $c) {
                            $recordtext = str_ireplace("[[$c]]", $r[$key], $recordtext);
                        }
                        echo format_text($recordtext, FORMAT_HTML);
                    }
                }
            }
        }

        echo "</div>\n";
        if ($this->config->displayprintbutton) {
            echo '<div class="centerpara"><br />';
            echo $OUTPUT->pix_icon('print', get_string('printreport', 'block_configurable_reports'), 'block_configurable_reports');
            echo "&nbsp;<a href=\"javascript: printDiv('printablediv')\">".get_string('printreport', 'block_configurable_reports')."</a>";
            echo "</div>\n";
        }
    }


    /**
     * print_template_gui
     *
     * GUIビルダーで作成されたテンプレート（JSON）を解釈してHTMLを出力する。
     *
     * JSON構造：
     * {
     *   "enabled": 1,
     *   "editormode": "gui",
     *   "rows": [
     *     {
     *       "cols": 2,
     *       "cells": [
     *         {"type": "placeholder", "value": "##graph:0##"},
     *         {"type": "placeholder", "value": "##graph:1##"}
     *       ]
     *     },
     *     {
     *       "cols": 1,
     *       "cells": [
     *         {"type": "html", "value": "<h2>詳細データ</h2>"}
     *       ]
     *     }
     *   ]
     * }
     *
     * グラフHTMLは format_text() を通さずにそのまま出力する。
     * HTMLテキストセルは format_text() でサニタイズして出力する。
     *
     * @param object $config
     * @param moodle_page $moodlepage
     * @return void
     */
    public function print_template_gui($config, moodle_page $moodlepage): void {
        // レイアウトJSONをデコード
        $layout = json_decode($config->gui_layout, false);
        if (empty($layout) || empty($layout->rows)) {
            return;
        }

        // グラフHTMLを事前に生成（##graphs## および ##graph:N## 用）
        $graphmap = [];
        $graphmap['##graphs##'] = $this->print_graphs(true);

        $allgraphs = $this->get_graphs($this->finalreport->table->data);
        foreach ($allgraphs as $n => $g) {
            $graphhtml = '<div class="centerpara">';
            if (str_starts_with(trim($g), '<')) {
                $graphhtml .= $g . '<br />';
            } else {
                $graphhtml .= '<img src="' . $g . '" alt="' . s($this->config->name) . '"><br />';
            }
            $graphhtml .= '</div>';
            $graphmap['##graph:' . ($n + 1) . '##'] = $graphhtml;
        }

        // ##reporttable## 用
        // cr_print_table() は echo するので ob_start() で文字列として取得する
        $tablehtml = '';
        if (!empty($this->finalreport->table->data)) {
            ob_start();
            cr_print_table($this->finalreport->table);
            $tablehtml = ob_get_clean();
        }
        $graphmap['##reporttable##'] = $tablehtml;

        // ##calculationstable## 用
        $calcshtml = '';
        if (!empty($this->finalreport->calcs->data[0])) {
            $calcshtml = html_writer::table($this->finalreport->calcs);
        }
        $graphmap['##calculationstable##'] = $calcshtml;

        // ##reportname## / ##reportsummary##
        $graphmap['##reportname##']    = format_string($this->config->name);
        $graphmap['##reportsummary##'] = format_text($this->config->summary);

        // Chart.js AMD モジュールを登録
        if (!empty($allgraphs)) {
            $haschartjs = false;
            foreach ($allgraphs as $g) {
                if (str_starts_with(trim($g), '<')) {
                    $haschartjs = true;
                    break;
                }
            }
            if ($haschartjs) {
                global $PAGE;
                $PAGE->requires->js_call_amd('block_configurable_reports/chartrenderer', 'initAll');
            }
        }

        if ($this->config->jsordering) {
            $this->add_jsordering($moodlepage);
        }
        $this->print_filters();

        // GUIモード用：グラフのcanvasをカラム幅に追従させる
        // 各グラフプラグインは固定px幅でdivを生成するが、
        // CSSでwidth/heightを上書きしてレスポンシブに動作させる。
        echo '<style>
#printablediv canvas.cr-chartjs-pending,
#printablediv canvas[data-chartjs-config] {
    width: 100% !important;
    height: auto !important;
}
#printablediv [style*="position:relative"] {
    width: 100% !important;
    height: auto !important;
    min-height: 300px;
}
</style>' . "\n";

        echo '<div id="printablediv">' . "\n";

        // 行ごとに出力
        foreach ($layout->rows as $row) {
            $cols = (int)($row->cols ?? 1);
            $cells = $row->cells ?? [];

            echo '<div style="display:flex; gap:16px; margin-bottom:16px;">' . "\n";

            foreach ($cells as $cell) {
                $celltype  = $cell->type  ?? 'html';
                $cellvalue = $cell->value ?? '';

                $cellwidth = 'calc(' . (100 / $cols) . '% - ' . (16 * ($cols - 1) / $cols) . 'px)';
                echo '<div style="flex:0 0 ' . $cellwidth . '; min-width:0;">' . "\n";

                if ($celltype === 'placeholder' && isset($graphmap[$cellvalue])) {
                    // プレースホルダー → format_text()を通さずそのまま出力
                    echo $graphmap[$cellvalue];
                } elseif ($celltype === 'placeholder') {
                    // 未知のプレースホルダーはそのまま表示（デバッグ用）
                    echo htmlspecialchars($cellvalue);
                } else {
                    // HTMLテキスト → format_text()でサニタイズして出力
                    echo format_text($cellvalue, FORMAT_HTML);
                }

                echo '</div>' . "\n";
            }

            echo '</div>' . "\n";
        }

        echo '</div>' . "\n";
    }

    public function print_report_page(moodle_page $moodlepage) {
        global $OUTPUT;

        if ($this->config->displayprintbutton) {
            cr_print_js_function();
        }
        $components = cr_unserialize($this->config->components);

        // テンプレートの有効判定：
        //   classic モード : enabled=1 かつ record が存在する
        //   gui モード     : enabled=1 かつ gui_layout が存在する
        $templateconfig = $components['template']['config'] ?? null;
        $template = false;
        if ($templateconfig && !empty($templateconfig->enabled)) {
            $isgui = !empty($templateconfig->editormode) && $templateconfig->editormode === 'gui';
            if ($isgui && !empty($templateconfig->gui_layout)) {
                $template = $templateconfig;
            } else if (!$isgui && !empty($templateconfig->record)) {
                $template = $templateconfig;
            }
        }

        if ($template) {
            $this->print_template($template, $moodlepage);

            return true;
        }

        // Debug.
        $debug = optional_param('debug', false, PARAM_BOOL);
        if ($debug || !empty($this->config->debug)) {
            echo html_writer::empty_tag('hr');
            echo html_writer::tag('div', $this->sql, ['id' => 'debug', 'style' => 'direction:ltr;text-align:left;']);
            echo html_writer::empty_tag('hr');
        }

        echo '<div class="centerpara">';
        echo format_text($this->config->summary);
        echo '</div>';

        $this->print_filters();
        if ($this->finalreport->table && !empty($this->finalreport->table->data[0])) {

            echo "<div id=\"printablediv\">\n";
            $this->print_graphs();

            if ($this->config->jsordering) {
                $this->add_jsordering($moodlepage);
            }

            $this->totalrecords = count($this->finalreport->table->data);
            if ($this->config->pagination) {
                $page = optional_param('page', 0, PARAM_INT);
                $this->totalrecords = count($this->finalreport->table->data);
                $this->finalreport->table->data =
                    array_slice($this->finalreport->table->data, $page * $this->config->pagination, $this->config->pagination);
            }

            cr_print_table($this->finalreport->table);

            if ($this->config->pagination) {
                $postfiltervars = '';
                $request = array_merge($_POST, $_GET);
                if ($request) {
                    foreach ($request as $key => $val) {
                        if (strpos($key, 'filter_') !== false) {
                            $key = s(clean_param($key, PARAM_CLEANHTML));
                            if (is_array($val)) {
                                foreach ($val as $k => $v) {
                                    $k = s(clean_param($k, PARAM_CLEANHTML));
                                    $v = s(clean_param($v, PARAM_CLEANHTML));
                                    $postfiltervars .= "&amp;{$key}[$k]=" . $v;
                                }
                            } else {
                                $val = s(clean_param($val, PARAM_CLEANHTML));
                                $postfiltervars .= "&amp;$key=" . $val;
                            }
                        }
                    }
                }

                $pagingbar = new paging_bar(
                    $this->totalrecords,
                    $page,
                    $this->config->pagination,
                    "viewreport.php?id=" . s($this->config->id) . "&courseid=" . s($this->config->courseid) . "$postfiltervars&amp;"
                );
                $pagingbar->pagevar = 'page';
                echo $OUTPUT->render($pagingbar);
            }

            // Report statistics.
            $a = new stdClass();
            $a->totalrecords = $this->totalrecords;
            echo html_writer::tag('div', get_string('totalrecords', 'block_configurable_reports', $a), ['id' => 'totalrecords']);

            echo html_writer::tag(
                'div',
                get_string('lastexecutiontime', 'block_configurable_reports', $this->config->lastexecutiontime / 1000),
                ['id' => 'lastexecutiontime']
            );

            if (!empty($this->finalreport->calcs->data[0])) {
                echo '<br /><br /><br /><div class="centerpara"><b>' .
                    get_string('columncalculations', 'block_configurable_reports') . '</b></div><br />';
                echo html_writer::table($this->finalreport->calcs);
            }
            echo "</div>";

            $this->print_export_options();
        } else {
            echo '<div class="centerpara">' . get_string('norecordsfound', 'block_configurable_reports') . '</div>';
        }

        if ($this->config->displayprintbutton) {
            echo '<div class="centerpara"><br />';
            echo $OUTPUT->pix_icon('print', get_string('printreport', 'block_configurable_reports'), 'block_configurable_reports');
            echo "&nbsp;<a href=\"javascript: printDiv('printablediv')\">".get_string('printreport', 'block_configurable_reports')."</a>";
            echo "</div>\n";
        }
    }

    /**
     * utf8_strrev
     *
     * @param string $str
     * @return string
     */
    public function utf8_strrev(string $str): string {
        preg_match_all('/./us', $str, $ar);

        return implode('', array_reverse($ar[0]));
    }

}
