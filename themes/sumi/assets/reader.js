/* Sumi reader: dependency-free paged RTL/LTR and vertical presentation,
   keyboard/click navigation, preloading, progress, and preference persistence. */
(function () {
    'use strict';

    var cfgEl = document.getElementById('readerConfig');
    if (!cfgEl) return;

    var cfg;
    try { cfg = JSON.parse(cfgEl.textContent); } catch (error) { return; }

    var stage = document.getElementById('readerStage');
    var zones = document.getElementById('readerZones');
    var counter = document.getElementById('rCounter');
    var endPanel = document.getElementById('readerEnd');
    var bar = document.getElementById('readerBar');
    var progressFill = document.getElementById('readerProgressFill');
    var modeBtns = document.querySelectorAll('#rModes button');
    var modeGroup = document.getElementById('rModes');
    var preferenceStatus = document.getElementById('rPreferenceStatus');
    var pageEls = Array.prototype.slice.call(stage.querySelectorAll('.page'));
    var total = pageEls.length;
    var STORE_KEY = 'koma.reader.mode';
    var current = 0;
    var statusTimer = null;
    var lastY = 0;

    function validMode(value) { return value === 'rtl' || value === 'ltr' || value === 'vertical'; }
    function readStoredMode() {
        try { var stored = localStorage.getItem(STORE_KEY); return validMode(stored) ? stored : null; }
        catch (error) { return null; }
    }
    function storeMode(next) { try { localStorage.setItem(STORE_KEY, next); } catch (error) {} }
    function announce(message, isError) {
        if (!preferenceStatus) return;
        window.clearTimeout(statusTimer);
        preferenceStatus.textContent = message;
        preferenceStatus.classList.toggle('error', Boolean(isError));
        statusTimer = window.setTimeout(function () { preferenceStatus.textContent = ''; preferenceStatus.classList.remove('error'); }, isError ? 6000 : 3000);
    }
    function setSaving(saving) {
        modeBtns.forEach(function (button) { button.disabled = saving; });
        if (modeGroup) modeGroup.setAttribute('aria-busy', saving ? 'true' : 'false');
    }

    var serverMode = validMode(cfg.initialMode) ? cfg.initialMode : (validMode(cfg.direction) ? cfg.direction : 'rtl');
    var mode = cfg.authenticated ? serverMode : (readStoredMode() || serverMode);
    if (cfg.authenticated) storeMode(mode);

    function loadImage(index) {
        if (index < 0 || index >= total) return;
        var image = pageEls[index].querySelector('img');
        if (image && !image.src && image.dataset.src) image.src = image.dataset.src;
    }
    function preloadAround(index) { loadImage(index); loadImage(index + 1); loadImage(index + 2); loadImage(index - 1); }
    function loadAll() { for (var index = 0; index < total; index++) loadImage(index); }
    function setPagedProgress() {
        if (!progressFill) return;
        progressFill.style.width = (total > 0 ? ((current + 1) / total) * 100 : 0) + '%';
    }
    function setVerticalProgress() {
        if (!progressFill || mode !== 'vertical') return;
        var distance = Math.max(1, stage.scrollHeight - window.innerHeight);
        progressFill.style.width = Math.max(0, Math.min(100, ((window.scrollY - stage.offsetTop) / distance) * 100)) + '%';
    }
    function showPage(index) {
        if (index < 0) { if (cfg.prev) window.location.href = cfg.prev; return; }
        if (index >= total) { if (cfg.next) window.location.href = cfg.next; return; }
        current = index;
        pageEls.forEach(function (element, pageIndex) { element.classList.toggle('current', pageIndex === index); });
        if (counter) counter.textContent = (index + 1) + ' / ' + total;
        preloadAround(index);
        setPagedProgress();
        window.scrollTo(0, 0);
    }
    function advance() { showPage(current + 1); }
    function retreat() { showPage(current - 1); }
    function leftAction() { mode === 'ltr' ? retreat() : advance(); }
    function rightAction() { mode === 'ltr' ? advance() : retreat(); }
    function applyMode(next) {
        if (!validMode(next)) return;
        mode = next;
        modeBtns.forEach(function (button) {
            var active = button.dataset.mode === mode;
            button.classList.toggle('on', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        var paged = mode !== 'vertical';
        stage.classList.toggle('paged', paged);
        stage.classList.toggle('webtoon', !paged);
        if (zones) zones.style.display = paged && total > 0 ? 'flex' : 'none';
        if (endPanel) endPanel.style.display = paged ? 'none' : 'block';
        if (paged) {
            if (counter) counter.style.display = '';
            if (total > 0) showPage(Math.min(current, total - 1));
            else { if (counter) counter.textContent = '0 / 0'; if (progressFill) progressFill.style.width = '0%'; }
        } else {
            if (counter) counter.style.display = 'none';
            pageEls.forEach(function (element) { element.classList.remove('current'); });
            loadAll();
            window.requestAnimationFrame(setVerticalProgress);
        }
    }

    modeBtns.forEach(function (button) {
        button.addEventListener('click', function () {
            var next = button.dataset.mode;
            if (!validMode(next) || next === mode) return;
            var previous = mode;
            applyMode(next);
            if (!cfg.authenticated) { storeMode(next); announce('Reader mode saved on this device.', false); return; }
            var csrf = document.querySelector('meta[name="csrf-token"]');
            setSaving(true);
            fetch(cfg.preferenceUrl, {
                method: 'PUT', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf ? csrf.content : ''},
                body: JSON.stringify({mode: next})
            }).then(function (response) {
                if (!response.ok) throw new Error('Preference request failed.');
                return response.json();
            }).then(function (result) {
                if (!result || !validMode(result.mode)) throw new Error('Invalid preference response.');
                applyMode(result.mode); storeMode(result.mode); announce('Reader mode saved to your account.', false);
            }).catch(function () {
                applyMode(previous); storeMode(previous); announce('Could not save reader mode. Your previous setting was restored.', true);
            }).then(function () { setSaving(false); });
        });
    });
    if (zones) zones.querySelectorAll('.z').forEach(function (zone) { zone.addEventListener('click', function () { zone.dataset.zone === 'left' ? leftAction() : rightAction(); }); });
    document.addEventListener('keydown', function (event) {
        var target = event.target;
        if (target && typeof target.matches === 'function' && (target.matches('input, textarea, select, button') || target.isContentEditable)) return;
        if (mode === 'vertical') return;
        switch (event.key) {
            case 'ArrowLeft': event.preventDefault(); leftAction(); break;
            case 'ArrowRight': event.preventDefault(); rightAction(); break;
            case 'ArrowDown':
            case ' ': event.preventDefault(); advance(); break;
            case 'ArrowUp': event.preventDefault(); retreat(); break;
        }
    });
    window.addEventListener('scroll', function () {
        if (mode !== 'vertical') return;
        var y = window.scrollY;
        if (bar) bar.classList.toggle('is-hidden', y > lastY && y > 80);
        lastY = y;
        setVerticalProgress();
    }, {passive: true});
    window.addEventListener('resize', function () { if (mode === 'vertical') setVerticalProgress(); });
    applyMode(mode);
})();
