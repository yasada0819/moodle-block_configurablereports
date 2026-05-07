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
 * Class plugin_radar
 *
 * SQLの出力結果をレーダーチャートとして描画するプラグイン。
 * Chart.js 専用（pChart非対応）。
 *
 * データ構造の前提：
 *   - ラベル列（軸の名前：例 "読解力", "計算力" など）
 *   - 1つ以上の値列（系列：例 学習者ごとのスコア）
 *
 * @package   block_configurable_reports
 */
class plugin_radar extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = "Radar chart";
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
        return "Radar chart summary";
    }

    /**
     * Build the series array from finalreport data.
     * barプラグインと同じ構造。
     *
     * @param object $data
     * @param array  $finalreport
     * @return array ['LabelColumnName' => [...labels...], 'Series1' => [...values...], ...]
     */
    protected function build_series(object $data, array $finalreport): array {
        $series = [];
        if (!$finalreport) {
            return $series;
        }

        [$labelidx, $labelname] = explode(',', $data->label_field);
        $series[$labelname] = [];

        if (!is_array($data->value_fields)) {
            $data->value_fields = [$data->value_fields];
        }

        foreach ($finalreport as $r) {
            $series[$labelname][] = $r[$labelidx];
            foreach ($data->value_fields as $valuefields) {
                [$idx, $name] = explode(',', $valuefields);
                $value = $r[$idx];

                if ($idx == $labelidx) {
                    debugging(
                        "moodle:configurable_reports:radar:  refusing to chart label field",
                        DEBUG_DEVELOPER
                    );
                    continue;
                }

                if (!is_numeric($value)) {
                    debugging(
                        "moodle:configurable_reports:radar:  substituting 0 for non-numeric value '$value'",
                        DEBUG_DEVELOPER
                    );
                    $value = 0;
                }

                if (!array_key_exists($name, $series)) {
                    $series[$name] = [];
                }
                $series[$name][] = $value;
            }
        }

        return $series;
    }

    /**
     * Execute
     *
     * radar は Chart.js 専用。pChart 設定でも Chart.js で描画する。
     * report.class.php の print_graphs() が返り値の先頭文字で判定するため、
     * 常に HTML 文字列を返す。
     *
     * @param int    $id
     * @param object $data
     * @param array  $finalreport
     * @return string HTML fragment
     */
    public function execute($id, $data, $finalreport) {
        $series = $this->build_series($data, $finalreport);

        if (empty($series)) {
            return '';
        }

        // 先頭キーがラベル列。array_shift で取り出し、残りがデータ系列。
        $labels = array_shift($series);

        $width  = property_exists($data, 'width')  ? (int)$data->width  : 500;
        $height = property_exists($data, 'height') ? (int)$data->height : 500;

        // スケール設定（min/max が指定されていれば反映）
        $scaleoptions = ['beginAtZero' => true];
        if (property_exists($data, 'scalemin') && $data->scalemin !== '') {
            $scaleoptions['min'] = (float)$data->scalemin;
        }
        if (property_exists($data, 'scalemax') && $data->scalemax !== '') {
            $scaleoptions['max'] = (float)$data->scalemax;
        }

        // カラーパレット（radarは塗りつぶしがあるので透過度高め）
        $palette = [
            ['bg' => 'rgba(54,  162, 235, 0.2)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(255, 99,  132, 0.2)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.2)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.2)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.2)', 'border' => 'rgba(153, 102, 255, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.2)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.2)', 'border' => 'rgba(201, 203, 207, 1)'],
        ];

        // datasets 配列を構築
        $datasets = [];
        $colorindex = 0;
        foreach ($series as $name => $values) {
            $color = $palette[$colorindex % count($palette)];
            $datasets[] = [
                'label'           => $name,
                'data'            => array_values($values),
                'backgroundColor' => $color['bg'],
                'borderColor'     => $color['border'],
                'borderWidth'     => 2,
                'pointBackgroundColor' => $color['border'],
            ];
            $colorindex++;
        }

        $chartconfig = json_encode([
            'type' => 'radar',
            'data' => [
                'labels'   => array_values($labels),
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'top'],
                ],
                'scales' => [
                    'r' => $scaleoptions,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $canvasid = 'cr_radar_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

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
     * radar は graph.php 経由の pChart 描画を使わないため、
     * このメソッドは使用されない。互換性のためのスタブとして残す。
     *
     * @return array
     */
    public function get_series(): array {
        return [];
    }

}
