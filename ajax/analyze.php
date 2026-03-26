<?php

/**
 * agentassistant/ajax/analyze.php
 *
 * GET  ?ticket_id=X  → return existing suggestion (or trigger analysis if none)
 * POST {ticket_id}   → force re-analysis
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
header('Content-Type: application/json; charset=utf-8');

use GlpiPlugin\Agentassistant\Config;
use GlpiPlugin\Agentassistant\SuggestionEngine;

// Only technicians
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

// Check ticket READ access
$ticket = new Ticket();
if (!$ticket->canViewItem() || !$ticket->getFromDB($ticketId)) {
    http_response_code(403);
    echo json_encode(['error' => 'access denied']);
    exit;
}

$engine = new SuggestionEngine();

// POST = force re-analysis
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $engine->analyze($ticketId);
    echo json_encode($result ?? ['error' => 'no suggestion generated']);
    exit;
}

// GET = return cached suggestion, trigger if none exists
$suggestion = $engine->getForTicket($ticketId);

if ($suggestion === null) {
    // Enqueue and process inline (small overhead acceptable for first load)
    $result     = $engine->analyze($ticketId);
    $suggestion = $result !== null ? $engine->getForTicket($ticketId) : null;
}

if ($suggestion === null) {
    echo json_encode(['suggestion' => null]);
    exit;
}

echo json_encode(['suggestion' => $suggestion]);
