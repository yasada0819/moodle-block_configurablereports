/**
 * Template GUI builder for Configurable Reports
 *
 * 行×カラム構成のレイアウトをGUIで編集し、
 * JSON形式で hidden フィールド（gui_layout）に保存する。
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

    /**
     * 状態をJSONにシリアライズしてhiddenフィールドに保存する
     */
    function saveState() {
        if (layoutField) {
            layoutField.value = JSON.stringify(state);
        }
    }

    /**
     * セルのHTMLを生成する
     *
     * @param {number} rowIndex
     * @param {number} cellIndex
     * @param {object} cell  { type, value }
     * @returns {string}
     */
    function renderCell(rowIndex, cellIndex, cell) {
        var preview = cell.value
            ? '<code style="font-size:11px;word-break:break-all;">' + escapeHtml(cell.value) + '</code>'
            : '<span style="color:var(--color-text-tertiary);font-size:12px;">空のセル</span>';

        return '<div class="cr-gui-cell" data-row="' + rowIndex + '" data-cell="' + cellIndex + '" '
            + 'style="border:1px solid var(--color-border-tertiary);border-radius:6px;padding:10px;'
            + 'background:var(--color-background-secondary);min-height:60px;position:relative;">'
            + '<div class="cr-cell-preview" style="margin-bottom:6px;">' + preview + '</div>'
            + '<button type="button" class="cr-edit-cell btn btn-sm btn-outline-secondary" '
            + 'data-row="' + rowIndex + '" data-cell="' + cellIndex + '" '
            + 'style="font-size:11px;">✎ 編集</button>'
            + '</div>';
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

        return '<div class="cr-gui-row card mb-2" data-row="' + rowIndex + '">'
            + '<div class="card-header d-flex align-items-center gap-2" style="padding:6px 12px;">'
            + '<span style="font-size:12px;color:var(--color-text-secondary);">行 ' + (rowIndex + 1) + '</span>'
            + '<select class="cr-cols-select form-select form-select-sm" data-row="' + rowIndex + '" '
            + 'style="width:auto;">' + colOptions + '</select>'
            + '<div class="ml-auto" style="margin-left:auto;">'
            + '<button type="button" class="cr-move-up btn btn-sm btn-outline-secondary" data-row="' + rowIndex + '" '
            + 'title="上へ" ' + (rowIndex === 0 ? 'disabled' : '') + '>↑</button> '
            + '<button type="button" class="cr-move-down btn btn-sm btn-outline-secondary" data-row="' + rowIndex + '" '
            + 'title="下へ" ' + (rowIndex === state.rows.length - 1 ? 'disabled' : '') + '>↓</button> '
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
     * セルを編集するインラインUIを表示する
     *
     * @param {number} rowIndex
     * @param {number} cellIndex
     */
    function editCell(rowIndex, cellIndex) {
        var cell = state.rows[rowIndex].cells[cellIndex];

        // プレースホルダー選択肢
        var phOptions = '<option value="">-- プレースホルダーを選択 --</option>';
        for (var ph in placeholders) {
            phOptions += '<option value="' + escapeHtml(ph) + '">' + escapeHtml(ph) + ' (' + escapeHtml(placeholders[ph]) + ')</option>';
        }

        var modal = document.createElement('div');
        modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.4);'
            + 'display:flex;align-items:center;justify-content:center;z-index:9999;';

        var isHtml = cell.type === 'html';

        modal.innerHTML = '<div style="background:var(--color-background-primary);border-radius:8px;padding:24px;'
            + 'width:520px;max-width:90vw;box-shadow:0 4px 20px rgba(0,0,0,0.2);">'
            + '<h3 style="font-size:16px;font-weight:500;margin:0 0 16px;">セルの編集</h3>'
            + '<div style="margin-bottom:12px;">'
            + '<label style="font-size:13px;display:block;margin-bottom:4px;">種別</label>'
            + '<select id="cr-cell-type" class="form-select form-select-sm" style="width:auto;">'
            + '<option value="placeholder"' + (!isHtml ? ' selected' : '') + '>プレースホルダー</option>'
            + '<option value="html"' + (isHtml ? ' selected' : '') + '>HTML テキスト</option>'
            + '</select>'
            + '</div>'
            + '<div id="cr-ph-section" style="margin-bottom:12px;' + (isHtml ? 'display:none;' : '') + '">'
            + '<label style="font-size:13px;display:block;margin-bottom:4px;">プレースホルダー</label>'
            + '<select id="cr-cell-ph" class="form-select form-select-sm">' + phOptions + '</select>'
            + '</div>'
            + '<div id="cr-html-section" style="margin-bottom:12px;' + (!isHtml ? 'display:none;' : '') + '">'
            + '<label style="font-size:13px;display:block;margin-bottom:4px;">HTML</label>'
            + '<textarea id="cr-cell-html" class="form-control" rows="5" style="font-size:12px;font-family:monospace;">'
            + escapeHtml(isHtml ? cell.value : '') + '</textarea>'
            + '</div>'
            + '<div style="display:flex;gap:8px;justify-content:flex-end;">'
            + '<button type="button" id="cr-cell-cancel" class="btn btn-sm btn-outline-secondary">キャンセル</button>'
            + '<button type="button" id="cr-cell-save" class="btn btn-sm btn-primary">保存</button>'
            + '</div>'
            + '</div>';

        document.body.appendChild(modal);

        // 種別切り替え
        modal.querySelector('#cr-cell-type').addEventListener('change', function() {
            var isph = this.value === 'placeholder';
            modal.querySelector('#cr-ph-section').style.display = isph ? '' : 'none';
            modal.querySelector('#cr-html-section').style.display = isph ? 'none' : '';
        });

        // 現在値をセット
        if (!isHtml) {
            var phSel = modal.querySelector('#cr-cell-ph');
            for (var i = 0; i < phSel.options.length; i++) {
                if (phSel.options[i].value === cell.value) {
                    phSel.selectedIndex = i;
                    break;
                }
            }
        }

        // キャンセル
        modal.querySelector('#cr-cell-cancel').addEventListener('click', function() {
            document.body.removeChild(modal);
        });

        // 保存
        modal.querySelector('#cr-cell-save').addEventListener('click', function() {
            var type = modal.querySelector('#cr-cell-type').value;
            var value = type === 'placeholder'
                ? modal.querySelector('#cr-cell-ph').value
                : modal.querySelector('#cr-cell-html').value;

            state.rows[rowIndex].cells[cellIndex] = { type: type, value: value };
            document.body.removeChild(modal);
            render();
        });

        // 背景クリックで閉じる
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                document.body.removeChild(modal);
            }
        });
    }

    /**
     * イベントをバインドする
     */
    function bindEvents() {
        // 行追加
        var addBtn = document.getElementById('cr-add-row');
        if (addBtn) {
            addBtn.addEventListener('click', function() {
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

                // セル数をカラム数に合わせる
                while (row.cells.length < newCols) {
                    row.cells.push({ type: 'placeholder', value: '' });
                }
                if (row.cells.length > newCols) {
                    row.cells = row.cells.slice(0, newCols);
                }

                render();
            });
        });

        // セル編集
        document.querySelectorAll('.cr-edit-cell').forEach(function(btn) {
            btn.addEventListener('click', function() {
                editCell(parseInt(this.dataset.row), parseInt(this.dataset.cell));
            });
        });

        // 行削除
        document.querySelectorAll('.cr-delete-row').forEach(function(btn) {
            btn.addEventListener('click', function() {
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
