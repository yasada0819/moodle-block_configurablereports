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
 * Class plugin_tiledscatter
 *
 * グループキー列でデータを仕分けし、グループごとに1つの散布図／バブルチャートを
 * タイル状に並べて表示するプラグイン（Chart.js 専用）。
 *
 * r_field が未選択のとき → 全点同サイズの散布図（scatter）
 * r_field が選択されたとき → バブルチャート（bubble）
 * label_field で色分け可能（未選択=全点同色）
 *
 * @package   block_configurable_reports
 */
class plugin_tiledscatter extends plugin_base {

    public function init(): void {
        $this->fullname = "Tiled scatter / bubble";
        $this->form     = true;
        $this->ordering = true;
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    public function summary(object $data): string {
        $usesbubble = !empty($data->r_field) && $data->r_field !== 'none';
        return $usesbubble ? "Tiled bubble chart" : "Tiled scatter plot";
    }

    // =========================================================================
    // Execute
    // =========================================================================

    public function execute($id, $data, $finalreport) {
        global $PAGE;

        if (empty($finalreport)) {
            return '';
        }

        // --- 設定値の取得 ---
        $groupidx  = !empty($data->group_field) ? (int)explode(',', $data->group_field)[0] : 0;
        $xidx      = !empty($data->x_field)     ? (int)explode(',', $data->x_field)[0]     : 0;
        $yidx      = !empty($data->y_field)     ? (int)explode(',', $data->y_field)[0]     : 1;
        $tilewidth  = !empty($data->tilewidth)  ? (int)$data->tilewidth  : 400;
        $tileheight = !empty($data->tileheight) ? (int)$data->tileheight : 400;
        $columns    = !empty($data->tilecolumns) ? $data->tilecolumns    : 'auto';
        $rdefault   = !empty($data->rdefault)   ? (int)$data->rdefault   : 5;

        // ラベル列（色分け用、任意）
        $labelfield = !empty($data->label_field) ? $data->label_field : 'none';
        $uselabel   = ($labelfield !== 'none');
        $labelidx   = $uselabel ? (int)explode(',', $labelfield)[0] : null;

        // バブルサイズ列（任意）
        $rfield  = !empty($data->r_field) ? $data->r_field : 'none';
        $user    = ($rfield !== 'none');
        $ridx    = $user ? (int)explode(',', $rfield)[0] : null;
        $rscale  = !empty($data->rscale) ? $data->rscale : 'auto';

        // タイル横幅スタイル
        if ($columns === 'auto') {
            $tilestyle = 'width:' . $tilewidth . 'px;';
        } else {
            $cols     = (int)$columns;
            $gapwidth = ($cols - 1) * 16;
            $tilestyle = 'width:calc((100% - ' . $gapwidth . 'px) / ' . $cols . ');';
        }

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

        // --- finalreport をグループキーで仕分け ---
        // groups[$groupkey][$label][] = ['x'=>, 'y'=>, 'r'=>]
        $groups     = [];
        $grouporder = [];
        $rvalues    = []; // autoスケール用に全rを収集

        foreach ($finalreport as $r) {
            $groupkey = (string)($r[$groupidx] ?? '');
            $xlabel   = $uselabel ? (string)($r[$labelidx] ?? '') : '__single__';
            $x        = is_numeric($r[$xidx] ?? null) ? (float)$r[$xidx] : 0;
            $y        = is_numeric($r[$yidx] ?? null) ? (float)$r[$yidx] : 0;
            $rraw     = ($user && is_numeric($r[$ridx] ?? null)) ? (float)$r[$ridx] : null;

            if (!in_array($groupkey, $grouporder, true)) {
                $grouporder[] = $groupkey;
            }
            if (!isset($groups[$groupkey][$xlabel])) {
                $groups[$groupkey][$xlabel] = [];
            }
            $groups[$groupkey][$xlabel][] = ['x' => $x, 'y' => $y, 'rraw' => $rraw];

            if ($rraw !== null) {
                $rvalues[] = $rraw;
            }
        }

        if (empty($groups)) {
            return '';
        }

        // autoスケール：rrawの最大値を $rdefault*2 にマッピング
        $rmax     = !empty($rvalues) ? max($rvalues) : 1;
        $rmaxsize = $rdefault * 2;

        // --- タイルを生成 ---
        $tiles = '';
        foreach ($grouporder as $groupkey) {
            $groupdata  = $groups[$groupkey];
            $canvasid   = 'cr_tscatter_' . $id . '_' . substr(md5($groupkey . uniqid('', true)), 0, 8);
            $isbubbble  = $user;

            // datasetsを構築
            $datasets   = [];
            $colorindex = 0;
            foreach ($groupdata as $label => $points) {
                $color       = $palette[$colorindex % count($palette)];
                $chartpoints = [];

                foreach ($points as $pt) {
                    if ($isbubbble) {
                        // バブルサイズを計算
                        if ($pt['rraw'] !== null) {
                            $rsize = ($rscale === 'auto' && $rmax > 0)
                                ? round($pt['rraw'] / $rmax * $rmaxsize, 2)
                                : (float)$pt['rraw'];
                        } else {
                            $rsize = $rdefault;
                        }
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
                if (!$isbubbble) {
                    $dataset['pointRadius'] = $rdefault;
                }

                $datasets[] = $dataset;
                $colorindex++;
            }

            $charttype = $isbubbble ? 'bubble' : 'scatter';

            $chartconfig = json_encode([
                'type' => $charttype,
                'data' => ['datasets' => $datasets],
                'options' => [
                    'responsive'          => true,
                    'maintainAspectRatio' => false,
                    'plugins' => [
                        'legend' => ['display' => $uselabel, 'position' => 'top'],
                        'title'  => ['display' => true, 'text' => $groupkey],
                    ],
                    'scales' => [
                        'x' => ['beginAtZero' => true],
                        'y' => ['beginAtZero' => true],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);

            $tiles .= '<div style="' . $tilestyle . ' box-sizing:border-box;">';
            $tiles .= '<div style="position:relative; height:' . $tileheight . 'px;">';
            $tiles .= '<canvas id="' . $canvasid . '"'
                    . ' class="cr-chartjs-pending"'
                    . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
                    . '></canvas>';
            $tiles .= '</div></div>';
        }

        $html = '<div style="display:flex; flex-wrap:wrap; gap:16px; width:100%;">'
              . $tiles
              . '</div>';

        $PAGE->requires->js_call_amd('block_configurable_reports/chartrenderer', 'initAll');

        return $html;
    }

    public function get_series(): array {
        return [];
    }

}
