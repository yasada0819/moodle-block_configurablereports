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
 * Class plugin_tiledchart
 *
 * SQLで「グループキー・X軸・Y軸」の3列を出力し、グループキーごとに
 * Chart.js グラフを生成してタイル状に並べて表示するプラグイン。
 *
 * 対応グラフタイプ: bar / line / area / pie / doughnut / radar
 * pChart には非対応（Chart.js 専用プラグイン）。
 *
 * @package   block_configurable_reports
 */
class plugin_tiledchart extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = "Tiled chart";
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
        $type = !empty($data->charttype) ? $data->charttype : 'line';
        return "Tiled chart ({$type})";
    }

    /**
     * Execute
     *
     * tiledchart は Chart.js 専用。pChart 設定でも Chart.js で描画する。
     * report.class.php の print_graphs() が返り値の先頭文字で判定するため、
     * 常に HTML 文字列を返す。
     *
     * @param int    $id
     * @param object $data
     * @param array  $finalreport
     * @return string HTML fragment
     */
    public function execute($id, $data, $finalreport) {
        global $PAGE;

        if (empty($finalreport)) {
            return '';
        }

        // --- 設定値の取得 ---
        $charttype   = !empty($data->charttype)   ? $data->charttype   : 'line';
        $groupidx    = !empty($data->group_field)  ? (int)explode(',', $data->group_field)[0]  : 0;
        $xidx        = !empty($data->x_field)      ? (int)explode(',', $data->x_field)[0]      : 1;
        $yidx        = !empty($data->y_field)      ? (int)explode(',', $data->y_field)[0]      : 2;
        $grouplabel  = !empty($data->group_field)  ? explode(',', $data->group_field)[1]       : 'group';
        $tilewidth   = !empty($data->tilewidth)    ? (int)$data->tilewidth   : 400;
        $tileheight  = !empty($data->tileheight)   ? (int)$data->tileheight  : 280;
        $columns     = !empty($data->tilecolumns)  ? $data->tilecolumns      : 'auto';

        // --- finalreport をグループキーで仕分け ---
        $groups = [];
        foreach ($finalreport as $r) {
            $groupkey = $r[$groupidx];
            if (!isset($groups[$groupkey])) {
                $groups[$groupkey] = ['labels' => [], 'values' => []];
            }
            $groups[$groupkey]['labels'][] = $r[$xidx];
            $groups[$groupkey]['values'][] = is_numeric($r[$yidx]) ? (float)$r[$yidx] : 0;
        }

        if (empty($groups)) {
            return '';
        }

        // --- タイルの横幅を決定 ---
        if ($columns === 'auto') {
            $tilestyle = 'width:' . $tilewidth . 'px;';
        } else {
            $cols     = (int)$columns;
            $gapwidth = ($cols - 1) * 16;
            $tilestyle = 'width:calc((100% - ' . $gapwidth . 'px) / ' . $cols . ');';
        }

        // --- Chart.js カラーパレット ---
        $color       = 'rgba(54, 162, 235, 0.8)';
        $bordercolor = 'rgba(54, 162, 235, 1)';

        // pie/doughnut/radar 用のカラーパレット（複数スライス・軸に対応）
        $multipalette = [
            'rgba(54,  162, 235, 0.8)',
            'rgba(255, 99,  132, 0.8)',
            'rgba(75,  192, 192, 0.8)',
            'rgba(255, 205, 86,  0.8)',
            'rgba(153, 102, 255, 0.8)',
            'rgba(255, 159, 64,  0.8)',
            'rgba(201, 203, 207, 0.8)',
        ];

        // --- グループごとにcanvasを生成 ---
        $tiles = '';
        foreach ($groups as $groupname => $groupdata) {
            $canvasid = 'cr_tiled_' . $id . '_' . substr(md5($groupname . uniqid('', true)), 0, 8);

            // グラフタイプに応じてconfigを切り替える
            if (in_array($charttype, ['pie', 'doughnut'])) {
                // pie / doughnut：labelsが各スライス名、valuesが各スライスの大きさ
                $dataset = [
                    'data'            => $groupdata['values'],
                    'backgroundColor' => array_slice($multipalette, 0, count($groupdata['values'])),
                    'borderWidth'     => 1,
                ];
                $chartconfig = json_encode([
                    'type' => $charttype,
                    'data' => [
                        'labels'   => $groupdata['labels'],
                        'datasets' => [$dataset],
                    ],
                    'options' => [
                        'responsive'          => true,
                        'maintainAspectRatio' => false,
                        'plugins' => [
                            'legend' => ['display' => true, 'position' => 'bottom'],
                            'title'  => ['display' => true, 'text' => (string)$groupname],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE);

            } else if ($charttype === 'radar') {
                // radar：labelsが各軸の名前、valuesが各軸のスコア
                $dataset = [
                    'label'           => (string)$groupname,
                    'data'            => $groupdata['values'],
                    'backgroundColor' => 'rgba(54, 162, 235, 0.3)',
                    'borderColor'     => $bordercolor,
                    'borderWidth'     => 2,
                    'pointRadius'     => 3,
                ];
                $chartconfig = json_encode([
                    'type' => 'radar',
                    'data' => [
                        'labels'   => $groupdata['labels'],
                        'datasets' => [$dataset],
                    ],
                    'options' => [
                        'responsive'          => true,
                        'maintainAspectRatio' => false,
                        'plugins' => [
                            'legend' => ['display' => false],
                            'title'  => ['display' => true, 'text' => (string)$groupname],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE);

            } else {
                // bar / line / area
                $actualtype = ($charttype === 'area') ? 'line' : $charttype;
                $dataset = [
                    'label'           => (string)$groupname,
                    'data'            => $groupdata['values'],
                    'backgroundColor' => $color,
                    'borderColor'     => $bordercolor,
                    'borderWidth'     => 1,
                    'fill'            => ($charttype === 'area'),
                    'tension'         => 0.0,
                ];
                $chartconfig = json_encode([
                    'type' => $actualtype,
                    'data' => [
                        'labels'   => $groupdata['labels'],
                        'datasets' => [$dataset],
                    ],
                    'options' => [
                        'responsive'          => true,
                        'maintainAspectRatio' => false,
                        'plugins' => [
                            'legend' => ['display' => false],
                            'title'  => ['display' => true, 'text' => (string)$groupname],
                        ],
                        'scales' => [
                            'y' => ['beginAtZero' => true],
                        ],
                    ],
                ], JSON_UNESCAPED_UNICODE);
            }

            $tiles .= '<div style="' . $tilestyle . ' box-sizing:border-box;">';
            $tiles .= '<div style="position:relative; height:' . $tileheight . 'px;">';
            $tiles .= '<canvas id="' . $canvasid . '"'
                    . ' class="cr-chartjs-pending"'
                    . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
                    . '></canvas>';
            $tiles .= '</div>';
            $tiles .= '</div>';
        }

        // --- タイルコンテナ ---
        $html  = '<div style="display:flex; flex-wrap:wrap; gap:16px; width:100%;">';
        $html .= $tiles;
        $html .= '</div>';

        // AMD モジュールを登録（print_graphs()側でも登録されるが、
        // tiledchart単独で使われる場合に備えて念のため呼んでおく）
        $PAGE->requires->js_call_amd('block_configurable_reports/chartrenderer', 'initAll');

        return $html;
    }

    /**
     * get_series
     *
     * tiledchart は graph.php 経由の pChart 描画を使わないため、
     * このメソッドは使用されない。互換性のためのスタブとして残す。
     *
     * @return array
     */
    public function get_series(): array {
        return [];
    }

}
