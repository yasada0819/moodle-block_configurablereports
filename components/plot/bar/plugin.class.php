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
require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');

/**
 * Class plugin_bar
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class plugin_bar extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = "Bar chart";
        $this->form = true;
        $this->ordering = true;
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    /**
     * Summary
     *
     * @param object $data
     * @return string
     */
    public function summary(object $data): string {
        return "Bar chart summary";
    }

    /**
     * Build the series array from finalreport data.
     * pChart・Chart.js 両方から共通して使う。
     *
     * @param object $data        Plugin configuration (formdata)
     * @param array  $finalreport
     * @return array  ['LabelColumnName' => [...labels...], 'Series1' => [...values...], ...]
     */
    protected function build_series(object $data, array $finalreport): array {
        $series = [];
        if (!$finalreport) {
            return $series;
        }

        [$labelidx, $labelname] = explode(",", $data->label_field);
        $series[$labelname] = [];

        if (!is_array($data->value_fields)) {
            $data->value_fields = [$data->value_fields];
        }

        foreach ($finalreport as $r) {
            $series[$labelname][] = $r[$labelidx];
            foreach ($data->value_fields as $valuefields) {
                [$idx, $name] = explode(",", $valuefields);
                $value = $r[$idx];

                if ($idx == $labelidx) {
                    debugging(
                        "moodle:configurable_reports:bar:  refusing to chart label field",
                        DEBUG_DEVELOPER
                    );
                    continue;
                }

                if (!is_numeric($value)) {
                    debugging(
                        "moodle:configurable_reports:bar:  substituting 0 for non-numeric value '$value'",
                        DEBUG_DEVELOPER
                    );
                    $value = 0;
                }

                if (!array_key_exists($name, $series)) {
                    $series[$name] = [];
                }
                $series[$name][] = $value;
            }
        }

        return $series;
    }

    /**
     * Execute
     *
     * graphlibrary 設定に応じて返す値が異なる：
     *   - pChart モード  : URL文字列 → report.class.php が <img src="..."> として出力
     *   - Chart.js モード: HTML文字列 → report.class.php がそのまま出力
     *
     * @param int    $id
     * @param object $data
     * @param array  $finalreport
     * @return string
     */
    public function execute($id, $data, $finalreport) {
        global $CFG;

        $graphlibrary = get_config('block_configurable_reports', 'graphlibrary');

        if ($graphlibrary === 'chartjs') {
            return $this->execute_chartjs($id, $data, $finalreport);
        }

        // --- pChart モード（既存コード・変更なし） ---
        $series = [];
        if ($finalreport) {
            [$labelidx, $labelname] = explode(",", $data->label_field);
            $series[$labelname] = [];
            if (!is_array($data->value_fields)) {
                $data->value_fields = [$data->value_fields];
            }
            foreach ($finalreport as $r) {
                $series[$labelname][] = $r[$labelidx];
                foreach ($data->value_fields as $valuefields) {
                    [$idx, $name] = explode(",", $valuefields);
                    $value = $r[$idx];

                    if ($idx == $labelidx) {
                        debugging(
                            "moodle:configurable_reports:bar:  refusing to chart label field",
                            DEBUG_DEVELOPER
                        );
                        continue;
                    }

                    if (!is_numeric($value)) {
                        debugging(
                            "moodle:configurable_reports:bar:  substituting 0 for non-numeric value '$value'",
                            DEBUG_DEVELOPER
                        );
                        $value = 0;
                    }

                    if (!array_key_exists($name, $series)) {
                        $series[$name] = [];
                    }
                    $series[$name][] = $value;
                }
            }
        }

        $graphdata = urlencode(json_encode($series));

        return $CFG->wwwroot . '/blocks/configurable_reports/components/plot/bar/graph.php?reportid=' . $this->report->id . '&id=' .
            $id . '&graphdata=' . $graphdata . '&courseid=' . $this->report->courseid;
    }

    /**
     * Execute (Chart.js モード)
     *
     * <canvas> タグに data-chartjs-config 属性でグラフ設定を持たせた
     * HTML文字列を返す。実際の描画は chartrenderer.js の initAll() が行う。
     * require() をインラインで呼ばないことで RequireJS のスコープ問題を回避する。
     *
     * @param int    $id
     * @param object $data
     * @param array  $finalreport
     * @return string HTML fragment
     */
    protected function execute_chartjs($id, $data, $finalreport): string {
        $series = $this->build_series($data, $finalreport);

        if (empty($series)) {
            return '';
        }

        // 先頭キーがラベル列。array_shift で取り出し、残りがデータ系列。
        $labels = array_shift($series);

        $width  = property_exists($data, 'width')  ? (int)$data->width  : 900;
        $height = property_exists($data, 'height') ? (int)$data->height : 500;

        // Chart.js 用カラーパレット
        $palette = [
            'rgba(54,  162, 235, 0.8)',
            'rgba(255, 99,  132, 0.8)',
            'rgba(75,  192, 192, 0.8)',
            'rgba(255, 205, 86,  0.8)',
            'rgba(153, 102, 255, 0.8)',
            'rgba(255, 159, 64,  0.8)',
            'rgba(201, 203, 207, 0.8)',
        ];

        // datasets 配列を構築
        $datasets = [];
        $colorindex = 0;
        foreach ($series as $name => $values) {
            $color = $palette[$colorindex % count($palette)];
            $datasets[] = [
                'label'           => $name,
                'data'            => array_values($values),
                'backgroundColor' => $color,
                'borderColor'     => str_replace('0.8', '1', $color),
                'borderWidth'     => 1,
            ];
            $colorindex++;
        }

        // 系列の積み上げ順を逆にする
        if (!empty($data->reversedatasets)) {
            $datasets = array_reverse($datasets);
        }

        // 向き: vertical（縦）/ horizontal（横）
        $horizontal = !empty($data->bardirection) && $data->bardirection === 'horizontal';

        // グループ分け: grouped（横並べ）/ stacked（積み上げ）
        $stacked = !empty($data->bargrouping) && $data->bargrouping === 'stacked';

        // ヒストグラムモード：棒の隙間をなくす
        $histogram = !empty($data->histogram);
        if ($histogram) {
            foreach ($datasets as &$ds) {
                $ds['barPercentage']      = 1.0;
                $ds['categoryPercentage'] = 1.0;
            }
            unset($ds);
        }

        // 横向きのとき indexAxis: 'y' を指定する。
        // 積み上げのとき x・y 両軸に stacked: true を指定する。
        $axisoptions = ['beginAtZero' => true];
        if ($stacked) {
            $axisoptions['stacked'] = true;
        }

        $chartconfig = json_encode([
            'type' => 'bar',
            'data' => [
                'labels'   => array_values($labels),
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'indexAxis'           => $horizontal ? 'y' : 'x',
                'plugins' => [
                    'legend' => ['position' => 'top'],
                ],
                'scales' => [
                    'x' => $axisoptions,
                    'y' => $axisoptions,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        // ユニークな canvas ID（同一ページに複数グラフがあっても衝突しない）
        $canvasid = 'cr_bar_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

        // config を data 属性に持たせる。class="cr-chartjs-pending" で未初期化を示す。
        // require() をここで呼ばず、report.class.php の print_graphs() が
        // $PAGE->requires->js_call_amd() でまとめて初期化する。
        $html  = '<div style="position:relative; width:' . $width . 'px; height:' . $height . 'px;">';
        $html .= '<canvas id="' . $canvasid . '"'
               . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
               . ' class="cr-chartjs-pending"></canvas>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get series (pChart の graph.php から呼ばれる・変更なし)
     *
     * @return array
     */
    public function get_series(): array {
        $graphdataraw = required_param('graphdata', PARAM_RAW);
        $graphdata = json_decode(urldecode($graphdataraw), false, 512, JSON_THROW_ON_ERROR);

        return (array) $graphdata;
    }

}
