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
    // 集計ロジック
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
    // ピボット変換
    // =========================================================================

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

        $labelorder  = [];
        $seriesorder = [];
        $rawdata     = [];

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
            if ($agg !== 'count' && !is_numeric($value)) {
                $value = 0.0;
            }
            $rawdata[$xlabel][$seriesval][] = is_numeric($value) ? (float)$value : 1.0;
        }

        $result = ['__labels__' => $labelorder];
        foreach ($seriesorder as $sname) {
            $values = [];
            foreach ($labelorder as $xlabel) {
                $vals     = $rawdata[$xlabel][$sname] ?? [];
                $values[] = empty($vals) ? 0 : round((float)$this->aggregate($vals, $agg), 4);
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

        $charttype  = !empty($data->charttype)    ? $data->charttype       : 'bar';
        $width      = !empty($data->width)        ? (int)$data->width      : 900;
        $height     = !empty($data->height)       ? (int)$data->height     : 500;
        $horizontal = !empty($data->bardirection) && $data->bardirection   === 'horizontal';
        $stacked    = !empty($data->bargrouping)  && $data->bargrouping    === 'stacked';
        $showlegend = !isset($data->show_legend)  || !empty($data->show_legend);

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

        $actualtype  = ($charttype === 'area') ? 'line' : $charttype;
        $axisoptions = ['beginAtZero' => true];
        if ($stacked) {
            $axisoptions['stacked'] = true;
        }

        $chartoptions = [
            'responsive'          => true,
            'maintainAspectRatio' => false,
            'plugins' => [
                'legend' => ['display' => $showlegend, 'position' => 'top'],
            ],
            'scales' => [
                'x' => $axisoptions,
                'y' => $axisoptions,
            ],
        ];
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

    public function get_series(): array {
        return [];
    }
}
