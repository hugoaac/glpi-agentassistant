/**
 * Agent Assistant — Technician panel  v1.1.0
 *
 * Fluxo nao-bloqueante:
 *  1. Injeta painel minimizado imediatamente
 *  2. GET /ajax/analyze.php — resposta imediata
 *     a) {suggestion} → renderiza e expande
 *     b) {queued:true} → polling a cada 6s
 *  3. Posicao: canto inferior esquerdo
 */
(function () {
  'use strict';

  var PLUGIN_BASE   = window.CFG_GLPI
    ? (CFG_GLPI.root_doc + '/plugins/agentassistant')
    : '/plugins/agentassistant';
  var ANALYZE_URL   = PLUGIN_BASE + '/ajax/analyze.php';
  var FEEDBACK_URL  = PLUGIN_BASE + '/ajax/feedback.php';
  var PANEL_ID      = 'aa-panel';
  var STORAGE_KEY   = 'aa_dismissed_';
  var POLL_INTERVAL = 6000;
  var MAX_POLLS     = 20;
  var pollTimer     = null;
  var pollCount     = 0;

  /* ── Entry point ─────────────────────────────────────────────────────────── */

  function init() {
    var ticketId = extractTicketId();
    if (!ticketId) return;
    if (document.getElementById(PANEL_ID)) return;
    if (sessionStorage.getItem(STORAGE_KEY + ticketId) === '1') return;

    injectPanel(ticketId, true);

    setTimeout(function () {
      fetchSuggestion(ticketId, false);
    }, 800);
  }

  /* ── Ticket ID ───────────────────────────────────────────────────────────── */

  function extractTicketId() {
    var m = window.location.pathname.match(/\/Ticket\/(\d+)/i);
    if (m) return parseInt(m[1], 10);
    var q  = new URLSearchParams(window.location.search);
    var id = parseInt(q.get('id') || '0', 10);
    if (id > 0 && window.location.pathname.indexOf('ticket') !== -1) return id;
    return null;
  }

  /* ── Panel injection ─────────────────────────────────────────────────────── */

  function injectPanel(ticketId, minimized) {
    var panel = document.createElement('div');
    panel.id = PANEL_ID;
    panel.className = 'aa-panel' + (minimized ? ' aa-minimized' : '');
    panel.dataset.ticketId = ticketId;
    panel.innerHTML = [
      '<div class="aa-header">',
        '<span class="aa-icon">&#129302;</span>',
        '<span class="aa-title">Agente IA</span>',
        '<button class="aa-toggle" title="Expandir/Minimizar" onclick="AAPanel.toggle()">&#x25B2;</button>',
        '<button class="aa-close"  title="Fechar"            onclick="AAPanel.close()">&#x2715;</button>',
      '</div>',
      '<div class="aa-body" id="aa-body">',
        '<div class="aa-loading">',
          '<div class="aa-spinner"></div>',
          '<span>Analisando em background...</span>',
        '</div>',
      '</div>',
    ].join('');

    document.body.appendChild(panel);
    makeDraggable(panel);
  }

  /* ── Fetch & polling ─────────────────────────────────────────────────────── */

  function fetchSuggestion(ticketId, isPoll) {
    var url = ANALYZE_URL + '?ticket_id=' + ticketId + (isPoll ? '&poll=1' : '');

    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data && data.suggestion) {
        stopPolling();
        renderPanel(data.suggestion, ticketId);
        notifyReady();
      } else if (data && data.queued) {
        if (!isPoll) startPolling(ticketId);
        updateLoadingDot();
      } else {
        stopPolling();
        renderEmpty();
      }
    })
    .catch(function () {
      stopPolling();
      renderError();
    });
  }

  function startPolling(ticketId) {
    stopPolling();
    pollCount = 0;
    pollTimer = setInterval(function () {
      pollCount++;
      if (pollCount > MAX_POLLS) {
        stopPolling();
        renderEmpty();
        return;
      }
      fetchSuggestion(ticketId, true);
    }, POLL_INTERVAL);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  /* ── Panel state ─────────────────────────────────────────────────────────── */

  function expandPanel() {
    var panel = document.getElementById(PANEL_ID);
    if (!panel) return;
    panel.classList.remove('aa-minimized');
    var btn = panel.querySelector('.aa-toggle');
    if (btn) btn.innerHTML = '&#x25BC;';
  }

  function notifyReady() {
    var panel = document.getElementById(PANEL_ID);
    if (!panel) return;
    panel.classList.add('aa-ready');
    var icon = panel.querySelector('.aa-icon');
    if (icon) icon.title = 'Sugestão disponível — clique para expandir';
  }

  function togglePanel() {
    var panel = document.getElementById(PANEL_ID);
    if (!panel) return;
    var isMin = panel.classList.toggle('aa-minimized');
    var btn = panel.querySelector('.aa-toggle');
    if (btn) btn.innerHTML = isMin ? '&#x25B2;' : '&#x25BC;';
  }

  var dotCount = 0;
  function updateLoadingDot() {
    var icon = document.querySelector('#' + PANEL_ID + ' .aa-icon');
    if (!icon) return;
    dotCount = (dotCount + 1) % 4;
    icon.textContent = ['🤖', '🤖.', '🤖..', '🤖...'][dotCount];
  }

  /* ── Render ──────────────────────────────────────────────────────────────── */

  function renderPanel(suggestion, ticketId) {
    var body = document.getElementById('aa-body');
    if (!body) return;

    var conf      = parseInt(suggestion.confidence_score, 10) || 0;
    var confClass = conf >= 80 ? 'aa-conf-high' : (conf >= 50 ? 'aa-conf-med' : 'aa-conf-low');
    var confLabel = conf >= 80 ? 'Alta confianca' : (conf >= 50 ? 'Possivel solucao' : 'Incerto');
    var sourceIds = Array.isArray(suggestion.source_ids) ? suggestion.source_ids : [];

    var confHtml = [
      '<div class="aa-conf-bar-wrap">',
        '<div class="aa-conf-bar ' + confClass + '" style="width:' + conf + '%"></div>',
      '</div>',
      '<div class="aa-conf-label ' + confClass + '">',
        '<strong>' + conf + '%</strong> — ' + confLabel,
      '</div>',
    ].join('');

    var refsHtml = '';
    if (sourceIds.length > 0) {
      var links = sourceIds.map(function (id) {
        return '<a href="/front/ticket.form.php?id=' + id + '" target="_blank">#' + id + '</a>';
      });
      refsHtml = '<div class="aa-refs"><i class="ti ti-link"></i> Baseado em: ' + links.join(', ') + '</div>';
    }

    var explanation = suggestion.explanation || '';
    var explHtml = explanation
      ? '<div class="aa-expl"><i class="ti ti-info-circle"></i> ' + escHtml(explanation) + '</div>'
      : '';

    var actHtml = [
      '<div class="aa-actions">',
        '<button class="btn btn-sm btn-success aa-btn-used"',
          ' onclick="AAPanel.feedback(' + suggestion.id + ',' + ticketId + ',\'used\')">',
          '<i class="ti ti-check me-1"></i>Utilizei',
        '</button>',
        '<button class="btn btn-sm btn-outline-secondary aa-btn-dismiss"',
          ' onclick="AAPanel.feedback(' + suggestion.id + ',' + ticketId + ',\'dismissed\')">',
          'Ignorar',
        '</button>',
      '</div>',
    ].join('');

    body.innerHTML = confHtml + refsHtml + explHtml
      + '<div class="aa-text">' + mdToHtml(suggestion.text) + '</div>'
      + actHtml;

    var icon = document.querySelector('#' + PANEL_ID + ' .aa-icon');
    if (icon) icon.textContent = '🤖';
  }

  function renderEmpty() {
    var body = document.getElementById('aa-body');
    if (body) body.innerHTML = '<div class="aa-empty"><i class="ti ti-info-circle"></i> Nenhuma sugestao disponivel para este chamado.</div>';
    var icon = document.querySelector('#' + PANEL_ID + ' .aa-icon');
    if (icon) icon.textContent = '🤖';
  }

  function renderError() {
    var body = document.getElementById('aa-body');
    if (body) body.innerHTML = '<div class="aa-empty text-danger"><i class="ti ti-alert-circle"></i> Erro ao carregar sugestao.</div>';
  }

  /* ── Feedback ────────────────────────────────────────────────────────────── */

  function sendFeedback(suggestionId, ticketId, action) {
    var csrf = (document.querySelector('meta[property="glpi:csrf_token"]') || {}).getAttribute('content') || '';
    fetch(FEEDBACK_URL, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Glpi-Csrf-Token': csrf,
      },
      body: JSON.stringify({ suggestion_id: suggestionId, ticket_id: ticketId, action: action }),
    })
    .then(function () {
      if (action === 'dismissed') {
        sessionStorage.setItem(STORAGE_KEY + ticketId, '1');
        closePanel();
      } else {
        var body = document.getElementById('aa-body');
        if (body) body.insertAdjacentHTML('afterbegin',
          '<div class="aa-success"><i class="ti ti-circle-check"></i> Sugestao registrada como utilizada.</div>'
        );
        document.querySelectorAll('.aa-btn-used, .aa-btn-dismiss').forEach(function (b) {
          b.disabled = true; b.classList.add('opacity-50');
        });
      }
    })
    .catch(function () {});
  }

  /* ── Controls ────────────────────────────────────────────────────────────── */

  function closePanel() {
    stopPolling();
    var panel = document.getElementById(PANEL_ID);
    if (panel) panel.remove();
  }

  /* ── Draggable ───────────────────────────────────────────────────────────── */

  function makeDraggable(panel) {
    var header = panel.querySelector('.aa-header');
    if (!header) return;
    var startX, startY, startL, startT;
    header.addEventListener('mousedown', function (e) {
      if (e.target.classList.contains('aa-close') || e.target.classList.contains('aa-toggle')) return;
      startX = e.clientX; startY = e.clientY;
      var rect = panel.getBoundingClientRect();
      startL = rect.left; startT = rect.top;
      function onMove(e) {
        panel.style.left   = (startL + e.clientX - startX) + 'px';
        panel.style.top    = (startT + e.clientY - startY) + 'px';
        panel.style.right  = 'auto';
        panel.style.bottom = 'auto';
      }
      function onUp() {
        document.removeEventListener('mousemove', onMove);
        document.removeEventListener('mouseup', onUp);
      }
      document.addEventListener('mousemove', onMove);
      document.addEventListener('mouseup', onUp);
    });
  }

  /* ── Markdown-lite ───────────────────────────────────────────────────────── */

  function mdToHtml(text) {
    if (!text) return '';
    var html = escHtml(text);
    html = html.replace(/^##\s+(.+)$/gm,    '<h5 class="aa-md-h">$1</h5>');
    html = html.replace(/^###\s+(.+)$/gm,   '<h6 class="aa-md-h">$1</h6>');
    html = html.replace(/\*\*(.+?)\*\*/g,   '<strong>$1</strong>');
    html = html.replace(/^[\-\*]\s+(.+)$/gm,'<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>)/s,  '<ul>$1</ul>');
    html = html.replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>');
    html = html.replace(/\n{2,}/g, '</p><p>');
    html = html.replace(/\n/g, '<br>');
    return '<p>' + html + '</p>';
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── Public API ──────────────────────────────────────────────────────────── */

  window.AAPanel = { close: closePanel, toggle: togglePanel, feedback: sendFeedback };

  /* ── Bootstrap ───────────────────────────────────────────────────────────── */

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  window.addEventListener('popstate', function () { setTimeout(init, 300); });

}());
