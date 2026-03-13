/**
 * Past Events Filter Bar — past-filter-bar.js
 *
 * Handles:
 *  - select change → fetch new URL → swap .events + selects + pills in place
 *  - pill remove links + clear link → same fetch+swap
 *  - loading state during fetch
 *  - browser back/forward (popstate)
 *
 * Root scoping: if a [data-spk-filter-root] element exists on the page,
 * all DOM queries for live elements are scoped to it. This allows a second
 * filter bar / events block to coexist without interference. Falls back to
 * document if no root element is present, so existing single-bar layouts
 * need no template changes.
 */

(function () {
    'use strict';

    var RESULTS_SEL = '.events';
    var SELECTS_SEL = '[data-spk-select]';

    /** Returns the scoping root, or document as fallback. */
    function getRoot() {
        return document.querySelector('[data-spk-filter-root]') || document;
    }

    function setLoading(on) {
        var results = getRoot().querySelector(RESULTS_SEL);
        if (results) {
            results.style.transition = 'opacity 0.15s';
            results.style.opacity    = on ? '0.4' : '1';
        }
        getRoot().querySelectorAll(SELECTS_SEL).forEach(function (s) {
            s.disabled = on;
        });
    }

    function swapSelects(doc) {
        var newSelects = doc.querySelectorAll(SELECTS_SEL);
        var curSelects = getRoot().querySelectorAll(SELECTS_SEL);
        curSelects.forEach(function (sel, i) {
            if (newSelects[i]) {
                sel.innerHTML = newSelects[i].innerHTML;
                sel.className = newSelects[i].className;
            }
        });
    }

    function swapPills(doc) {
        var pillWrap = getRoot().querySelector('[data-spk-pills]');
        if (!pillWrap) return;
        var newPillWrap = doc.querySelector('[data-spk-pills]');
        pillWrap.innerHTML = newPillWrap ? newPillWrap.innerHTML : '';
    }

    function swapClear(doc) {
        var clearWrap = getRoot().querySelector('[data-spk-clear]');
        if (!clearWrap) return;
        var newClearWrap = doc.querySelector('[data-spk-clear]');
        clearWrap.innerHTML = newClearWrap ? newClearWrap.innerHTML : '';
    }

    function isSameOrigin(url) {
        try {
            return new URL(url, location.origin).origin === location.origin;
        } catch (e) {
            return false;
        }
    }

    function navigate(url) {
        if (!url) return;
        if (!isSameOrigin(url)) return;
        setLoading(true);

        fetch(url)
            .then(function (res) {
                if (!res.ok) throw new Error('HTTP ' + res.status);
                return res.text();
            })
            .then(function (html) {
                try {
                    var parser     = new DOMParser();
                    var doc        = parser.parseFromString(html, 'text/html');
                    var newResults = doc.querySelector(RESULTS_SEL);
                    var results    = getRoot().querySelector(RESULTS_SEL);

                    if (newResults && results) {
                        newResults.querySelectorAll('.brx-query-trail').forEach(function (el) {
                            el.parentNode.removeChild(el);
                        });
                        results.innerHTML = newResults.innerHTML;
                    }

                    swapSelects(doc);
                    swapPills(doc);
                    swapClear(doc);

                    history.pushState(null, '', url);
                } catch (swapErr) {
                    history.pushState(null, '', url);
                }
                setLoading(false);
            })
            .catch(function () {
                window.location.href = url;
            });
    }

    function init() {
        // Delegated listeners stay on document — they must catch events that
        // bubble up from elements injected after page load by Bricks.
        document.addEventListener('change', function (e) {
            var sel = e.target.closest(SELECTS_SEL);
            if (!sel) return;
            // Ignore selects outside our root when a root is present.
            var root = getRoot();
            if (root !== document && !root.contains(sel)) return;
            navigate(sel.value);
        });

        document.addEventListener('click', function (e) {
            var el = e.target.closest('.spk-filter__pill-remove, .spk-filter__clear-all');
            if (!el) return;
            var root = getRoot();
            if (root !== document && !root.contains(el)) return;
            e.preventDefault();
            navigate(el.href);
        });

        window.addEventListener('popstate', function () {
            var currentPath = window.location.pathname;
            var initialPath = document.documentElement.dataset.spkPath;
            if (initialPath && currentPath !== initialPath) {
                window.location.reload();
                return;
            }
            navigate(window.location.href);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
