# ChatRespondController - Guida Tecnica

## Cos'e
`ChatRespondController` e il regista della pipeline `text`.
Coordina conversation layer, retrieval, policy decisionale, prompt assembly, chiamata modello e logging.

File principale:
- [ChatRespondController.php](/home/daniele/CharloTte/apps/backend/app/Http/Controllers/ChatRespondController.php)

File collegati:
- [ConversationStateService.php](/home/daniele/CharloTte/apps/backend/app/Chat/ConversationStateService.php)
- [ConversationInputResolver.php](/home/daniele/CharloTte/apps/backend/app/Chat/ConversationInputResolver.php)
- [KnowledgeSearchService.php](/home/daniele/CharloTte/apps/backend/app/Knowledge/KnowledgeSearchService.php)
- [OpenAITextService.php](/home/daniele/CharloTte/apps/backend/app/Services/OpenAITextService.php)
- [models.php](/home/daniele/CharloTte/apps/backend/config/models.php)
- [tenants.php](/home/daniele/CharloTte/apps/backend/config/tenants.php)

## Perche e importante
E il punto che influenza piu direttamente il comportamento finale del modello:
- come interpretare l'input nel contesto della conversazione
- quando rispondere in modo completo
- quando essere prudente
- quando fare fallback
- quando consentire web search
- che prompt costruire

## Flusso completo (end-to-end)
1. Legge `tenant` e valida che la pipeline sia `text`.
2. Logga `Text chat request received`.
3. Carica stato sessione con `ConversationStateService`.
4. Risolve il nuovo input con `ConversationInputResolver`.
5. Ottiene:
- `original_input`
- `resolved_query`
- `input_mode`
- `input_is_elliptic`
- `resolved_active_topic`
6. Calcola:
- `intent` (`core_info`, `pricing_estimate`, `showcase_web`)
- `query_token_count`
- `short_query`
7. Chiama `searchWithDiagnostics(...)` su `KnowledgeSearchService` usando `resolved_query`.
8. Riceve:
- `accepted_hits`
- `diagnostic_hits`
- `keyword_candidates`
- `top_score`
- `semantic_level`
9. Calcola:
- `confidence_score` (formula robusta)
- `confidence_bucket`
- `contradiction_flag`
- `contradiction_type`
- `contradiction_topic`
- `contradiction_evidence_count`
- `policy_path`
10. Se policy fallback:
- `strict_fallback` o `soft_fallback` con risposta guidata.
11. Altrimenti:
- costruisce prompt (`buildPrompt`) includendo anche contesto conversazionale breve
- decide web search (`resolveWebSearchConfig`)
- chiama `OpenAITextService->respond(...)`.
12. Aggiorna stato sessione con `persistConversationTurn(...)`.
13. Logga `Text chat response ready`.
14. Ritorna JSON al frontend con reply + diagnostica.

## Nuovo conversation layer
Il controller non tratta piu sempre il messaggio utente come query finale.

Ora distingue tra:
- `original_input`: quello che l'utente ha scritto davvero
- `resolved_query`: query ricostruita o confermata dal conversation layer

Questo cambia il flusso in modo importante:
- il RAG lavora su `resolved_query`
- il modello continua a vedere anche `original_input`
- lo stato conversazionale viene aggiornato a fine turno

## Come interagisce con i nuovi service

### `ConversationStateService`
Serve per:
- caricare stato sessione all'inizio del turno
- salvare turni e contesto a fine turno

Campi principali gestiti:
- `turns`
- `active_topic`
- `last_resolved_query`
- `last_bot_question`

### `ConversationInputResolver`
Serve per:
- classificare il nuovo input
- capire se e autosufficiente o ellittico
- produrre `resolved_query`
- proporre `resolved_active_topic`

Il controller usa questi output ma non decide lui la logica conversazionale.

## Policy decision tree
Funzione chiave: `resolvePolicyPath(...)`

Ordine logico:
1. Se `short_query=true` -> `partial_answer_clarify`.
2. Se zero `accepted_hits`:
- con `diagnostic_hits > 0` -> `soft_fallback`
- altrimenti -> `strict_fallback`
3. Se `contradiction_flag=true` -> `partial_answer`.
4. Se `hitCount < full_answer_requires_hits` -> `partial_answer`.
5. Se `semantic_level=high` e `confidence_bucket=high` -> `full_answer`.
6. Altrimenti -> `partial_answer`.

Nota importante:
ora `short_query`, `intent` e parte della diagnostica lavorano su `resolved_query`, non piu sempre sul messaggio originale.

## Prompt assembly (punto piu sensibile)
Funzione: `buildPrompt(...)`

Comportamenti:
- `partial_answer_clarify`: risposta prudente + 1 domanda mirata.
- fallback: no invenzioni, contatto supporto.
- full/partial: include fonti ufficiali (`title`, `excerpt`, `score`) e istruzioni di policy.
- se disponibile, include:
  - `Query risolta dal sistema`
  - piccolo storico conversazionale recente
- aggiunge guardrail per contraddizioni.
- aggiunge regole su web search in base all'intent.

Effetto pratico:
- determina precisione, tono operativo e rischio hallucination.
- riduce incoerenza sui follow-up brevi se la query e stata risolta bene

