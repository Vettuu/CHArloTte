# Architettura Multi-Modello (Backend unico)

## Obiettivo
Gestire più pipeline di generazione (es. `realtime` e `text`) nello stesso progetto, mantenendo condivise le parti core (tenant, RAG, reportistica) e separando solo ciò che è specifico del modello/trasporto.

## Stato attuale (sintesi)
- Frontend chat basata su Realtime SDK in `apps/frontend/src/app/page.tsx`.
- Token realtime generato da `POST /api/realtime/token`.
- Retrieval RAG gestito da backend (`knowledge/search`) con tenant filter.
- Config tenant in `apps/backend/config/tenants.php`.

## Principio chiave
Separare **pipeline di risposta** da **servizi condivisi**.

## Componenti condivisi
- Tenant e istruzioni (`config/tenants.php`).
- Knowledge base e indicizzazione (`app/Knowledge/*`).
- Embedding e chunk in DB (`knowledge_chunks`).
- Report log e KPI (`chat_messages` + endpoint report).
- Topic tagging / retag.

## Componenti separati per pipeline
- Creazione risposta (Realtime vs Text API).
- Session management (ephemeral realtime vs request/response text).
- Endpoint d'ingresso chat.
- Parametri modello runtime (model, temperature, max tokens, tool policy).

## Flusso condiviso (alto livello)
1. Frontend identifica tenant.
2. Backend risolve configurazione tenant.
3. Query utente passa al retrieval RAG tenant-aware.
4. Contesto ufficiale viene costruito.
5. Pipeline selezionata (realtime o text) genera la risposta.
6. Messaggi e metadati vengono loggati su `chat_messages`.

## Flusso pipeline Realtime
1. Frontend chiede token realtime (`/api/realtime/token`).
2. Backend crea client secret verso OpenAI Realtime.
3. Frontend usa RealtimeSession SDK.
4. Per ogni user message: retrieval RAG + context block.
5. Risposta assistente in stream/eventi.

## Flusso pipeline Text
1. Frontend invia request a endpoint text (`/api/chat/respond`, da introdurre).
2. Backend esegue retrieval RAG.
3. Backend invoca modello text (`responses/chat completions`, a scelta implementativa).
4. Backend restituisce messaggio finale al frontend.

## Cosa significa "RAG condiviso"
- Stesso `tenant_id`.
- Stessa base documentale in `resources/knowledge/<tenant>/`.
- Stessi chunk + embedding nel DB.
- Stessa logica di scoring/fallback.

Conclusione: i due modelli confrontano la stessa conoscenza e le stesse regole, cambia solo il motore di generazione.

## Convenzioni consigliate
- Aggiungere in tenant un campo `pipeline` (`realtime` | `text`).
- Aggiungere in tenant un eventuale `chat_model` override.
- Loggare sempre in report: `pipeline`, `model`, `tenant`, `fallback`.
