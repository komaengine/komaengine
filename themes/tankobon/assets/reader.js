/* Aozora reader — paged RTL/LTR + vertical webtoon, keyboard nav, preloading.
   Dependency-free vanilla JS so the theme needs no build step. */
(function () {
    'use strict';

    var cfgEl = document.getElementById('readerConfig');
    if (!cfgEl) return;
    var cfg = JSON.parse(cfgEl.textContent);

    var stage = document.getElementById('readerStage');
    var zones = document.getElementById('readerZones');
    var counter = document.getElementById('rCounter');
    var endPanel = document.getElementById('readerEnd');
    var bar = document.getElementById('readerBar');
    var modeBtns = document.querySelectorAll('#rModes button');
    var modeGroup = document.getElementById('rModes');
    var preferenceStatus = document.getElementById('rPreferenceStatus');
    var pageEls = Array.prototype.slice.call(stage.querySelectorAll('.page'));
    var total = pageEls.length;

    var STORE_KEY = 'koma.reader.mode';
    var current = 0;
    var statusTimer = null;

    function validMode(m) {
        return m === 'rtl' || m === 'ltr' || m === 'vertical';
    }

    function readStoredMode() {
        try {
            var stored = localStorage.getItem(STORE_KEY);
            return validMode(stored) ? stored : null;
        } catch (error) {
            return null;
        }
    }

    function storeMode(next) {
        try {
            localStorage.setItem(STORE_KEY, next);
        } catch (error) {
            // Storage can be unavailable in hardened/private browser contexts.
        }
    }

    function announce(message, isError) {
        if (!preferenceStatus) return;
        window.clearTimeout(statusTimer);
        preferenceStatus.textContent = message;
        preferenceStatus.classList.toggle('error', Boolean(isError));
        statusTimer = window.setTimeout(function () {
            preferenceStatus.textContent = '';
            preferenceStatus.classList.remove('error');
        }, isError ? 6000 : 3000);
    }

    function setSaving(saving) {
        modeBtns.forEach(function (button) { button.disabled = saving; });
        if (modeGroup) modeGroup.setAttribute('aria-busy', saving ? 'true' : 'false');
    }

    var serverMode = validMode(cfg.initialMode)
        ? cfg.initialMode
        : (validMode(cfg.direction) ? cfg.direction : 'rtl');
    var mode = cfg.authenticated ? serverMode : (readStoredMode() || serverMode);

    // Signed-in loads are server-authoritative; this cache keeps later anonymous
    // transitions on the same device responsive without overriding the account.
    if (cfg.authenticated) storeMode(mode);

    /* ---- image loading / preloading ---- */
    function loadImage(i) {
        if (i < 0 || i >= total) return;
        var img = pageEls[i].querySelector('img');
        if (img && !img.src && img.dataset.src) img.src = img.dataset.src;
    }
    function preloadAround(i) {
        loadImage(i);
        loadImage(i + 1);
        loadImage(i + 2);
        loadImage(i - 1);
    }
    function loadAll() {
        for (var i = 0; i < total; i++) loadImage(i);
    }

    /* ---- paged mode ---- */
    function showPage(i) {
        if (i < 0) {
            if (cfg.prev) { window.location.href = cfg.prev; }
            return;
        }
        if (i >= total) {
            if (cfg.next) { window.location.href = cfg.next; }
            return;
        }
        current = i;
        pageEls.forEach(function (el, idx) {
            el.classList.toggle('current', idx === i);
        });
        counter.textContent = (i + 1) + ' / ' + total;
        preloadAround(i);
        window.scrollTo(0, 0);
    }

    function advance() { showPage(current + 1); }
    function retreat() { showPage(current - 1); }

    /* Map physical direction to logical advance/retreat. */
    function leftAction() { mode === 'ltr' ? retreat() : advance(); }
    function rightAction() { mode === 'ltr' ? advance() : retreat(); }

    /* ---- mode switching ---- */
    function applyMode(next) {
        if (!validMode(next)) return;
        mode = next;
        modeBtns.forEach(function (b) {
            var active = b.dataset.mode === mode;
            b.classList.toggle('on', active);
            b.setAttribute('aria-pressed', active ? 'true' : 'false');
        });

        var paged = mode !== 'vertical';
        stage.classList.toggle('paged', paged);
        stage.classList.toggle('webtoon', !paged);
        zones.style.display = paged && total > 0 ? 'flex' : 'none';
        endPanel.style.display = paged ? 'none' : 'block';

        if (paged) {
            counter.style.display = '';
            if (total > 0) {
                showPage(Math.min(current, total - 1));
            } else {
                counter.textContent = '0 / 0';
            }
        } else {
            counter.style.display = 'none';
            pageEls.forEach(function (el) { el.classList.remove('current'); });
            loadAll();
        }
    }

    modeBtns.forEach(function (b) {
        b.addEventListener('click', function () {
            var next = b.dataset.mode;
            if (!validMode(next) || next === mode) return;

            var previous = mode;
            applyMode(next);

            if (!cfg.authenticated) {
                storeMode(next);
                announce('Reader mode saved on this device.', false);
                return;
            }

            var csrf = document.querySelector('meta[name="csrf-token"]');
            setSaving(true);

            fetch(cfg.preferenceUrl, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf ? csrf.content : ''
                },
                body: JSON.stringify({ mode: next })
            }).then(function (response) {
                if (!response.ok) throw new Error('Preference request failed.');
                return response.json();
            }).then(function (result) {
                if (!result || !validMode(result.mode)) throw new Error('Invalid preference response.');
                applyMode(result.mode);
                storeMode(result.mode);
                announce('Reader mode saved to your account.', false);
            }).catch(function () {
                applyMode(previous);
                storeMode(previous);
                announce('Could not save reader mode. Your previous setting was restored.', true);
            }).then(function () {
                setSaving(false);
            });
        });
    });

    /* ---- click zones (paged) ---- */
    zones.querySelectorAll('.z').forEach(function (z) {
        z.addEventListener('click', function () {
            z.dataset.zone === 'left' ? leftAction() : rightAction();
        });
    });

    /* ---- keyboard ---- */
    document.addEventListener('keydown', function (e) {
        var target = e.target;
        if (target && typeof target.matches === 'function'
            && (target.matches('input, textarea, select, button') || target.isContentEditable)) return;
        if (mode === 'vertical') return; // let the browser scroll
        switch (e.key) {
            case 'ArrowLeft': e.preventDefault(); leftAction(); break;
            case 'ArrowRight': e.preventDefault(); rightAction(); break;
            case 'ArrowDown':
            case ' ': e.preventDefault(); advance(); break;
            case 'ArrowUp': e.preventDefault(); retreat(); break;
        }
    });

    /* ---- auto-hiding bar in webtoon ---- */
    var lastY = 0;
    window.addEventListener('scroll', function () {
        if (mode !== 'vertical') return;
        var y = window.scrollY;
        bar.classList.toggle('is-hidden', y > lastY && y > 80);
        lastY = y;
    }, { passive: true });

    applyMode(mode);
})();