## Confidence score (attuale)
Formula robusta su top-N `diagnostic_hits`:
- `Confidence = (alpha * c1 + beta * mu) * (1 - sigma_n)`
- scala finale 0..100

Dove:
- `c1` = top score
- `mu` = media top-N
- `sigma_n` = deviazione standard normalizzata

Config:
- `OPENAI_TEXT_POLICY_CONFIDENCE_TOP_N`
- `OPENAI_TEXT_POLICY_CONFIDENCE_ALPHA`
- `OPENAI_TEXT_POLICY_CONFIDENCE_BETA`
- `OPENAI_TEXT_POLICY_CONFIDENCE_RANGE_MAX`
- soglie bucket: `OPENAI_TEXT_POLICY_CONFIDENCE_HIGH`, `..._MEDIUM`

## Intent e web search
`detectIntent(...)` usa keyword configurabili in `models.php`.
Ora lavora su `resolved_query`.

`resolveWebSearchConfig(...)` abilita ricerca web solo per intent consentiti.

Obiettivo:
- web search solo come estensione controllata
- priorita alle fonti ufficiali RAG

## Logging e telemetria
Il controller produce 3 blocchi principali:
1. request received
2. rag resolved
3. response ready

Campi chiave:
- `original_input`
- `resolved_query`
- `input_mode`
- `input_is_elliptic`
- `active_topic`
- `resolved_active_topic`
- `policy_path`
- `confidence_score` / `confidence_bucket`
- `semantic_level`
- `accepted_hits` / `diagnostic_hits`
- `keyword_candidates`
- `contradiction_flag`
- `contradiction_type`
- `contradiction_topic`
- `contradiction_evidence_count`
- `latency_ms`
- `web_search_enabled` / `web_sources`

Questi log sono la base per tuning empirico.

## Cosa influenza di piu il comportamento
1. `ConversationInputResolver` (qualita della query che entra nel RAG)
2. `resolvePolicyPath` (selezione percorso risposta)
3. `buildPrompt` (qualita istruzioni e contesto)
4. `analyzeContradiction` (topic/query-aware, puo forzare `partial_answer` se conflitto reale)
5. soglie confidence/semantic
6. intent + policy web search

## Punti sensibili attuali
1. Il nuovo conversation layer e corretto come base, ma va calibrato sui casi reali:
- follow-up selettivi
- follow-up tematici
- query brevi ma autosufficienti
1. `analyzeContradiction()` e stato migliorato e riduce i falsi positivi banali, ma resta da calibrare sui casi borderline.
2. Query brevissime passano spesso in `partial_answer_clarify` (voluto, ma da calibrare).
3. `full_answer` e deliberatamente restrittivo.

## Parametri principali da conoscere
In `config/models.php`:
- `conversation.short_input_length`
- `conversation.standalone_short_terms`
- `conversation.confirmation_terms`
- `conversation.selective_terms`
- `conversation.thematic_prefixes`
- `policy.max_hits`
- `policy.strict_fallback_on_zero_hits`
- `policy.full_answer_requires_hits`
- `policy.confidence_thresholds.high`
- `policy.confidence_thresholds.medium`
- `policy.confidence_formula.top_n`
- `policy.confidence_formula.alpha`
- `policy.confidence_formula.beta`
- `policy.confidence_formula.range_max`
- `policy.contradiction.min_evidence`
- `policy.contradiction.price_relative_delta`
- `policy.web_search_intents`
- `policy.intent_keywords.*`

## Cosa NON fa (ad oggi)
- classificazione intento avanzata ML (solo keyword-based)
- dedup/merge semantico avanzato delle fonti prima del prompt
- contradiction check robusto basato su regole dominio complete
- uso avanzato dei `turns` grezzi nel resolver (oggi usa soprattutto il contesto sintetico)
- ricostruzione semantica avanzata della query oltre la concatenazione conservativa

## Contradiction detector (stato attuale)
La vecchia funzione `hasContradiction` e stata sostituita da `analyzeContradiction`.

### Cosa fa ora
- usa regex a parola intera per segnali positivi/negativi (evita falsi match su sottostringhe)
- valuta contraddizioni di disponibilita per topic coerente
- valuta mismatch prezzi con soglia relativa configurabile
- usa minimo evidenze configurabile
- ritorna diagnostica strutturata:
  - `flag`
  - `type` (`none`, `availability_conflict`, `price_mismatch`)
  - `topic`
  - `evidence_count`

### Query-aware behavior
- se la query e chiaramente su disponibilita, evita di promuovere mismatch prezzi come conflitto dominante
- se la query e pricing-related, mantiene il controllo prezzi attivo

### Verifica effettuata
- query semplice (`cosa e echelon?`) -> `contradiction_flag=false`
- query disponibilita (`offline/non disponibile`) -> non classificata come `price_mismatch`
- query prezzi conflittuali (`600 vs 1200 euro`) -> `price_mismatch` corretto

## Strategia consigliata di lavoro sul controller
1. Toccare una leva alla volta (conversation layer -> policy -> prompt -> contradiction).
2. Validare su query test fisse e confrontare log prima/dopo.
3. Evitare refactor massivi insieme a cambi retrieval.
4. Misurare sempre:
- qualità risposta
- tasso fallback
- latenza
- stabilità bucket confidence
- qualità della `resolved_query`
