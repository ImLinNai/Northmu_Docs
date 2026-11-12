(function () {
    'use strict';

    var table = document.querySelector('[data-cat-table]');
    if (!table) return;

    var STORAGE_KEY = 'northmu-cat-collapsed';
    var rows = Array.prototype.slice.call(table.querySelectorAll('[data-cat-row]'));
    if (!rows.length) return;

    function depthOf(row) {
        return parseInt(row.getAttribute('data-depth'), 10) || 0;
    }

    function parentOf(row) {
        return row.getAttribute('data-parent') || '0';
    }

    function idOf(row) {
        return row.getAttribute('data-id');
    }

    function toggleOf(row) {
        return row.querySelector('[data-cat-toggle]');
    }

    function loadCollapsed() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw === null) {
                return rows.filter(function (row) {
                    return !!toggleOf(row);
                }).map(idOf);
            }
            var parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed.map(String) : [];
        } catch (e) {
            return [];
        }
    }

    var collapsed = new Set(loadCollapsed());

    function saveCollapsed() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(Array.from(collapsed)));
        } catch (e) { }
    }

    function isHiddenByAncestor(row) {
        var probe = depthOf(row);
        var prev = row.previousElementSibling;
        while (prev && probe > 0) {
            if (prev.hasAttribute('data-cat-row')) {
                var depth = depthOf(prev);
                if (depth < probe) {
                    if (collapsed.has(idOf(prev))) return true;
                    probe = depth;
                }
            }
            prev = prev.previousElementSibling;
        }
        return false;
    }

    function applyVisibility() {
        rows.forEach(function (row) {
            row.hidden = isHiddenByAncestor(row);
        });
    }

    function syncToggles() {
        rows.forEach(function (row) {
            var btn = toggleOf(row);
            if (!btn) return;
            var open = !collapsed.has(idOf(row));
            var label = open ? '收起子分类' : '展开子分类';
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', label);
            btn.setAttribute('title', label);
            var icon = btn.querySelector('i');
            if (icon) icon.className = 'ti ' + (open ? 'ti-chevron-down' : 'ti-chevron-right');
        });
    }

    function refresh() {
        applyVisibility();
        syncToggles();
    }

    rows.forEach(function (row) {
        var btn = toggleOf(row);
        if (!btn) return;
        btn.addEventListener('click', function () {
            var id = idOf(row);
            if (collapsed.has(id)) collapsed.delete(id);
            else collapsed.add(id);
            saveCollapsed();
            refresh();
        });
    });

    refresh();

    function blockOf(row) {
        var start = rows.indexOf(row);
        if (start === -1) return [row];
        var depth = depthOf(row);
        var block = [row];
        for (var i = start + 1; i < rows.length; i++) {
            if (depthOf(rows[i]) <= depth) break;
            block.push(rows[i]);
        }
        return block;
    }

    function siblingRows(row) {
        return rows.filter(function (item) {
            return parentOf(item) === parentOf(row);
        });
    }

    function updateSortLabels(parentId) {
        var key = String(parentId);
        var index = 0;
        rows.forEach(function (row) {
            if (parentOf(row) !== key) return;
            index += 1;
            var cell = row.querySelector('[data-cat-sort]');
            if (cell) cell.textContent = index;
        });
    }

    var saving = false;

    function persist(parentId) {
        if (saving) return;
        var ids = rows.filter(function (row) {
            return parentOf(row) === String(parentId);
        }).map(idOf);

        saving = true;
        table.classList.add('is-saving');
        window.NorthmuAdmin.post('/admin/categories/sort', {
            parent_id: parentId,
            ids: ids.join(',')
        }).then(function (res) {
            if (res && res.code === 0) {
                updateSortLabels(parentId);
                window.NorthmuUI.toast(res.msg || '排序已保存', 'success');
            } else {
                window.NorthmuAdmin.toast((res && res.msg) || '排序保存失败', 'error');
                setTimeout(function () { location.reload(); }, 1200);
            }
        }).catch(function () {
            window.NorthmuAdmin.toast('排序保存失败，请刷新页面后重试', 'error');
        }).finally(function () {
            saving = false;
            table.classList.remove('is-saving');
        });
    }

    var draggedRow = null;
    var dropRow = null;
    var dropAfter = false;

    function clearDropState() {
        if (dropRow) {
            dropRow.classList.remove('is-drop-before', 'is-drop-after');
        }
        dropRow = null;
        dropAfter = false;
    }

    function resetDrag() {
        if (draggedRow) draggedRow.classList.remove('is-dragging');
        draggedRow = null;
        clearDropState();
    }

    function canDropOn(target) {
        if (!draggedRow || !target || target === draggedRow) return false;
        if (depthOf(target) !== depthOf(draggedRow)) return false;
        if (parentOf(target) !== parentOf(draggedRow)) return false;
        return blockOf(draggedRow).indexOf(target) === -1;
    }

    function moveBlock(sourceRow, target, after) {
        var block = blockOf(sourceRow);
        var targetBlock = blockOf(target);
        var anchor = after ? targetBlock[targetBlock.length - 1] : targetBlock[0];
        var parentId = parentOf(sourceRow);

        var fragment = document.createDocumentFragment();
        block.forEach(function (node) {
            fragment.appendChild(node);
        });
        if (after) {
            anchor.parentNode.insertBefore(fragment, anchor.nextSibling);
        } else {
            anchor.parentNode.insertBefore(fragment, anchor);
        }

        rows = Array.prototype.slice.call(table.querySelectorAll('[data-cat-row]'));
        refresh();
        persist(parentId);
    }

    rows.forEach(function (row) {
        var handle = row.querySelector('[data-cat-drag]');
        if (!handle) return;

        handle.addEventListener('dragstart', function (event) {
            if (saving) {
                event.preventDefault();
                return;
            }
            draggedRow = row;
            row.classList.add('is-dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                try {
                    event.dataTransfer.setData('text/plain', idOf(row));
                } catch (e) { }
            }
        });

        handle.addEventListener('dragend', resetDrag);

        handle.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
            if (saving) return;

            var siblings = siblingRows(row);
            var index = siblings.indexOf(row);
            var targetIndex = event.key === 'ArrowUp' ? index - 1 : index + 1;
            if (targetIndex < 0 || targetIndex >= siblings.length) return;

            event.preventDefault();
            var target = siblings[targetIndex];
            moveBlock(row, target, event.key === 'ArrowDown');
            handle.focus();
        });
    });

    table.addEventListener('dragover', function (event) {
        if (!draggedRow) return;
        var target = event.target.closest ? event.target.closest('[data-cat-row]') : null;
        if (!canDropOn(target)) {
            clearDropState();
            return;
        }
        event.preventDefault();
        if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
        var rect = target.getBoundingClientRect();
        var after = event.clientY > rect.top + rect.height / 2;
        if (dropRow !== target || dropAfter !== after) {
            clearDropState();
            dropRow = target;
            dropAfter = after;
            target.classList.add(after ? 'is-drop-after' : 'is-drop-before');
        }
    });

    table.addEventListener('drop', function (event) {
        if (!draggedRow || !dropRow) return;
        event.preventDefault();
        var source = draggedRow;
        var target = dropRow;
        var after = dropAfter;
        clearDropState();
        moveBlock(source, target, after);
        resetDrag();
    });

    table.addEventListener('dragleave', function (event) {
        if (event.target === table) clearDropState();
    });

    document.addEventListener('drop', function () {
        if (draggedRow) resetDrag();
    });
})();
