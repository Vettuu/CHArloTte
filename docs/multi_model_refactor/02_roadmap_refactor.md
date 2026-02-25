# Roadmap Refactor Multi-Modello

## Obiettivo del refactor
Introdurre una seconda pipeline (`text`) senza rompere la pipeline attuale (`realtime`) e senza duplicare RAG/report.

## Step 0 - Preparazione
- Congelare comportamento attuale (baseline test).
- Salvare 10-20 prompt reali per regression test.
- Verificare che report e RAG siano verdi prima del refactor.

## Step 1 - Configurazione esplicita pipeline
1. Estendere `config/tenants.php`:
   - `pipeline`: `realtime` (default attuale) o `text`.
   - `chat_model`: opzionale override.
2. Introdurre `config/models.php`:
   - default model per `realtime`.
   - default model per `text`.
   - parametri runtime separati.

Deliverable: tenant dichiara esplicitamente quale pipeline usare.

## Step 2 - Orchestrazione pipeline in backend
1. Creare cartella `app/Chat/` con:
   - `Contracts/ChatPipeline.php`
   - `Pipelines/RealtimePipeline.php`
   - `Pipelines/TextPipeline.php`
   - `ChatPipelineResolver.php`
2. Spostare la logica specifica realtime in `RealtimePipeline`.
3. Implementare `TextPipeline` con stessa firma di input/output.

Deliverable: backend risolve pipeline via tenant senza `if` sparsi nei controller.

## Step 3 - Endpoint text dedicato
1. Aggiungere endpoint `POST /api/chat/respond`.
2. Request valida: `tenant`, `message`, `session_id` opzionale.
3. Controller usa `ChatPipelineResolver`.
4. Reuse di RAG esistente (`KnowledgeSearchService`).

Deliverable: pipeline text usabile in produzione senza toccare realtime.

## Step 4 - Frontend adapter
1. In `page.tsx` introdurre un client astratto:
   - `RealtimeChatClient`
   - `TextChatClient`
2. Se `tenant.pipeline = realtime` usa SDK realtime.
3. Se `tenant.pipeline = text` usa `/api/chat/respond`.

Deliverable: UI unica, trasporto differenziato.

## Step 5 - Reportistica unificata
- In `chat_messages.metadata` aggiungere:
  - `pipeline`
  - `model`
  - `rag_hits`
  - `fallback` boolean
- Garantire parità KPI tra pipeline.

Deliverable: confronto A/B reale tra modelli.

## Step 6 - Rollout controllato
1. Tenere `charlotte` su pipeline stabile.
2. Attivare pipeline alternativa su tenant test.
3. Confrontare output con stesso dataset domande.
4. Solo dopo metriche buone, promuovere tenant ufficiale.

## Rollback plan
- Tornare a `pipeline: realtime` sul tenant ufficiale.
- Disabilitare endpoint text da route se necessario.
- Nessun impatto su chunk/embedding/report storici.
