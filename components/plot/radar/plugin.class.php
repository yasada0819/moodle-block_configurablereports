<?php
defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot . '/blocks/configurable_reports/plugin.class.php');

/**
 * Class plugin_radar
 *
 * 系列ごとに列・集計方法・凡例ラベルを個別設定できるレーダーチャート。
 *
 * フォームデータ：
 *   label_field    : ラベル列（軸の名前）
 *   series_field[] : 各系列の列
 *   series_agg[]   : 各系列の集計方法
 *   series_label[] : 各系列の凡例ラベル（任意）
 *   nahandling     : NAの扱い（exclude / zero）
 *   scalemin/max   : スケール設定
 *
 * 集計方法：none / count / sum / avg / min / q1 / median / q3 / max
 */
class plugin_radar extends plugin_base {

    public function init(): void {
        $this->fullname = "Radar chart";
        $this->form = true;
        $this->ordering = true;
        $this->reporttypes = ['courses', 'sql', 'users', 'timeline', 'categories'];
    }

    public function summary(object $data): string {
        return "Radar chart summary";
    }

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
            default:       return array_sum($values); // none含む
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
     *   '__labels__' => [軸ラベル, ...]
     *   '系列名'     => [値, ...]
     */
    protected function build_series(object $data, array $finalreport): array {
        if (!$finalreport) {
            return [];
        }

        [$labelidx] = explode(',', $data->label_field);
        $labelidx   = (int)$labelidx;
        $nahandling = !empty($data->nahandling) ? $data->nahandling : 'exclude';

        // 系列定義を配列として取得
        $seriesfields = is_array($data->series_field) ? $data->series_field : [];
        $seriesaggs   = is_array($data->series_agg)   ? $data->series_agg   : [];
        $serieslabels = is_array($data->series_label) ? $data->series_label : [];

        if (empty($seriesfields)) {
            return [];
        }

        // ラベルの出現順を収集
        $labelorder = [];
        foreach ($finalreport as $r) {
            $label = $r[$labelidx];
            if (!in_array($label, $labelorder, true)) {
                $labelorder[] = $label;
            }
        }

        // 系列ごと・ラベルごとに値を積み上げる
        // rawdata[$seriesindex][$label][] = value
        $rawdata = [];
        foreach ($seriesfields as $si => $sf) {
            if (empty($sf)) {
                continue;
            }
            [$colidx] = explode(',', $sf);
            $colidx   = (int)$colidx;
            $agg      = $seriesaggs[$si] ?? 'none';

            foreach ($finalreport as $r) {
                $label = $r[$labelidx];
                $value = $r[$colidx] ?? null;

                if (!is_numeric($value)) {
                    if ($nahandling === 'zero') {
                        $value = 0.0;
                    } else {
                        continue; // exclude
                    }
                }

                $rawdata[$si][$label][] = (float)$value;
            }
        }

        // 結果配列を構築
        $result = ['__labels__' => $labelorder];

        foreach ($seriesfields as $si => $sf) {
            if (empty($sf)) {
                continue;
            }
            [$colidx, $colname] = explode(',', $sf);
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

            $values = [];
            foreach ($labelorder as $lbl) {
                $vals = $rawdata[$si][$lbl] ?? [];
                if ($agg === 'none') {
                    // 集計なし：最初の値を使う
                    $values[] = !empty($vals) ? $vals[0] : 0;
                } else {
                    $values[] = round($this->aggregate($vals, $agg), 4);
                }
            }

            $result[$uniquelabel] = $values;
        }

        return $result;
    }

    public function execute($id, $data, $finalreport) {
        $series = $this->build_series($data, $finalreport);
        if (empty($series)) {
            return '';
        }

        $labels = $series['__labels__'];
        unset($series['__labels__']);

        $width  = property_exists($data, 'width')  ? (int)$data->width  : 500;
        $height = property_exists($data, 'height') ? (int)$data->height : 500;

        $scaleoptions = ['beginAtZero' => true];
        if (property_exists($data, 'scalemin') && $data->scalemin !== '') {
            $scaleoptions['min'] = (float)$data->scalemin;
        }
        if (property_exists($data, 'scalemax') && $data->scalemax !== '') {
            $scaleoptions['max'] = (float)$data->scalemax;
        }

        $palette = [
            ['bg' => 'rgba(54,  162, 235, 0.2)', 'border' => 'rgba(54,  162, 235, 1)'],
            ['bg' => 'rgba(255, 99,  132, 0.2)', 'border' => 'rgba(255, 99,  132, 1)'],
            ['bg' => 'rgba(75,  192, 192, 0.2)', 'border' => 'rgba(75,  192, 192, 1)'],
            ['bg' => 'rgba(255, 205, 86,  0.2)', 'border' => 'rgba(255, 205, 86,  1)'],
            ['bg' => 'rgba(153, 102, 255, 0.2)', 'border' => 'rgba(153, 102, 255, 1)'],
            ['bg' => 'rgba(255, 159, 64,  0.2)', 'border' => 'rgba(255, 159, 64,  1)'],
            ['bg' => 'rgba(201, 203, 207, 0.2)', 'border' => 'rgba(201, 203, 207, 1)'],
        ];

        $datasets   = [];
        $colorindex = 0;
        foreach ($series as $name => $values) {
            $color      = $palette[$colorindex % count($palette)];
            $datasets[] = [
                'label'                => $name,
                'data'                 => array_values($values),
                'backgroundColor'      => $color['bg'],
                'borderColor'          => $color['border'],
                'borderWidth'          => 2,
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

    public function get_series(): array {
        return [];
    }

}