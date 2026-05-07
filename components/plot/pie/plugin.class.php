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
 * Class plugin_pie
 *
 * @package   block_configurable_reports
 * @author    Juan leyva <http://www.twitter.com/jleyvadelgado>
 */
class plugin_pie extends plugin_base {

    /**
     * Init
     *
     * @return void
     */
    public function init(): void {
        $this->fullname = get_string('pie', 'block_configurable_reports');
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
        return get_string('piesummary', 'block_configurable_reports');
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
            foreach ($finalreport as $r) {
                if ($data->areaname == $data->areavalue) {
                    $hash = md5(strtolower($r[$data->areaname]));
                    if (isset($series[0][$hash])) {
                        $series[1][$hash] += 1;
                    } else {
                        $series[0][$hash] = str_replace(',', '', $r[$data->areaname]);
                        $series[1][$hash] = 1;
                    }
                } else if (!isset($data->group) || !$data->group) {
                    $series[0][] = str_replace(',', '', $r[$data->areaname]);
                    $series[1][] = (isset($r[$data->areavalue]) && is_numeric($r[$data->areavalue])) ? $r[$data->areavalue] : 0;
                } else {
                    $hash = md5(strtolower($r[$data->areaname]));
                    if (isset($series[0][$hash])) {
                        $series[1][$hash] += (isset($r[$data->areavalue]) && is_numeric($r[$data->areavalue])) ?
                            $r[$data->areavalue] : 0;
                    } else {
                        $series[0][$hash] = str_replace(',', '', $r[$data->areaname]);
                        $series[1][$hash] =
                            (isset($r[$data->areavalue]) && is_numeric($r[$data->areavalue])) ? $r[$data->areavalue] : 0;
                    }
                }
            }
        }

        $colors = [];
        $mappedcolors = [];
        $unmappedcolors = [];

        if (!empty($data->{'piechart_label'})) {
            $length = count($data->{'piechart_label'});
            for ($i = 0; $i < $length; $i++) {
                if (!empty($data->{'piechart_label'}[$i])) {
                    $key = $data->{'piechart_label'}[$i];
                    $colorcode = ltrim($data->{'piechart_label_color'}[$i], '#');
                    $mappedcolors[$key] = $colorcode;
                }
            }
        }
        $mappedcolorkeys = array_keys($mappedcolors);

        if (!empty($data->{'generalcolorpalette'})) {
            $rawunmappedcolors = explode(PHP_EOL, $data->{'generalcolorpalette'});
            foreach ($rawunmappedcolors as $rawcolor) {
                if (!empty($rawcolor)) {
                    $unmappedcolors[] = ltrim(trim($rawcolor), '#');
                }
            }
        }

        $serie0sorted = [];
        $serie1sorted = [];
        $i = 0;
        $unmappedindex = 0;
        $unmappedcolorcount = count($unmappedcolors);
        foreach ($series[0] as $index => $serie) {
            $serie = strip_tags($serie);
            $serie0sorted[] = $serie;
            $serie1sorted[] = $series[1][$index];
            if (in_array($serie, $mappedcolorkeys)) {
                $colors[$i] = $this->parse_color($mappedcolors[$serie]);
            } else if ($unmappedindex < $unmappedcolorcount) {
                $colors[$i] = $this->parse_color($unmappedcolors[$unmappedindex]);
                $unmappedindex++;
            } else {
                $colors[$i] = '';
            }
            $i++;
        }

        $serie0 = base64_encode(strip_tags(implode(',', $serie0sorted)));
        $serie1 = base64_encode(implode(',', $serie1sorted));
        $colorpalette = base64_encode(implode(',', $colors));

