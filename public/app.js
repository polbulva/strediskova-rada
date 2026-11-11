(function () {
    var MAX_LEVEL = 5;

    function level(row) { return parseInt(row.dataset.level, 10) || 1; }

    function setLevel(row, value) {
        row.dataset.level = String(value);
        row.querySelector('[data-field="level"]').value = String(value);
    }

    function block(row) {
        var rows = [row];
        for (var n = row.nextElementSibling; n && level(n) > level(row); n = n.nextElementSibling) {
            rows.push(n);
        }
        return rows;
    }

    function reindex(container) {
        var name = container.dataset.name;
        Array.prototype.forEach.call(container.children, function (row, index) {
            Array.prototype.forEach.call(row.querySelectorAll('[data-field]'), function (input) {
                if (input.closest('[data-name]') !== container) { return; }
                input.name = name + '[' + index + '][' + input.dataset.field + ']';
            });
            var field = row.querySelector('[data-field="level"]');
            if (field) {
                row.dataset.level = field.value;
            }
        });
        normalize(container);
        applyCollapse(container);
    }

    function normalize(container) {
        var previous = 0;
        Array.prototype.forEach.call(container.children, function (row) {
            if (!row.classList.contains('item-row')) { return; }
            var wanted = Math.max(1, Math.min(level(row), previous + 1, MAX_LEVEL));
            if (wanted !== level(row)) { setLevel(row, wanted); }
            previous = wanted;
        });
    }

    function applyCollapse(container) {
        var hideBelow = Infinity;
        var previous = null;
        Array.prototype.forEach.call(container.children, function (row) {
            if (!row.classList.contains('item-row')) { return; }
            var current = level(row);
            if (current > hideBelow) {
                row.hidden = true;
            } else {
                hideBelow = Infinity;
                row.hidden = false;
            }
            var subs = block(row).length - 1;
            row.classList.toggle('has-subs', subs > 0);
            row.querySelector('.collapse-count').textContent = row.classList.contains('collapsed') && subs ? '(' + subs + ')' : '';
            if (row.classList.contains('collapsed') && subs > 0 && hideBelow === Infinity) {
                hideBelow = current;
            }
            var deepest = Math.max.apply(null, block(row).map(level));
            var indent = row.querySelector('[data-action="indent"]');
            var outdent = row.querySelector('[data-action="outdent"]');
            if (indent) { indent.disabled = !previous || level(previous) < current || deepest + 1 > MAX_LEVEL; }
            if (outdent) { outdent.disabled = current <= 1; }
            previous = row;
        });
    }

    document.querySelectorAll('[data-name]').forEach(reindex);

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-add]');
        if (add) {
            var container = document.getElementById(add.dataset.add);
            var template = document.getElementById(add.dataset.add + '-template');
            container.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(container.children.length)));
            reindex(container);
            var last = container.lastElementChild.querySelector('input[type="text"], textarea');
            if (last) {
                last.focus();
            }
            return;
        }

        var button = event.target.closest('[data-action]');
        if (!button || button.disabled) {
            return;
        }
        var row = button.closest('.item-row, .resolution-row, .event-row');
        var parent = row.parentElement;
        var action = button.dataset.action;
        var isItem = row.classList.contains('item-row');
        var rows = isItem ? block(row) : [row];
        var end = rows[rows.length - 1];

        if (action === 'remove') {
            row.remove();
        } else if (action === 'toggle') {
            row.classList.toggle('collapsed');
        } else if (action === 'indent' || action === 'outdent') {
            var delta = action === 'indent' ? 1 : -1;
            rows.forEach(function (r) { setLevel(r, level(r) + delta); });
        } else if (action === 'up') {
            var before = row.previousElementSibling;
            if (isItem) {
                while (before && level(before) > level(row)) { before = before.previousElementSibling; }
                if (!before || level(before) !== level(row)) { before = null; }
            }
            if (before) {
                rows.forEach(function (r) { parent.insertBefore(r, before); });
            }
        } else if (action === 'down') {
            var next = end.nextElementSibling;
            if (next && (!isItem || level(next) === level(row))) {
                var after = isItem ? block(next).pop().nextElementSibling : next.nextElementSibling;
                rows.forEach(function (r) { parent.insertBefore(r, after); });
            }
        }
        reindex(parent);
    });
})();
(function () {
    var dialog = document.getElementById('sch-dialog');
    if (!dialog || !window.SCHEDULE) {
        return;
    }
    var form = document.getElementById('sch-form');

    function addHour(time) {
        var parts = time.split(':');
        var hours = parseInt(parts[0], 10) + 1;
        return hours > 23 ? '' : (hours < 10 ? '0' : '') + hours + ':' + parts[1];
    }

    var autoEnd = false;
    form.elements.time_from.addEventListener('change', function () {
        if (form.elements.time_from.value && (autoEnd || !form.elements.time_to.value)) {
            form.elements.time_to.value = addHour(form.elements.time_from.value);
            autoEnd = true;
        }
    });
    form.elements.time_to.addEventListener('input', function () { autoEnd = false; });

    var groupRooms = JSON.parse(dialog.dataset.groupRooms || '{}');
    var datalist = document.getElementById('group-names');
    function filterGroups() {
        var room = form.elements.clubroom_id.value;
        datalist.innerHTML = '';
        Object.keys(groupRooms).forEach(function (name) {
            var groupRoom = String(groupRooms[name]);
            if (!room || groupRoom === '0' || groupRoom === room) {
                var option = document.createElement('option');
                option.value = name;
                datalist.appendChild(option);
            }
        });
    }
    form.elements.clubroom_id.addEventListener('change', filterGroups);
    form.elements.group_name.addEventListener('change', function () {
        var room = groupRooms[form.elements.group_name.value];
        if (room && !form.elements.clubroom_id.value) {
            form.elements.clubroom_id.value = room;
            filterGroups();
        }
    });

    function open(data, title) {
        autoEnd = !data.id && !!data.time_to;
        form.reset();
        ['id', 'group_name', 'clubroom_id', 'weekday', 'time_from', 'time_to', 'category_id', 'age_text', 'leaders'].forEach(function (name) {
            var value = data[name];
            form.elements[name].value = value === null || value === undefined ? '' : value;
        });
        filterGroups();
        document.getElementById('sch-title').textContent = title;
        document.getElementById('sch-delete').hidden = !data.id;
        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }
        form.elements.group_name.focus();
    }

    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-sch-add]');
        if (add) {
            var day = add.closest('[data-sch-day]');
            var data = {weekday: day.dataset.schDay, clubroom_id: day.dataset.schRoom};
            var lastEnd = '';
            day.querySelectorAll('[data-sch-card]').forEach(function (card) {
                var m = JSON.parse(card.dataset.schJson);
                var end = m.time_to || m.time_from;
                if (end && end > lastEnd) { lastEnd = end; }
            });
            if (lastEnd) {
                data.time_from = lastEnd;
                data.time_to = addHour(lastEnd);
            }
            open(data, 'Nová schůzka');
            return;
        }
        var card = event.target.closest('[data-sch-card]');
        if (card) {
            open(JSON.parse(card.dataset.schJson), 'Upravit schůzku');
        }
    });

    function refreshLive() {
        var live = document.querySelector('[data-tt-live]');
        return fetch('schedule.php?partial=1&season=' + encodeURIComponent(window.SCHEDULE.season), {credentials: 'same-origin'})
            .then(function (response) { return response.text(); })
            .then(function (html) { live.innerHTML = html; });
    }

    form.addEventListener('submit', function (event) {
        if (!window.SCHEDULE.ajax) {
            return;
        }
        event.preventDefault();
        var body = new FormData(form);
        body.set('action', event.submitter && event.submitter.value ? event.submitter.value : 'save');
        body.set('ajax', '1');
        fetch('schedule.php', {method: 'POST', body: body, credentials: 'same-origin'})
            .then(function (response) { return response.ok ? response.json() : {error: 'Změnu se nepodařilo uložit.'}; })
            .then(function (result) {
                if (result.error) {
                    alert(result.error);
                    return null;
                }
                dialog.close();
                return refreshLive();
            });
    });

    document.getElementById('sch-cancel').addEventListener('click', function () {
        dialog.close();
    });
    document.getElementById('sch-delete').addEventListener('click', function (event) {
        if (!confirm('Opravdu smazat tuto schůzku?')) {
            event.preventDefault();
        }
    });

    var dragged = null;
    document.addEventListener('dragstart', function (event) {
        var card = event.target.closest && event.target.closest('[data-sch-card]');
        if (card) {
            dragged = card;
            event.dataTransfer.effectAllowed = 'move';
            event.dataTransfer.setData('text/plain', card.dataset.schId);
            card.classList.add('dragging');
        }
    });
    document.addEventListener('dragend', function () {
        if (dragged) {
            dragged.classList.remove('dragging');
        }
        dragged = null;
        document.querySelectorAll('.tt-day.over').forEach(function (el) { el.classList.remove('over'); });
    });
    document.addEventListener('dragover', function (event) {
        var day = event.target.closest && event.target.closest('[data-sch-day]');
        if (dragged && day) {
            event.preventDefault();
            document.querySelectorAll('.tt-day.over').forEach(function (el) { if (el !== day) { el.classList.remove('over'); } });
            day.classList.add('over');
        }
    });
    document.addEventListener('drop', function (event) {
        var day = event.target.closest && event.target.closest('[data-sch-day]');
        if (!dragged || !day) {
            return;
        }
        event.preventDefault();
        var body = new FormData();
        body.append('action', 'move');
        body.append('csrf_token', window.SCHEDULE.token);
        body.append('season', window.SCHEDULE.season);
        body.append('id', dragged.dataset.schId);
        body.append('weekday', day.dataset.schDay);
        body.append('clubroom_id', day.dataset.schRoom);
        fetch('schedule.php', {method: 'POST', body: body, credentials: 'same-origin'}).then(function (response) {
            if (response.status === 204) {
                if (window.SCHEDULE.ajax) {
                    refreshLive();
                } else {
                    location.reload();
                }
            } else {
                alert('Přesun se nepodařilo uložit.');
            }
        });
    });
})();

