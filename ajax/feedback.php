<?php

/**
 * agentassistant/ajax/feedback.php
 *
 * POST { suggestion_id, ticket_id, action: 'used'|'dismissed' }
 * Records technician feedback for the learning engine.
 */

include('../../../inc/includes.php');

Session::checkLoginUser();
Session::checkCSRF($_SERVER);
header('Content-Type: application/json; charset=utf-8');

use GlpiPlugin\Agentassistant\LearningEngine;

if (Session::getCurrentInterface() !== 'central') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

$suggestionId = (int) ($data['suggestion_id'] ?? 0);
$ticketId     = (int) ($data['ticket_id']     ?? 0);
$action       = trim($data['action'] ?? '');

if ($suggestionId <= 0 || $ticketId <= 0 || !in_array($action, ['used', 'dismissed'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid params']);
    exit;
}

$userId  = (int) Session::getLoginUserID();
$engine  = new LearningEngine();
$engine->recordFeedback($suggestionId, $ticketId, $action, $userId);

echo json_encode(['ok' => true]);
