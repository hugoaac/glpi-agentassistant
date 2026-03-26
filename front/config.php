<?php

/**
 * agentassistant/front/config.php — Admin configuration page.
 */

include('../../../inc/includes.php');

Session::checkRight('config', UPDATE);

use GlpiPlugin\Agentassistant\Config;
use GlpiPlugin\Agentassistant\LearningEngine;

$selfUrl = Plugin::getWebDir('agentassistant') . '/front/config.php';

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['aa_save'])) {
    Config::setMany([
        'enabled'              => isset($_POST['enabled'])      ? '1' : '0',
        'ai_api_key'           => trim($_POST['ai_api_key']     ?? ''),
        'ai_model'             => trim($_POST['ai_model']       ?? 'claude-sonnet-4-6'),
        'confidence_high'      => (string)(int)($_POST['confidence_high']   ?? 80),
        'confidence_medium'    => (string)(int)($_POST['confidence_medium'] ?? 50),
        'similarity_threshold' => number_format((float)($_POST['similarity_threshold'] ?? 0.35), 2, '.', ''),
        'max_similar_tickets'  => (string)(int)($_POST['max_similar_tickets'] ?? 5),
        'auto_followup'        => isset($_POST['auto_followup'])    ? '1' : '0',
        'auto_problem'         => isset($_POST['auto_problem'])     ? '1' : '0',
        'recurrence_count'     => (string)(int)($_POST['recurrence_count'] ?? 10),
        'recurrence_days'      => (string)(int)($_POST['recurrence_days']  ?? 5),
        'max_tokens'           => (string)(int)($_POST['max_tokens']       ?? 800),
    ]);

    Session::addMessageAfterRedirect('Configuracoes salvas com sucesso.', false, INFO);
    Html::redirect($selfUrl);
    exit;
}

// ── Load current values ───────────────────────────────────────────────────────
$cfg = Config::getAll();

// Learning stats
$learning = new LearningEngine();
$stats    = $learning->getStats();

Html::header('Agent Assistant — Configuracao', $selfUrl, 'config', 'PluginAgentassistantMenu');
?>

