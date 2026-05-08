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
 * グループキー列でデータを仕分けし、グループごとに1つのグラフを生成して
 * タイル状に並べて表示するプラグイン（Chart.js 専用）。
 *
 * 対応グラフタイプ: bar / pie / doughnut
 * X軸（ラベル列）は1列固定。Y系列は最大5行で集計に対応。
 *
 * データ構造：
 *   group_field  : タイルの分割キー列（'index,colname' 形式）
 *   x_field      : X軸 / ラベル列（'index,colname' 形式）
 *   series_field : Y系列列の配列（最大5）
 *   series_agg   : 各系列の集計方法
 *   series_label : 各系列の凡例ラベル（任意）
 *   nahandling   : NAの扱い（exclude / zero）
 *
 * @package   block_configurable_reports
 */
class plugin_tiledchart extends plugin_base {

    /**
     * Init
     */
    public function init(): void {
        $this->fullname = "Tiled chart";
        $this->form     = true;
        $this->ordering = true;
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    /**
     * Summary
     */
    public function summary(object $data): string {
        $type = !empty($data->charttype) ? $data->charttype : 'bar';
        return "Tiled chart ({$type})";
    }

    // =========================================================================
    // 集計ロジック（radar / line と共通パターン）
    // =========================================================================

    /**
     * 値の配列を集計する。
     */
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
            default:       return $values[0]; // none：最初の値
        }
    }

    /**
     * パーセンタイルを計算する（線形補間）。
     */
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
     * finalreport をグループキーで仕分けし、系列ごとに集計した結果を返す。
     *
     * 返り値：
     * [
     *   'グループ名' => [
     *     'labels'   => [Xラベル, ...],          // 出現順
     *     'series'   => [
     *       ['name' => '系列名', 'values' => [値, ...]],
     *       ...
     *     ],
     *   ],
     *   ...
     * ]
     */
    protected function build_groups(object $data, array $finalreport): array {
        if (empty($finalreport)) {
            return [];
        }

        [$groupidx] = explode(',', $data->group_field);
        [$xidx]     = explode(',', $data->x_field);
        $groupidx   = (int)$groupidx;
        $xidx       = (int)$xidx;

        $nahandling   = !empty($data->nahandling) ? $data->nahandling : 'exclude';
        $seriesfields = is_array($data->series_field) ? $data->series_field : [];
        $seriesaggs   = is_array($data->series_agg)   ? $data->series_agg   : [];
        $serieslabels = is_array($data->series_label) ? $data->series_label : [];

        // 有効な系列だけに絞る
        $activeseries = [];
        foreach ($seriesfields as $si => $sf) {
            if (!empty($sf)) {
                [$colidx, $colname] = explode(',', $sf, 2);
                $agg   = $seriesaggs[$si]   ?? 'none';
                $label = !empty($serieslabels[$si])
                    ? $serieslabels[$si]
                    : ($agg !== 'none' ? "$colname ($agg)" : $colname);
                $activeseries[] = [
                    'colidx' => (int)$colidx,
                    'agg'    => $agg,
                    'label'  => $label,
                ];
            }
        }

        if (empty($activeseries)) {
            return [];
        }

        // グループ × Xラベル × 系列 で値を積み上げる
        // rawdata[$groupkey][$xlabel][$si][] = value
        $rawdata    = [];
        $labelorder = []; // グループごとのXラベル出現順

        foreach ($finalreport as $r) {
            $groupkey = (string)($r[$groupidx] ?? '');
            $xlabel   = (string)($r[$xidx]     ?? '');

            if (!isset($labelorder[$groupkey])) {
                $labelorder[$groupkey] = [];
            }
            if (!in_array($xlabel, $labelorder[$groupkey], true)) {
                $labelorder[$groupkey][] = $xlabel;
            }

            foreach ($activeseries as $si => $sdef) {
                $value = $r[$sdef['colidx']] ?? null;

                if (!is_numeric($value)) {
                    if ($nahandling === 'zero') {
                        $value = 0.0;
                    } else {
                        continue; // exclude
                    }
                }

                $rawdata[$groupkey][$xlabel][$si][] = (float)$value;
            }
        }

        // 集計して結果配列を構築
        $result = [];
        foreach ($labelorder as $groupkey => $labels) {
            $seriesresult = [];
            foreach ($activeseries as $si => $sdef) {
                // 同じラベルが系列間で重複する場合は連番付与
                $uniquelabel = $sdef['label'];
                $suffix      = 2;
                $existinglabels = array_column($seriesresult, 'name');
                while (in_array($uniquelabel, $existinglabels, true)) {
                    $uniquelabel = $sdef['label'] . ' ' . $suffix;
                    $suffix++;
                }

                $values = [];
                foreach ($labels as $xlabel) {
                    $vals = $rawdata[$groupkey][$xlabel][$si] ?? [];
                    if (empty($vals)) {
                        $values[] = null;
                    } else {
                        $agg = $sdef['agg'];
                        $v   = ($agg === 'none') ? $vals[0] : $this->aggregate($vals, $agg);
                        $values[] = round((float)$v, 4);
                    }
                }

                $seriesresult[] = ['name' => $uniquelabel, 'values' => $values];
            }

            $result[$groupkey] = [
                'labels' => $labels,
                'series' => $seriesresult,
            ];
        }

        return $result;
    }

    // =========================================================================
    // Execute
    // =========================================================================

    /**
     * Execute
     *
     * tiledchart は Chart.js 専用。pChart 設定でも Chart.js で描画する。
     */
    public function execute($id, $data, $finalreport) {
        global $PAGE;

        if (empty($finalreport)) {
            return '';
        }

        $groups = $this->build_groups($data, $finalreport);
        if (empty($groups)) {
            return '';
        }

        $charttype  = !empty($data->charttype)  ? $data->charttype  : 'bar';
        $tilewidth  = !empty($data->tilewidth)  ? (int)$data->tilewidth  : 400;
        $tileheight = !empty($data->tileheight) ? (int)$data->tileheight : 280;
        $columns    = !empty($data->tilecolumns) ? $data->tilecolumns    : 'auto';

        // タイル横幅スタイル
        if ($columns === 'auto') {
            $tilestyle = 'width:' . $tilewidth . 'px;';
        } else {
            $cols      = (int)$columns;
            $gapwidth  = ($cols - 1) * 16;
            $tilestyle = 'width:calc((100% - ' . $gapwidth . 'px) / ' . $cols . ');';
        }

        // カラーパレット
        $palette = [
            ['bg' => 'rgba(54,  162, 235, 0.8)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(255, 99,  132, 0.8)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.8)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.8)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.8)', 'border' => 'rgba(153, 102, 255, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.8)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.8)', 'border' => 'rgba(201, 203, 207, 1)'],
        ];

        $tiles = '';
        foreach ($groups as $groupname => $groupdata) {
            $canvasid = 'cr_tiled_' . $id . '_' . substr(md5($groupname . uniqid('', true)), 0, 8);
            $labels   = $groupdata['labels'];
            $series   = $groupdata['series'];

            if (in_array($charttype, ['pie', 'doughnut'])) {
                // pie / doughnut：
                // 系列が1本のとき → 各Xラベルが1スライス
                // 系列が複数のとき → 各系列の合計値がスライス（集計済みの総和）
                if (count($series) === 1) {
                    $slicevalues = array_map(fn($v) => $v ?? 0, $series[0]['values']);
                    $slicelabels = $labels;
                } else {
                    $slicevalues = [];
                    $slicelabels = [];
                    foreach ($series as $sdef) {
                        $slicelabels[] = $sdef['name'];
                        $total = array_sum(array_map(fn($v) => $v ?? 0, $sdef['values']));
                        $slicevalues[] = round($total, 4);
                    }
                }

                $bgcolors = array_map(fn($c) => $c['bg'], array_slice($palette, 0, count($slicevalues)));

                $chartconfig = json_encode([
                    'type' => $charttype,
                    'data' => [
                        'labels'   => $slicelabels,
                        'datasets' => [[
                            'data'            => $slicevalues,
                            'backgroundColor' => $bgcolors,
                            'borderWidth'     => 1,
                        ]],
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

            } else {
                // bar：系列ごとにdatasetを作成
                $datasets = [];
                foreach ($series as $ci => $sdef) {
                    $color      = $palette[$ci % count($palette)];
                    $datasets[] = [
                        'label'           => $sdef['name'],
                        'data'            => array_values(array_map(fn($v) => $v ?? 0, $sdef['values'])),
                        'backgroundColor' => $color['bg'],
                        'borderColor'     => $color['border'],
                        'borderWidth'     => 1,
                    ];
                }

                $chartconfig = json_encode([
                    'type' => 'bar',
                    'data' => [
                        'labels'   => $labels,
                        'datasets' => $datasets,
                    ],
                    'options' => [
                        'responsive'          => true,
                        'maintainAspectRatio' => false,
                        'plugins' => [
                            'legend' => ['display' => count($series) > 1, 'position' => 'top'],
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

        $html  = '<div style="display:flex; flex-wrap:wrap; gap:16px; width:100%;">';
        $html .= $tiles;
        $html .= '</div>';

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