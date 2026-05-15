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
 * Japanese language strings for Configurable Reports (chartjs fork additions)
 *
 * This file contains only the strings added in the chartjs branch.
 * All other strings are provided by the Moodle Language Pack.
 *
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// --- coursecustomfield permission plugin ---
$string['coursecustomfield']                   = 'コースカスタムフィールドの値';
$string['coursecustomfield_shortname']         = 'カスタムフィールド';
$string['coursecustomfield_value']             = '期待する値';
$string['coursecustomfield_value_help']        = '照合する値を入力してください。チェックボックス型は 1（オン）または 0（オフ）、セレクト型はラベルではなくオプション値を入力してください。';
$string['coursecustomfield_error_field']       = '指定したショートネームのカスタムフィールドが存在しません。';
 

// ChartJS 設定
$string['graphlibrary']      = 'グラフライブラリ';
$string['graphlibrary_desc'] = 'グラフの描画に使用するライブラリを選択します。Chart.js はブラウザ上でインタラクティブに描画します。pChart は PNG 画像として描画します（レガシー）。';

// テンプレートエディタ設定
$string['templateeditor']         = 'テンプレートエディタ';
$string['templateeditor_desc']    = 'テンプレートエディタのモードを選択します。';
$string['templateeditor_classic'] = 'クラシック（テキストエディタ）';
$string['templateeditor_gui']     = 'GUIビルダー';

// Chart.js 共通
$string['head_chartjs_options'] = 'Chart.js オプション';

// 棒グラフ
$string['bardirection']            = '棒の向き';
$string['bardirection_help']       = '棒グラフの向きを選択します。';
$string['bardirection_vertical']   = '縦';
$string['bardirection_horizontal'] = '横';
$string['bargrouping']             = 'グループ分け';
$string['bargrouping_help']        = '複数の系列を横並べにするか積み上げにするかを選択します。';
$string['bargrouping_grouped']     = '横並べ';
$string['bargrouping_stacked']     = '積み上げ';
$string['reversedatasets']         = '系列の順番を逆にする';
$string['reversedatasets_help']    = '積み上げ順を逆にします。';
$string['histogram']               = 'ヒストグラムモード';
$string['histogram_help']          = '棒と棒の間の隙間をなくします（度数分布表などに有用）。';

// 折れ線グラフ
$string['line_smooth']       = 'スムーズ曲線';
$string['line_smooth_help']  = '直線の代わりに滑らかな曲線で結びます。';
$string['line_filled']       = 'エリア塗りつぶし';
$string['line_filled_help']  = '折れ線の下の領域を塗りつぶします。';
$string['line_serieid']      = 'Y軸1のグループ列（任意）';
$string['line_serieid2']     = 'Y軸2のグループ列（任意）';
$string['line_yaxis2']       = 'Y軸2の列（任意）';
$string['line_dualaxis']      = 'Y軸を分ける';
$string['line_dualaxis_help'] = 'Y軸1を左軸、Y軸2を右軸として分けて表示します。値の範囲が大きく異なる場合に有用です。';
$string['line_series_field']  = '列';
$string['line_series_agg']    = '集計方法';
$string['line_series_label']  = '凡例ラベル（任意）';
$string['line_series_y2']     = 'Y2軸';
$string['line_series_row']    = '系列 {$a}';

// 円グラフ
$string['pie_doughnut']      = 'ドーナツスタイル';
$string['pie_doughnut_help'] = '円グラフの代わりにドーナツチャートとして表示します。';

// バブルチャート
$string['bubble_label_field']        = 'ラベル列（任意）';
$string['bubble_label_field_help']   = 'バブルをグループ分けして色分けする列を選択します。「なし」を選択すると全バブルが同色になります。';
$string['bubble_label_none']         = 'なし（単色）';
$string['bubble_x_field']            = 'X軸列';
$string['bubble_x_field_help']       = 'X座標の値となる列（数値列）。';
$string['bubble_y_field']            = 'Y軸列';
$string['bubble_y_field_help']       = 'Y座標の値となる列（数値列）。';
$string['bubble_r_field']            = 'バブルサイズ列';
$string['bubble_r_field_help']       = 'バブルの大きさとなる列（数値列）。';
$string['bubble_rscaling']           = 'バブルサイズのスケーリング';
$string['bubble_rscaling_help']      = '自動：最大値を基準に正規化します。手動：生値をピクセルとして使用します。';
$string['bubble_rscaling_auto']      = '自動（最大サイズに正規化）';
$string['bubble_rscaling_manual']    = '手動（生値をピクセルとして使用）';
$string['bubble_maxbubblesize']      = 'バブルの最大サイズ（px）';
$string['bubble_maxbubblesize_help'] = '自動スケーリング時のバブル半径の上限（px）。デフォルト: 40。';

// レーダーチャート
$string['radar_scalemin']      = 'スケール最小値';
$string['radar_scalemin_help'] = 'レーダーのスケール最小値。空白で自動。';
$string['radar_scalemax']      = 'スケール最大値';
$string['radar_scalemax_help'] = 'レーダーのスケール最大値。空白で自動。例：パーセンテージの場合は 100。';
$string['radar_series_field']  = '列';
$string['radar_series_agg']    = '集計方法';
$string['radar_series_label']  = '凡例ラベル（任意）';
$string['radar_series_add']    = '系列を追加';
$string['radar_series_row']    = '系列 {$a}';

