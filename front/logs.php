<?php

/**
 * agentassistant/front/logs.php — Operation logs viewer.
 */

include('../../../inc/includes.php');

Session::checkRight('config', READ);

$selfUrl = Plugin::getWebDir('agentassistant') . '/front/logs.php';

Html::header('Agent Assistant — Logs', $selfUrl, 'config', 'PluginAgentassistantMenu');

global $DB;

// Pagination
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

// Filters
$filterTicket = (int)  ($_GET['ticket_id'] ?? 0);
$filterOp     = trim(   $_GET['operation'] ?? '');

$where = '1=1';
if ($filterTicket > 0) {
    $where .= " AND l.tickets_id = $filterTicket";
}
if ($filterOp !== '') {
    $esc = $DB->escape($filterOp);
    $where .= " AND l.operation = '$esc'";
}

$total = (int) $DB->doQuery("SELECT COUNT(*) AS cnt FROM `glpi_plugin_agentassistant_logs` l WHERE $where")
    ->fetch_assoc()['cnt'];

$res = $DB->doQuery(
    "SELECT l.id, l.tickets_id, l.operation, l.confidence_score,
            l.source_type, l.tokens_used, l.duration_ms, l.message, l.date_creation
     FROM `glpi_plugin_agentassistant_logs` l
     WHERE $where
     ORDER BY l.date_creation DESC
     LIMIT $perPage OFFSET $offset"
);

$totalPages = max(1, (int) ceil($total / $perPage));
?>

<div class="container-fluid mt-4 mb-5">

    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="ti ti-list fs-2 text-primary"></i>
        <div>
            <h4 class="mb-0">Agent Assistant — Logs</h4>
            <small class="text-muted"><?= $total ?> registro(s) total</small>
        </div>
        <div class="ms-auto">
            <a href="config.php" class="btn btn-sm btn-outline-secondary">
                <i class="ti ti-arrow-left me-1"></i>Configuracao
            </a>
        </div>
    </div>

    <!-- Filters -->
    <form method="get" class="row g-2 mb-3">
        <div class="col-auto">
            <input type="number" class="form-control form-control-sm" name="ticket_id"
                   placeholder="Ticket ID" value="<?= $filterTicket ?: '' ?>">
        </div>
        <div class="col-auto">
            <select class="form-select form-select-sm" name="operation">
                <option value="">Todas operacoes</option>
                <option value="analyze" <?= $filterOp === 'analyze' ? 'selected' : '' ?>>analyze</option>
                <option value="ai_call" <?= $filterOp === 'ai_call'  ? 'selected' : '' ?>>ai_call</option>
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-sm btn-outline-primary">Filtrar</button>
            <a href="logs.php" class="btn btn-sm btn-outline-secondary ms-1">Limpar</a>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-sm table-hover table-bordered align-middle">
            <thead class="table-light">
                <tr>
                    <th>Data</th>
                    <th>Chamado</th>
                    <th>Operacao</th>
                    <th>Confianca</th>
                    <th>Fonte</th>
                    <th>Tokens</th>
                    <th>Duracao</th>
                    <th>Mensagem</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($res): while ($row = $res->fetch_assoc()): ?>
                <?php
                    $confClass = match(true) {
                        (int)$row['confidence_score'] >= 80 => 'text-success',
                        (int)$row['confidence_score'] >= 50 => 'text-warning',
                        default                             => 'text-danger',
                    };
                ?>
                <tr>
                    <td class="text-nowrap small"><?= htmlspecialchars($row['date_creation']) ?></td>
                    <td>
                        <?php if ($row['tickets_id'] > 0): ?>
                        <a href="<?= Ticket::getFormURLWithID($row['tickets_id']) ?>">
                            #<?= $row['tickets_id'] ?>
                        </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><code class="small"><?= htmlspecialchars($row['operation']) ?></code></td>
                    <td>
                        <?php if ($row['confidence_score'] > 0): ?>
                        <span class="fw-semibold <?= $confClass ?>"><?= $row['confidence_score'] ?>%</span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><span class="badge bg-secondary"><?= htmlspecialchars($row['source_type']) ?: '—' ?></span></td>
                    <td class="small"><?= $row['tokens_used'] > 0 ? $row['tokens_used'] : '—' ?></td>
                    <td class="small"><?= $row['duration_ms'] ?>ms</td>
                    <td class="small text-truncate" style="max-width:260px" title="<?= htmlspecialchars($row['message'] ?? '') ?>">
                        <?= htmlspecialchars(substr($row['message'] ?? '', 0, 80)) ?>
                    </td>
                </tr>
            <?php endwhile; else: ?>
                <tr><td colspan="8" class="text-center text-muted py-3">Nenhum log encontrado.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="?page=<?= $p ?>&ticket_id=<?= $filterTicket ?>&operation=<?= urlencode($filterOp) ?>">
                    <?= $p ?>
                </a>
            </li>
            <?php endfor; ?>
        </ul>
    </nav>
    <?php endif; ?>

</div>

<?php Html::footer(); ?>
