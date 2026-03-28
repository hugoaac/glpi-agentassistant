<?php

/**
 * agentassistant/ajax/analyze.php
 *
 * GET  ?ticket_id=X          → retorna sugestão em cache imediatamente;
 *                               se não há cache, responde {queued:true} e
 *                               roda análise em background (após flush).
 * GET  ?ticket_id=X&poll=1   → apenas verifica cache, sem disparar análise.
 * POST {ticket_id}           → força re-análise (síncrono, uso interno).
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');

use GlpiPlugin\Agentassistant\Config;
use GlpiPlugin\Agentassistant\SuggestionEngine;

// Apenas interface central (técnicos)
if (Session::getCurrentInterface() !== 'central') {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

if (!Config::getBool('enabled')) {
    echo json_encode(['error' => 'disabled']);
    exit;
}

$ticketId = (int) ($_REQUEST['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid ticket_id']);
    exit;
}

// Verifica acesso de leitura ao ticket
$ticket = new Ticket();
if (!$ticket->canViewItem() || !$ticket->getFromDB($ticketId)) {
    http_response_code(403);
    echo json_encode(['error' => 'access denied']);
    exit;
}

$engine = new SuggestionEngine();

// ── POST: força re-análise síncrona ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $engine->analyze($ticketId);
    echo json_encode($result ?? ['error' => 'no suggestion generated']);
    exit;
}

// ── GET: verifica cache ───────────────────────────────────────────────────────
$suggestion = $engine->getForTicket($ticketId);

if ($suggestion !== null) {
    // Cache hit — retorna imediatamente
    echo json_encode(['suggestion' => $suggestion]);
    exit;
}

// ── Cache miss ────────────────────────────────────────────────────────────────
// Se for polling, apenas informa que ainda não há resultado
if (!empty($_GET['poll'])) {
    echo json_encode(['suggestion' => null, 'queued' => true]);
    exit;
}

// Primeira visita: responde {queued:true} AGORA e roda análise em background
// depois que o cliente receber a resposta.
ignore_user_abort(true);

$payload = json_encode(['suggestion' => null, 'queued' => true]);
header('Content-Length: ' . strlen($payload));
header('Connection: close');
echo $payload;

// Flush para o cliente
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    ob_end_flush();
    flush();
}

// Roda análise em background (cliente já recebeu a resposta)
$engine->analyze($ticketId);
