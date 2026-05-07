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
 * Class plugin_combo
 *
 * 棒グラフと折れ線グラフを重ねて表示するプラグイン。
 * Chart.js 専用（pChart非対応）。
 *
 * データ構造：
 *   ラベル列（X軸）+ 棒グラフ系列（複数可）+ 折れ線系列（複数可）
 *
 * オプション：
 *   - 棒グラフのグループ分け（grouped / stacked）
 *   - 折れ線のスムーズ・エリア塗りつぶし
 *   - Y軸の分離（棒=左軸、折れ線=右軸）
 *
 * @package   block_configurable_reports
 */
class plugin_combo extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = "Combo chart (bar + line)";
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
        return "Combo chart summary";
    }

    /**
     * Execute
     *
     * combo は Chart.js 専用。pChart 設定でも Chart.js で描画する。
     * report.class.php の print_graphs() が返り値の先頭文字で判定するため、
     * 常に HTML 文字列を返す。
     *
     * @param int    $id
     * @param object $data
     * @param array  $finalreport
     * @return string HTML fragment
     */
    public function execute($id, $data, $finalreport) {
        if (empty($finalreport)) {
            return '';
        }

        // --- 設定値の取得 ---
        $width      = !empty($data->width)      ? (int)$data->width    : 900;
        $height     = !empty($data->height)     ? (int)$data->height   : 500;
        $bargrouping = !empty($data->bargrouping) ? $data->bargrouping  : 'grouped';
        $smooth     = !empty($data->smooth);
        $filled     = !empty($data->filled);
        $dualaxis   = !empty($data->dualaxis);

        // ラベル列（インデックス）
        [$labelidx, $labelname] = explode(',', $data->label_field);
        $labelidx = (int)$labelidx;

        // 棒グラフ系列
        $barfields = [];
        if (!empty($data->bar_fields)) {
            $barfields = is_array($data->bar_fields) ? $data->bar_fields : [$data->bar_fields];
        }

        // 折れ線系列
        $linefields = [];
        if (!empty($data->line_fields)) {
            $linefields = is_array($data->line_fields) ? $data->line_fields : [$data->line_fields];
        }

        if (empty($barfields) && empty($linefields)) {
            return '';
        }

        // --- データを構築 ---
        $labels   = [];
        $bardata  = []; // [ 列名 => [値, ...] ]
        $linedata = []; // [ 列名 => [値, ...] ]

        // 棒グラフ列のインデックスと名前を解析
        $barcols = [];
        foreach ($barfields as $f) {
            [$idx, $name] = explode(',', $f);
            $barcols[] = ['idx' => (int)$idx, 'name' => $name];
            $bardata[$name] = [];
        }

        // 折れ線列のインデックスと名前を解析
        $linecols = [];
        foreach ($linefields as $f) {
            [$idx, $name] = explode(',', $f);
            $linecols[] = ['idx' => (int)$idx, 'name' => $name];
            $linedata[$name] = [];
        }

        foreach ($finalreport as $r) {
            $labels[] = $r[$labelidx];
            foreach ($barcols as $col) {
                $val = isset($r[$col['idx']]) && is_numeric($r[$col['idx']]) ? (float)$r[$col['idx']] : 0;
                $bardata[$col['name']][] = $val;
            }
            foreach ($linecols as $col) {
                $val = isset($r[$col['idx']]) && is_numeric($r[$col['idx']]) ? (float)$r[$col['idx']] : 0;
                $linedata[$col['name']][] = $val;
            }
        }

        // --- カラーパレット ---
        $barpalette = [
            ['bg' => 'rgba(54,  162, 235, 0.8)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.8)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.8)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.8)', 'border' => 'rgba(153, 102, 255, 1)'],
        ];
        $linepalette = [
            ['bg' => 'rgba(255, 99,  132, 0.4)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.4)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.4)', 'border' => 'rgba(201, 203, 207, 1)'],
            ['bg' => 'rgba(255, 99,  255, 0.4)', 'border' => 'rgba(255, 99,  255, 1)'],
        ];

        // --- datasets 構築 ---
        $datasets   = [];
        $stacked    = ($bargrouping === 'stacked');

        // 棒グラフ datasets
        $colorindex = 0;
        foreach ($bardata as $name => $values) {
            $color      = $barpalette[$colorindex % count($barpalette)];
            $dataset    = [
                'type'            => 'bar',
                'label'           => $name,
                'data'            => $values,
                'backgroundColor' => $color['bg'],
                'borderColor'     => $color['border'],
                'borderWidth'     => 1,
                'yAxisID'         => 'y',
            ];
            if ($stacked) {
                $dataset['stack'] = 'bar';
            }
            $datasets[] = $dataset;
            $colorindex++;
        }

        // 折れ線 datasets
        $colorindex = 0;
        foreach ($linedata as $name => $values) {
            $color      = $linepalette[$colorindex % count($linepalette)];
            $datasets[] = [
                'type'            => 'line',
                'label'           => $name,
                'data'            => $values,
                'borderColor'     => $color['border'],
                'backgroundColor' => $filled ? $color['bg'] : 'transparent',
                'borderWidth'     => 2,
                'tension'         => $smooth ? 0.4 : 0.0,
                'fill'            => $filled,
                'yAxisID'         => $dualaxis ? 'y2' : 'y',
                'pointRadius'     => 4,
            ];
            $colorindex++;
        }

        // --- スケール設定 ---
        $yaxis = ['beginAtZero' => true, 'position' => 'left'];
        if ($stacked) {
            $yaxis['stacked'] = true;
        }

        $scales = ['y' => $yaxis];

        if ($dualaxis && !empty($linedata)) {
            $scales['y2'] = [
                'beginAtZero' => true,
                'position'    => 'right',
                'grid'        => ['drawOnChartArea' => false], // 右軸のグリッドは非表示
            ];
        }

        $chartconfig = json_encode([
            'type' => 'bar', // 外側はbar（個別datasetでtypeを上書き）
            'data' => [
                'labels'   => $labels,
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'top'],
                ],
                'scales' => $scales,
            ],
        ], JSON_UNESCAPED_UNICODE);

        $canvasid = 'cr_combo_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

        $html  = '<div style="position:relative; width:' . $width . 'px; height:' . $height . 'px;">';
        $html .= '<canvas id="' . $canvasid . '"'
               . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
               . ' class="cr-chartjs-pending"></canvas>';
        $html .= '</div>';

        return $html;
    }

    /**
     * get_series（互換性のためのスタブ）
     *
     * @return array
     */
    public function get_series(): array {
        return [];
    }

}