        return $CFG->wwwroot . '/blocks/configurable_reports/components/plot/pie/graph.php?reportid=' . $this->report->id . '&id=' .
            $id . '&serie0=' . $serie0 . '&serie1=' . $serie1 . '&colorpalette=' . $colorpalette . '&courseid=' . $this->report->courseid;
    }

    /**
     * Execute (Chart.js モード)
     *
     * 既存のカラーパレット設定（piechart_label / generalcolorpalette）を Chart.js でも活かす。
     * ラベルに対応する色が設定されていれば優先使用し、なければデフォルトパレットを使用する。
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

        $width    = property_exists($data, 'width')    ? (int)$data->width    : 500;
        $height   = property_exists($data, 'height')   ? (int)$data->height   : 500;
        $doughnut = !empty($data->doughnut);
        $type     = $doughnut ? 'doughnut' : 'pie';

        // --- データ集計（既存ロジックと同等） ---
        $series = [0 => [], 1 => []];
        if ($finalreport) {
            foreach ($finalreport as $r) {
                if ($data->areaname == $data->areavalue) {
                    $hash = md5(strtolower($r[$data->areaname]));
                    if (isset($series[0][$hash])) {
                        $series[1][$hash] += 1;
                    } else {
                        $series[0][$hash] = strip_tags($r[$data->areaname]);
                        $series[1][$hash] = 1;
                    }
                } else if (!isset($data->group) || !$data->group) {
                    $series[0][] = strip_tags($r[$data->areaname]);
                    $series[1][] = (isset($r[$data->areavalue]) && is_numeric($r[$data->areavalue])) ?
                        (float)$r[$data->areavalue] : 0;
                } else {
                    $hash = md5(strtolower($r[$data->areaname]));
                    if (isset($series[0][$hash])) {
                        $series[1][$hash] += (isset($r[$data->areavalue]) && is_numeric($r[$data->areavalue])) ?
                            (float)$r[$data->areavalue] : 0;
                    } else {
                        $series[0][$hash] = strip_tags($r[$data->areaname]);
                        $series[1][$hash] = (isset($r[$data->areavalue]) && is_numeric($r[$data->areavalue])) ?
                            (float)$r[$data->areavalue] : 0;
                    }
                }
            }
        }

        if (empty($series[0])) {
            return '';
        }

        $labels = array_values($series[0]);
        $values = array_values($series[1]);

        // --- カラーパレットの構築 ---
        // 既存のpiechart_label設定をラベル→HEX色のマップとして使う
        $mappedcolors = [];
        if (!empty($data->{'piechart_label'})) {
            $length = count($data->{'piechart_label'});
            for ($i = 0; $i < $length; $i++) {
                if (!empty($data->{'piechart_label'}[$i]) && !empty($data->{'piechart_label_color'}[$i])) {
                    $mappedcolors[$data->{'piechart_label'}[$i]] = $data->{'piechart_label_color'}[$i];
                }
            }
        }

        // generalcolorpalette をフォールバック用の順序付き色リストとして使う
        $unmappedcolors = [];
        if (!empty($data->{'generalcolorpalette'})) {
            foreach (explode(PHP_EOL, $data->{'generalcolorpalette'}) as $rawcolor) {
                $rawcolor = trim($rawcolor);
                if (!empty($rawcolor)) {
                    $unmappedcolors[] = $rawcolor;
                }
            }
        }

        // Chart.js デフォルトパレット（上記どちらも設定されていない場合）
        $defaultpalette = [
            'rgba(54,  162, 235, 0.8)',
            'rgba(255, 99,  132, 0.8)',
            'rgba(75,  192, 192, 0.8)',
            'rgba(255, 205, 86,  0.8)',
            'rgba(153, 102, 255, 0.8)',
            'rgba(255, 159, 64,  0.8)',
            'rgba(201, 203, 207, 0.8)',
        ];

        // ラベルごとに色を決定
        $backgroundcolors = [];
        $unmappedindex    = 0;
        foreach ($labels as $label) {
            if (isset($mappedcolors[$label])) {
                // ラベル指定色（HEX → rgba変換）
                $hex = ltrim($mappedcolors[$label], '#');
                $r   = hexdec(substr($hex, 0, 2));
                $g   = hexdec(substr($hex, 2, 2));
                $b   = hexdec(substr($hex, 4, 2));
                $backgroundcolors[] = "rgba($r, $g, $b, 0.8)";
            } else if ($unmappedindex < count($unmappedcolors)) {
                // generalcolorpalette の色（HEX → rgba変換）
                $hex = ltrim($unmappedcolors[$unmappedindex], '#');
                $r   = hexdec(substr($hex, 0, 2));
                $g   = hexdec(substr($hex, 2, 2));
                $b   = hexdec(substr($hex, 4, 2));
                $backgroundcolors[] = "rgba($r, $g, $b, 0.8)";
                $unmappedindex++;
            } else {
                // デフォルトパレット
                $backgroundcolors[] = $defaultpalette[count($backgroundcolors) % count($defaultpalette)];
            }
        }

        $chartconfig = json_encode([
            'type' => $type,
            'data' => [
                'labels'   => $labels,
                'datasets' => [[
                    'data'            => $values,
                    'backgroundColor' => $backgroundcolors,
                    'borderWidth'     => 1,
                ]],
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'plugins' => [
                    'legend' => ['position' => 'right'],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE);

        $canvasid = 'cr_pie_' . $id . '_' . substr(md5(uniqid('', true)), 0, 8);

        $html  = '<div style="position:relative; width:' . $width . 'px; height:' . $height . 'px;">';
        $html .= '<canvas id="' . $canvasid . '"'
               . ' data-chartjs-config="' . htmlspecialchars($chartconfig, ENT_QUOTES, 'UTF-8') . '"'
               . ' class="cr-chartjs-pending"></canvas>';
        $html .= '</div>';

        return $html;
    }

    /**
     * get_series（pChart の graph.php から呼ばれる・変更なし）
     *
     * @return array
     */
    public function get_series(): array {
        $serie0 = required_param('serie0', PARAM_RAW);
        $serie1 = required_param('serie1', PARAM_RAW);

        return [explode(',', base64_decode($serie0)), explode(',', base64_decode($serie1))];
    }

    /**
     * get_color_palette（pChart の graph.php から呼ばれる・変更なし）
     *
     * @return array|string[]|null
     */
    public function get_color_palette(): ?array {
        if ($colorpalette = optional_param('colorpalette', '', PARAM_RAW)) {
            $colorpalette = explode(',', base64_decode($colorpalette));
            foreach ($colorpalette as $index => $item) {
                if (!empty($item)) {
                    $colorpalette[$index] = explode('|', $item);
                } else {
                    unset($colorpalette[$index]);
                }
            }
            return $colorpalette;
        }
        return null;
    }

    /**
     * parse_color（pChart モード用・変更なし）
     *
     * @param string $colorcode
     * @return string
     */
    public function parse_color(string $colorcode): string {
        return implode(
            '|',
            array_map(
                function ($c) {
                    return hexdec(str_pad($c, 2, $c));
                },
                str_split($colorcode, strlen($colorcode) > 4 ? 2 : 1)
            )
        );
    }

}
