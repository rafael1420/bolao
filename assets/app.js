/* Melhorias progressivas. A página funciona sem este arquivo:
   - contagem regressiva dos jogos (o texto inicial vem do servidor);
   - botões − e + nos placares;
   - copiar e compartilhar link;
   - mostrar ou ocultar senha;
   - contador "x de y preenchidos" e aviso de alterações não salvas;
   - botão de exclusão só libera depois de digitar o nome do bolão;
   - botões de envio mostram "Salvando…" e não enviam duas vezes;
   - botão de tema claro ou escuro (a escolha fica no localStorage);
   - seletores com data-autosubmit enviam o formulário ao trocar a opção. */
(function () {
    'use strict';

    var DAYS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    var BRT_OFFSET = 3 * 3600 * 1000; // Brasília, sem horário de verão desde 2019

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    /** Mesmo formato de includes/app.php (kick_clock). Recebe milissegundos. */
    function clock(ms) {
        var d = new Date(ms - BRT_OFFSET);
        var h = d.getUTCHours(), m = d.getUTCMinutes();
        return m ? h + 'h' + pad(m) : h + 'h';
    }

    function dayClock(ms, now) {
        var d = new Date(ms - BRT_OFFSET);
        if (ms - now >= 6 * 86400000) {
            return pad(d.getUTCDate()) + '/' + pad(d.getUTCMonth() + 1) + ', ' + clock(ms);
        }
        return DAYS[d.getUTCDay()] + ', ' + clock(ms);
    }

    /** Mesmo texto de kickoff_phrase() em app.php. */
    function phrase(kick, now, first) {
        var diff = kick - now;
        if (diff <= 0) {
            return (first ? 'fechou' : 'Fechou') + ' às ' + clock(kick);
        }
        var core;
        if (diff >= 86400000) {
            core = dayClock(kick, now);
        } else if (diff >= 3600000) {
            var h = Math.floor(diff / 3600000);
            var m = Math.floor((diff % 3600000) / 60000);
            core = 'em ' + h + ' h' + (m > 0 ? ' ' + m + ' min' : '');
        } else if (diff >= 60000) {
            core = 'em ' + Math.floor(diff / 60000) + ' min';
        } else {
            core = 'em menos de 1 min';
        }
        return first ? core : 'Fecha ' + core;
    }

    /* ---------- Contagem regressiva ---------- */
    function closeCard(li) {
        li.setAttribute('data-open', '0');
        li.classList.remove('is-open');
        li.classList.add('is-closed');
        var inputs = li.querySelectorAll('input.tile');
        for (var i = 0; i < inputs.length; i++) { inputs[i].disabled = true; }
        var steps = li.querySelectorAll('.step');
        for (var j = 0; j < steps.length; j++) { steps[j].hidden = true; }
    }

    function tick() {
        var now = Date.now();
        var SOON = 3600000; // "fechando em breve": falta menos de 1 hora
        var cards = document.querySelectorAll('li.match[data-kick]');
        var openCount = 0;
        var firstOpen = null;

        for (var i = 0; i < cards.length; i++) {
            var li = cards[i];
            if (li.getAttribute('data-open') !== '1') { continue; }
            var kick = parseInt(li.getAttribute('data-kick'), 10) * 1000;
            if (kick <= now) {
                closeCard(li);
                li.classList.remove('is-soon');
                var t = li.querySelector('time[data-kickoff]');
                if (t) { t.textContent = phrase(kick, now, false); t.classList.remove('is-soon'); }
                continue;
            }
            openCount++;
            li.classList.toggle('is-soon', kick - now < SOON);
            if (firstOpen === null || kick < firstOpen) { firstOpen = kick; }
            var time = li.querySelector('time[data-kickoff]');
            if (time) {
                time.textContent = phrase(kick, now, false);
                time.classList.toggle('is-soon', kick - now < 600000);
            }
        }

        var header = document.querySelector('time[data-first]');
        if (header) {
            if (firstOpen !== null) {
                header.textContent = phrase(firstOpen, now, true);
                header.setAttribute('datetime', new Date(firstOpen).toISOString());
            }
        }
        var count = document.querySelector('[data-open-count]');
        if (count) { count.textContent = openCount; }
        refreshSaveBar();
    }

    /* ---------- Placar: − e + ---------- */
    function clampScore(v) {
        if (isNaN(v)) { v = 0; }
        return Math.min(30, Math.max(0, v));
    }

    function setupSteppers() {
        var buttons = document.querySelectorAll('button.step[data-target]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].hidden = false;
            buttons[i].addEventListener('click', function (ev) {
                var btn = ev.currentTarget;
                var input = document.getElementById(btn.getAttribute('data-target'));
                if (!input || input.disabled) { return; }
                var current = input.value === '' ? 0 : parseInt(input.value, 10);
                input.value = clampScore(current + parseInt(btn.getAttribute('data-step'), 10));
                input.dispatchEvent(new Event('input', { bubbles: true }));
                // Pequeno "pulo" do número, para mostrar que mudou
                input.classList.remove('is-bump');
                void input.offsetWidth;
                input.classList.add('is-bump');
                input.focus();
            });
        }
    }

    /* ---------- Barra de salvar ---------- */
    function refreshSaveBar() {
        var bar = document.querySelector('[data-save-bar]');
        if (!bar) { return; }
        var form = bar.closest('form');
        var cards = form ? form.querySelectorAll('li.match[data-open="1"]') : [];
        var filled = 0;
        var dirty = false;
        for (var i = 0; i < cards.length; i++) {
            var inputs = cards[i].querySelectorAll('input.tile');
            var changed = false;
            if (inputs.length === 2 && inputs[0].value !== '' && inputs[1].value !== '') { filled++; }
            for (var j = 0; j < inputs.length; j++) {
                if (inputs[j].value !== inputs[j].defaultValue) { changed = true; }
            }
            cards[i].classList.toggle('is-changed', changed);
            if (changed) { dirty = true; }
        }
        var filledEl = bar.querySelector('[data-filled]');
        var totalEl = bar.querySelector('[data-total]');
        if (filledEl) { filledEl.textContent = filled; }
        if (totalEl) { totalEl.textContent = cards.length; }

        var fill = bar.querySelector('[data-progress]');
        if (fill) {
            var pct = cards.length ? Math.round(filled * 100 / cards.length) : 0;
            fill.style.width = pct + '%';
            fill.parentNode.classList.toggle('is-full', cards.length > 0 && filled === cards.length);
        }

        var badge = bar.querySelector('[data-dirty]');
        if (badge) { badge.hidden = !dirty; }
        var status = bar.querySelector('[data-save-status]');
        if (status) {
            // Guarda o texto do servidor para voltar a ele se a pessoa desfizer as mudanças.
            if (!status.hasAttribute('data-initial')) {
                status.setAttribute('data-initial', status.textContent.trim());
                status.setAttribute('data-initial-saved', status.classList.contains('is-saved') ? '1' : '0');
            }
            if (dirty) {
                status.textContent = 'Alterações ainda não salvas.';
                status.classList.remove('is-saved');
            } else {
                status.textContent = status.getAttribute('data-initial');
                status.classList.toggle('is-saved', status.getAttribute('data-initial-saved') === '1');
            }
        }
        if (form) { form.setAttribute('data-is-dirty', dirty ? '1' : '0'); }
    }

    function setupDirtyGuard() {
        var forms = document.querySelectorAll('form[data-dirty-guard]');
        for (var i = 0; i < forms.length; i++) {
            (function (form) {
                var dirty = false;
                form.addEventListener('input', function () { dirty = true; });
                form.addEventListener('submit', function () { dirty = false; });
                window.addEventListener('beforeunload', function (ev) {
                    if (dirty) { ev.preventDefault(); ev.returnValue = ''; }
                });
            })(forms[i]);
        }
    }

    /* ---------- Copiar e compartilhar ---------- */
    function copyText(text, input) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text).then(function () { return true; }, function () {
                return fallbackCopy(input);
            });
        }
        return Promise.resolve(fallbackCopy(input));
    }

    function fallbackCopy(input) {
        if (!input) { return false; }
        input.focus();
        input.select();
        try { return document.execCommand('copy'); } catch (e) { return false; }
    }

    function setupCopy() {
        var buttons = document.querySelectorAll('[data-copy]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].addEventListener('click', function (ev) {
                var btn = ev.currentTarget;
                var input = document.getElementById(btn.getAttribute('data-copy'));
                if (!input) { return; }
                var box = btn.closest('.copy-box');
                var status = box ? box.querySelector('[data-copy-status]') : null;
                copyText(input.value, input).then(function (ok) {
                    if (status) { status.textContent = ok ? 'Link copiado.' : 'Copie o link selecionado.'; }
                });
            });
        }

        var shares = document.querySelectorAll('[data-share]');
        if (navigator.share) {
            for (var j = 0; j < shares.length; j++) {
                shares[j].hidden = false;
                shares[j].addEventListener('click', function (ev) {
                    var input = document.getElementById(ev.currentTarget.getAttribute('data-share'));
                    if (!input) { return; }
                    navigator.share({ title: document.title, url: input.value }).catch(function () {});
                });
            }
        }

        var selectOnFocus = document.querySelectorAll('[data-select-on-focus]');
        for (var k = 0; k < selectOnFocus.length; k++) {
            selectOnFocus[k].addEventListener('focus', function (ev) { ev.currentTarget.select(); });
        }
    }

    /* ---------- Mostrar ou ocultar senha ---------- */
    function setupReveal() {
        var buttons = document.querySelectorAll('button.reveal[data-reveal]');
        for (var i = 0; i < buttons.length; i++) {
            buttons[i].hidden = false;
            buttons[i].addEventListener('click', function (ev) {
                var btn = ev.currentTarget;
                var input = document.getElementById(btn.getAttribute('data-reveal'));
                if (!input) { return; }
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.textContent = show ? 'Ocultar' : 'Mostrar';
                btn.setAttribute('aria-label', (show ? 'Ocultar' : 'Mostrar') + ' senha');
            });
        }
    }

    /* ---------- Exclusão: liberar o botão só com o nome certo ---------- */
    function setupRequires() {
        var buttons = document.querySelectorAll('button[data-requires]');
        for (var i = 0; i < buttons.length; i++) {
            (function (btn) {
                var form = btn.closest('form');
                var input = form ? form.querySelector('input[name="confirm_name"]') : null;
                if (!input) { return; }
                var expected = btn.getAttribute('data-requires');
                var update = function () { btn.disabled = input.value.trim() !== expected; };
                btn.disabled = true;
                input.addEventListener('input', update);
            })(buttons[i]);
        }
    }

    /* ---------- Cancelar um disclosure sem recarregar ---------- */
    function setupCancel() {
        var links = document.querySelectorAll('a[data-cancel]');
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener('click', function (ev) {
                var details = ev.currentTarget.closest('details');
                if (details) {
                    ev.preventDefault();
                    details.open = false;
                }
            });
        }
    }

    /* ---------- Exemplo de pontuação ao criar bolão e em Configurar ---------- */
    function setupPointsExample() {
        var examples = document.querySelectorAll('[data-points-example]');
        for (var i = 0; i < examples.length; i++) {
            (function (el) {
                var fieldset = el.closest('form') || document;
                var winner = fieldset.querySelector('input[name="winner_points"]');
                var diff = fieldset.querySelector('input[name="goal_diff_points"]');
                if (!winner || !diff) { return; }
                var update = function () {
                    var w = parseInt(winner.value, 10) || 0;
                    var d = parseInt(diff.value, 10) || 0;
                    var total = w + d;
                    el.textContent = 'Palpite 2 × 1 e resultado 3 × 2: acertou o vencedor (' + w +
                        ') e o saldo (+' + d + ') = ' + total + (total === 1 ? ' ponto.' : ' pontos.');
                };
                winner.addEventListener('input', update);
                diff.addEventListener('input', update);
            })(examples[i]);
        }
    }

    /* ---------- Botões de envio: "Salvando…" e sem envio duplo ---------- */
    function setupBusy() {
        var forms = document.querySelectorAll('form');
        for (var i = 0; i < forms.length; i++) {
            forms[i].addEventListener('submit', function (ev) {
                var form = ev.currentTarget;
                if (form.getAttribute('data-sent') === '1') { ev.preventDefault(); return; }
                form.setAttribute('data-sent', '1');
                var btn = ev.submitter || form.querySelector('button[type="submit"][data-busy]');
                if (btn && btn.getAttribute('data-busy')) {
                    var label = btn.getAttribute('data-busy');
                    setTimeout(function () {
                        btn.setAttribute('data-orig', btn.textContent);
                        btn.disabled = true;
                        btn.textContent = label;
                    }, 0);
                }
            });
        }
        // Voltar pelo histórico pode restaurar a página com o botão ainda travado.
        window.addEventListener('pageshow', function (ev) {
            if (!ev.persisted) { return; }
            var sent = document.querySelectorAll('form[data-sent="1"]');
            for (var i = 0; i < sent.length; i++) { sent[i].removeAttribute('data-sent'); }
            var busy = document.querySelectorAll('button[data-orig]');
            for (var j = 0; j < busy.length; j++) {
                busy[j].disabled = false;
                busy[j].textContent = busy[j].getAttribute('data-orig');
                busy[j].removeAttribute('data-orig');
            }
        });
    }

    /* ---------- Seletor que envia sozinho (ranking por rodada) ---------- */
    function setupAutosubmit() {
        var selects = document.querySelectorAll('select[data-autosubmit]');
        for (var i = 0; i < selects.length; i++) {
            (function (select) {
                var form = select.form;
                if (!form) { return; }
                // O botão "Ver" só é preciso sem JavaScript.
                var btn = form.querySelector('button[type="submit"]');
                if (btn) { btn.hidden = true; }
                select.addEventListener('change', function () {
                    if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
                });
            })(selects[i]);
        }
    }

    /* ---------- Tema claro ou escuro ---------- */
    function currentTheme() {
        var chosen = document.documentElement.getAttribute('data-theme');
        if (chosen === 'light' || chosen === 'dark') { return chosen; }
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) ? 'dark' : 'light';
    }

    function applyThemeLabel(btn) {
        var next = currentTheme() === 'dark' ? 'claro' : 'escuro';
        btn.setAttribute('aria-label', 'Mudar para o tema ' + next);
        btn.setAttribute('title', 'Tema ' + next);
    }

    function setupTheme() {
        var buttons = document.querySelectorAll('[data-theme-toggle]');
        for (var i = 0; i < buttons.length; i++) {
            (function (btn) {
                btn.hidden = false;
                applyThemeLabel(btn);
                btn.addEventListener('click', function () {
                    var next = currentTheme() === 'dark' ? 'light' : 'dark';
                    document.documentElement.setAttribute('data-theme', next);
                    try { localStorage.setItem('bolao-tema', next); } catch (e) {}
                    var metas = document.querySelectorAll('meta[name="theme-color"]');
                    for (var j = 0; j < metas.length; j++) {
                        metas[j].setAttribute('content', next === 'light' ? '#f3f5fa' : '#0a1120');
                    }
                    applyThemeLabel(btn);
                });
            })(buttons[i]);
        }
        // Sem escolha salva, acompanha a mudança do sistema.
        if (window.matchMedia) {
            var mq = window.matchMedia('(prefers-color-scheme: dark)');
            var onChange = function () {
                for (var k = 0; k < buttons.length; k++) { applyThemeLabel(buttons[k]); }
            };
            if (mq.addEventListener) { mq.addEventListener('change', onChange); } else if (mq.addListener) { mq.addListener(onChange); }
        }
    }

    /* ---------- Rolar a faixa de rodadas até a rodada atual ---------- */
    function setupCurrentRound() {
        var strip = document.querySelector('[data-current-scroll]');
        var current = strip ? strip.querySelector('[data-current]') : null;
        if (!strip || !current) { return; }
        var target = current.offsetLeft - (strip.clientWidth / 2) + (current.offsetWidth / 2);
        strip.scrollLeft = Math.max(0, target);
    }

    function init() {
        setupTheme();
        setupAutosubmit();
        setupSteppers();
        setupReveal();
        setupCopy();
        setupDirtyGuard();
        setupRequires();
        setupCancel();
        setupPointsExample();
        setupBusy();
        setupCurrentRound();
        tick();
        setInterval(function () {
            if (document.visibilityState !== 'hidden') { tick(); }
        }, 30000);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') { tick(); }
        });
        // O formulário de palpites: contador ao digitar.
        document.addEventListener('input', function (ev) {
            if (ev.target && ev.target.closest && ev.target.closest('.matches-form')) { refreshSaveBar(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