// コンボチャート
$string['combo_bar_fields']       = '棒グラフ系列';
$string['combo_bar_fields_help']  = '棒グラフとして表示する列を選択します（複数選択可）。';
$string['combo_line_fields']      = '折れ線系列';
$string['combo_line_fields_help'] = '折れ線グラフとして表示する列を選択します（複数選択可）。';
$string['combo_bargrouping']      = '棒のグループ分け';
$string['combo_bargrouping_help'] = '複数の棒グラフ系列の表示方法を選択します。';
$string['combo_dualaxis']         = 'Y軸を分ける';
$string['combo_dualaxis_help']    = '棒グラフを左軸、折れ線を右軸として分けます。値の範囲が大きく異なる場合に有用です。';

// タイルチャート
$string['tiledchart_type_area']     = '面グラフ';
$string['tiledchart_type_pie']      = '円グラフ';
$string['tiledchart_type_doughnut'] = 'ドーナツ';
$string['tiledchart_type_radar']    = 'レーダー';
$string['tiledchart_col1']          = '列1（X軸 / ラベル / 軸名）';
$string['tiledchart_col1_help']     = 'bar/line/area: X軸ラベル。pie/doughnut: スライスのラベル。radar: 軸の名前。';
$string['tiledchart_col2']          = '列2（Y軸 / 値）';
$string['tiledchart_col2_help']     = 'すべてのタイプで値として使用されます（Y軸の値・スライスの大きさ・軸のスコア）。';

// pivotchart プラグイン
$string['pivotchart']                  = 'ピボットチャート';
$string['pivotchart_x_field']          = 'X軸の列';
$string['pivotchart_x_field_help']     = 'X軸ラベルになる列を選択します（例：氏名、日付）。この列の値の種類がX軸の目盛りになります。';
$string['pivotchart_series_field']     = 'シリーズの列（色分け）';
$string['pivotchart_series_field_help']= '棒や線の色分けに使う列を選択します（例：科目、カテゴリ）。この列の値の種類がそれぞれ1つの色（系列）になります。';
$string['pivotchart_value_field']      = '値の列';
$string['pivotchart_value_field_help'] = 'Y軸にプロットする数値列を選択します。';
$string['pivotchart_value_agg']        = '集計方法';
$string['pivotchart_value_agg_help']   = 'X軸とシリーズの組み合わせが複数行ある場合の集計方法を選択します。合計はSum、平均はAverage、行数をカウントするにはCountを選択してください（Countの場合は値列の内容を問わず行数をカウントします）。';
 
// tiledpivot プラグイン

$string['tiledpivot']                   = 'タイルドピボットチャート';
$string['tiledpivot_tile_field']        = 'タイル分割の列';
$string['tiledpivot_tile_field_help']   = 'タイルの分割単位になる列を選択します（例：年度、クラス）。この列の値の種類がそれぞれ1つのタイルになります。';

// Tiled scatter / bubble
$string['tiledscatter_r_field']        = 'バブルサイズ列（任意）';
$string['tiledscatter_r_field_help']   = 'バブルサイズに使用する数値列を選択します。「なし」を選択すると固定サイズの散布図として表示されます。';
$string['tiledscatter_rscale']         = 'バブルサイズのスケーリング';
$string['tiledscatter_rscale_auto']    = '自動（最大値に合わせてスケール）';
$string['tiledscatter_rscale_manual']  = '手動（生の値をそのまま使用）';
$string['tiledscatter_rdefault']       = 'デフォルトの点 / バブルサイズ';
$string['tiledscatter_rdefault_help']  = 'バブル列が未選択のときの点のサイズ。自動スケーリング時の最大バブルサイズにも使用されます。';

// 集計
$string['head_aggregation']   = '集計';
$string['aggregation']        = '集計方法';
$string['aggregation_help']   = '同じラベルに複数の値がある場合の集計方法を選択します。「なし」を選択すると生の値をそのまま使用します。';
$string['aggregation_none']   = 'なし（生の値）';
$string['aggregation_count']  = '件数';
$string['aggregation_sum']    = '合計';
$string['aggregation_avg']    = '平均';
$string['aggregation_min']    = '最小値';
$string['aggregation_q1']     = 'Q1（第1四分位数）';
$string['aggregation_median'] = '中央値（Q2）';
$string['aggregation_q3']     = 'Q3（第3四分位数）';
$string['aggregation_max']    = '最大値';
$string['nahandling']         = '欠損値（NA）の扱い';
$string['nahandling_help']    = '集計時に数値以外または欠損値をどう扱うかを選択します。';
$string['nahandling_exclude'] = '集計から除外（デフォルト）';
$string['nahandling_zero']    = '0として扱う';

// テンプレートエディタ（プレースホルダー・GUIビルダー）
$string['template_ph_graphs']     = '全グラフ';
$string['template_ph_table']      = 'データテーブル';
$string['template_ph_reportname'] = 'レポート名';
$string['template_ph_pagination'] = 'ページネーション';
$string['template_ph_export']     = 'エクスポートオプション';
$string['template_gui_loading']   = 'GUIビルダーを読み込み中...';
// --- Extension ---
$string['heading_extension']      = 'グラフエンジン拡張';
$string['heading_extension_desc'] = 'Extensionプラグイン（例: block_configurable_reports_extension）を別途インストールすることで、Chart.jsグラフ・GUIテンプレートビルダー・追加権限プラグインが使用できます。';
$string['useextension']           = 'Extensionを有効にする';
$string['useextension_desc']      = '有効にすると、インストール済みのExtensionプラグインがグラフ描画・テンプレート編集・権限プラグインに使用されます。Extensionが見つからない場合は組み込みのpChartにフォールバックします。';
$string['activeextension']        = '使用するExtension';
$string['activeextension_desc']   = '複数のExtensionがインストールされている場合に、使用するExtensionを選択します。';