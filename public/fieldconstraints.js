/**
 * Field Constraints - client side (GLPI 10 and 11).
 *
 * The server stays the only authority: everything here only shows, ahead of
 * time, what the rules require. Four parts:
 *
 *  1. required labels: red `*` on the fields rules require, fetched for the
 *     item page (ajax/itemconfig.php);
 *  2. solution notice: the red notice above the solution button, whose
 *     solution items disappear as they get filled; the button stays disabled
 *     while anything is missing;
 *  3. date constraints: bounds the date pickers of Fields date fields (any
 *     page) and of form questions (ajax/dateconstraints.php);
 *  4. visual picker: below an item rule, the real item form in an iframe where
 *     clicking a field toggles it in the rule (`_fieldconstraints_pick=1`).
 *
 * Forms and tabs are loaded on demand, so all of it re-runs on DOM changes.
 */
(function () {
    'use strict';

    var PICK_MODE = /[?&]_fieldconstraints_pick=1(&|$)/.test(window.location.search) && window.parent !== window;

    // Plugin URL, from this script's own: /plugins/fieldconstraints/fieldconstraints.js on
    // GLPI 11, /(plugins|marketplace)/fieldconstraints/public/fieldconstraints.js on GLPI 10.
    var BASE_URL = (function () {
        var src = document.currentScript ? document.currentScript.src : '';
        return src.replace(/\/(public\/)?fieldconstraints\.js(\?.*)?$/, '');
    })();

    var itemMarks = [];

    // ------------------------------------------------------------------ utils

    function cssEscape(value) {
        return window.CSS && CSS.escape ? CSS.escape(value) : String(value).replace(/["\\\]\[]/g, '\\$&');
    }

    function findTargets(root, target) {
        var found = [];
        if (!target) {
            return found;
        }
        (target.names || []).forEach(function (name) {
            root.querySelectorAll('[name="' + cssEscape(name) + '"]').forEach(function (el) {
                found.push(el);
            });
        });
        if (target.actor) {
            root.querySelectorAll('select[data-actor-type="' + cssEscape(target.actor) + '"]').forEach(function (el) {
                found.push(el);
            });
        }
        return found;
    }

    /**
     * Visible blocks of the fields a target points to: their `.form-field`
     * row, or the upload area.
     */
    function findBlocks(root, target) {
        var blocks = [];
        findTargets(root, target).forEach(function (el) {
            var block = el.closest('.form-field');
            if (block && blocks.indexOf(block) === -1) {
                blocks.push(block);
            }
        });
        if (target && target.upload) {
            root.querySelectorAll('.fileupload').forEach(function (block) {
                blocks.push(block);
            });
        }
        return blocks;
    }

    // Timeline forms (followup, task, solution...) reuse names of the item form
    // (requesttypes_id): item-level hints only apply outside of them.
    function inTimeline(el) {
        return !!el.closest('[id^="new-"][id$="-block"], .timeline-item, .itil-timeline');
    }

    function parseJson(text, fallback) {
        try {
            return JSON.parse(text);
        } catch (e) {
            return fallback;
        }
    }

    // ------------------------------------------------------- 1. required labels

    function labelOf(block) {
        if (block.classList.contains('fileupload')) {
            return block.querySelector(':scope > b');
        }
        return block.querySelector('label');
    }

    function markLabel(label, title) {
        if (!label || label.getAttribute('data-fieldconstraints-marked')) {
            return;
        }
        label.setAttribute('data-fieldconstraints-marked', '1');
        label.classList.add('text-danger', 'fieldconstraints-required');
        label.setAttribute('title', title);
        // A field already mandatory by template has its star.
        if (!label.querySelector('.required')) {
            var star = document.createElement('span');
            star.className = 'required text-danger fieldconstraints-star ms-1';
            star.textContent = '*';
            label.appendChild(star);
        }
    }

    var itemConfigRequested = false;

    /**
     * Item pages (front/ticket.form.php...): the config is fetched rather than
     * printed with the item form, which a page opened on another tab lacks.
     */
    function loadItemConfig() {
        if (itemConfigRequested || !BASE_URL) {
            return;
        }
        var match = window.location.pathname.match(/\/front\/(ticket|change|problem)\.form\.php$/);
        if (!match) {
            return;
        }
        itemConfigRequested = true;

        var itemtype = match[1].charAt(0).toUpperCase() + match[1].slice(1);
        var id = new URLSearchParams(window.location.search).get('id') || '0';
        fetch(BASE_URL + '/ajax/itemconfig.php?itemtype=' + itemtype + '&id=' + encodeURIComponent(id), { credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : {}; })
            .then(function (config) {
                itemMarks = itemMarks.concat(config.marks || []);
                run();
            })
            .catch(function () {
                // No hint: the server still checks every write.
            });
    }

    function applyItemMarks() {
        itemMarks.forEach(function (mark) {
            findBlocks(document, mark).forEach(function (block) {
                if (!inTimeline(block)) {
                    markLabel(labelOf(block), mark.title);
                }
            });
        });
    }

    // ------------------------------------------------------ 2. solution notice

    function hasText(html) {
        if (/<img/i.test(html)) {
            return true;
        }
        var div = document.createElement('div');
        div.innerHTML = html;
        return div.textContent.replace(/[\s ]+/g, '') !== '';
    }

    function isFilled(form, key) {
        var el;
        switch (key) {
            case 'solution:solutiontypes_id':
                el = form.querySelector('[name="solutiontypes_id"]');
                return !!el && parseInt(el.value, 10) > 0;
            case 'solution:content':
                el = form.querySelector('textarea[name="content"]');
                if (!el) {
                    return false;
                }
                if (window.tinymce && el.id && tinymce.get(el.id)) {
                    return hasText(tinymce.get(el.id).getContent());
                }
                return hasText(el.value);
            case 'solution:document':
                return Array.prototype.some.call(
                    form.querySelectorAll('input[name="_filename[]"], input[name="_content[]"]'),
                    function (input) { return input.value !== ''; }
                );
        }
        return false;
    }

    function submitButtons(form) {
        return form ? form.querySelectorAll('button[type="submit"][name="add"]') : [];
    }

    function refreshNotice(notice) {
        var form = notice.closest('form');
        var pendingRules = 0;
        notice.querySelectorAll('[data-fieldconstraints-rule]').forEach(function (rule) {
            var pending = 0;
            rule.querySelectorAll('li').forEach(function (li) {
                var key = li.getAttribute('data-fieldconstraints-key');
                var done = !!(key && form && isFilled(form, key));
                li.classList.toggle('d-none', done);
                pending += done ? 0 : 1;
            });
            rule.classList.toggle('d-none', pending === 0);
            pendingRules += pending > 0 ? 1 : 0;
        });

        var blocked = pendingRules > 0;
        notice.classList.toggle('d-none', !blocked);
        submitButtons(form).forEach(function (button) {
            button.disabled = blocked;
        });
    }

    function setupNotices() {
        document.querySelectorAll('[data-fieldconstraints-notice]:not([data-fieldconstraints-ready])').forEach(function (notice) {
            notice.setAttribute('data-fieldconstraints-ready', '1');
            var form = notice.closest('form') || notice.parentElement;

            parseJson(notice.getAttribute('data-fieldconstraints-marks') || '[]', []).forEach(function (mark) {
                findBlocks(form, mark).forEach(function (block) {
                    markLabel(labelOf(block), mark.title);
                });
            });

            refreshNotice(notice);
            // Rich text editor and uploads give no reliable common event: poll
            // while the notice is on the page.
            var timer = window.setInterval(function () {
                if (!document.documentElement.contains(notice)) {
                    window.clearInterval(timer);
                    return;
                }
                refreshNotice(notice);
            }, 700);
        });
    }

    // ------------------------------------------------------------ 3. dates

    /**
     * Date constraints: "<input> cannot be before|after <today | date | other
     * input>". Each picker gets, from every constraint touching it, the latest
     * minimum and the earliest maximum; a constraint against another field
     * also bounds that field the other way round.
     */
    var constraints = [];
    var pickers = {}; // input name => { fp, initial }

    function addConstraints(list) {
        (list || []).forEach(function (constraint) {
            var key = JSON.stringify(constraint);
            if (!constraints.some(function (c) { return c.key === key; })) {
                constraints.push({ key: key, config: constraint });
            }
        });
    }

    function startOfToday() {
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        return today;
    }

    function parseDate(value) {
        if (!value) {
            return null;
        }
        var parts = String(value).split(/[-T :]/).map(Number);
        return new Date(parts[0], parts[1] - 1, parts[2], parts[3] || 0, parts[4] || 0, parts[5] || 0);
    }

    /**
     * Date picker of an input: GLPI wraps its inputs (`wrap: true`), so the
     * instance hangs on the `.flatpickr` container.
     */
    function findPicker(name) {
        var found = null;
        document.querySelectorAll('[name="' + cssEscape(name) + '"]').forEach(function (el) {
            var wrap = el.closest('.flatpickr');
            var fp = (wrap && wrap._flatpickr) || el._flatpickr;
            if (fp && !found) {
                found = fp;
            }
        });
        return found;
    }

    function valueOf(name) {
        var picker = pickers[name];
        return picker ? picker.fp.selectedDates[0] || null : null;
    }

    function later(a, b) {
        return !a ? b : (!b ? a : (a > b ? a : b));
    }

    function earlier(a, b) {
        return !a ? b : (!b ? a : (a < b ? a : b));
    }

    /**
     * A fixed bound (today, a given date) never forbids the value the field
     * already had: a past date may be kept, as on the server.
     */
    function keepInitial(name, bound, isMin) {
        var initial = pickers[name] ? pickers[name].initial : null;
        if (!initial || !bound) {
            return bound;
        }
        return isMin ? earlier(bound, initial) : later(bound, initial);
    }

    /**
     * A comparison with another field takes precedence, when that field is
     * filled, over today / a given date in the same direction (as on the server).
     */
    function overriddenByField(name, operator) {
        return constraints.some(function (entry) {
            var c = entry.config;
            return c.input === name && c.operator === operator && c.reference === 'field' && !!valueOf(c.ref_input);
        });
    }

    function boundsOf(name) {
        var min = null;
        var max = null;
        constraints.forEach(function (entry) {
            var c = entry.config;
            if (c.input === name) {
                if (c.reference !== 'field' && overriddenByField(name, c.operator)) {
                    return;
                }
                var ref = null;
                if (c.reference === 'today') {
                    ref = keepInitial(name, startOfToday(), c.operator === 'min');
                } else if (c.reference === 'date') {
                    ref = keepInitial(name, parseDate(c.ref_date), c.operator === 'min');
                } else if (c.reference === 'field') {
                    ref = valueOf(c.ref_input);
                }
                if (c.operator === 'min') {
                    min = later(min, ref);
                } else {
                    max = earlier(max, ref);
                }
            } else if (c.reference === 'field' && c.ref_input === name) {
                // "other >= this" makes `other` a maximum of this field, and so on.
                var other = valueOf(c.input);
                if (c.operator === 'min') {
                    max = earlier(max, other);
                } else {
                    min = later(min, other);
                }
            }
        });
        return { min: min, max: max };
    }

    function refreshPickers() {
        Object.keys(pickers).forEach(function (name) {
            var bounds = boundsOf(name);
            pickers[name].fp.set('minDate', bounds.min);
            pickers[name].fp.set('maxDate', bounds.max);
        });
    }

    function linkDates() {
        var names = {};
        constraints.forEach(function (entry) {
            names[entry.config.input] = true;
            if (entry.config.ref_input) {
                names[entry.config.ref_input] = true;
            }
        });

        var changed = false;
        Object.keys(names).forEach(function (name) {
            var fp = findPicker(name);
            // The field may have been re-rendered (tab reloaded).
            if (fp && (!pickers[name] || pickers[name].fp !== fp)) {
                pickers[name] = { fp: fp, initial: fp.selectedDates[0] || null };
                fp.config.onChange.push(function () {
                    // A field moved past one depending on it drags it along,
                    // as the limiter did (end follows start).
                    var moved = fp.selectedDates[0];
                    constraints.forEach(function (entry) {
                        var c = entry.config;
                        var dependent = c.reference === 'field' && c.ref_input === name ? pickers[c.input] : null;
                        var current = dependent ? dependent.fp.selectedDates[0] : null;
                        if (moved && current && ((c.operator === 'min' && current < moved) || (c.operator === 'max' && current > moved))) {
                            dependent.fp.setDate(moved, true);
                        }
                    });
                    refreshPickers();
                });
                changed = true;
            }
        });
        if (changed) {
            refreshPickers();
        }
    }

    var configRequested = {};

    function requestConfig(key, url) {
        if (configRequested[key] || !BASE_URL) {
            return;
        }
        configRequested[key] = true;
        fetch(BASE_URL + url, { credentials: 'same-origin' })
            .then(function (response) { return response.ok ? response.json() : {}; })
            .then(function (config) {
                addConstraints(config.constraints);
                linkDates();
            })
            .catch(function () {
                // No hint: the server still checks every write.
            });
    }

    /**
     * Which constraints this page may need: Fields date inputs (named
     * `<label>field` by Fields), a Formcreator form, a native form.
     */
    function loadDateConfig() {
        if (document.querySelector('.flatpickr input[name$="field"]')) {
            requestConfig('fields', '/ajax/dateconstraints.php?source=fields');
        }

        var formcreator = document.querySelector('form#plugin_formcreator_form[data-id]');
        if (formcreator) {
            var fid = formcreator.getAttribute('data-id');
            requestConfig('formcreator#' + fid, '/ajax/dateconstraints.php?source=formcreator&id=' + encodeURIComponent(fid));
        }

        var native = document.querySelector('[data-glpi-form-renderer-id]')
            ? document.querySelector('form input[type="hidden"][name="forms_id"]')
            : null;
        if (native && native.value) {
            requestConfig('native#' + native.value, '/ajax/dateconstraints.php?source=native&id=' + encodeURIComponent(native.value));
        }
    }

    // ------------------------------------------------------ 4. visual picker

    var pick = { options: [], selected: [], solutionOpened: false };

    /**
     * Opens the solution form the way a user would: GLPI 11 only loads a
     * timeline form when its button is clicked.
     */
    function openSolutionForm() {
        if (pick.solutionOpened) {
            return;
        }
        var opener = document.querySelector('.answer-action[data-bs-target="#new-ITILSolution-block"], a.action-solution');
        if (opener) {
            pick.solutionOpened = true;
            opener.click();
        }
    }

    function pickRefresh() {
        document.querySelectorAll('[data-fieldconstraints-pick-key]').forEach(function (block) {
            block.classList.toggle('fieldconstraints-picked', pick.selected.indexOf(block.getAttribute('data-fieldconstraints-pick-key')) !== -1);
        });
    }

    function pickScan() {
        var solution = document.getElementById('new-ITILSolution-block');
        pick.options.forEach(function (option) {
            var isSolution = option.key.indexOf('solution:') === 0;
            if (isSolution) {
                openSolutionForm();
                if (!solution) {
                    return;
                }
            }
            findBlocks(isSolution ? solution : document, option.target).forEach(function (block) {
                if (block.getAttribute('data-fieldconstraints-pick-key')) {
                    return;
                }
                // Item-level keys must not grab timeline fields sharing a name.
                if (!isSolution && inTimeline(block)) {
                    return;
                }
                block.setAttribute('data-fieldconstraints-pick-key', option.key);
                block.setAttribute('title', option.label);
            });
        });
        pickRefresh();
    }

    function startPickMode() {
        var style = document.createElement('style');
        style.textContent = ''
            + 'aside.navbar-vertical, header.navbar, #header, [data-fieldconstraints-notice] { display: none !important; }'
            // The layout keeps the hidden sidebar's width as a margin.
            + '.page-wrapper { margin-left: 0 !important; }'
            + '[data-fieldconstraints-pick-key] { outline: 2px dashed #206bc4; outline-offset: 2px; cursor: pointer; border-radius: 4px; }'
            + '[data-fieldconstraints-pick-key]:hover { background: rgba(32, 107, 196, .08); }'
            + '[data-fieldconstraints-pick-key].fieldconstraints-picked { outline: 2px solid #d63939; background: rgba(214, 57, 57, .10); }'
            + '[data-fieldconstraints-pick-key] * { cursor: pointer !important; }';
        document.head.appendChild(style);

        // Nothing in the preview may be saved or open: only picks happen here.
        ['mousedown', 'click'].forEach(function (type) {
            document.addEventListener(type, function (event) {
                var block = event.target.closest('[data-fieldconstraints-pick-key]');
                if (!block) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                // GLPI and its widgets dispatch synthetic clicks while settling.
                if (type !== 'click' || !event.isTrusted) {
                    return;
                }
                var key = block.getAttribute('data-fieldconstraints-pick-key');
                var at = pick.selected.indexOf(key);
                if (at === -1) {
                    pick.selected.push(key);
                } else {
                    pick.selected.splice(at, 1);
                }
                pickRefresh();
                window.parent.postMessage({ type: 'fieldconstraints:pick-toggle', key: key, on: at === -1 }, window.location.origin);
            }, true);
        });
        document.addEventListener('submit', function (event) {
            event.preventDefault();
            event.stopPropagation();
        }, true);

        window.addEventListener('message', function (event) {
            if (event.origin !== window.location.origin || !event.data || event.data.type !== 'fieldconstraints:pick-init') {
                return;
            }
            pick.options = event.data.options || [];
            pick.selected = event.data.selected || [];
            pickScan();
        });

        window.parent.postMessage({ type: 'fieldconstraints:pick-ready' }, window.location.origin);
    }

    /**
     * Rule form side: the item form, always shown below the rule, kept in sync
     * with the "required fields" dropdown both ways. Its configuration (form URL,
     * sample item, clickable fields) is re-rendered with the field list when the
     * rule's item changes.
     */
    var panel = { config: null, options: [], ready: false };

    function panelParts() {
        var el = document.querySelector('[data-fieldconstraints-picker-panel]');
        return el ? {
            el: el,
            frame: el.querySelector('[data-fieldconstraints-picker-frame]'),
            sample: el.querySelector('[data-fieldconstraints-picker-sample]'),
            title: el.querySelector('[data-fieldconstraints-picker-title]')
        } : null;
    }

    function requiredSelect() {
        return document.querySelector('select[name="required_fields[]"]');
    }

    function selectedKeys() {
        var select = requiredSelect();
        return select
            ? Array.prototype.filter.call(select.options, function (o) { return o.selected; }).map(function (o) { return o.value; })
            : [];
    }

    function panelLoad() {
        var parts = panelParts();
        if (!parts || !panel.config) {
            return;
        }
        var url = panel.config.getAttribute('data-url');
        var id = parseInt(parts.sample.value, 10);
        parts.frame.src = url + (url.indexOf('?') === -1 ? '?' : '&') + (id > 0 ? 'id=' + id + '&' : '') + '_fieldconstraints_pick=1';
    }

    function panelSync() {
        var parts = panelParts();
        if (parts && parts.frame.contentWindow) {
            parts.frame.contentWindow.postMessage({
                type: 'fieldconstraints:pick-init',
                options: panel.options,
                selected: selectedKeys()
            }, window.location.origin);
        }
    }

    function setupPickerPanel() {
        var parts = panelParts();
        if (!parts) {
            return;
        }

        if (!panel.ready) {
            panel.ready = true;
            parts.el.querySelector('[data-fieldconstraints-picker-load]').addEventListener('click', panelLoad);

            window.addEventListener('message', function (event) {
                var current = panelParts();
                if (!current || event.origin !== window.location.origin || event.source !== current.frame.contentWindow || !event.data) {
                    return;
                }
                if (event.data.type === 'fieldconstraints:pick-ready') {
                    panelSync();
                } else if (event.data.type === 'fieldconstraints:pick-toggle') {
                    var select = requiredSelect();
                    if (!select) {
                        return;
                    }
                    var keys = selectedKeys().filter(function (key) { return key !== event.data.key; });
                    if (event.data.on) {
                        keys.push(event.data.key);
                    }
                    if (window.jQuery) {
                        window.jQuery(select).val(keys).trigger('change');
                    } else {
                        Array.prototype.forEach.call(select.options, function (o) {
                            o.selected = keys.indexOf(o.value) !== -1;
                        });
                    }
                }
            });

            // A field removed in the dropdown is unpicked in the form too.
            if (window.jQuery) {
                window.jQuery(document).on('change', 'select[name="required_fields[]"]', panelSync);
            }
        }

        // First render, or the item changed (field list re-rendered).
        var config = document.querySelector('[data-fieldconstraints-picker-config]');
        if (config && config !== panel.config) {
            var itemChanged = !panel.config || panel.config.getAttribute('data-itemtype') !== config.getAttribute('data-itemtype');
            panel.config = config;
            panel.options = parseJson(config.getAttribute('data-options'), []);
            parts.title.textContent = config.getAttribute('data-title') || '';
            if (itemChanged) {
                parts.sample.value = config.getAttribute('data-sample') || '';
                panelLoad();
            } else {
                panelSync();
            }
        }
    }

    // ------------------------------------------------------------------ run

    function run() {
        if (PICK_MODE) {
            pickScan();
            return;
        }
        loadItemConfig();
        applyItemMarks();
        setupNotices();
        setupPickerPanel();
        loadDateConfig();
        linkDates();
    }

    var scheduled = false;
    function schedule() {
        if (scheduled) {
            return;
        }
        scheduled = true;
        window.setTimeout(function () {
            scheduled = false;
            run();
        }, 150);
    }

    new MutationObserver(schedule).observe(document.documentElement, { childList: true, subtree: true });
    document.addEventListener('DOMContentLoaded', function () {
        if (PICK_MODE) {
            startPickMode();
        }
        run();
    });
})();
