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
 * Class plugin_scatter
 *
 * SQLの出力結果を散布図として描画するプラグイン。
 * Chart.js 専用（pChart非対応）。
 *
 * 2列モード（ラベルなし）：
 *   x列, y列 → 全点同色で描画
 *
 * 3列モード（ラベルあり）：
 *   label列, x列, y列 → label列でグループ化して色分け描画
 *
 * @package   block_configurable_reports
 */
class plugin_scatter extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = "Scatter plot";
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
        return "Scatter plot summary";
    }

    /**
     * Execute
     *
     * scatter は Chart.js 専用。pChart 設定でも Chart.js で描画する。
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
        $xidx     = !empty($data->x_field)     ? (int)explode(',', $data->x_field)[0]     : 0;
        $yidx     = !empty($data->y_field)     ? (int)explode(',', $data->y_field)[0]     : 1;
        $width    = !empty($data->width)       ? (int)$data->width                        : 700;
        $height   = !empty($data->height)      ? (int)$data->height                       : 500;
        $rdefault = !empty($data->rdefault)    ? (int)$data->rdefault                     : 5;

        // ラベル列：'none' または列インデックス,列名 の形式
        $labelfield = !empty($data->label_field) ? $data->label_field : 'none';
        $uselabel   = ($labelfield !== 'none');
        $labelidx   = $uselabel ? (int)explode(',', $labelfield)[0] : null;

        // バブルサイズ列（任意）
        $rfield  = !empty($data->r_field) ? $data->r_field : 'none';
        $user    = ($rfield !== 'none');
        $ridx    = $user ? (int)explode(',', $rfield)[0] : null;
        $rscale  = !empty($data->rscale) ? $data->rscale : 'auto';

        // カラーパレット
        $palette = [
            ['bg' => 'rgba(54,  162, 235, 0.7)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(255, 99,  132, 0.7)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.7)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.7)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.7)', 'border' => 'rgba(153, 102, 255, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.7)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.7)', 'border' => 'rgba(201, 203, 207, 1)'],
        ];

        // autoスケール用にrの最大値を収集
        $rvalues  = [];
        if ($user) {
            foreach ($finalreport as $r) {
                $rraw = $r[$ridx] ?? null;
                if (is_numeric($rraw)) {
                    $rvalues[] = (float)$rraw;
                }
            }
        }
        $rmax     = !empty($rvalues) ? max($rvalues) : 1;
        $rmaxsize = $rdefault * 2;

        // --- データを構築 ---
        // ラベルでグループ化（uselabel=falseのときは '__single__' で1グループ）
        $groups = [];
        foreach ($finalreport as $r) {
            $label = $uselabel ? (string)($r[$labelidx] ?? '') : '__single__';
            $x     = is_numeric($r[$xidx] ?? null) ? (float)$r[$xidx] : 0;
            $y     = is_numeric($r[$yidx] ?? null) ? (float)$r[$yidx] : 0;
            $rraw  = ($user && is_numeric($r[$ridx] ?? null)) ? (float)$r[$ridx] : null;

            if (!isset($groups[$label])) {
                $groups[$label] = [];
            }
            $groups[$label][] = ['x' => $x, 'y' => $y, 'rraw' => $rraw];
        }

        $datasets   = [];
        $colorindex = 0;
        foreach ($groups as $label => $points) {
            $color       = $palette[$colorindex % count($palette)];
            $chartpoints = [];

            foreach ($points as $pt) {
                if ($user) {
                    $rsize = ($pt['rraw'] !== null)
                        ? (($rscale === 'auto' && $rmax > 0)
                            ? round($pt['rraw'] / $rmax * $rmaxsize, 2)
                            : (float)$pt['rraw'])
                        : $rdefault;
                    $chartpoints[] = ['x' => $pt['x'], 'y' => $pt['y'], 'r' => $rsize];
                } else {
                    $chartpoints[] = ['x' => $pt['x'], 'y' => $pt['y']];
                }
            }

            $displaylabel = ($label === '__single__') ? '' : $label;
            $dataset = [
                'label'           => $displaylabel,
                'data'            => $chartpoints,
                'backgroundColor' => $color['bg'],
                'borderColor'     => $color['border'],
                'borderWidth'     => 1,
            ];
            if (!$user) {
                $dataset['pointRadius'] = $rdefault;
            }
            $datasets[]  = $dataset;
            $colorindex++;
        }

        if (empty($datasets)) {
            return '';
        }

        $chartconfig = json_encode([
            'type' => $user ? 'bubble' : 'scatter',
            'data' => [
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => [
                        // 2列モード（ラベルなし）のときは凡例を非表示
                        'display' => $uselabel,
                    ],
                ],
                'scales' => [
                    'x' => ['beginAtZero' => true],
                    'y' => ['beginAtZero' => true],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $canvasid = 'cr_scatter_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

        $html  = '<div style="position:relative; width:' . $width . 'px; height:' . $height . 'px;">';
        $html .= '<canvas id="' . $canvasid . '"'
               . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
               . ' class="cr-chartjs-pending"></canvas>';
        $html .= '</div>';

        return $html;
    }

    /**
     * get_series
     *
     * scatter は graph.php 経由の pChart 描画を使わないため、
     * このメソッドは使用されない。互換性のためのスタブとして残す。
     *
     * @return array
     */
    public function get_series(): array {
        return [];
    }

}
