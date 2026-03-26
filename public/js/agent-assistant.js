/**
 * Agent Assistant — Technician panel  v1.0.0
 *
 * Injects a floating AI suggestion panel on Ticket view pages.
 * Fetches suggestion from /ajax/analyze.php and renders:
 *   - Confidence meter (0–100 %)
 *   - Suggestion text with Markdown-lite rendering
 *   - Explainability: source ticket refs
 *   - Actions: "Utilizei" (used) / "Ignorar" (dismissed)
 */
(function () {
  'use strict';

  /* ── Config ──────────────────────────────────────────────────────────────── */

  var PLUGIN_BASE   = window.CFG_GLPI
    ? (CFG_GLPI.root_doc + '/plugins/agentassistant')
    : '/plugins/agentassistant';
  var ANALYZE_URL   = PLUGIN_BASE + '/ajax/analyze.php';
  var FEEDBACK_URL  = PLUGIN_BASE + '/ajax/feedback.php';
  var PANEL_ID      = 'aa-panel';
  var STORAGE_KEY   = 'aa_dismissed_';

  /* ── Entry point ─────────────────────────────────────────────────────────── */

  function init() {
    var ticketId = extractTicketId();
    if (!ticketId) return;

    // Avoid double-init (SPA navigation)
    if (document.getElementById(PANEL_ID)) return;

    // Check if user dismissed this ticket's suggestion already
    if (sessionStorage.getItem(STORAGE_KEY + ticketId) === '1') return;

    injectPanel(ticketId);
    fetchSuggestion(ticketId);
  }

  /* ── Ticket ID extraction ─────────────────────────────────────────────────── */

  function extractTicketId() {
    // GLPI 11 Symfony: /Ticket/123  or  /front/ticket.form.php?id=123
    var m = window.location.pathname.match(/\/Ticket\/(\d+)/i);
    if (m) return parseInt(m[1], 10);

    var q = new URLSearchParams(window.location.search);
    var id = parseInt(q.get('id') || '0', 10);
    if (id > 0 && window.location.pathname.indexOf('ticket') !== -1) return id;

    return null;
  }

  /* ── Panel injection ─────────────────────────────────────────────────────── */

  function injectPanel(ticketId) {
    var panel = document.createElement('div');
    panel.id = PANEL_ID;
    panel.className = 'aa-panel';
    panel.dataset.ticketId = ticketId;
    panel.innerHTML = [
      '<div class="aa-header">',
        '<span class="aa-icon">&#129302;</span>',
        '<span class="aa-title">Agente IA</span>',
        '<button class="aa-close" title="Fechar" onclick="AAPanel.close()">&#x2715;</button>',
      '</div>',
      '<div class="aa-body" id="aa-body">',
        '<div class="aa-loading">',
          '<div class="aa-spinner"></div>',
          '<span>Analisando chamado...</span>',
        '</div>',
      '</div>',
    ].join('');

    document.body.appendChild(panel);

    // Make draggable
    makeDraggable(panel);
  }

  /* ── Fetch & render ──────────────────────────────────────────────────────── */

  function fetchSuggestion(ticketId) {
    fetch(ANALYZE_URL + '?ticket_id=' + ticketId, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var s = data && data.suggestion;
      renderPanel(s, ticketId);
    })
    .catch(function () {
      renderError();
    });
  }

  function renderPanel(suggestion, ticketId) {
    var body = document.getElementById('aa-body');
    if (!body) return;

    if (!suggestion || !suggestion.text) {
      body.innerHTML = '<div class="aa-empty"><i class="ti ti-info-circle"></i> Nenhuma sugestao disponivel para este chamado.</div>';
      return;
    }

    var conf       = parseInt(suggestion.confidence_score, 10) || 0;
    var confClass  = conf >= 80 ? 'aa-conf-high' : (conf >= 50 ? 'aa-conf-med' : 'aa-conf-low');
    var confLabel  = conf >= 80 ? 'Alta confianca' : (conf >= 50 ? 'Possivel solucao' : 'Incerto');
    var sourceIds  = Array.isArray(suggestion.source_ids) ? suggestion.source_ids : [];
    var explanation = suggestion.explanation || '';

    /* Confidence bar */
    var confHtml = [
      '<div class="aa-conf-bar-wrap">',
        '<div class="aa-conf-bar ' + confClass + '" style="width:' + conf + '%"></div>',
      '</div>',
      '<div class="aa-conf-label ' + confClass + '">',
        '<strong>' + conf + '%</strong> — ' + confLabel,
      '</div>',
    ].join('');

    /* Source refs */
    var refsHtml = '';
    if (sourceIds.length > 0) {
      var links = sourceIds.map(function (id) {
        return '<a href="/front/ticket.form.php?id=' + id + '" target="_blank">#' + id + '</a>';
      });
      refsHtml = [
        '<div class="aa-refs">',
          '<i class="ti ti-link"></i> Baseado em: ' + links.join(', '),
        '</div>',
      ].join('');
    }

    /* Explanation */
    var explHtml = explanation
      ? '<div class="aa-expl"><i class="ti ti-info-circle"></i> ' + escHtml(explanation) + '</div>'
      : '';

    /* Suggestion text */
    var textHtml = '<div class="aa-text">' + mdToHtml(suggestion.text) + '</div>';

    /* Actions */
    var actHtml = [
      '<div class="aa-actions">',
        '<button class="btn btn-sm btn-success aa-btn-used" ',
          'onclick="AAPanel.feedback(' + suggestion.id + ',' + ticketId + ',\'used\')">',
          '<i class="ti ti-check me-1"></i>Utilizei a sugestao',
        '</button>',
        '<button class="btn btn-sm btn-outline-secondary aa-btn-dismiss" ',
          'onclick="AAPanel.feedback(' + suggestion.id + ',' + ticketId + ',\'dismissed\')">',
          'Ignorar',
        '</button>',
      '</div>',
    ].join('');

    body.innerHTML = confHtml + refsHtml + explHtml + textHtml + actHtml;
  }

  function renderError() {
    var body = document.getElementById('aa-body');
    if (body) {
      body.innerHTML = '<div class="aa-empty text-danger"><i class="ti ti-alert-circle"></i> Erro ao carregar sugestao.</div>';
    }
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
      body: JSON.stringify({
        suggestion_id: suggestionId,
        ticket_id: ticketId,
        action: action,
      }),
    })
    .then(function () {
      if (action === 'dismissed') {
        sessionStorage.setItem(STORAGE_KEY + ticketId, '1');
        closePanel();
      } else {
        var body = document.getElementById('aa-body');
        if (body) {
          body.insertAdjacentHTML('afterbegin',
            '<div class="aa-success"><i class="ti ti-circle-check"></i> Obrigado pelo feedback! A sugestao foi registrada como utilizada.</div>'
          );
        }
        // Disable action buttons
        document.querySelectorAll('.aa-btn-used, .aa-btn-dismiss').forEach(function (b) {
          b.disabled = true;
          b.classList.add('opacity-50');
        });
      }
    })
    .catch(function () {});
  }

  /* ── Panel controls ─────────────────────────────────────────────────────── */

  function closePanel() {
    var panel = document.getElementById(PANEL_ID);
    if (panel) panel.remove();
  }

  /* ── Markdown-lite renderer ─────────────────────────────────────────────── */

  function mdToHtml(text) {
    if (!text) return '';
    var html = escHtml(text);

    // Headers ## → <h5>
    html = html.replace(/^##\s+(.+)$/gm, '<h5 class="aa-md-h">$1</h5>');
    html = html.replace(/^###\s+(.+)$/gm, '<h6 class="aa-md-h">$1</h6>');

    // Bold **text**
    html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    // Bullet lists (lines starting with - or *)
    html = html.replace(/^[\-\*]\s+(.+)$/gm, '<li>$1</li>');
    html = html.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');

    // Numbered lists: "1. text"
    html = html.replace(/^\d+\.\s+(.+)$/gm, '<li>$1</li>');

    // Line breaks
    html = html.replace(/\n{2,}/g, '</p><p>');
    html = html.replace(/\n/g, '<br>');

    return '<p>' + html + '</p>';
  }

  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ── Draggable ──────────────────────────────────────────────────────────── */

  function makeDraggable(panel) {
    var header = panel.querySelector('.aa-header');
    if (!header) return;

    var startX, startY, startL, startT;

    header.addEventListener('mousedown', function (e) {
      if (e.target.classList.contains('aa-close')) return;
      startX = e.clientX;
      startY = e.clientY;
      var rect = panel.getBoundingClientRect();
      startL = rect.left;
      startT = rect.top;

      function onMove(e) {
        var dx = e.clientX - startX;
        var dy = e.clientY - startY;
        panel.style.left   = (startL + dx) + 'px';
        panel.style.top    = (startT + dy) + 'px';
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

  /* ── Public API ─────────────────────────────────────────────────────────── */

  window.AAPanel = {
    close: closePanel,
    feedback: sendFeedback,
  };

  /* ── Bootstrap ──────────────────────────────────────────────────────────── */

  // Run after DOM is ready; also handles GLPI SPA navigation
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Re-run on GLPI Symfony router navigation (popstate)
  window.addEventListener('popstate', function () {
    setTimeout(init, 300);
  });
}());
