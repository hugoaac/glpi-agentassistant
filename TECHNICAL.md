# Agent Assistant — Documentação Técnica

Plugin GLPI 11+ que analisa chamados de suporte em tempo real e fornece sugestões de solução via busca por similaridade e IA (Claude).

**Versão:** 1.0.0 | **Autor:** Hugo | **Licença:** GPL v2+

---

## Sumário

1. [Visão Geral](#visão-geral)
2. [Arquitetura](#arquitetura)
3. [Banco de Dados](#banco-de-dados)
4. [Classes do Core](#classes-do-core)
5. [Integração com GLPI](#integração-com-glpi)
6. [Endpoints AJAX](#endpoints-ajax)
7. [Frontend](#frontend)
8. [Fluxos de Dados](#fluxos-de-dados)
9. [Configuração](#configuração)
10. [Segurança e Performance](#segurança-e-performance)

---

## Visão Geral

O plugin oferece:

- **Busca por similaridade** — TF-IDF + similaridade cosseno entre chamados fechados
- **Fallback de IA** — Sugestões via Claude API quando similaridade é baixa
- **Aprendizado contínuo** — Pesos ajustados pelo feedback dos técnicos (+0.10 / -0.05)
- **Detecção de recorrências** — Clusterização automática e criação de Problemas GLPI
- **Painel flutuante** — Interface JS injetada na tela de chamado, sempre minimizado, arrastável

---

## Arquitetura

```
agentassistant/
├── setup.php                        # Registro, instalação, autoloader
├── hook.php                         # Callbacks dos hooks GLPI
├── src/                             # Lógica de negócio (GlpiPlugin\Agentassistant)
│   ├── Config.php                   # Gerenciamento de configuração com cache
│   ├── AIProvider.php               # Integração Claude API
│   ├── EmbeddingService.php         # Embeddings TF-IDF e similaridade
│   ├── SimilarityEngine.php         # Busca de chamados similares + pesos
│   ├── SuggestionEngine.php         # Orquestrador principal
│   ├── LearningEngine.php           # Rastreamento de feedback
│   └── RecurrenceDetector.php       # Detecção de incidentes recorrentes
├── inc/
│   ├── cron.class.php               # Tarefas cron GLPI
│   └── menu.class.php               # Entrada no menu admin
├── front/
│   ├── config.php                   # UI de configuração + dashboard
│   └── logs.php                     # Visualizador de logs
├── ajax/
│   ├── analyze.php                  # Busca/gera sugestão
│   └── feedback.php                 # Registra feedback
└── public/
    ├── js/agent-assistant.js        # Controlador do painel flutuante
    └── css/agent-assistant.css      # Estilos e animações
```

**Autoloader:** `setup.php` registra um `spl_autoload_register` que mapeia `GlpiPlugin\Agentassistant\*` → `src/*.php`.

---

## Banco de Dados

### `glpi_plugin_agentassistant_config`

Configurações chave/valor com cache em memória por requisição.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT UNSIGNED | PK |
| config_key | VARCHAR(100) UNIQUE | Nome da configuração |
| config_value | TEXT | Valor |

### `glpi_plugin_agentassistant_queue`

Fila de análise para reprocessamento via cron (tickets atualizados via hook).

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT UNSIGNED | PK |
| tickets_id | INT UNSIGNED | Chamado a analisar |
| operation | VARCHAR(30) | Sempre `'analyze'` |
| priority | TINYINT | Prioridade (1–10, maior = primeiro) |
| attempts | TINYINT | Contador de tentativas (máx 3) |
| date_scheduled | DATETIME | Timestamp de inserção |

**Constraint única:** `(tickets_id, operation)` — evita entradas duplicadas.

**Enfileiramento:** feito via `INSERT ... ON DUPLICATE KEY UPDATE` diretamente em `hook.php` (`_agentassistant_enqueue()`). O método `DB::insertOrIgnore()` não existe no GLPI — usar sempre raw SQL ou `$DB->doQuery()`.

### `glpi_plugin_agentassistant_embeddings`

Vetores TF-IDF por chamado, com cache por checksum.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT UNSIGNED | PK |
| tickets_id | INT UNSIGNED UNIQUE | ID do chamado |
| checksum | VARCHAR(32) | MD5 do texto (skip se não mudou) |
| embedding_json | MEDIUMTEXT | Vetor TF-IDF esparso `{termo:peso}` |
| keywords_json | TEXT | Top-10 palavras-chave (fallback Jaccard) |
| date_creation | DATETIME | Criação |
| date_mod | DATETIME | Última atualização |

### `glpi_plugin_agentassistant_suggestions`

Sugestões geradas (1 ativa por chamado).

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT UNSIGNED | PK |
| tickets_id | INT UNSIGNED | Chamado |
| confidence_score | TINYINT UNSIGNED | Confiança 0–100% |
| source_type | ENUM | `'similar'`, `'ai'`, `'hybrid'` |
| source_ids | TEXT | JSON array de IDs similares |
| suggestion_text | LONGTEXT | Texto da sugestão (Markdown) |
| explanation | TEXT | Motivo da sugestão |
| followup_id | INT UNSIGNED | Followup privado gerado automaticamente |
| status | ENUM | `'pending'`, `'shown'`, `'used'`, `'dismissed'` |
| date_creation | DATETIME | Criação |

### `glpi_plugin_agentassistant_learning`

Histórico de feedback para ajuste de pesos.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT UNSIGNED | PK |
| suggestion_id | INT UNSIGNED | Sugestão avaliada |
| tickets_id | INT UNSIGNED | Contexto do chamado |
| users_id | INT UNSIGNED | Técnico que deu feedback |
| action | ENUM | `'used'`, `'dismissed'`, `'ignored'` |
| source_hash | VARCHAR(32) | MD5(source_ids) para agregação |
| weight_delta | FLOAT | +0.10 (used), -0.05 (dismissed), 0 (ignored) |
| date_action | DATETIME | Timestamp do feedback |

**Fórmula do peso:** `weight = max(0.5, min(2.0, 1.0 + SUM(weight_delta)))`

### `glpi_plugin_agentassistant_clusters`

Padrões de incidentes recorrentes para criação de Problemas.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT UNSIGNED | PK |
| cluster_key | VARCHAR(32) UNIQUE | MD5(top-3-keywords \| categoria) |
| category_name | VARCHAR(255) | Categoria ITIL |
| keywords_json | TEXT | JSON array de palavras-chave |
| incident_ids | MEDIUMTEXT | JSON array de IDs de chamados |
| incident_count | INT UNSIGNED | Quantidade de chamados |
| problems_id | INT UNSIGNED | Problema GLPI criado (NULL se ainda não) |
| first_seen | DATETIME | Criação do cluster |
| last_seen | DATETIME | Última atualização |
| is_active | TINYINT(1) | Ativo/arquivado |

### `glpi_plugin_agentassistant_logs`

Trilha de auditoria completa.

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| id | INT UNSIGNED | PK |
| tickets_id | INT UNSIGNED | Chamado (0 = operação de sistema) |
| operation | VARCHAR(50) | `'analyze'`, `'ai_call'` |
| confidence_score | TINYINT UNSIGNED | Confiança do resultado |
| source_type | VARCHAR(20) | `'similar'`, `'ai'`, `'hybrid'` |
| tokens_used | SMALLINT UNSIGNED | Tokens Claude consumidos |
| duration_ms | INT UNSIGNED | Tempo de execução |
| message | TEXT | Notas da operação |
| date_creation | DATETIME (INDEX) | Timestamp |

---

## Classes do Core

### `Config` — Gerenciador de Configuração

Cache em memória estático; acesso via métodos estáticos.

```php
Config::get(string $key): string
Config::getInt(string $key): int
Config::getFloat(string $key): float
Config::getBool(string $key): bool
Config::set(string $key, string $value): void
Config::setMany(array $data): void   // ignora chaves desconhecidas
Config::loadAll(): void
Config::getAll(): array
```

Lazy-load do DB no primeiro acesso; fallback nos `$defaults` se chave ausente.

---

### `AIProvider` — Integração Claude API

```php
generateSuggestion(array $ticket, array $similar = []): ?array
// Retorna: ['text' => string, 'tokens' => int] ou null
```

**Fluxo:**
1. Valida `ai_api_key`
2. Monta prompt com dados do chamado + soluções passadas (se houver similares)
3. POST para Anthropic Messages API (timeout 30s)
4. Loga chamada em `glpi_plugin_agentassistant_logs`
5. Retorna `null` em erros de rede/API

**Idioma:** prompt inteiramente em português do Brasil. A instrução explícita `"Responda sempre em português do Brasil"` garante que o modelo responda no idioma correto independente do conteúdo do chamado.

**Formato do prompt:**
- Categoria, título, descrição do chamado
- Até 5 soluções de chamados similares (label: `Incidentes Similares Anteriores`)
- Solicita solução em até 5 passos numerados
- Pede referência `"Com base em: Chamado #X, Chamado #Y"`

---

### `EmbeddingService` — Vetores TF-IDF

```php
EmbeddingService::embedTicket(int $ticketId): string   // retorna checksum
EmbeddingService::embed(string $text): array           // {termo:peso, ...}
EmbeddingService::cosineSimilarity(array $a, array $b): float
EmbeddingService::jaccardSimilarity(array $a, array $b): float
```

**Algoritmo TF-IDF:**
1. Normalização: lowercase → remover acentos (Normalizer::FORM_D) → strip HTML
2. Tokenização: split em não-alfanumérico → filtrar stopwords PT+EN → min 3 chars → sem números
3. TF = contagem / total_tokens; IDF = log(1 + 1/contagem); peso = TF × IDF
4. Mantém top 60 termos ordenados DESC
5. Cache por checksum MD5 do texto fonte

---

### `SimilarityEngine` — Busca e Ranking

```php
findSimilar(int $queryTicketId): array
// [{ticket_id, score, title, resolution}, ...]

computeConfidence(array $similar): int   // 0–100
```

**Algoritmo:**
1. Embute chamado alvo
2. Carrega embeddings de chamados fechados/resolvidos
3. Score = cosseno×0.80 + Jaccard×0.20
4. Aplica pesos de aprendizado: `score *= weight` (weight de `source_hash`)
5. Filtra por `similarity_threshold`, ordena DESC, limita a N resultados

**Cálculo de confiança:**
```
base = top_score * 100
if count ≥ 3 && top_score ≥ 0.6: base += 8
if count ≥ 2 && top_score ≥ 0.5: base += 4
return min(100, base)
```

---

### `SuggestionEngine` — Orquestrador

```php
analyze(int $ticketId): ?array    // ponto de entrada principal
getForTicket(int $ticketId): ?array   // busca sugestão (muda status para 'shown')
```

**Árvore de decisão:**
- Confiança ≥ medium (50%) → sugestão de similares
- Confiança < medium → fallback Claude AI
- AI disponível + similaridade baixa → modo híbrido (ambas as fontes)
- Confiança ≥ high (80%) + `auto_followup` ativo → adiciona followup privado automático

Deleta sugestões anteriores antes de armazenar (1 ativa por chamado).

---

### `LearningEngine` — Feedback

```php
recordFeedback(int $suggestionId, int $ticketId, string $action, int $userId = 0): void
getStats(): array           // {total, used, dismissed, use_rate}
getTopContributors(int $limit = 10): array
```

Armazena `source_hash = MD5(suggestion.source_ids)` para que `SimilarityEngine` aplique pesos acumulados.

---

### `RecurrenceDetector` — Clustering de Incidentes

```php
run(): int   // retorna número de Problemas criados
```

**Algoritmo:**
1. Consulta chamados abertos dos últimos N dias (`recurrence_days`)
2. Para cada chamado: extrai top-3 keywords + categoria
3. `cluster_key = MD5(keywords | categoria)`
4. Agrupa por cluster_key
5. Para clusters com ≥ X chamados (`recurrence_count`):
   - Verifica se já existe Problema
   - Cria Problema: nome `[IA] Incidente recorrente: {keywords} ({categoria})`
   - Vincula chamados via `Problem_Ticket`
   - Salva/atualiza registro do cluster

---

## Integração com GLPI

### Hooks (`hook.php`)

```php
// CSRF
$PLUGIN_HOOKS['csrf_compliant']['agentassistant'] = true;

// Chamado criado → enfileira (prioridade 3)
$PLUGIN_HOOKS['item_add']['agentassistant'] = 'plugin_agentassistant_item_add';

// Chamado atualizado (título/desc/categoria) → enfileira (prioridade 5)
$PLUGIN_HOOKS['item_update']['agentassistant'] = 'plugin_agentassistant_item_update';

// CSS+JS injetados apenas em páginas /Ticket na interface central
$PLUGIN_HOOKS['add_css']['agentassistant'] = ...;
$PLUGIN_HOOKS['add_javascript']['agentassistant'] = ...;
```

**`_agentassistant_enqueue(int $ticketId, int $priority)`** — helper interno que enfileira via:
```sql
INSERT INTO glpi_plugin_agentassistant_queue (...)
VALUES (...)
ON DUPLICATE KEY UPDATE priority = LEAST(priority, ?), date_scheduled = NOW()
```
> Não usar `$DB->insertOrIgnore()` — método inexistente no GLPI. Usar `$DB->doQuery()` com raw SQL.

### Cron (`inc/cron.class.php`)

| Tarefa | Frequência | Descrição |
|--------|-----------|-----------|
| `agentassistantProcessQueue` | 5 min | Processa até 20 itens da fila; máx 3 tentativas |
| `agentassistantDetectRecurrences` | 2 horas | Detecta clusters e cria Problemas |

**Registro:** `PluginAgentassistantCron::install()` é chamado tanto em `plugin_agentassistant_install()` (instalação) quanto em `plugin_init_agentassistant()` (init, idempotente). Isso garante que as tarefas sejam registradas no BD do GLPI mesmo sem reinstalar o plugin.

> `CronTask::register()` é idempotente — pode ser chamado a cada init sem efeitos colaterais.

### Menu Admin (`inc/menu.class.php`)

Entrada em Setup → Agent Assistant com acesso por direito `'config'` (READ/UPDATE).

---

## Endpoints AJAX

### `GET /ajax/analyze.php?ticket_id=X`

**Cache hit** (sugestão já existe no BD):
```json
{"suggestion": { "id": 1, "confidence_score": 85, ... }}
```
Retorna imediatamente.

**Cache miss — primeira visita** (sem `&poll=1`):
Roda `SuggestionEngine::analyze()` de forma **síncrona** na mesma requisição e retorna o resultado assim que concluir. O painel exibe o spinner durante o processamento (tipicamente 2–10 segundos).
```json
{"suggestion": { ... }}   // análise concluída
{"suggestion": null}       // nenhuma sugestão gerada (sem similares e sem API key)
```

**Cache miss — polling** (com `&poll=1`):
Usado pelo JS para verificar se o cron já processou uma atualização de chamado.
```json
{"suggestion": null, "queued": true}
```

> **Compatibilidade GLPI 11 / Symfony:** não usar `ob_end_flush()` — fecha o buffer do Symfony e gera warning `"Unexpected output detected"`. O processamento em background via `fastcgi_finish_request` foi removido por ser pouco confiável; a análise é sempre síncrona na primeira visita.

### `POST /ajax/analyze.php`

Força re-análise síncrona. Body: `{ticket_id: N}`.

### `POST /ajax/feedback.php`

Registra feedback do técnico.

**Body:**
```json
{"suggestion_id": 123, "ticket_id": 456, "action": "used|dismissed"}
```

**Resposta:** `{"ok": true}` ou `{"ok": false, "error": "..."}`

**Segurança:** requer login (`checkLoginUser`) + permissão de leitura no chamado.

---

## Frontend

### `public/js/agent-assistant.js`

IIFE que expõe `window.AAPanel`.

**Funções principais:**

| Função | Descrição |
|--------|-----------|
| `init()` | Entry point; detecta ID do chamado, injeta painel sempre minimizado |
| `extractTicketId()` | Parseia URL `/Ticket/{id}` ou `?id=` |
| `injectPanel()` | Cria elemento fixo bottom-left, draggável, classe `aa-minimized` sempre presente |
| `fetchSuggestion()` | AJAX — aguarda resposta síncrona; inicia polling apenas se `queued: true` |
| `renderPanel()` | Barra de confiança, Markdown simplificado, botões de feedback |
| `notifyReady()` | Adiciona classe `aa-ready` ao painel → dispara animação de pulso no header |
| `makeDraggable()` | Drag por mousedown/mousemove no header |
| `mdToHtml()` | Converte `##`, `**`, `- item`, listas numeradas para HTML |

**Comportamento do painel:**
- Sempre inicia **minimizado** (classe `aa-minimized`) — nunca expande automaticamente
- Ao receber sugestão: adiciona classe `aa-ready` → header pulsa 3× para notificar o técnico
- O técnico expande manualmente clicando no botão ▲
- "Ignorar" salva chave em `sessionStorage` → painel não reaparece no mesmo chamado/sessão

**API pública:**
```js
AAPanel.close()
AAPanel.toggle()
AAPanel.feedback(sid, tid, action)
```

### `public/css/agent-assistant.css`

| Classe | Descrição |
|--------|-----------|
| `#aa-panel` | Container fixo 360px, bottom-left |
| `.aa-minimized` | Colapsa para o header (max-height: 44px) |
| `.aa-minimized.aa-ready` | Pulso no header 3× ao receber sugestão (animação `aa-pulse`) |
| `.aa-header` | Gradiente roxo (#4f46e5–#7c3aed), arrastável |
| `.aa-conf-bar` | Barra de progresso animada de confiança |
| `.aa-conf-high/med/low` | Verde/amarelo/vermelho |
| `.aa-loading` | Animação de spinner |

Suporte a dark mode (`prefers-color-scheme`) e responsivo (< 480px).

---

## Fluxos de Dados

### Análise na Abertura do Chamado (principal)

```
Técnico abre formulário de chamado
  → JS init() → injectPanel (minimizado, spinner)
  → GET /ajax/analyze.php?ticket_id=X  (sem poll)
      ├─ Cache HIT → retorna sugestão imediatamente
      └─ Cache MISS → SuggestionEngine::analyze() síncrono
           ├─ EmbeddingService::embedTicket()
           ├─ SimilarityEngine::findSimilar()
           ├─ Decisão: confiança ≥ 50%?
           │   ├─ SIM: sugestão de similares
           │   └─ NÃO: AIProvider::generateSuggestion() (Claude API)
           ├─ Store → glpi_plugin_agentassistant_suggestions
           ├─ Confiança ≥ 80%: adiciona followup privado
           └─ Log → glpi_plugin_agentassistant_logs
  → JS recebe {suggestion: {...}} → renderPanel()
  → header pulsa 3× (aa-ready) → técnico expande quando quiser
  → Técnico clica "Utilizei" ou "Ignorar"
  → POST /ajax/feedback.php → LearningEngine::recordFeedback()
```

### Reprocessamento por Atualização (cron)

```
Chamado atualizado (título/desc/categoria)
  → hook item_update
  → _agentassistant_enqueue() → INSERT ... ON DUPLICATE KEY UPDATE queue
  → Cron (5 min) → cronAgentassistantProcessQueue()
      → SuggestionEngine::analyze() → store → DELETE from queue
  → Próxima abertura do chamado: cache hit, retorno imediato
```

### Detecção de Recorrências

```
Cron (2h) → RecurrenceDetector::run()
  → Chamados abertos dos últimos N dias
  → Agrupamento por MD5(top-3-keywords | categoria)
  → Clusters com ≥ X chamados sem Problema existente:
      → createProblem() → Problem_Ticket (vincula chamados)
      → saveCluster()
  → Log resultado
```

---

## Configuração

| Chave | Tipo | Padrão | Descrição |
|-------|------|--------|-----------|
| `enabled` | bool | true | Habilitar plugin |
| `ai_api_key` | string | '' | Chave Claude API (Anthropic) |
| `ai_model` | string | claude-sonnet-4-6 | Modelo de IA |
| `confidence_high` | int | 80 | Limiar alta confiança (%) |
| `confidence_medium` | int | 50 | Limiar média confiança (%) |
| `similarity_threshold` | float | 0.35 | Mínimo de similaridade cosseno |
| `max_similar_tickets` | int | 5 | Máximo de resultados similares |
| `auto_followup` | bool | true | Adicionar followup privado automático |
| `auto_problem` | bool | true | Criar Problemas de clusters |
| `recurrence_count` | int | 10 | Mínimo de chamados para cluster |
| `recurrence_days` | int | 5 | Janela de detecção (dias) |
| `max_tokens` | int | 800 | Limite de tokens Claude |

> Se `ai_api_key` não estiver configurado e não houver chamados similares, `analyze()` retorna `null` e nenhuma sugestão é armazenada.

---

## Segurança e Performance

### Segurança

- **CSRF:** `$PLUGIN_HOOKS['csrf_compliant'] = true`
- **Autenticação:** `Session::checkLoginUser()` em todos os endpoints AJAX
- **Autorização:** `Session::checkRight('config', UPDATE)` nas páginas admin
- **Permissão por chamado:** `$ticket->canViewItem()` antes de retornar sugestão
- **SQL:** usa query builder GLPI (`$DB->request`, `$DB->insert`, `$DB->update`) ou raw SQL via `$DB->doQuery()` quando necessário
- **Interface:** JS/CSS injetados apenas na interface central (não self-service)

### Performance

- **Cache de embeddings:** MD5 evita re-embedar texto não modificado
- **Cache de configuração:** em memória por requisição (sem queries repetidas)
- **Análise síncrona:** primeira visita aguarda resultado direto (2–10s); sem dependência de cron para a UX principal
- **Vetores esparsos:** apenas top 60 termos + fallback Jaccard para vetores esparsos
- **Paginação:** logs exibem 50 linhas/página

### Compatibilidade GLPI 11 (Symfony)

- Não usar `ob_end_flush()` — interfere no buffer do `LegacyFileLoadController`
- Não usar `$DB->insertOrIgnore()` — método inexistente; usar raw SQL com `ON DUPLICATE KEY UPDATE`
- Não chamar funções de `hook.php` diretamente em outros arquivos PHP — risco de redefinição de funções

---

## Dependências

**Classes GLPI usadas:**
`Ticket`, `Problem`, `Problem_Ticket`, `ITILFollowup`, `ITILCategory`, `Session`, `Plugin`, `Html`, `CronTask`, `CommonDBTM`, `DBConnection`

**PHP stdlib:**
SPL autoloader, JSON, regex (`preg_*`), multibyte strings (`mb_*`), `Normalizer`, stream context HTTP

**Sem dependências Composer externas.**
