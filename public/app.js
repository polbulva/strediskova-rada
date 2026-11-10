(function () {
    function reindex(container) {
        var name = container.dataset.name;
        Array.prototype.forEach.call(container.children, function (row, index) {
            Array.prototype.forEach.call(row.querySelectorAll('[data-field]'), function (input) {
                if (input.dataset.field === 'level') {
                    return;
                }
                input.name = name + '[' + index + '][' + input.dataset.field + ']';
            });
            var level = row.querySelector('[data-field="level"]');
            if (level) {
                level.name = name + '[' + index + '][level]';
                row.dataset.level = level.value;
            }
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
        if (!button) {
            return;
        }
        var row = button.closest('.item-row, .resolution-row, .event-row');
        var parent = row.parentElement;
        if (button.dataset.action === 'remove') {
            row.remove();
        } else if (button.dataset.action === 'up' && row.previousElementSibling) {
            parent.insertBefore(row, row.previousElementSibling);
        } else if (button.dataset.action === 'down' && row.nextElementSibling) {
            parent.insertBefore(row.nextElementSibling, row);
        }
        reindex(parent);
    });

    document.addEventListener('change', function (event) {
        if (event.target.matches('[data-field="level"]')) {
            reindex(event.target.closest('[data-name]'));
        }
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