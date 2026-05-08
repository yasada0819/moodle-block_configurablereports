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
 * Class plugin_line
 *
 * graphlibrary 設定に応じて動作が異なる：
 *   - pChart モード  : URL文字列を返す → report.class.php が <img src="..."> として出力
 *   - Chart.js モード: HTML文字列を返す → report.class.php がそのまま出力
 *
 * Chart.js モードのデータ構造：
 *   xaxis        : X軸列（'index,colname' 形式）
 *   series_field : Y系列列の配列（最大5）
 *   series_agg   : 各系列の集計方法
 *   series_label : 各系列の凡例ラベル（任意）
 *   series_y2    : 各系列をY2軸（右軸）に割り当てるかどうか
 *   nahandling   : NAの扱い（exclude / zero）
 *   smooth/filled/dualaxis : 描画オプション
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class plugin_line extends plugin_base {

    /**
     * Init
     */
    public function init(): void {
        $this->fullname = get_string('line', 'block_configurable_reports');
        $this->form = true;
        $this->ordering = true;
        $this->reporttypes = ['timeline', 'sql', 'timeline'];
    }

    /**
     * Summary
     */
    public function summary(object $data): string {
        return get_string('linesummary', 'block_configurable_reports');
    }

    /**
     * Execute
     */
    public function execute($id, $data, $finalreport) {
        global $CFG;

        $graphlibrary = get_config('block_configurable_reports', 'graphlibrary');

        if ($graphlibrary === 'chartjs') {
            return $this->execute_chartjs($id, $data, $finalreport);
        }

        // --- pChart モード（変更なし） ---
        $series = [];
        $data->xaxis--;
        $data->yaxis--;
        $data->serieid--;
        $minvalue = 0;
        $maxvalue = 0;

        if ($finalreport) {
            foreach ($finalreport as $r) {
                $hash = md5(strtolower($r[$data->serieid] ?? ''));
                $sname[$hash] = $r[$data->serieid] ?? null;
                $val = (isset($r[$data->yaxis]) && is_numeric($r[$data->yaxis])) ? $r[$data->yaxis] : 0;
                $series[$hash][] = $val;
                $minvalue = ($val < $minvalue) ? $val : $minvalue;
                $maxvalue = ($val > $maxvalue) ? $val : $maxvalue;
            }
        }

        $params = '';
        $i = 0;
        foreach ($series as $h => $s) {
            $params .= "&amp;serie$i=" . base64_encode($sname[$h] . '||' . implode(',', $s));
            $i++;
        }

        return $CFG->wwwroot . '/blocks/configurable_reports/components/plot/line/graph.php?reportid=' . $this->report->id .
            '&id=' . $id . $params . '&amp;min=' . $minvalue . '&amp;max=' . $maxvalue . '&courseid=' . $this->report->courseid;
    }

    // =========================================================================
    // Chart.js モード
    // =========================================================================

    /**
     * 値の配列を集計する（radarと共通ロジック）。
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
            default:       return array_sum($values); // none 含む
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

    /**
     * 系列データを構築する。
     *
     * 返り値：
     *   '__labels__' => [X軸ラベル, ...]  出現順
     *   '__y2__'     => [系列名, ...]      Y2軸に割り当てる系列名のリスト
     *   '系列名'     => [値or null, ...]   X軸ラベルと同順
     *
     * X軸ラベルの順序は最初に出現した順を使用する。
     * 集計ありの場合、同じ X ラベルを持つ行の値を集計してプロットする。
     * 集計なし（none）の場合、同じ X ラベルが複数あれば最後の値を使う。
     */
    protected function build_series(object $data, array $finalreport): array {
        if (!$finalreport) {
            return [];
        }

        // X軸列インデックス（'index,colname' 形式）
        [$xidx] = explode(',', $data->xaxis);
        $xidx   = (int)$xidx;

        $nahandling   = !empty($data->nahandling) ? $data->nahandling : 'exclude';
        $seriesfields = is_array($data->series_field) ? $data->series_field : [];
        $seriesaggs   = is_array($data->series_agg)   ? $data->series_agg   : [];
        $serieslabels = is_array($data->series_label) ? $data->series_label : [];
        $seriesy2     = is_array($data->series_y2)    ? $data->series_y2    : [];

        if (empty($seriesfields)) {
            return [];
        }

        // X軸ラベルの出現順を収集
        $labelorder = [];
        foreach ($finalreport as $r) {
            $xlabel = (string)($r[$xidx] ?? '');
            if (!in_array($xlabel, $labelorder, true)) {
                $labelorder[] = $xlabel;
            }
        }

        // 系列ごと・Xラベルごとに値を積み上げる
        // rawdata[$si][$xlabel][] = value
        $rawdata = [];
        foreach ($seriesfields as $si => $sf) {
            if (empty($sf)) {
                continue;
            }
            [$colidx] = explode(',', $sf);
            $colidx   = (int)$colidx;

            foreach ($finalreport as $r) {
                $xlabel = (string)($r[$xidx] ?? '');
                $value  = $r[$colidx] ?? null;

                if (!is_numeric($value)) {
                    if ($nahandling === 'zero') {
                        $value = 0.0;
                    } else {
                        continue; // exclude
                    }
                }

                $rawdata[$si][$xlabel][] = (float)$value;
            }
        }

        // 結果配列を構築
        $result  = ['__labels__' => $labelorder, '__y2__' => []];

        foreach ($seriesfields as $si => $sf) {
            if (empty($sf)) {
                continue;
            }
            [$colidx, $colname] = explode(',', $sf, 2);
            $agg   = $seriesaggs[$si]   ?? 'none';
            $label = !empty($serieslabels[$si])
                ? $serieslabels[$si]
                : ($agg !== 'none' ? "$colname ($agg)" : $colname);

            // 同じラベルが重複する場合は連番を付ける
            $uniquelabel = $label;
            $suffix      = 2;
            while (array_key_exists($uniquelabel, $result)) {
                $uniquelabel = $label . ' ' . $suffix;
                $suffix++;
            }

            // Y2軸フラグ
            if (!empty($seriesy2[$si])) {
                $result['__y2__'][] = $uniquelabel;
            }

            // 値を配列化
            $values = [];
            foreach ($labelorder as $xlabel) {
                $vals = $rawdata[$si][$xlabel] ?? [];
                if (empty($vals)) {
                    $values[] = null; // spanGaps=true で線が途切れる
                } elseif ($agg === 'none') {
                    $values[] = $vals[0]; // 最初の値
                } else {
                    $values[] = round($this->aggregate($vals, $agg), 4);
                }
            }

            $result[$uniquelabel] = $values;
        }

        return $result;
    }

    /**
     * Execute (Chart.js モード)
     */
    protected function execute_chartjs($id, $data, $finalreport): string {
        if (empty($finalreport)) {
            return '';
        }

        $series = $this->build_series($data, $finalreport);
        if (empty($series)) {
            return '';
        }

        $labels  = $series['__labels__'];
        $y2list  = $series['__y2__'];
        unset($series['__labels__'], $series['__y2__']);

        $width    = property_exists($data, 'width')    ? (int)$data->width    : 900;
        $height   = property_exists($data, 'height')   ? (int)$data->height   : 500;
        $smooth   = !empty($data->smooth);
        $filled   = !empty($data->filled);
        $dualaxis = !empty($data->dualaxis);

        // カラーパレット（Y1系列 / Y2系列で色を分ける）
        $palette1 = [
            ['bg' => 'rgba(54,  162, 235, 0.4)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.4)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.4)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.4)', 'border' => 'rgba(153, 102, 255, 1)'],
        ];
        $palette2 = [
            ['bg' => 'rgba(255, 99,  132, 0.4)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.4)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.4)', 'border' => 'rgba(201, 203, 207, 1)'],
        ];

        $datasets = [];
        $ci1 = 0;
        $ci2 = 0;
        foreach ($series as $sname => $values) {
            $isY2   = $dualaxis && in_array($sname, $y2list, true);
            $color  = $isY2
                ? $palette2[$ci2++ % count($palette2)]
                : $palette1[$ci1++ % count($palette1)];

            $datasets[] = [
                'label'           => $sname,
                'data'            => array_values($values),
                'borderColor'     => $color['border'],
                'backgroundColor' => $filled ? $color['bg'] : 'transparent',
                'borderWidth'     => 2,
                'tension'         => $smooth ? 0.4 : 0.0,
                'fill'            => $filled,
                'spanGaps'        => true,
                'yAxisID'         => $isY2 ? 'y2' : 'y',
            ];
        }

        // スケール設定
        $scales = ['y' => ['beginAtZero' => true, 'position' => 'left']];
        if ($dualaxis && !empty($y2list)) {
            $scales['y2'] = [
                'beginAtZero' => true,
                'position'    => 'right',
                'grid'        => ['drawOnChartArea' => false],
            ];
        }

        $chartconfig = json_encode([
            'type' => 'line',
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

        $canvasid = 'cr_line_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

        $html  = '<div style="position:relative; width:' . $width . 'px; height:' . $height . 'px;">';
        $html .= '<canvas id="' . $canvasid . '"'
               . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
               . ' class="cr-chartjs-pending"></canvas>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Get series (pChart の graph.php から呼ばれる・変更なし)
     */
    public function get_series(): array {
        $series = [];

        // TODO don't use $_GET.
        foreach ($_GET as $key => $val) {
            if (strpos($key, 'serie') !== false) {
                $id = (int) str_replace('serie', '', $key);
                [$name, $values] = explode('||', base64_decode($val));
                $series[$id] = ['serie' => explode(',', $values), 'name' => $name];
            }
        }

        return $series;
    }

}