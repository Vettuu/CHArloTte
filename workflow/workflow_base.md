# Workflow Charlotte

## Contesto e visione
- Obiettivo: creare un assistente AI real-time (testo + voce) per congressi/eventi, basato su Next.js (frontend) e Laravel (backend) che sfrutta `gpt-realtime` per interazioni speech-to-speech, instructions dinamiche e tool invocation (doc: `1_overview.md`, `3_usage_using_realtime_model.md`).
- Vincoli: interfaccia funzionale (non focus estetico), latenza bassa, gestione sia modalità push-to-talk che full duplex, supporto multi-lingua e fallback su chat testuale.
- Rischi principali: gestione audio browser (WebRTC) vs server (WebSocket), generazione sicura di chiavi effimere (`11_quickstart.md`), orchestrazione tool/server-side events e cost control (`5_usage_Webhooks_and_server_side_controls.md`, `6_usage_managing_cost.md`).

## Riferimenti documentali prioritari
- `11_quickstart.md`: flusso base Voice Agent (Agents SDK, WebRTC, generazione chiavi effimere).
- `2_connect_websocket.md` & `4_usage_realtime_conversation.md`: dettagli su eventi client/server, session lifecycle, audio buffer.
- `3_usage_using_realtime_model.md`: best practice prompting, gestione output modalities, turn detection.
- `5_usage_Webhooks_and_server_side_controls.md`: meccanismi per orchestrare tool, webhooks e guard-rails lato backend.
- `6_usage_managing_cost.md`, `7_usage_realtime_transcription.md`, `8_model_text_to_speech.md`, `9_model_speech_to_text.md`, `10_model_embeddings.md`: linee guida per ottimizzazioni future (trascrizioni, TTS/STT dedicate, retrieval).

## Milestone e piano operativo

### 1. Discovery & architettura (M0)
- Definire user journey per congressi: scenari Q&A info point, check-in, agenda speaker ⇒ descrivere in system prompt (vedi sezioni suggerite in `3_usage_using_realtime_model.md`).
- Scegliere pattern trasporto: WebRTC nel browser (per audio automatizzato) + WebSocket su backend per eventuali automazioni (rif. `1_overview.md`, `2_connect_websocket.md`).
- Disegnare flusso sicurezza: API key standard solo su Laravel, endpoint per `POST /v1/realtime/client_secrets` e TTL gestione token (`11_quickstart.md`).
- Identificare tool necessari (es. fetch agenda, registrazioni) e requisiti webhook per controllo conversazione (`5_usage_Webhooks_and_server_side_controls.md`).

### 2. Backend Laravel (M1)
- Bootstrappare progetto Laravel + moduli base (config `.env` per API key OpenAI, storage log conversazioni).
- Implementare service OpenAI: wrapper HTTP per `client_secrets`, sessioni e eventuali webhooks (con test unitari sui formati richiesti in `2_connect_websocket.md`).
- API endpoints:
  - `POST /api/realtime/token` → retorna chiave effimera (body session config: modello, voce, modalities, prompt defaults).
  - `POST /api/realtime/invoke-tool` → riceve webhook `response.output_text.delta` / tool call e invoca servizi evento (es. agenda).
  - `GET /api/session/:id/logs` → auditing latenza/costi (usa metrics suggerite in `6_usage_managing_cost.md`).
- Implementare event bus (es. Laravel Events + queue) per loggare `session.created/updated`, `response.completed`, errori (`4_usage_realtime_conversation.md`).
- Preparare test integrazione (Pest) per assicurare 401 su token mancante, TTL enforcement, resilienza ai fallback error (doc `5_usage_Webhooks_and_server_side_controls.md`).

### 3. Frontend Next.js (M2)
- Setup Next 15 App Router + TypeScript + Tailwind minimo.
- Creare store stato conversazione (Zustand/Context) che rifletta `Conversation` items (rif. `4_usage_realtime_conversation.md`) e supporti text + audio transcripts.
- Implementare hook `useRealtimeSession`:
  - Richiede token da Laravel, istanzia `RealtimeSession` (`@openai/agents/realtime`) con config (model, turn detection, voce, instructions).
  - Gestisce reconnessioni, aggiornamenti `session.update` (cambio lingua, contesto evento) come da `3_usage_using_realtime_model.md`.
- UI:
  - Widget voice (mic state, VU meter, timer session < 60 min: limite doc).
  - Chat view con stream `response.output_text.delta` + eventuale `audio` waveform/download.
  - Pannello admin per cambiare prompt variables (es. `city`, `event_name`) e aggiornare sessione a runtime.
- Introdurre fallback text-only (toggle `output_modalities: ["text"]`) per ambienti senza audio.
- Implementare gestione errori: toast su `response.error`, forzare `session.update` reset voce (voce non mutabile dopo audio, rif. `4_usage_realtime_conversation.md`).

### 4. Funzionalità avanzate & integrazioni (M3)
- Tooling: definire schema funzioni (agenda lookup, location info) e integrarli via webhooks → backend restituisce `response.create` con contenuti aggiornati.
- Trascrizioni e note: sfruttare `7_usage_realtime_transcription.md` per registrare meeting (modalità transcription parallela).
- Text-to-speech fallback: usare `8_model_text_to_speech.md` per messaggi pre-registrati (es. emergenze) quando sessione RT non disponibile.
- Retrieval/Embeddings: pipeline ingestion materiali congresso con `10_model_embeddings.md` e retrieval lato Laravel per arricchire prompt (Context section).
- Cost guardrails: limiti durata sessione, pause auto se inattivo, triggers su `input_audio_buffer.committed` per evitare spam (doc cost).

### 5. QA, osservabilità & SecOps (M4)
- Monitoring: log strutturati (session id, `response.id`, turn latency) + dashboard (ex. Laravel Horizon) per code/queue.
- Test end-to-end: script Playwright per verificare handshake WebRTC + fallback text, mockare token scaduto.
- Chaos testing: simulare network loss → Next deve re-inizializzare `RealtimeSession` con nuovo token, mantenendo contesto conversazione se necessario via backend.
- Compliance: assicurare gestione consenso audio (banner + storage policy), encryption at rest per log, rotate API keys.

### 6. Deployment & handover (M5)
- Pipeline CI/CD: lint/test (Laravel Pint, Next lint) + build container (Docker multi-stage) pronti per ambiente staging/prod.
- Provisioning: definire variabili ambiente (API key, base URL Realtime) e secrets management.
- Documentazione: playbook per support (reset session, rigenerare token), guida per staff evento (come usare voice widget).
- Backlog post-MVP: supporto SIP (`1_overview.md`), multi-room, analytics sugli intenti, UX polishing.

## Deliverable chiave
- Requisiti & architettura firmati off (fine M0).
- Repo monorepo (Next + Laravel) con CI minimo funzionante.
- Endpoint token + UI voice funzionante con `gpt-realtime` (demo interna).
- Tool integration + logging costi.
- Checklist QA + guida operativa per on-site team.
