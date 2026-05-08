/**
 * Template GUI builder for Configurable Reports
 *
 * 行×カラム構成のレイアウトをGUIで編集し、
 * JSON形式で hidden フィールド（gui_layout）に保存する。
 *
 * セル編集はアコーディオン方式（モーダルなし）。
 * Moodle iframe環境での position:fixed 問題を回避。
 *
 * @module block_configurable_reports/templatebuilder
 */
define([], function() {

    /**
     * GUIビルダーの状態
     * rows: [ { cols: N, cells: [ { type: 'placeholder'|'html', value: '...' } ] } ]
     */
    var state = {
        rows: []
    };

    /** プレースホルダー一覧（data属性から取得） */
    var placeholders = {};

    /** コンテナ要素 */
    var container = null;

    /** hidden フィールド */
    var layoutField = null;

    /** 現在編集中のセル識別子 "rowIndex-cellIndex"、なければ null */
    var editingKey = null;

    /**
     * 状態をJSONにシリアライズしてhiddenフィールドに保存する
     */
    function saveState() {
        if (layoutField) {
            layoutField.value = JSON.stringify(state);
        }
    }

    /**
     * セルのHTMLを生成する（アコーディオン方式）
     *
     * @param {number} rowIndex
     * @param {number} cellIndex
     * @param {object} cell  { type, value }
     * @returns {string}
     */
    function renderCell(rowIndex, cellIndex, cell) {
        var key = rowIndex + '-' + cellIndex;
        var isEditing = (editingKey === key);
        var isHtml = cell.type === 'html';

        var preview = cell.value
            ? '<code style="font-size:11px;word-break:break-all;">' + escapeHtml(cell.value) + '</code>'
            : '<span style="color:var(--color-text-tertiary);font-size:12px;">空のセル</span>';

        // 通常表示
        var html = '<div class="cr-gui-cell" data-row="' + rowIndex + '" data-cell="' + cellIndex + '" '
            + 'style="border:1px solid var(--color-border-tertiary);border-radius:6px;'
            + 'background:var(--color-background-secondary);overflow:hidden;">'
            + '<div style="padding:10px;">'
            + '<div class="cr-cell-preview" style="margin-bottom:6px;">' + preview + '</div>'
            + '<button type="button" class="cr-edit-cell btn btn-sm btn-outline-secondary" '
            + 'data-row="' + rowIndex + '" data-cell="' + cellIndex + '" '
            + 'style="font-size:11px;">' + (isEditing ? '✕ 閉じる' : '✎ 編集') + '</button>'
            + '</div>';

        // アコーディオン：編集フォーム
        if (isEditing) {
            // プレースホルダー選択肢
            var phOptions = '<option value="">-- プレースホルダーを選択 --</option>';
            for (var ph in placeholders) {
                var sel = (cell.value === ph && !isHtml) ? ' selected' : '';
                phOptions += '<option value="' + escapeHtml(ph) + '"' + sel + '>'
                    + escapeHtml(ph) + ' (' + escapeHtml(placeholders[ph]) + ')</option>';
            }

            html += '<div class="cr-gui-cell-form" '
                + 'style="border-top:1px solid var(--color-border-tertiary);padding:10px;'
                + 'background:var(--color-background-primary);">'
                // 種別
                + '<div style="margin-bottom:8px;">'
                + '<label style="font-size:12px;display:block;margin-bottom:3px;">種別</label>'
                + '<select class="cr-cell-type-sel form-select form-select-sm" style="width:auto;" '
                + 'data-row="' + rowIndex + '" data-cell="' + cellIndex + '">'
                + '<option value="placeholder"' + (!isHtml ? ' selected' : '') + '>プレースホルダー</option>'
                + '<option value="html"' + (isHtml ? ' selected' : '') + '>HTML テキスト</option>'
                + '</select>'
                + '</div>'
                // プレースホルダー選択（placeholder選択時のみ表示）
                + '<div class="cr-ph-section" style="margin-bottom:8px;' + (isHtml ? 'display:none;' : '') + '">'
                + '<label style="font-size:12px;display:block;margin-bottom:3px;">プレースホルダー</label>'
                + '<select class="cr-cell-ph-sel form-select form-select-sm" '
                + 'data-row="' + rowIndex + '" data-cell="' + cellIndex + '">'
                + phOptions + '</select>'
                + '</div>'
                // HTML入力（html選択時のみ表示）
                + '<div class="cr-html-section" style="margin-bottom:8px;' + (!isHtml ? 'display:none;' : '') + '">'
                + '<label style="font-size:12px;display:block;margin-bottom:3px;">HTML</label>'
                + '<textarea class="cr-cell-html-ta form-control" rows="4" '
                + 'style="font-size:11px;font-family:monospace;" '
                + 'data-row="' + rowIndex + '" data-cell="' + cellIndex + '">'
                + escapeHtml(isHtml ? cell.value : '') + '</textarea>'
                + '</div>'
                // ボタン
                + '<div style="display:flex;gap:6px;">'
                + '<button type="button" class="cr-cell-save btn btn-sm btn-primary" '
                + 'data-row="' + rowIndex + '" data-cell="' + cellIndex + '">保存</button>'
                + '<button type="button" class="cr-cell-cancel btn btn-sm btn-outline-secondary" '
                + 'data-row="' + rowIndex + '" data-cell="' + cellIndex + '">キャンセル</button>'
                + '</div>'
                + '</div>';
        }

        html += '</div>'; // .cr-gui-cell
        return html;
    }

    /**
     * 行のHTMLを生成する
     *
     * @param {number} rowIndex
     * @param {object} row  { cols, cells }
     * @returns {string}
     */
    function renderRow(rowIndex, row) {
        var colOptions = '';
        for (var c = 1; c <= 4; c++) {
            colOptions += '<option value="' + c + '"' + (row.cols === c ? ' selected' : '') + '>'
                + c + ' カラム</option>';
        }

        var cellsHtml = '';
        var colWidth = 'calc(' + (100 / row.cols) + '% - 8px)';
        for (var i = 0; i < row.cells.length; i++) {
            cellsHtml += '<div style="flex:0 0 ' + colWidth + ';min-width:0;">'
                + renderCell(rowIndex, i, row.cells[i])
                + '</div>';
        }

        var isFirst = rowIndex === 0;
        var isLast  = rowIndex === state.rows.length - 1;

        return '<div class="cr-gui-row card mb-2" data-row="' + rowIndex + '">'
            + '<div class="card-header d-flex align-items-center gap-2" style="padding:6px 12px;">'
            + '<span style="font-size:12px;color:var(--color-text-secondary);">行 ' + (rowIndex + 1) + '</span>'
            + '<select class="cr-cols-select form-select form-select-sm" data-row="' + rowIndex + '" '
            + 'style="width:auto;">' + colOptions + '</select>'
            + '<div class="ml-auto" style="margin-left:auto;">'
            + '<button type="button" class="cr-move-up btn btn-sm btn-outline-secondary" data-row="' + rowIndex + '" '
            + 'title="上へ" ' + (isFirst ? 'disabled' : '') + '>↑</button> '
            + '<button type="button" class="cr-move-down btn btn-sm btn-outline-secondary" data-row="' + rowIndex + '" '
            + 'title="下へ" ' + (isLast ? 'disabled' : '') + '>↓</button> '
            + '<button type="button" class="cr-delete-row btn btn-sm btn-outline-danger" data-row="' + rowIndex + '">'
            + '削除</button>'
            + '</div>'
            + '</div>'
            + '<div class="card-body" style="padding:10px;">'
            + '<div class="cr-cells-container d-flex gap-2" style="flex-wrap:nowrap;">'
            + cellsHtml
            + '</div>'
            + '</div>'
            + '</div>';
    }

    /**
     * ビルダー全体を再描画する
     */
    function render() {
        if (!container) {
            return;
        }

        var html = '<div class="cr-gui-rows mb-3">';
        if (state.rows.length === 0) {
            html += '<p class="text-muted" style="font-size:13px;">行がありません。「行を追加」から始めてください。</p>';
        } else {
            for (var i = 0; i < state.rows.length; i++) {
                html += renderRow(i, state.rows[i]);
            }
        }
        html += '</div>';

        // 行追加ボタン
        html += '<button type="button" id="cr-add-row" class="btn btn-secondary btn-sm mb-3">+ 行を追加</button>';

        // プレースホルダー一覧
        html += '<div class="cr-placeholders mt-2" style="font-size:12px;color:var(--color-text-secondary);">';
        html += '<strong>利用可能なプレースホルダー：</strong><br>';
        for (var ph in placeholders) {
            html += '<code style="margin-right:8px;cursor:pointer;" class="cr-ph-badge" data-ph="' + escapeHtml(ph) + '">'
                + escapeHtml(ph) + '</code>';
        }
        html += '</div>';

        container.innerHTML = html;
        bindEvents();
        saveState();
    }

    /**
     * イベントをバインドする
     */
    function bindEvents() {
        // 行追加
        var addBtn = document.getElementById('cr-add-row');
        if (addBtn) {
            addBtn.addEventListener('click', function() {
                editingKey = null;
                state.rows.push({
                    cols: 1,
                    cells: [{ type: 'placeholder', value: '' }]
                });
                render();
            });
        }

        // カラム数変更
        document.querySelectorAll('.cr-cols-select').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var rowIndex = parseInt(this.dataset.row);
                var newCols = parseInt(this.value);
                var row = state.rows[rowIndex];

                row.cols = newCols;
                editingKey = null;

                while (row.cells.length < newCols) {
                    row.cells.push({ type: 'placeholder', value: '' });
                }
                if (row.cells.length > newCols) {
                    row.cells = row.cells.slice(0, newCols);
                }

                render();
            });
        });

        // 編集ボタン（トグル）
        document.querySelectorAll('.cr-edit-cell').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var key = this.dataset.row + '-' + this.dataset.cell;
                editingKey = (editingKey === key) ? null : key;
                render();
            });
        });

        // 種別切り替え（placeholder ↔ html）
        document.querySelectorAll('.cr-cell-type-sel').forEach(function(sel) {
            sel.addEventListener('change', function() {
                var row  = this.closest('[data-row]').dataset.row ||
                           this.dataset.row;
                var cell = this.dataset.cell;
                var isph = this.value === 'placeholder';
                var cellEl = container.querySelector(
                    '.cr-gui-cell[data-row="' + this.dataset.row + '"][data-cell="' + this.dataset.cell + '"]');
                if (cellEl) {
                    var phSec   = cellEl.querySelector('.cr-ph-section');
                    var htmlSec = cellEl.querySelector('.cr-html-section');
                    if (phSec)   { phSec.style.display   = isph ? '' : 'none'; }
                    if (htmlSec) { htmlSec.style.display  = isph ? 'none' : ''; }
                }
            });
        });

        // セル保存
        document.querySelectorAll('.cr-cell-save').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var rowIndex  = parseInt(this.dataset.row);
                var cellIndex = parseInt(this.dataset.cell);

                var cellEl = container.querySelector(
                    '.cr-gui-cell[data-row="' + rowIndex + '"][data-cell="' + cellIndex + '"]');

                var typeSel  = cellEl.querySelector('.cr-cell-type-sel');
                var phSel    = cellEl.querySelector('.cr-cell-ph-sel');
                var htmlTa   = cellEl.querySelector('.cr-cell-html-ta');

                var type  = typeSel ? typeSel.value : 'placeholder';
                var value = type === 'placeholder'
                    ? (phSel   ? phSel.value   : '')
                    : (htmlTa  ? htmlTa.value  : '');

                state.rows[rowIndex].cells[cellIndex] = { type: type, value: value };
                editingKey = null;
                render();
            });
        });

        // セルキャンセル
        document.querySelectorAll('.cr-cell-cancel').forEach(function(btn) {
            btn.addEventListener('click', function() {
                editingKey = null;
                render();
            });
        });

        // 行削除
        document.querySelectorAll('.cr-delete-row').forEach(function(btn) {
            btn.addEventListener('click', function() {
                editingKey = null;
                var rowIndex = parseInt(this.dataset.row);
                state.rows.splice(rowIndex, 1);
                render();
            });
        });

        // 行を上へ
        document.querySelectorAll('.cr-move-up').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var rowIndex = parseInt(this.dataset.row);
                if (rowIndex > 0) {
                    editingKey = null;
                    var tmp = state.rows[rowIndex - 1];
                    state.rows[rowIndex - 1] = state.rows[rowIndex];
                    state.rows[rowIndex] = tmp;
                    render();
                }
            });
        });

        // 行を下へ
        document.querySelectorAll('.cr-move-down').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var rowIndex = parseInt(this.dataset.row);
                if (rowIndex < state.rows.length - 1) {
                    editingKey = null;
                    var tmp = state.rows[rowIndex + 1];
                    state.rows[rowIndex + 1] = state.rows[rowIndex];
                    state.rows[rowIndex] = tmp;
                    render();
                }
            });
        });

        // プレースホルダーバッジクリック（クリップボードコピー）
        document.querySelectorAll('.cr-ph-badge').forEach(function(badge) {
            badge.addEventListener('click', function() {
                var ph = this.dataset.ph;
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(ph);
                }
            });
        });
    }

    /**
     * HTML エスケープ
     *
     * @param {string} str
     * @returns {string}
     */
    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#39;');
    }

    return {
        /**
         * 初期化
         */
        init: function() {
            container = document.getElementById('cr-gui-builder');
            if (!container) {
                return;
            }

            // プレースホルダー一覧を取得
            try {
                placeholders = JSON.parse(container.dataset.placeholders || '{}');
            } catch (e) {
                placeholders = {};
            }

            // hidden フィールドを取得
            layoutField = document.querySelector('input[name="gui_layout"]');

            // 既存のJSONがあれば読み込む
            if (layoutField && layoutField.value) {
                try {
                    var saved = JSON.parse(layoutField.value);
                    if (saved && saved.rows) {
                        state.rows = saved.rows;
                    }
                } catch (e) {
                    state.rows = [];
                }
            }

            render();
        }
    };
});