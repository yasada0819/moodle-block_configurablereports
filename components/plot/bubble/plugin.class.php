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
 * Class plugin_bubble
 *
 * SQLの出力結果をバブルチャートとして描画するプラグイン。
 * Chart.js 専用（pChart非対応）。
 *
 * データ構造：
 *   label列（任意）, x列, y列, r列（バブルサイズ）
 *
 * r値のスケーリング：
 *   auto   : データ内の最大値を maxbubblesize px に正規化
 *   manual : r値をそのままピクセルとして使用（スケーリングなし）
 *
 * @package   block_configurable_reports
 */
class plugin_bubble extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = "Bubble chart";
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
        return "Bubble chart summary";
    }

    /**
     * Execute
     *
     * bubble は Chart.js 専用。pChart 設定でも Chart.js で描画する。
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
        $xidx   = !empty($data->x_field) ? (int)explode(',', $data->x_field)[0] : 0;
        $yidx   = !empty($data->y_field) ? (int)explode(',', $data->y_field)[0] : 1;
        $ridx   = !empty($data->r_field) ? (int)explode(',', $data->r_field)[0] : 2;
        $width  = !empty($data->width)   ? (int)$data->width  : 700;
        $height = !empty($data->height)  ? (int)$data->height : 500;

        // ラベル列：'none' または 列インデックス,列名 の形式
        $labelfield = !empty($data->label_field) ? $data->label_field : 'none';
        $uselabel   = ($labelfield !== 'none');
        $labelidx   = $uselabel ? (int)explode(',', $labelfield)[0] : null;

        // スケーリング設定
        $rscaling    = !empty($data->rscaling) ? $data->rscaling : 'auto';
        $maxbubble   = !empty($data->maxbubblesize) ? (float)$data->maxbubblesize : 40.0;

        // カラーパレット
        $palette = [
            ['bg' => 'rgba(54,  162, 235, 0.6)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(255, 99,  132, 0.6)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.6)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.6)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.6)', 'border' => 'rgba(153, 102, 255, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.6)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.6)', 'border' => 'rgba(201, 203, 207, 1)'],
        ];

        // --- r値を収集してスケーリング係数を計算 ---
        $rvalues = [];
        foreach ($finalreport as $r) {
            $rvalues[] = is_numeric($r[$ridx]) ? (float)$r[$ridx] : 0;
        }
        $maxr = !empty($rvalues) ? max($rvalues) : 1;
        if ($maxr == 0) {
            $maxr = 1;
        }

        // --- データを構築 ---
        if ($uselabel) {
            // ラベルありモード：ラベルでグループ化して色分け
            $groups = [];
            foreach ($finalreport as $row) {
                $label = $row[$labelidx];
                $x     = is_numeric($row[$xidx]) ? (float)$row[$xidx] : 0;
                $y     = is_numeric($row[$yidx]) ? (float)$row[$yidx] : 0;
                $rraw  = is_numeric($row[$ridx])  ? (float)$row[$ridx]  : 0;
                $r     = $this->scale_r($rraw, $maxr, $maxbubble, $rscaling);

                if (!isset($groups[$label])) {
                    $groups[$label] = [];
                }
                $groups[$label][] = ['x' => $x, 'y' => $y, 'r' => $r];
            }

            $datasets   = [];
            $colorindex = 0;
            foreach ($groups as $label => $points) {
                $color      = $palette[$colorindex % count($palette)];
                $datasets[] = [
                    'label'           => (string)$label,
                    'data'            => $points,
                    'backgroundColor' => $color['bg'],
                    'borderColor'     => $color['border'],
                    'borderWidth'     => 1,
                ];
                $colorindex++;
            }
        } else {
            // ラベルなしモード：全点同色
            $points = [];
            foreach ($finalreport as $row) {
                $x    = is_numeric($row[$xidx]) ? (float)$row[$xidx] : 0;
                $y    = is_numeric($row[$yidx]) ? (float)$row[$yidx] : 0;
                $rraw = is_numeric($row[$ridx])  ? (float)$row[$ridx]  : 0;
                $r    = $this->scale_r($rraw, $maxr, $maxbubble, $rscaling);
                $points[] = ['x' => $x, 'y' => $y, 'r' => $r];
            }

            $datasets = [[
                'label'           => '',
                'data'            => $points,
                'backgroundColor' => $palette[0]['bg'],
                'borderColor'     => $palette[0]['border'],
                'borderWidth'     => 1,
            ]];
        }

        if (empty($datasets)) {
            return '';
        }

        $chartconfig = json_encode([
            'type' => 'bubble',
            'data' => [
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => [
                        'display' => $uselabel,
                    ],
                ],
                'scales' => [
                    'x' => ['beginAtZero' => true],
                    'y' => ['beginAtZero' => true],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $canvasid = 'cr_bubble_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

        $html  = '<div style="position:relative; width:' . $width . 'px; height:' . $height . 'px;">';
        $html .= '<canvas id="' . $canvasid . '"'
               . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
               . ' class="cr-chartjs-pending"></canvas>';
        $html .= '</div>';

        return $html;
    }

    /**
     * r値をスケーリングする。
     *
     * auto   : データ内最大値を maxbubble px に正規化
     *          r_scaled = (r / maxr) * maxbubble
     *          最小値は 3px（視認性確保）
     * manual : r値をそのままピクセルとして使用
     *
     * @param float  $rraw      元のr値
     * @param float  $maxr      データ内の最大r値
     * @param float  $maxbubble 最大バブルサイズ（px）
     * @param string $rscaling  'auto' or 'manual'
     * @return float スケーリング後のr値（px）
     */
    protected function scale_r(float $rraw, float $maxr, float $maxbubble, string $rscaling): float {
        if ($rscaling === 'manual') {
            return max(1.0, $rraw);
        }
        // auto: 最小3px を保証
        $scaled = ($maxr > 0) ? ($rraw / $maxr) * $maxbubble : $maxbubble;
        return max(3.0, round($scaled, 1));
    }

    /**
     * get_series
     *
     * bubble は graph.php 経由の pChart 描画を使わないため、
     * このメソッドは使用されない。互換性のためのスタブとして残す。
     *
     * @return array
     */
    public function get_series(): array {
        return [];
    }

}
