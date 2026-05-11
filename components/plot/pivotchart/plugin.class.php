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
 * Class plugin_pivotchart
 *
 * ロング形式（縦持ち）のデータを自動的にワイド形式へピボット変換し、
 * 1枚のグラフに集約して表示するプラグイン（Chart.js 専用）。
 *
 * 入力データ例：
 *   氏名 | 科目 | 素点
 *   A    | 数学 | 80
 *   A    | 英語 | 70
 *   B    | 数学 | 90
 *
 * 設定：x_field=氏名, series_field=科目, value_field=素点, value_agg=sum
 *
 * 出力：AとBを横軸に、数学・英語を系列（色分け）とした棒グラフ1枚
 *
 * tiledchart との関係：
 *   tiledchart  → ロング形式 → group_field でタイル分割
 *   pivotchart  → ロング形式 → series_field で色分け・1グラフに集約
 *
 * @package   block_configurable_reports
 */
class plugin_pivotchart extends plugin_base {

    public function init(): void {
        $this->fullname  = 'Pivot chart';
        $this->form      = true;
        $this->ordering  = true;
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    public function summary(object $data): string {
        $type = !empty($data->charttype) ? $data->charttype : 'bar';
        return "Pivot chart ({$type})";
    }

    // =========================================================================
    // 集計ロジック（tiledchart / radar / line と共通パターン）
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
    // ピボット変換
    // =========================================================================

    /**
     * ロング形式の finalreport をワイド形式にピボット変換して返す。
     *
     * 返り値：
     * [
     *   '__labels__'  => ['A', 'B', ...],   // X軸ラベル（SQL ORDER BY 順）
     *   '数学'        => [80, 90, ...],      // シリーズ名 => 各Xラベルの集計値
     *   '英語'        => [70,  0, ...],      // 欠損は 0 埋め
     * ]
     *
     * @param object $data        フォーム設定
     * @param array  $finalreport SQLの全行
     * @return array
     */
    protected function build_pivot(object $data, array $finalreport): array {
        if (empty($finalreport)) {
            return [];
        }

        if (empty($data->x_field) || empty($data->series_field) || empty($data->value_field)) {
            return [];
        }

        [$xidx]      = explode(',', $data->x_field,      2);
        [$seriesidx] = explode(',', $data->series_field,  2);
        [$valueidx]  = explode(',', $data->value_field,   2);
        $xidx      = (int)$xidx;
        $seriesidx = (int)$seriesidx;
        $valueidx  = (int)$valueidx;
        $agg       = !empty($data->value_agg) ? $data->value_agg : 'sum';

        // X軸ラベルとシリーズ名の出現順を収集（SQL ORDER BY を尊重）
        $labelorder  = [];
        $seriesorder = [];
        // 生データ蓄積: $rawdata[xラベル][シリーズ名][] = 値
        $rawdata = [];

        foreach ($finalreport as $r) {
            $xlabel    = (string)($r[$xidx]      ?? '');
            $seriesval = (string)($r[$seriesidx] ?? '');
            $value     = $r[$valueidx] ?? null;

            if (!in_array($xlabel, $labelorder, true)) {
                $labelorder[] = $xlabel;
            }
            if (!in_array($seriesval, $seriesorder, true)) {
                $seriesorder[] = $seriesval;
            }

            // count は非数値行もカウント対象、それ以外は数値のみ
            if ($agg !== 'count' && !is_numeric($value)) {
                $value = 0.0; // ピボットでは欠損を 0 扱いが自然
            }
            $rawdata[$xlabel][$seriesval][] = is_numeric($value) ? (float)$value : 1.0;
        }

        // 集計してワイド形式に変換
        $result = ['__labels__' => $labelorder];

        foreach ($seriesorder as $sname) {
            $values = [];
            foreach ($labelorder as $xlabel) {
                $vals = $rawdata[$xlabel][$sname] ?? [];
                if (empty($vals)) {
                    $values[] = 0; // 欠損は 0 埋め
                } else {
                    $values[] = round((float)$this->aggregate($vals, $agg), 4);
                }
            }
            $result[$sname] = $values;
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

        $pivotdata = $this->build_pivot($data, $finalreport);
        if (empty($pivotdata)) {
            return '';
        }

        $labels = $pivotdata['__labels__'];
        unset($pivotdata['__labels__']);

        $charttype  = !empty($data->charttype)   ? $data->charttype    : 'bar';
        $width      = !empty($data->width)        ? (int)$data->width   : 900;
        $height     = !empty($data->height)       ? (int)$data->height  : 500;
        $horizontal = !empty($data->bardirection) && $data->bardirection === 'horizontal';
        $stacked    = !empty($data->bargrouping)  && $data->bargrouping  === 'stacked';

        $palette = [
            ['bg' => 'rgba(54,  162, 235, 0.8)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(255, 99,  132, 0.8)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.8)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.8)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.8)', 'border' => 'rgba(153, 102, 255, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.8)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.8)', 'border' => 'rgba(201, 203, 207, 1)'],
        ];

        $datasets   = [];
        $colorindex = 0;
        foreach ($pivotdata as $sname => $values) {
            $color    = $palette[$colorindex % count($palette)];
            $isbar    = ($charttype === 'bar');
            $isfilled = ($charttype === 'area');

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

        // bar/line/area の Chart.js type
        $actualtype  = ($charttype === 'area') ? 'line' : $charttype;

        $axisoptions = ['beginAtZero' => true];
        if ($stacked) {
            $axisoptions['stacked'] = true;
        }

        $chartoptions = [
            'responsive'          => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['position' => 'top'],
            ],
            'scales' => [
                'x' => $axisoptions,
                'y' => $axisoptions,
            ],
        ];

        // 横棒グラフ
        if ($charttype === 'bar' && $horizontal) {
            $chartoptions['indexAxis'] = 'y';
        }

        $chartconfig = json_encode([
            'type' => $actualtype,
            'data' => [
                'labels'   => array_values($labels),
                'datasets' => $datasets,
            ],
            'options' => $chartoptions,
        ], JSON_UNESCAPED_UNICODE);

        $canvasid = 'cr_pivot_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

        $html  = '<div style="position:relative; width:' . $width . 'px; height:' . $height . 'px;">';
        $html .= '<canvas id="' . $canvasid . '"'
               . ' class="cr-chartjs-pending"'
               . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
               . '></canvas>';
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