(function () {
    function filterTripGroups(row) {
        var unit = row.querySelector('[data-field="unit_id"]');
        var group = row.querySelector('[data-field="group_id"]');
        if (!unit || !group) { return; }
        Array.prototype.forEach.call(group.options, function (option) {
            var match = !unit.value || !option.dataset.unit || option.dataset.unit === '0' || option.dataset.unit === unit.value;
            option.hidden = !match;
            option.disabled = !match;
        });
        if (group.selectedOptions[0] && group.selectedOptions[0].disabled) {
            group.value = '';
        }
    }
    document.addEventListener('change', function (event) {
        if (event.target.matches && event.target.matches('.trip-row [data-field="unit_id"]')) {
            filterTripGroups(event.target.closest('.trip-row'));
        }
    });
    document.querySelectorAll('.trip-row').forEach(filterTripGroups);
    new MutationObserver(function (mutations) {
        mutations.forEach(function (mutation) {
            mutation.addedNodes.forEach(function (node) {
                if (node.nodeType === 1) {
                    (node.matches('.trip-row') ? [node] : node.querySelectorAll('.trip-row')).forEach(filterTripGroups);
                }
            });
        });
    }).observe(document.body, {childList: true, subtree: true});
})();

(() => {
    document.querySelectorAll('[data-sign]').forEach((pad) => {
        const canvas = pad.querySelector('canvas');
        const input = pad.querySelector('input[type="hidden"]');
        const ctx = canvas.getContext('2d');
        ctx.lineWidth = 2.5;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.strokeStyle = '#111';
        let drawing = false;

        if (input.value) {
            const image = new Image();
            image.onload = () => ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
            image.src = input.value;
        }

        const point = (event) => {
            const rect = canvas.getBoundingClientRect();
            return [(event.clientX - rect.left) * canvas.width / rect.width, (event.clientY - rect.top) * canvas.height / rect.height];
        };
        canvas.addEventListener('pointerdown', (event) => {
            drawing = true;
            canvas.setPointerCapture(event.pointerId);
            const [x, y] = point(event);
            ctx.beginPath();
            ctx.moveTo(x, y);
            ctx.lineTo(x + 0.1, y);
            ctx.stroke();
        });
        canvas.addEventListener('pointermove', (event) => {
            if (!drawing) return;
            const [x, y] = point(event);
            ctx.lineTo(x, y);
            ctx.stroke();
        });
        const stop = () => {
            if (!drawing) return;
            drawing = false;
            input.value = canvas.toDataURL('image/png');
        };
        canvas.addEventListener('pointerup', stop);
        canvas.addEventListener('pointercancel', stop);
        pad.querySelector('[data-sign-clear]').addEventListener('click', () => {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            input.value = '';
        });
    });
})();

