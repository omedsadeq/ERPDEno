/**
 * Shared helpers for the ERP till screens (sales + purchases).
 *
 * Extracted rather than copied into both pages: the two grids differ in their
 * columns and totals, but the number formatting, HTML escaping and the
 * keyboard-driven autocomplete are identical, and a lookup popup that behaves
 * differently on the sales and purchase screens is exactly the kind of drift
 * that trains staff to distrust one of them.
 *
 * Exposes window.ErpUI. No dependencies beyond the browser.
 */
(function () {
    'use strict';

    // acItems holds every row of the open list (headings included); acSel the
    // indices of the rows that can actually be picked.
    var acEl = null, acItems = [], acSel = [], acIdx = -1, acPick = null, acAnchor = null;

    function nf(n) {
        return Number(n || 0).toLocaleString('en-US');
    }

    /** Quantities print without trailing zeros: 12 not 12.000, but 2.5 stays. */
    function qf(n) {
        var v = Number(n || 0);
        if (Number.isInteger(v)) return v.toLocaleString('en-US');
        return String(parseFloat(v.toFixed(3)));
    }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /** A product's «group · type» for the lists and grids ('' when it has neither). */
    function groupType(p) {
        return [p && p.category_name, p && p.type_name].filter(Boolean).join(' · ');
    }

    // The list's look lives here, so every page gets the same one. The extra
    // class outranks the older per-page .ac-box rules.
    var cssDone = false;
    function ensureCss() {
        if (cssDone) return;
        cssDone = true;
        var st = document.createElement('style');
        st.textContent =
            '.ac-box.ac-v2{position:fixed;z-index:1150;min-width:0;max-height:none;overflow:auto;padding:5px;background:#fff;'
          + 'border:1px solid #d6dee6;border-radius:12px;box-shadow:0 20px 44px -14px rgba(15,23,42,.38);font-family:inherit}'
          + '.ac-v2 .ac-item{padding:8px 11px;border:0;border-radius:9px;cursor:pointer;font-size:13px;line-height:1.45}'
          + '.ac-v2 .ac-item+.ac-item{margin-top:1px}'
          + '.ac-v2 .ac-item.on,.ac-v2 .ac-item:hover{background:#e8f2fc}'
          + '.ac-v2 .ac-item.on{box-shadow:inset 3px 0 0 #1565c0}'
          + '.ac-v2 .ac-row{display:flex;align-items:center;gap:8px;min-width:0}'
          + '.ac-v2 .ac-label{flex:0 1 auto;min-width:0;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
          + '.ac-v2 .ac-tag{flex:0 0 auto;padding:1px 8px;border-radius:999px;background:#e0f2fe;color:#0369a1;font-size:11px;font-weight:700;white-space:nowrap}'
          + '.ac-v2 .meta{margin-top:1px;font-size:11.5px;color:#64748b}'
          + '.ac-v2 .ac-head{position:sticky;top:-5px;z-index:1;margin:5px 0 2px;padding:5px 11px;border-radius:7px;background:#f1f5f9;'
          + 'color:#334155;font-size:11.5px;font-weight:800;letter-spacing:.2px}'
          + '.ac-v2 .ac-head:first-child{margin-top:0}'
          + '.ac-v2 .ac-empty{padding:14px 12px;text-align:center;color:#94a3b8;font-size:12.5px}'
          + '.ac-v2 .ac-item.ac-add .ac-label{color:#b45309}';
        document.head.appendChild(st);
    }

    // Any scroll on the page (the content pane, the grid, a widget updating
    // itself) moves the list with its box; it only closes once the box itself
    // has scrolled out of sight. Closing on every scroll lost the list to
    // scrolls the user never made.
    function onWinScroll(e) {
        if (!acEl || (e.target instanceof Node && acEl.contains(e.target))) return;
        var r = acAnchor ? acAnchor.getBoundingClientRect() : null;
        if (!r || r.bottom < 0 || r.top > document.documentElement.clientHeight || (r.width === 0 && r.height === 0)) {
            hideAC();
        } else {
            place();
        }
    }
    function onWinResize() { if (acEl) place(); }

    function hideAC() {
        if (acEl) { acEl.remove(); acEl = null; }
        window.removeEventListener('scroll', onWinScroll, true);
        window.removeEventListener('resize', onWinResize);
        acItems = []; acSel = []; acIdx = -1; acPick = null; acAnchor = null;
    }

    /**
     * Put the list under its box — or above it when there is no room below —
     * kept inside the window. In a right-to-left page it lines up with the
     * box's RIGHT edge and grows to the left, away from the sidebar.
     */
    function place() {
        if (!acEl || !acAnchor) return;
        var r = acAnchor.getBoundingClientRect();
        var vw = document.documentElement.clientWidth, vh = document.documentElement.clientHeight;
        var width = Math.min(Math.max(r.width, 360), vw - 16);
        var rtl = getComputedStyle(acAnchor).direction === 'rtl';
        var left = rtl ? r.right - width : r.left;
        left = Math.max(8, Math.min(left, vw - width - 8));
        acEl.style.width = width + 'px';
        acEl.style.left = left + 'px';

        var below = vh - r.bottom - 10, above = r.top - 10;
        var up = below < 220 && above > below;
        acEl.style.maxHeight = Math.max(120, Math.min(380, up ? above : below)) + 'px';
        acEl.style.top = up ? Math.max(8, r.top - 4 - acEl.offsetHeight) + 'px' : (r.bottom + 4) + 'px';
    }

    /**
     * Anchored dropdown list.
     * @param {Element}  anchor  input to position under
     * @param {Array}    items   [{label, tag?, meta?, data, add?}] — plus, not
     *                           pickable: {header: 'Antibiotic'} and {empty: 'text'}
     * @param {Function} onPick  receives the chosen item's `data`
     * @param {Object}   [opts]  select:false — start with nothing highlighted
     *                           (a browse list), so Enter is not a pick
     */
    function showAC(anchor, items, onPick, opts) {
        hideAC();
        if (!items || !items.length) return;
        ensureCss();
        acItems = items; acPick = onPick; acAnchor = anchor;
        acSel = [];
        items.forEach(function (it, i) { if (it.header === undefined && it.empty === undefined) acSel.push(i); });
        acIdx = (opts && opts.select === false) || !acSel.length ? -1 : acSel[0];

        acEl = document.createElement('div');
        acEl.className = 'ac-box ac-v2';
        acEl.setAttribute('role', 'listbox');
        acEl.innerHTML = items.map(function (it, i) {
            if (it.header !== undefined) return '<div class="ac-head">' + esc(it.header) + '</div>';
            if (it.empty !== undefined) return '<div class="ac-empty">' + esc(it.empty) + '</div>';
            return '<div class="ac-item' + (i === acIdx ? ' on' : '') + (it.add ? ' ac-add' : '') + '" data-i="' + i + '" role="option">'
                 + '<div class="ac-row"><bdi class="ac-label">' + esc(it.label) + '</bdi>'
                 + (it.tag ? '<span class="ac-tag">' + esc(it.tag) + '</span>' : '') + '</div>'
                 + (it.meta ? '<div class="meta">' + esc(it.meta) + '</div>' : '')
                 + '</div>';
        }).join('');
        document.body.appendChild(acEl);
        place();
        window.addEventListener('scroll', onWinScroll, true);
        window.addEventListener('resize', onWinResize);

        // mousedown, not click: click fires after the input's blur, by which
        // point the list may already have been dismissed.
        acEl.addEventListener('mousedown', function (e) {
            e.preventDefault();
            var el = e.target.closest('.ac-item');
            if (el) pickAC(Number(el.dataset.i));
        });
    }

    function moveAC(delta) {
        if (!acEl || !acSel.length) return;
        var at = acSel.indexOf(acIdx);
        at = Math.max(0, Math.min(acSel.length - 1, (at < 0 ? 0 : at + delta)));
        acIdx = acSel[at];
        acEl.querySelectorAll('.ac-item').forEach(function (el) {
            el.classList.toggle('on', Number(el.dataset.i) === acIdx);
        });
        var on = acEl.querySelector('.ac-item.on');
        if (on) on.scrollIntoView({ block: 'nearest' });
    }

    function pickAC(i) {
        if (!acItems[i] || acSel.indexOf(i) < 0) return;
        var cb = acPick, data = acItems[i].data;
        hideAC();
        if (cb) cb(data);
    }

    function acOpen() { return acEl !== null; }

    /**
     * Wire the arrow/enter/escape behaviour onto an input that opens a list.
     * Returns true when the key was consumed by the dropdown, so the caller
     * can skip its own Enter handling.
     */
    function acHandleKey(e) {
        if (!acEl) return false;
        if (e.key === 'ArrowDown') { e.preventDefault(); moveAC(1);  return true; }
        if (e.key === 'ArrowUp')   { e.preventDefault(); moveAC(-1); return true; }
        if (e.key === 'Enter') {
            if (acIdx < 0) { hideAC(); return false; }   // nothing pickable: let Enter through
            e.preventDefault(); pickAC(acIdx); return true;
        }
        if (e.key === 'Escape')    { hideAC(); return true; }
        return false;
    }

    document.addEventListener('click', function (e) {
        if (acEl && !acEl.contains(e.target) && e.target !== acAnchor) hideAC();
    });

    /** GET a JSON endpoint, returning null instead of throwing. */
    async function getJson(url) {
        try {
            var res = await fetch(url);
            return await res.json();
        } catch (err) {
            return null;
        }
    }

    /** POST a document as JSON with the CSRF header the project expects. */
    async function postJson(url, payload) {
        try {
            var res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.CSRF_TOKEN || ''
                },
                body: JSON.stringify(payload)
            });
            return await res.json();
        } catch (err) {
            return { ok: false, error: 'کێشەی پەیوەندی ڕوویدا' };
        }
    }

    window.ErpUI = {
        nf: nf, qf: qf, esc: esc, groupType: groupType,
        showAC: showAC, hideAC: hideAC, moveAC: moveAC, pickAC: pickAC,
        acOpen: acOpen, acHandleKey: acHandleKey,
        getJson: getJson, postJson: postJson
    };
})();