<div class="container-fluid mt-4 mb-5" id="aa-config">

    <!-- Header -->
    <div class="d-flex align-items-center gap-3 mb-4">
        <i class="ti ti-robot fs-2 text-primary"></i>
        <div>
            <h4 class="mb-0">Agent Assistant</h4>
            <small class="text-muted">Assistente de IA para tecnicos GLPI 11 — v<?= PLUGIN_AGENTASSISTANT_VERSION ?></small>
        </div>
        <div class="ms-auto d-flex gap-2">
            <a href="logs.php" class="btn btn-sm btn-outline-secondary">
                <i class="ti ti-list me-1"></i>Ver Logs
            </a>
        </div>
    </div>

    <!-- Stats row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-primary"><?= $stats['total'] ?></div>
                    <small class="text-muted">Feedbacks recebidos</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-success"><?= $stats['used'] ?></div>
                    <small class="text-muted">Sugestoes utilizadas</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-warning"><?= $stats['dismissed'] ?></div>
                    <small class="text-muted">Sugestoes descartadas</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card text-center">
                <div class="card-body py-3">
                    <div class="fs-4 fw-bold text-info"><?= $stats['use_rate'] ?>%</div>
                    <small class="text-muted">Taxa de utilizacao</small>
                </div>
            </div>
        </div>
    </div>

    <form method="post" action="<?= htmlspecialchars($selfUrl) ?>">
        <input type="hidden" name="_glpi_csrf_token" value="<?= Session::getNewCSRFToken() ?>">

        <div class="row g-4">

            <!-- ── Coluna esquerda ───────────────────────────────── -->
            <div class="col-12 col-lg-6">

                <!-- Geral -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold">
                        <i class="ti ti-settings me-2"></i>Configuracao Geral
                    </div>
                    <div class="card-body">
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="enabled"
                                   name="enabled" <?= $cfg['enabled'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="enabled">
                                Habilitar Agent Assistant
                            </label>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="auto_followup"
                                   name="auto_followup" <?= $cfg['auto_followup'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auto_followup">
                                Adicionar followup privado automaticamente (quando confianca >= alta)
                            </label>
                        </div>

                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_problem"
                                   name="auto_problem" <?= $cfg['auto_problem'] === '1' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="auto_problem">
                                Criar Problema automaticamente ao detectar recorrencia
                            </label>
                        </div>
                    </div>
                </div>

                <!-- IA -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold">
                        <i class="ti ti-brand-openai me-2"></i>Provedor de IA (Claude / Anthropic)
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="ai_api_key">API Key</label>
                            <input type="password" class="form-control" id="ai_api_key"
                                   name="ai_api_key" value="<?= htmlspecialchars($cfg['ai_api_key']) ?>"
                                   placeholder="sk-ant-...">
                            <div class="form-text">Chave da API Anthropic. Deixe em branco para usar apenas similaridade local.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="ai_model">Modelo</label>
                            <select class="form-select" id="ai_model" name="ai_model">
                                <?php
                                $models = [
                                    'claude-sonnet-4-6' => 'Claude Sonnet 4.6 (recomendado)',
                                    'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5 (rapido)',
                                    'claude-opus-4-6'   => 'Claude Opus 4.6 (avancado)',
                                ];
                                foreach ($models as $val => $label):
                                ?>
                                <option value="<?= $val ?>" <?= $cfg['ai_model'] === $val ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-0">
                            <label class="form-label fw-semibold" for="max_tokens">Max tokens por resposta</label>
                            <input type="number" class="form-control" id="max_tokens"
                                   name="max_tokens" value="<?= (int)$cfg['max_tokens'] ?>"
                                   min="200" max="2000" step="100">
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── Coluna direita ────────────────────────────────── -->
            <div class="col-12 col-lg-6">

                <!-- Confiança -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold">
                        <i class="ti ti-chart-bar me-2"></i>Limiares de Confianca
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="confidence_high">
                                Confianca alta (%): <span class="badge bg-success" id="lbl_high"><?= $cfg['confidence_high'] ?>%</span>
                            </label>
                            <input type="range" class="form-range" id="confidence_high"
                                   name="confidence_high" min="50" max="100" step="5"
                                   value="<?= (int)$cfg['confidence_high'] ?>"
                                   oninput="document.getElementById('lbl_high').textContent=this.value+'%'">
                            <div class="form-text">Acima deste valor: followup privado adicionado automaticamente.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold" for="confidence_medium">
                                Confianca media (%): <span class="badge bg-warning text-dark" id="lbl_med"><?= $cfg['confidence_medium'] ?>%</span>
                            </label>
                            <input type="range" class="form-range" id="confidence_medium"
                                   name="confidence_medium" min="20" max="80" step="5"
                                   value="<?= (int)$cfg['confidence_medium'] ?>"
                                   oninput="document.getElementById('lbl_med').textContent=this.value+'%'">
                            <div class="form-text">Acima deste valor: sugestao exibida no painel do tecnico.</div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label fw-semibold" for="similarity_threshold">
                                Limiar de similaridade: <span class="badge bg-secondary" id="lbl_sim"><?= $cfg['similarity_threshold'] ?></span>
                            </label>
                            <input type="range" class="form-range" id="similarity_threshold"
                                   name="similarity_threshold" min="0.10" max="0.90" step="0.05"
                                   value="<?= $cfg['similarity_threshold'] ?>"
                                   oninput="document.getElementById('lbl_sim').textContent=parseFloat(this.value).toFixed(2)">
                            <div class="form-text">Similaridade coseno minima para considerar um chamado como similar (0.0–1.0).</div>
                        </div>
                    </div>
                </div>

                <!-- Recorrência -->
                <div class="card mb-3">
                    <div class="card-header fw-semibold">
                        <i class="ti ti-refresh me-2"></i>Deteccao de Recorrencia
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-6">
                                <label class="form-label fw-semibold" for="recurrence_count">
                                    Minimo de chamados
                                </label>
                                <input type="number" class="form-control" id="recurrence_count"
                                       name="recurrence_count" value="<?= (int)$cfg['recurrence_count'] ?>"
                                       min="2" max="100">
                            </div>
                            <div class="col-6">
                                <label class="form-label fw-semibold" for="recurrence_days">
                                    Janela de dias
                                </label>
                                <input type="number" class="form-control" id="recurrence_days"
                                       name="recurrence_days" value="<?= (int)$cfg['recurrence_days'] ?>"
                                       min="1" max="90">
                            </div>
                        </div>
                        <div class="form-text mt-2">
                            Se X chamados similares forem abertos em Y dias → cria Problema automaticamente.
                        </div>
                    </div>
                </div>

                <!-- Similaridade -->
                <div class="card">
                    <div class="card-header fw-semibold">
                        <i class="ti ti-search me-2"></i>Busca de Similaridade
                    </div>
                    <div class="card-body">
                        <label class="form-label fw-semibold" for="max_similar_tickets">
                            Max chamados similares retornados
                        </label>
                        <input type="number" class="form-control" id="max_similar_tickets"
                               name="max_similar_tickets" value="<?= (int)$cfg['max_similar_tickets'] ?>"
                               min="1" max="20">
                    </div>
                </div>

            </div>

        </div><!-- /row -->

        <div class="mt-3 d-flex justify-content-end">
            <button type="submit" name="aa_save" value="1" class="btn btn-primary px-4">
                <i class="ti ti-device-floppy me-1"></i>Salvar configuracoes
            </button>
        </div>

    </form>

</div>

<?php Html::footer(); ?>
