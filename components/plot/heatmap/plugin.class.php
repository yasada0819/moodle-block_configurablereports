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
 * Configurable Reports - Heatmap plugin (Plotly.js)
 *
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');

/**
 * Class plugin_heatmap
 *
 * ロング型データ（x列・y列・値列）からヒートマップを生成する。
 * Plotly.js 専用。graphlibrary = 'plotly' のときのみ動作する。
 *
 * データ変換の流れ：
 *   finalreport（行×列の2次元配列）
 *     → x/y/value列を取り出してロング→ワイド変換
 *     → 同一セルが複数行ある場合は value_agg で集計
 *     → Plotly の heatmap トレース形式（z: 2次元配列）に変換
 *     → JSON として <div data-plotly-config="..."> に埋め込む
 *     → plotlyrenderer.js の initAll() が Plotly.newPlot() を呼び出す
 *
 * @package   block_configurable_reports
 */
class plugin_heatmap extends plugin_base {

    public function init(): void {
        $this->fullname    = 'Heatmap (Plotly)';
        $this->form        = true;
        $this->ordering    = true;
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    public function summary(object $data): string {
        $cs = !empty($data->colorscale) ? $data->colorscale : 'Viridis';
        return "Heatmap ({$cs})";
    }

    // =========================================================================
    // 集計ロジック（tiledchart / radar と同じパターン）
    // =========================================================================

    protected function aggregate(array $values, string $method) {
        $n = count($values);
        if ($n === 0) {
            return null;
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
            return (float)$values[$lo];
        }
        return $values[$lo] + ($idx - $lo) * ($values[$hi] - $values[$lo]);
    }

    // =========================================================================
    // データ構築：ロング→ワイド変換
    // =========================================================================

    /**
     * finalreport をヒートマップ用の z 配列に変換する。
     *
     * 返り値：
     * [
     *   'x'      => [X軸ラベル, ...],      // 列方向
     *   'y'      => [Y軸ラベル, ...],      // 行方向
     *   'z'      => [[値or null, ...], ...] // y×x の2次元配列
     * ]
     */
    protected function build_matrix(object $data, array $finalreport): array {
        if (empty($finalreport)) {
            return ['x' => [], 'y' => [], 'z' => []];
        }

        [$xidx]     = explode(',', $data->x_field);
        [$yidx]     = explode(',', $data->y_field);
        [$validx]   = explode(',', $data->value_field);
        $xidx       = (int)$xidx;
        $yidx       = (int)$yidx;
        $validx     = (int)$validx;
        $agg        = !empty($data->value_agg)  ? $data->value_agg  : 'avg';
        $nahandling = !empty($data->nahandling) ? $data->nahandling : 'exclude';

        // ロング形式を raw[$ylabel][$xlabel][] に積み上げ
        $raw    = [];
        $xlabels = [];
        $ylabels = [];

        foreach ($finalreport as $r) {
            $xlabel = (string)($r[$xidx]  ?? '');
            $ylabel = (string)($r[$yidx]  ?? '');
            $value  = $r[$validx] ?? null;

            // Xラベル・Yラベルの出現順を記録
            if (!in_array($xlabel, $xlabels, true)) {
                $xlabels[] = $xlabel;
            }
            if (!in_array($ylabel, $ylabels, true)) {
                $ylabels[] = $ylabel;
            }

            // 数値チェック
            if (!is_numeric($value)) {
                if ($nahandling === 'zero') {
                    $value = 0.0;
                } else {
                    continue; // exclude: この行は集計から除外
                }
            }

            $raw[$ylabel][$xlabel][] = (float)$value;
        }

        // 集計して z 配列を構築（y × x の順）
        $z = [];
        foreach ($ylabels as $ylabel) {
            $row = [];
            foreach ($xlabels as $xlabel) {
                if (!empty($raw[$ylabel][$xlabel])) {
                    $row[] = $this->aggregate($raw[$ylabel][$xlabel], $agg);
                } else {
                    $row[] = null; // セルが空：Plotlyはnullを空白として表示
                }
            }
            $z[] = $row;
        }

        return [
            'x' => $xlabels,
            'y' => $ylabels,
            'z' => $z,
        ];
    }

    // =========================================================================
    // HTML出力
    // =========================================================================

    public function execute($id, $data, $finalreport) {
        global $PAGE;

        // graphlibrary チェック（このプラグインは plotly 専用）
        $library = get_config('block_configurable_reports', 'graphlibrary');
        if ($library !== 'plotly') {
            return html_writer::tag('p',
                get_string('heatmap_plotly_only', 'block_configurable_reports'),
                ['class' => 'alert alert-warning']
            );
        }

        // Plotly.js を読み込む（ページ内1回だけ）
        $plotlyurl = get_config('block_configurable_reports', 'plotlycdnurl');
        if (empty($plotlyurl)) {
            $plotlyurl = 'https://cdn.plot.ly/plotly-3.5.1.min.js';
        }
        $PAGE->requires->js(new moodle_url($plotlyurl), true);

        // plotlyrenderer.js を AMD で読み込む
        $PAGE->requires->js_call_amd('block_configurable_reports/plotlyrenderer', 'initAll');

        // データ変換（$data はフォーム設定値オブジェクト、$finalreport はデータ配列）
        $rows = is_array($finalreport) ? $finalreport : [];
        $matrix = $this->build_matrix($data, $rows);

        if (empty($matrix['x'])) {
            return html_writer::tag('p',
                get_string('nodata', 'block_configurable_reports'),
                ['class' => 'alert alert-info']
            );
        }

        // Plotly トレース設定
        $colorscale  = !empty($data->colorscale)   ? $data->colorscale   : 'Viridis';
        $reversescale = !empty($data->reversescale) ? (bool)$data->reversescale : false;

        $config = [
            'type'   => 'heatmap',
            'data'   => [
                [
                    'type'         => 'heatmap',
                    'x'            => $matrix['x'],
                    'y'            => $matrix['y'],
                    'z'            => $matrix['z'],
                    'colorscale'   => $colorscale,
                    'reversescale' => $reversescale,
                    'hoverongaps'  => false,
                ],
            ],
            'layout' => [
                'margin'    => ['l' => 80, 'r' => 20, 't' => 20, 'b' => 80],
                'xaxis'     => ['tickangle' => -45],
                'paper_bgcolor' => 'rgba(0,0,0,0)',
                'plot_bgcolor'  => 'rgba(0,0,0,0)',
            ],
            'plotlyconfig' => [
                'responsive'   => true,
                'displaylogo'  => false,
            ],
        ];

        // <div data-plotly-config="..."> に埋め込む
        // plotlyrenderer.js がこのdivを検索して Plotly.newPlot() を呼び出す
        $attrs = [
            'class'              => 'cr-plotly-heatmap',
            'data-plotly-config' => json_encode($config),
            'style'              => 'width:100%; min-height:400px;',
        ];

        return html_writer::div('', 'cr-plotly-pending', $attrs);
    }
}