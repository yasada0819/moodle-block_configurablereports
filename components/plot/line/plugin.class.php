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
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class plugin_line extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = get_string('line', 'block_configurable_reports');
        $this->form = true;
        $this->ordering = true;
        $this->reporttypes = ['timeline', 'sql', 'timeline'];
    }

    /**
     * Summary
     *
     * @param object $data
     * @return string
     */
    public function summary(object $data): string {
        return get_string('linesummary', 'block_configurable_reports');
    }

    /**
     * Execute
     *
     * graphlibrary 設定に応じて返す値が異なる：
     *   - pChart モード  : URL文字列 → report.class.php が <img src="..."> として出力
     *   - Chart.js モード: HTML文字列 → report.class.php がそのまま出力
     *
     * @param int    $id
     * @param object $data
     * @param array  $finalreport
     * @return string
     */
    public function execute($id, $data, $finalreport) {
        global $CFG;

        $graphlibrary = get_config('block_configurable_reports', 'graphlibrary');

        if ($graphlibrary === 'chartjs') {
            return $this->execute_chartjs($id, $data, $finalreport);
        }

        // --- pChart モード（既存コード・変更なし） ---
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

    /**
     * Execute (Chart.js モード)
     *
     * 元データは「縦持ち」形式：
     *   xaxis列  : X軸ラベル（例：日付）
     *   serieid列: 系列名（例：コース名）→ グループ化キー
     *   yaxis列  : Y軸の値
     *
     * serieid でグループ化し、系列ごとに dataset を作成する。
     * X軸ラベルは全系列共通で最初に出現した順序を使用する。
     *
     * @param int    $id
     * @param object $data
     * @param array  $finalreport
     * @return string HTML fragment
     */
    protected function execute_chartjs($id, $data, $finalreport): string {
        if (empty($finalreport)) {
            return '';
        }

        // form.phpのインデックスは1始まりなので0始まりに変換
        // 0 = 未選択（'Choose...'）なのでそのままnullとして扱う
        $xidx     = (int)$data->xaxis - 1;
        $yidx     = (int)$data->yaxis - 1;

        // Y1グループ列（任意）：0 = 未選択
        $has_serie  = !empty($data->serieid) && (int)$data->serieid > 0;
        $serieidx   = $has_serie ? (int)$data->serieid - 1 : null;

        // Y2軸（任意）：yaxis2 > 0 のときのみ有効
        $has_y2     = !empty($data->yaxis2) && (int)$data->yaxis2 > 0;
        $yidx2      = $has_y2 ? (int)$data->yaxis2 - 1 : null;

        // Y2グループ列（任意）
        $has_serie2 = $has_y2 && !empty($data->serieid2) && (int)$data->serieid2 > 0;
        $serieidx2  = $has_serie2 ? (int)$data->serieid2 - 1 : null;

        $width     = property_exists($data, 'width')  ? (int)$data->width  : 900;
        $height    = property_exists($data, 'height') ? (int)$data->height : 500;
        $smooth    = !empty($data->smooth);
        $filled    = !empty($data->filled);
        $dualaxis  = !empty($data->dualaxis);

        // --- データを縦持ち→横持ちに変換 ---
        // labels: X軸ラベルの順序リスト（重複なし・出現順）
        // seriesdata:  [ 系列名 => [ xラベル => y値 ] ]  Y軸用
        // seriesdata2: [ 系列名 => [ xラベル => y値 ] ]  Y2軸用
        $labels      = [];
        $seriesdata  = [];
        $seriesdata2 = [];

        foreach ($finalreport as $r) {
            $xlabel = $r[$xidx] ?? '';

            // Y1系列名：グループ列が未選択なら固定文字列で1系列にまとめる
            $sname  = $has_serie ? ($r[$serieidx] ?? '') : '__single__';
            $yval   = (isset($r[$yidx]) && is_numeric($r[$yidx])) ? (float)$r[$yidx] : 0;

            if (!in_array($xlabel, $labels, true)) {
                $labels[] = $xlabel;
            }
            if (!isset($seriesdata[$sname])) {
                $seriesdata[$sname] = [];
            }
            $seriesdata[$sname][$xlabel] = $yval;

            // Y2軸データ
            if ($has_y2) {
                // Y2グループ列が未選択なら固定文字列で1系列にまとめる
                $sname2 = $has_serie2 ? ($r[$serieidx2] ?? '') : '__single2__';
                $yval2  = (isset($r[$yidx2]) && is_numeric($r[$yidx2])) ? (float)$r[$yidx2] : 0;
                if (!isset($seriesdata2[$sname2])) {
                    $seriesdata2[$sname2] = [];
                }
                $seriesdata2[$sname2][$xlabel] = $yval2;
            }
        }

        if (empty($labels) || empty($seriesdata)) {
            return '';
        }

        // カラーパレット（Y軸用 / Y2軸用で色を分ける）
        $palette = [
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

        // datasets を構築（Y軸系列）
        $datasets   = [];
        $colorindex = 0;
        foreach ($seriesdata as $sname => $xmap) {
            $color  = $palette[$colorindex % count($palette)];
            $ydata  = [];
            foreach ($labels as $xlabel) {
                $ydata[] = isset($xmap[$xlabel]) ? $xmap[$xlabel] : null;
            }
            // '__single__' は未グループ時の内部キーなのでラベルを空にする
            $displaylabel = ($sname === '__single__') ? '' : (string)$sname;
            $dataset = [
                'label'           => $displaylabel,
                'data'            => $ydata,
                'borderColor'     => $color['border'],
                'backgroundColor' => $filled ? $color['bg'] : 'transparent',
                'borderWidth'     => 2,
                'tension'         => $smooth ? 0.4 : 0.0,
                'fill'            => $filled,
                'spanGaps'        => true,
                'yAxisID'         => 'y',
            ];
            $datasets[] = $dataset;
            $colorindex++;
        }

        // datasets を構築（Y2軸系列）
        $colorindex2 = 0;
        foreach ($seriesdata2 as $sname => $xmap) {
            $color  = $palette2[$colorindex2 % count($palette2)];
            $ydata  = [];
            foreach ($labels as $xlabel) {
                $ydata[] = isset($xmap[$xlabel]) ? $xmap[$xlabel] : null;
            }
            $displaylabel2 = ($sname === '__single2__') ? '' : (string)$sname;
            $dataset = [
                'label'           => $displaylabel2,
                'data'            => $ydata,
                'borderColor'     => $color['border'],
                'backgroundColor' => $filled ? $color['bg'] : 'transparent',
                'borderWidth'     => 2,
                'tension'         => $smooth ? 0.4 : 0.0,
                'fill'            => $filled,
                'spanGaps'        => true,
                'yAxisID'         => ($dualaxis && $has_y2) ? 'y2' : 'y',
            ];
            $datasets[] = $dataset;
            $colorindex2++;
        }

        // スケール設定
        $scales = ['y' => ['beginAtZero' => true, 'position' => 'left']];
        if ($dualaxis && $has_y2) {
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
     *
     * @return array
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
