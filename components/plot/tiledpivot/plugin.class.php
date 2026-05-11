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
 * Class plugin_tiledpivot
 *
 * ロング形式（縦持ち）のデータを tile_field でタイル分割しつつ、
 * 各タイル内では series_field の値で色分けした1枚のグラフを生成する
 * プラグイン（Chart.js 専用）。
 *
 * 入力データ例：
 *   年度 | 日付 | 氏名 | 点数
 *   2024 | 1月  |  A  | 80
 *   2024 | 2月  |  A  | 85
 *   2025 | 1月  |  A  | 78
 *   2025 | 2月  |  B  | 90
 *
 * 設定：tile_field=年度, x_field=日付, series_field=氏名, value_field=点数
 *
 * 出力：「2024年度」タイルと「2025年度」タイルが並び、
 *       各タイル内でAとBを色分けした折れ線/棒グラフ
 *
 * 三者の関係：
 *   tiledchart  → ロング形式 → group_field でタイル / 列名を直接系列指定
 *   pivotchart  → ロング形式 → series_field の値で色分け / 1グラフ
 *   tiledpivot  → ロング形式 → tile_field でタイル / series_field の値で色分け
 *
 * @package   block_configurable_reports
 */
class plugin_tiledpivot extends plugin_base {

    public function init(): void {
        $this->fullname  = 'Tiled pivot chart';
        $this->form      = true;
        $this->ordering  = true;
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    public function summary(object $data): string {
        $type = !empty($data->charttype) ? $data->charttype : 'bar';
        return "Tiled pivot chart ({$type})";
    }

    // =========================================================================
    // 集計ロジック（tiledchart / pivotchart と共通パターン）
    // =========================================================================

    protected function aggregate(array $values, string $method) {
        $n = count($values);
        if ($n === 0) {
            return 0;
        }
        switch ($method) {
            case 'count':  return $n;
            case 'sum':    return array_sum($values);
            case 'avg':    return array_sum($values) / $n;
            case 'median': return $this->percentile($values, 50);
            case 'q1':     return $this->percentile($values, 25);
            case 'q3':     return $this->percentile($values, 75);
            case 'min':    return min($values);
            case 'max':    return max($values);
            default:       return $values[0];
        }
    }

    protected function percentile(array $values, float $pct): float {
        sort($values);
        $n   = count($values);
        $idx = ($pct / 100) * ($n - 1);
        $lo  = (int)floor($idx);
        $hi  = (int)ceil($idx);
        if ($lo === $hi) {
            return $values[$lo];
        }
        return $values[$lo] + ($idx - $lo) * ($values[$hi] - $values[$lo]);
    }

    // =========================================================================
    // データ構築
    // =========================================================================

    /**
     * finalreport を tile_field で仕分けし、各タイル内で
     * x_field × series_field のピボット集計を行った結果を返す。
     *
     * 返り値：
     * [
     *   '2024' => [
     *     '__labels__'  => ['1月', '2月'],
     *     'A'           => [80, 85],
     *     'B'           => [0,  75],   // 欠損は 0 埋め
     *   ],
     *   '2025' => [ ... ],
     * ]
     *
     * @param object $data        フォーム設定
     * @param array  $finalreport SQLの全行
     * @return array
     */
    protected function build_tiled_pivot(object $data, array $finalreport): array {
        if (empty($finalreport)) {
            return [];
        }

        if (empty($data->tile_field) || empty($data->x_field)
            || empty($data->series_field) || empty($data->value_field)) {
            return [];
        }

        [$tileidx]   = explode(',', $data->tile_field,   2);
        [$xidx]      = explode(',', $data->x_field,      2);
        [$seriesidx] = explode(',', $data->series_field,  2);
        [$valueidx]  = explode(',', $data->value_field,   2);
        $tileidx   = (int)$tileidx;
        $xidx      = (int)$xidx;
        $seriesidx = (int)$seriesidx;
        $valueidx  = (int)$valueidx;
        $agg       = !empty($data->value_agg) ? $data->value_agg : 'sum';

        // タイル・Xラベル・シリーズの出現順を収集（SQL ORDER BY を尊重）
        $tileorder   = [];
        $labelorder  = [];  // [tilekey => [xlabel, ...]]
        $seriesorder = [];  // シリーズ名はタイルをまたいで統一
        // 生データ蓄積: $rawdata[tilekey][xlabel][seriesname][] = 値
        $rawdata = [];

        foreach ($finalreport as $r) {
            $tilekey   = (string)($r[$tileidx]   ?? '');
            $xlabel    = (string)($r[$xidx]      ?? '');
            $seriesval = (string)($r[$seriesidx] ?? '');
            $value     = $r[$valueidx] ?? null;

            if (!in_array($tilekey, $tileorder, true)) {
                $tileorder[] = $tilekey;
            }
            if (!isset($labelorder[$tilekey])) {
                $labelorder[$tilekey] = [];
            }
            if (!in_array($xlabel, $labelorder[$tilekey], true)) {
                $labelorder[$tilekey][] = $xlabel;
            }
            if (!in_array($seriesval, $seriesorder, true)) {
                $seriesorder[] = $seriesval;
            }

            // count は非数値もカウント、それ以外は数値のみ
            if ($agg !== 'count' && !is_numeric($value)) {
                $value = 0.0;
            }
            $rawdata[$tilekey][$xlabel][$seriesval][] = is_numeric($value) ? (float)$value : 1.0;
        }

        // タイルごとにピボット集計
        $result = [];
        foreach ($tileorder as $tilekey) {
            $labels = $labelorder[$tilekey] ?? [];
            $tile   = ['__labels__' => $labels];

            foreach ($seriesorder as $sname) {
                $values = [];
                foreach ($labels as $xlabel) {
                    $vals = $rawdata[$tilekey][$xlabel][$sname] ?? [];
                    if (empty($vals)) {
                        $values[] = 0; // 欠損は 0 埋め
                    } else {
                        $values[] = round((float)$this->aggregate($vals, $agg), 4);
                    }
                }
                $tile[$sname] = $values;
            }

            $result[$tilekey] = $tile;
        }

        return $result;
    }

    // =========================================================================
    // Execute
    // =========================================================================

    public function execute($id, $data, $finalreport) {
        global $PAGE;

        if (empty($finalreport)) {
            return '';
        }

        $tileddata = $this->build_tiled_pivot($data, $finalreport);
        if (empty($tileddata)) {
            return '';
        }

        $charttype  = !empty($data->charttype)   ? $data->charttype    : 'bar';
        $tilewidth  = !empty($data->tilewidth)   ? (int)$data->tilewidth  : 400;
        $tileheight = !empty($data->tileheight)  ? (int)$data->tileheight : 300;
        $columns    = !empty($data->tilecolumns) ? $data->tilecolumns     : 'auto';
        $stacked    = !empty($data->bargrouping) && $data->bargrouping === 'stacked';

        if ($columns === 'auto') {
            $tilestyle = 'width:' . $tilewidth . 'px;';
        } else {
            $cols      = (int)$columns;
            $gapwidth  = ($cols - 1) * 16;
            $tilestyle = 'width:calc((100% - ' . $gapwidth . 'px) / ' . $cols . ');';
        }

        $palette = [
            ['bg' => 'rgba(54,  162, 235, 0.8)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(255, 99,  132, 0.8)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.8)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.8)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.8)', 'border' => 'rgba(153, 102, 255, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.8)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.8)', 'border' => 'rgba(201, 203, 207, 1)'],
        ];

        $actualtype = ($charttype === 'area') ? 'line' : $charttype;
        $isfilled   = ($charttype === 'area');
        $isbar      = ($charttype === 'bar');

        $axisoptions = ['beginAtZero' => true];
        if ($stacked) {
            $axisoptions['stacked'] = true;
        }

        $tiles = '';
        foreach ($tileddata as $tilekey => $tiledata) {
            $labels = $tiledata['__labels__'];
            unset($tiledata['__labels__']);

            // シリーズが1つだけなら凡例非表示
            $showlegend = count($tiledata) > 1;

            $datasets   = [];
            $colorindex = 0;
            foreach ($tiledata as $sname => $values) {
                $color   = $palette[$colorindex % count($palette)];
                $dataset = [
                    'label'           => $sname,
                    'data'            => array_values($values),
                    'backgroundColor' => $color['bg'],
                    'borderColor'     => $color['border'],
                    'borderWidth'     => $isbar ? 1 : 2,
                ];
                if (!$isbar) {
                    $dataset['fill']     = $isfilled;
                    $dataset['tension']  = 0.0;
                    $dataset['spanGaps'] = true;
                }
                $datasets[]  = $dataset;
                $colorindex++;
            }

            $chartconfig = json_encode([
                'type' => $actualtype,
                'data' => [
                    'labels'   => array_values($labels),
                    'datasets' => $datasets,
                ],
                'options' => [
                    'responsive'          => true,
                    'maintainAspectRatio' => false,
                    'plugins' => [
                        'legend' => ['display' => $showlegend, 'position' => 'top'],
                        'title'  => ['display' => true, 'text' => (string)$tilekey],
                    ],
                    'scales' => [
                        'x' => $axisoptions,
                        'y' => $axisoptions,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);

            $canvasid = 'cr_tiledpivot_' . $id . '_' . substr(md5($tilekey . uniqid('', true)), 0, 8);

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

    /**
     * get_series（pChart 互換スタブ）
     */
    public function get_series(): array {
        return [];
    }
}
