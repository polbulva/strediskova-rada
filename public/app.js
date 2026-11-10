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

    function open(data, title) {
        autoEnd = !data.id && !!data.time_to;
        form.reset();
        ['id', 'group_name', 'clubroom_id', 'weekday', 'time_from', 'time_to', 'category_id', 'age_text', 'leaders'].forEach(function (name) {
            var value = data[name];
            form.elements[name].value = value === null || value === undefined ? '' : value;
        });
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
                location.reload();
            } else {
                alert('Přesun se nepodařilo uložit.');
            }
        });
    });
})();
