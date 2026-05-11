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
 * Class plugin_bar
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class plugin_bar extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = "Bar chart";
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
        return "Bar chart summary";
    }

    /**
     * Build the series array from finalreport data.
     * pChart・Chart.js 両方から共通して使う。
     *
     * @param object $data        Plugin configuration (formdata)
     * @param array  $finalreport
     * @return array  ['LabelColumnName' => [...labels...], 'Series1' => [...values...], ...]
     */
    protected function build_series(object $data, array $finalreport): array {
        $series = [];
        if (!$finalreport) {
            return $series;
        }

        [$labelidx, $labelname] = explode(",", $data->label_field);
        $series[$labelname] = [];

        if (!is_array($data->value_fields)) {
            $data->value_fields = [$data->value_fields];
        }

        foreach ($finalreport as $r) {
            $series[$labelname][] = $r[$labelidx];
            foreach ($data->value_fields as $valuefields) {
                [$idx, $name] = explode(",", $valuefields);
                $value = $r[$idx];

                if ($idx == $labelidx) {
                    debugging(
                        "moodle:configurable_reports:bar:  refusing to chart label field",
                        DEBUG_DEVELOPER
                    );
                    continue;
                }

                if (!is_numeric($value)) {
                    debugging(
                        "moodle:configurable_reports:bar:  substituting 0 for non-numeric value '$value'",
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
        if ($finalreport) {
            [$labelidx, $labelname] = explode(",", $data->label_field);
            $series[$labelname] = [];
            if (!is_array($data->value_fields)) {
                $data->value_fields = [$data->value_fields];
            }
            foreach ($finalreport as $r) {
                $series[$labelname][] = $r[$labelidx];
                foreach ($data->value_fields as $valuefields) {
                    [$idx, $name] = explode(",", $valuefields);
                    $value = $r[$idx];

                    if ($idx == $labelidx) {
                        debugging(
                            "moodle:configurable_reports:bar:  refusing to chart label field",
                            DEBUG_DEVELOPER
                        );
                        continue;
                    }

                    if (!is_numeric($value)) {
                        debugging(
                            "moodle:configurable_reports:bar:  substituting 0 for non-numeric value '$value'",
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
        }

        $graphdata = urlencode(json_encode($series));

        return $CFG->wwwroot . '/blocks/configurable_reports/components/plot/bar/graph.php?reportid=' . $this->report->id . '&id=' .
            $id . '&graphdata=' . $graphdata . '&courseid=' . $this->report->courseid;
    }

    /**
     * Execute (Chart.js モード)
     *
     * <canvas> タグに data-chartjs-config 属性でグラフ設定を持たせた
     * HTML文字列を返す。実際の描画は chartrenderer.js の initAll() が行う。
     * require() をインラインで呼ばないことで RequireJS のスコープ問題を回避する。
     *
     * @param int    $id
     * @param object $data
     * @param array  $finalreport
     * @return string HTML fragment
     */
    protected function execute_chartjs($id, $data, $finalreport): string {

        // series_field があれば新設計（5系列+集計）、なければ旧設計（label_field+value_fields）
        if (!empty($data->series_field) && is_array($data->series_field)) {
            $seriesdata = $this->build_series_chartjs($data, $finalreport);
            if (empty($seriesdata)) {
                return '';
            }
            $labels  = $seriesdata['__labels__'];
            unset($seriesdata['__labels__']);

            $palette = [
                ['bg' => 'rgba(54,  162, 235, 0.8)', 'border' => 'rgba(54,  162, 235, 1)'],
                ['bg' => 'rgba(255, 99,  132, 0.8)', 'border' => 'rgba(255, 99,  132, 1)'],
                ['bg' => 'rgba(75,  192, 192, 0.8)', 'border' => 'rgba(75,  192, 192, 1)'],
                ['bg' => 'rgba(255, 205, 86,  0.8)', 'border' => 'rgba(255, 205, 86,  1)'],
                ['bg' => 'rgba(153, 102, 255, 0.8)', 'border' => 'rgba(153, 102, 255, 1)'],
            ];

            $datasets   = [];
            $colorindex = 0;
            foreach ($seriesdata as $sname => $values) {
                $color      = $palette[$colorindex % count($palette)];
                $datasets[] = [
                    'label'           => $sname,
                    'data'            => array_values($values),
                    'backgroundColor' => $color['bg'],
                    'borderColor'     => $color['border'],
                    'borderWidth'     => 1,
                ];
                $colorindex++;
            }
        } else {
            // 旧設計：label_field + value_fields
            $series = $this->build_series($data, $finalreport);
            if (empty($series)) {
                return '';
            }
            $labels  = array_shift($series);

            $palette = [
                'rgba(54,  162, 235, 0.8)',
                'rgba(255, 99,  132, 0.8)',
                'rgba(75,  192, 192, 0.8)',
                'rgba(255, 205, 86,  0.8)',
                'rgba(153, 102, 255, 0.8)',
                'rgba(255, 159, 64,  0.8)',
                'rgba(201, 203, 207, 0.8)',
            ];

            $datasets   = [];
            $colorindex = 0;
            foreach ($series as $name => $values) {
                $color      = $palette[$colorindex % count($palette)];
                $datasets[] = [
                    'label'           => $name,
                    'data'            => array_values($values),
                    'backgroundColor' => $color,
                    'borderColor'     => str_replace('0.8', '1', $color),
                    'borderWidth'     => 1,
                ];
                $colorindex++;
            }
        }

        $width  = property_exists($data, 'width')  ? (int)$data->width  : 900;
        $height = property_exists($data, 'height') ? (int)$data->height : 500;

        // 系列の積み上げ順を逆にする
        if (!empty($data->reversedatasets)) {
            $datasets = array_reverse($datasets);
        }

        // 向き: vertical（縦）/ horizontal（横）
        $horizontal = !empty($data->bardirection) && $data->bardirection === 'horizontal';

        // グループ分け: grouped（横並べ）/ stacked（積み上げ）
        $stacked = !empty($data->bargrouping) && $data->bargrouping === 'stacked';

        // ヒストグラムモード：棒の隙間をなくす
        $histogram = !empty($data->histogram);
        if ($histogram) {
            foreach ($datasets as &$ds) {
                $ds['barPercentage']      = 1.0;
                $ds['categoryPercentage'] = 1.0;
            }
            unset($ds);
        }

        $axisoptions = ['beginAtZero' => true];
        if ($stacked) {
            $axisoptions['stacked'] = true;
        }

        $chartconfig = json_encode([
            'type' => 'bar',
            'data' => [
                'labels'   => array_values($labels),
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'indexAxis'           => $horizontal ? 'y' : 'x',
                'plugins' => [
                    'legend' => ['position' => 'top'],
                ],
                'scales' => [
                    'x' => $axisoptions,
                    'y' => $axisoptions,
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $canvasid = 'cr_bar_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

        $html  = '<div style="position:relative; width:' . $width . 'px; height:' . $height . 'px;">';
        $html .= '<canvas id="' . $canvasid . '"'
               . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
               . ' class="cr-chartjs-pending"></canvas>';
        $html .= '</div>';

        return $html;
    }

    /**
     * 新設計フォーム（5系列+集計）用のデータ構築（lineのbuild_series()と同パターン）
     */
    protected function build_series_chartjs(object $data, array $finalreport): array {
        if (!$finalreport) {
            return [];
        }

        [$xidx]       = explode(',', $data->xaxis);
        $xidx         = (int)$xidx;
        $nahandling   = !empty($data->nahandling) ? $data->nahandling : 'exclude';
        $seriesfields = is_array($data->series_field) ? $data->series_field : [];
        $seriesaggs   = is_array($data->series_agg)   ? $data->series_agg   : [];
        $serieslabels = is_array($data->series_label) ? $data->series_label : [];

        // X軸ラベルの出現順を収集
        $labelorder = [];
        foreach ($finalreport as $r) {
            $xlabel = (string)($r[$xidx] ?? '');
            if (!in_array($xlabel, $labelorder, true)) {
                $labelorder[] = $xlabel;
            }
        }

        // 系列ごと・Xラベルごとに値を積み上げる
        $rawdata = [];
        foreach ($finalreport as $r) {
            $xlabel = (string)($r[$xidx] ?? '');
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
                $rawdata[$si][$xlabel][] = (float)$value;
            }
        }

        $result = ['__labels__' => $labelorder];

        foreach ($seriesfields as $si => $sf) {
            if (empty($sf)) {
                continue;
            }
            [, $colname] = explode(',', $sf, 2);
            $agg         = $seriesaggs[$si]   ?? 'none';
            $label       = !empty($serieslabels[$si])
                ? $serieslabels[$si]
                : ($agg !== 'none' ? "$colname ($agg)" : $colname);

            // ラベル重複に連番付与
            $uniquelabel = $label;
            $suffix      = 2;
            while (array_key_exists($uniquelabel, $result)) {
                $uniquelabel = $label . ' ' . $suffix;
                $suffix++;
            }

            $values = [];
            foreach ($labelorder as $xlabel) {
                $vals = $rawdata[$si][$xlabel] ?? [];
                if (empty($vals)) {
                    $values[] = 0; // barはnullより0の方が自然
                } elseif ($agg === 'none') {
                    $values[] = $vals[0];
                } else {
                    $values[] = round((float)$this->aggregate($vals, $agg), 4);
                }
            }
            $result[$uniquelabel] = $values;
        }

        return $result;
    }

    /**
     * 値の配列を集計する（line/radar/tiledchartと共通パターン）
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

    /**
     * Get series (pChart の graph.php から呼ばれる・変更なし)
     *
     * @return array
     */
    public function get_series(): array {
        $graphdataraw = required_param('graphdata', PARAM_RAW);
        $graphdata = json_decode(urldecode($graphdataraw), false, 512, JSON_THROW_ON_ERROR);

        return (array) $graphdata;
    }

}