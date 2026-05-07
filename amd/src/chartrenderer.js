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
 * Chart.js renderer for block_configurable_reports
 *
 * print_graphs() から js_call_amd() で呼ばれる。
 * ページ内の class="cr-chartjs-pending" な canvas を一括初期化する。
 *
 * 配置場所: blocks/configurable_reports/amd/src/chartrenderer.js
 * ビルド後: blocks/configurable_reports/amd/build/chartrenderer.min.js
 *
 * @module     block_configurable_reports/chartrenderer
 * @package    block_configurable_reports
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/chartjs'], function(Chart) {

    return {
        /**
         * ページ内の未初期化 canvas を一括で Chart.js 初期化する。
         */
        initAll: function() {
            var canvases = document.querySelectorAll('canvas.cr-chartjs-pending');
            canvases.forEach(function(canvas) {
                var configJson = canvas.getAttribute('data-chartjs-config');
                if (!configJson) {
                    return;
                }
                try {
                    var config = JSON.parse(configJson);
                    new Chart(canvas, config);
                    canvas.classList.remove('cr-chartjs-pending');
                } catch (e) {
                    // eslint-disable-next-line no-console
                    console.error('cr chartrenderer: failed to initialize chart for canvas#' + canvas.id, e);
                }
            });
        },
    };
});
