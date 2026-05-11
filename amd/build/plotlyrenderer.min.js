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
 * Plotly.js renderer for block_configurable_reports
 *
 * plugin.class.php から js_call_amd() で呼ばれる。
 * ページ内の class="cr-plotly-pending" な div を一括初期化する。
 *
 * Plotly.js 本体は plugin.class.php 側で $PAGE->requires->js() により
 * グローバルスコープに読み込まれるため、ここでは window.Plotly を参照する。
 * （Moodleコア同梱ではないため AMD define の依存には含めない）
 *
 * 配置場所: blocks/configurable_reports/amd/src/plotlyrenderer.js
 * gruntなし環境では手動コピー:
 *   cp amd/src/plotlyrenderer.js amd/build/plotlyrenderer.min.js
 *
 * @module     block_configurable_reports/plotlyrenderer
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    return {
        /**
         * ページ内の未初期化 div.cr-plotly-pending を一括で Plotly 初期化する。
         *
         * Plotly.js はグローバル（window.Plotly）として読まれている前提。
         * まだ読み込み中の場合は 100ms 後にリトライする（最大10回）。
         *
         * @param {number} [retryCount=0] 内部用リトライカウンタ
         */
        initAll: function(retryCount) {
            var self = this;
            retryCount = retryCount || 0;

            // Plotly.js がまだ読み込まれていない場合はリトライ
            if (typeof window.Plotly === 'undefined') {
                if (retryCount < 10) {
                    setTimeout(function() {
                        self.initAll(retryCount + 1);
                    }, 100);
                } else {
                    // eslint-disable-next-line no-console
                    console.error('cr plotlyrenderer: Plotly.js not loaded after retries.');
                }
                return;
            }

            var divs = document.querySelectorAll('div.cr-plotly-pending');
            divs.forEach(function(div) {
                var configJson = div.getAttribute('data-plotly-config');
                if (!configJson) {
                    return;
                }
                try {
                    var config = JSON.parse(configJson);
                    var traces = config.data   || [];
                    var layout = config.layout || {};
                    var opts   = config.plotlyconfig || {responsive: true, displaylogo: false};

                    window.Plotly.newPlot(div, traces, layout, opts);
                    div.classList.remove('cr-plotly-pending');
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('cr plotlyrenderer: failed to initialize chart', e);
                }
            });
        },
    };
});