(() => {
    const MARKER = '<!--html-->';
    const MAX_IMAGE = 900;

    document.querySelectorAll('[data-rte]').forEach(initEditor);
    new MutationObserver((mutations) => {
        mutations.forEach((m) => m.addedNodes.forEach((node) => {
            if (node.nodeType !== 1) return;
            if (node.matches('[data-rte]')) initEditor(node);
            node.querySelectorAll('[data-rte]').forEach(initEditor);
        }));
    }).observe(document.body, { childList: true, subtree: true });

    function initEditor(editor) {
        if (editor.dataset.ready) return;
        editor.dataset.ready = '1';
        const area = editor.querySelector('.rte-area');
        const hidden = editor.querySelector('input[type="hidden"]');
        const file = editor.querySelector('[data-rte-file]');

        const sync = () => {
            const html = area.innerHTML.trim();
            const empty = area.textContent.trim() === '' && !area.querySelector('img, table');
            hidden.value = empty ? '' : MARKER + html;
        };
        area.addEventListener('input', sync);
        area.addEventListener('blur', sync);
        area.addEventListener('paste', (event) => {
            const files = Array.from(event.clipboardData ? event.clipboardData.files : []).filter((f) => f.type.startsWith('image/'));
            if (files.length) {
                event.preventDefault();
                files.forEach(insertImage);
                return;
            }
            const text = event.clipboardData ? event.clipboardData.getData('text/plain') : '';
            if (text) {
                event.preventDefault();
                document.execCommand('insertText', false, text);
            }
        });
        const form = editor.closest('form');
        if (form) form.addEventListener('submit', sync);

        const cell = () => {
            const selection = window.getSelection();
            const node = selection.anchorNode && (selection.anchorNode.nodeType === 1 ? selection.anchorNode : selection.anchorNode.parentElement);
            return node && area.contains(node) ? node.closest('td, th') : null;
        };

        function insertImage(source) {
            const reader = new FileReader();
            reader.onload = () => {
                const image = new Image();
                image.onload = () => {
                    const scale = Math.min(1, MAX_IMAGE / Math.max(image.width, image.height));
                    const canvas = document.createElement('canvas');
                    canvas.width = Math.round(image.width * scale);
                    canvas.height = Math.round(image.height * scale);
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff';
                    ctx.fillRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(image, 0, 0, canvas.width, canvas.height);
                    area.focus();
                    document.execCommand('insertImage', false, canvas.toDataURL('image/jpeg', 0.82));
                    sync();
                };
                image.src = reader.result;
            };
            reader.readAsDataURL(source);
        }

        file.addEventListener('change', () => {
            if (file.files[0]) insertImage(file.files[0]);
            file.value = '';
        });

        editor.querySelector('.rte-bar').addEventListener('mousedown', (event) => {
            if (event.target.closest('button')) event.preventDefault();
        });
        editor.querySelector('.rte-bar').addEventListener('click', (event) => {
            const button = event.target.closest('[data-rte-cmd]');
            if (!button) return;
            area.focus();
            const exec = (command, value) => document.execCommand(command, false, value);
            const current = cell();
            switch (button.dataset.rteCmd) {
                case 'bold': exec('bold'); break;
                case 'italic': exec('italic'); break;
                case 'underline': exec('underline'); break;
                case 'ul': exec('insertUnorderedList'); break;
                case 'ol': exec('insertOrderedList'); break;
                case 'indent': exec('indent'); break;
                case 'outdent': exec('outdent'); break;
                case 'clear': exec('removeFormat'); break;
                case 'link': {
                    const url = window.prompt('Adresa odkazu (https://…):', 'https://');
                    if (url && /^(https?:\/\/|mailto:)/i.test(url)) exec('createLink', url);
                    break;
                }
                case 'image': file.click(); break;
                case 'table': {
                    const rows = parseInt(window.prompt('Počet řádků:', '3'), 10);
                    const cols = parseInt(window.prompt('Počet sloupců:', '3'), 10);
                    if (rows > 0 && cols > 0 && rows <= 50 && cols <= 12) {
                        let html = '<table><tbody>';
                        for (let r = 0; r < rows; r++) {
                            html += '<tr>' + (r === 0 ? '<th><br></th>' : '<td><br></td>').repeat(cols) + '</tr>';
                        }
                        exec('insertHTML', html + '</tbody></table><p><br></p>');
                    }
                    break;
                }
                case 'row+': if (current) {
                    const row = current.parentElement;
                    const copy = row.cloneNode(true);
                    copy.querySelectorAll('th, td').forEach((c) => { c.innerHTML = '<br>'; });
                    row.after(copy);
                } break;
                case 'row-': if (current) {
                    const row = current.parentElement;
                    const body = row.parentElement;
                    if (body.children.length > 1) row.remove(); else body.closest('table').remove();
                } break;
                case 'col+': if (current) {
                    const index = current.cellIndex;
                    current.closest('table').querySelectorAll('tr').forEach((row) => {
                        const reference = row.cells[index];
                        if (!reference) return;
                        const added = document.createElement(reference.tagName.toLowerCase());
                        added.innerHTML = '<br>';
                        reference.after(added);
                    });
                } break;
                case 'col-': if (current) {
                    const index = current.cellIndex;
                    const table = current.closest('table');
                    if (current.parentElement.cells.length > 1) {
                        table.querySelectorAll('tr').forEach((row) => row.cells[index] && row.cells[index].remove());
                    } else {
                        table.remove();
                    }
                } break;
            }
            sync();
        });
    }
})();

(function () {
    document.addEventListener('click', function (event) {
        var add = event.target.closest('[data-pick-add]');
        if (!add) return;
        var picker = add.closest('.event-picker');
        var select = picker.querySelector('[data-pick-select]');
        if (!select.value) { select.focus(); return; }
        var template = picker.querySelector('[data-pick-template="' + select.value + '"]');
        var container = document.getElementById('events');
        if (!template || !container) return;
        container.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(container.children.length)));
        template.remove();
        select.querySelector('option[value="' + select.value + '"]').remove();
        select.value = '';
        container.lastElementChild.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
})();