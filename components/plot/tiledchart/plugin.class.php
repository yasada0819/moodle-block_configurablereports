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
 * 対応グラフタイプ: bar / pie / doughnut / line / area / radar
 * X軸（ラベル列）は1列固定。Y系列は最大5行で集計に対応。
 * pie/doughnut で系列が複数のとき、同心円状に描画される（Chart.js の仕様）。
 *
 * データ構造：
 *   group_field  : タイルの分割キー列（'index,colname' 形式）
 *   x_field      : X軸 / ラベル列（'index,colname' 形式）
 *   series_field : Y系列列の配列（最大5、'index,colname' 形式）
 *   series_agg   : 各系列の集計方法
 *   series_label : 各系列の凡例ラベル（任意）
 *   nahandling   : NAの扱い（exclude / zero）
 *
 * @package   block_configurable_reports
 */
class plugin_tiledchart extends plugin_base {

    public function init(): void {
        $this->fullname = "Tiled chart";
        $this->form     = true;
        $this->ordering = true;
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    public function summary(object $data): string {
        $type = !empty($data->charttype) ? $data->charttype : 'bar';
        return "Tiled chart ({$type})";
    }

    // =========================================================================
    // 集計ロジック（radar / line と共通パターン）
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
            default:       return $values[0]; // none：最初の値
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
     * finalreport をグループキーで仕分けし、系列ごとに集計した結果を返す。
     *
     * 返り値：
     * [
     *   'グループ名' => [
     *     'labels' => [Xラベル, ...],
     *     'series' => [
     *       ['name' => '系列名', 'values' => [値or null, ...]],
     *       ...
     *     ],
     *   ],
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

        // 有効な系列があるか確認
        $hasactiveseries = false;
        foreach ($seriesfields as $sf) {
            if (!empty($sf)) {
                $hasactiveseries = true;
                break;
            }
        }
        if (!$hasactiveseries) {
            return [];
        }

        // グループ × Xラベル × 系列($si) で値を積み上げる
        // $si は元のフォームインデックス（0〜4）を保持する
        $rawdata    = [];
        $labelorder = [];

        foreach ($finalreport as $r) {
            $groupkey = (string)($r[$groupidx] ?? '');
            $xlabel   = (string)($r[$xidx]     ?? '');

            if (!isset($labelorder[$groupkey])) {
                $labelorder[$groupkey] = [];
            }
            if (!in_array($xlabel, $labelorder[$groupkey], true)) {
                $labelorder[$groupkey][] = $xlabel;
            }

            foreach ($seriesfields as $si => $sf) {
                if (empty($sf)) {
                    continue;
                }
                [$colidx] = explode(',', $sf);
                $value    = $r[(int)$colidx] ?? null;

                if (!is_numeric($value)) {
                    if ($nahandling === 'zero') {
                        $value = 0.0;
                    } else {
                        continue;
                    }
                }

                $rawdata[$groupkey][$xlabel][$si][] = (float)$value;
            }
        }

        // 集計して結果配列を構築
        $result = [];
        foreach ($labelorder as $groupkey => $labels) {
            $seriesresult = [];
            $usedlabels   = [];

            foreach ($seriesfields as $si => $sf) {
                if (empty($sf)) {
                    continue;
                }
                [, $colname] = explode(',', $sf, 2);
                $agg         = $seriesaggs[$si]   ?? 'none';
                $label       = !empty($serieslabels[$si])
                    ? $serieslabels[$si]
                    : ($agg !== 'none' ? "$colname ($agg)" : $colname);

                // 凡例ラベルの重複に連番付与
                $uniquelabel = $label;
                $suffix      = 2;
                while (in_array($uniquelabel, $usedlabels, true)) {
                    $uniquelabel = $label . ' ' . $suffix;
                    $suffix++;
                }
                $usedlabels[] = $uniquelabel;

                $values = [];
                foreach ($labels as $xlabel) {
                    $vals = $rawdata[$groupkey][$xlabel][$si] ?? [];
                    if (empty($vals)) {
                        $values[] = null;
                    } elseif ($agg === 'none') {
                        $values[] = $vals[0];
                    } else {
                        $values[] = round((float)$this->aggregate($vals, $agg), 4);
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

    public function execute($id, $data, $finalreport) {
        global $PAGE;

        if (empty($finalreport)) {
            return '';
        }

        $groups = $this->build_groups($data, $finalreport);
        if (empty($groups)) {
            return '';
        }

        $charttype  = !empty($data->charttype)   ? $data->charttype       : 'bar';
        $tilewidth  = !empty($data->tilewidth)   ? (int)$data->tilewidth  : 400;
        $tileheight = !empty($data->tileheight)  ? (int)$data->tileheight : 280;
        $columns    = !empty($data->tilecolumns) ? $data->tilecolumns     : 'auto';
        $showlegend = !isset($data->show_legend)  || !empty($data->show_legend);

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

        $tiles = '';
        foreach ($groups as $groupname => $groupdata) {
            $canvasid    = 'cr_tiled_' . $id . '_' . substr(md5($groupname . uniqid('', true)), 0, 8);
            $chartconfig = $this->build_chartconfig(
                $charttype, (string)$groupname,
                $groupdata['labels'], $groupdata['series'], $palette, $showlegend
            );

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
     * グラフタイプに応じたChart.js configを構築する。
     */
    protected function build_chartconfig(
        string $charttype,
        string $groupname,
        array  $labels,
        array  $series,
        array  $palette,
        bool   $showlegend
    ): string {

        $titleopts  = ['display' => true, 'text' => $groupname];

        if (in_array($charttype, ['pie', 'doughnut'])) {
            // 系列ごとにdataset → 複数系列 = 同心円
            $datasets = [];
            foreach ($series as $ci => $sdef) {
                $bgcolors   = array_map(fn($c) => $c['bg'], array_slice($palette, 0, count($labels)));
                $datasets[] = [
                    'label'           => $sdef['name'],
                    'data'            => array_values(array_map(fn($v) => $v ?? 0, $sdef['values'])),
                    'backgroundColor' => $bgcolors,
                    'borderWidth'     => 1,
                ];
            }
            return json_encode([
                'type' => $charttype,
                'data' => ['labels' => $labels, 'datasets' => $datasets],
                'options' => [
                    'responsive'          => true,
                    'maintainAspectRatio' => false,
                    'plugins' => [
                        'legend' => ['display' => $showlegend, 'position' => 'bottom'],
                        'title'  => $titleopts,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);

        } elseif ($charttype === 'radar') {
            $datasets = [];
            foreach ($series as $ci => $sdef) {
                $color      = $palette[$ci % count($palette)];
                $datasets[] = [
                    'label'           => $sdef['name'],
                    'data'            => array_values(array_map(fn($v) => $v ?? 0, $sdef['values'])),
                    'backgroundColor' => str_replace('0.8', '0.2', $color['bg']),
                    'borderColor'     => $color['border'],
                    'borderWidth'     => 2,
                    'pointRadius'     => 3,
                ];
            }
            return json_encode([
                'type' => 'radar',
                'data' => ['labels' => $labels, 'datasets' => $datasets],
                'options' => [
                    'responsive'          => true,
                    'maintainAspectRatio' => false,
                    'plugins' => [
                        'legend' => ['display' => $showlegend, 'position' => 'top'],
                        'title'  => $titleopts,
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE);

        } else {
            // bar / line / area
            $actualtype = ($charttype === 'area') ? 'line' : $charttype;
            $isfilled   = ($charttype === 'area');
            $isbar      = ($charttype === 'bar');

            $datasets = [];
            foreach ($series as $ci => $sdef) {
                $color    = $palette[$ci % count($palette)];
                $dataset  = [
                    'label'           => $sdef['name'],
                    'data'            => array_values(array_map(fn($v) => $v ?? null, $sdef['values'])),
                    'backgroundColor' => $color['bg'],
                    'borderColor'     => $color['border'],
                    'borderWidth'     => $isbar ? 1 : 2,
                    'spanGaps'        => true,
                ];
                if (!$isbar) {
                    $dataset['fill']    = $isfilled;
                    $dataset['tension'] = 0.0;
                }
                $datasets[] = $dataset;
            }

            return json_encode([
                'type' => $actualtype,
                'data' => ['labels' => $labels, 'datasets' => $datasets],
                'options' => [
                    'responsive'          => true,
                    'maintainAspectRatio' => false,
                    'plugins' => [
                        'legend' => ['display' => $showlegend, 'position' => 'top'],
                        'title'  => $titleopts,
                    ],
                    'scales' => ['y' => ['beginAtZero' => true]],
                ],
            ], JSON_UNESCAPED_UNICODE);
        }
    }

    /**
     * get_series（pChart 互換スタブ）
     */
    public function get_series(): array {
        return [];
    }

}