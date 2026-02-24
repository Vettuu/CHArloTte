# Workflow Charlotte

## Contesto e visione
Charlotte è un assistente AI real-time per eventi e siti web, con chat testuale e voce. Il sistema usa Next.js come client e Laravel come backend. La logica principale è: una sessione realtime per ogni utente, arricchita da RAG su documenti di conoscenza, con comportamento personalizzato per tenant.

## Architettura logica
- Frontend: gestisce UI, sessione realtime, invio messaggi e visualizzazione risposte.
- Backend: genera token realtime, gestisce RAG, indicizza conoscenza, separa i tenant e fornisce report.
- Knowledge base: file Markdown/JSON organizzati per tenant e indicizzati in database.
- Tenant: ogni dominio/uso ha istruzioni dedicate, knowledge dedicata e comportamenti specifici.

## Flusso principale (utente)
1) L’utente apre la pagina (con `tenant` in query).
2) Il frontend legge il tenant e recupera configurazione e istruzioni.
3) Il frontend richiede un token realtime al backend.
4) L’utente scrive o parla.
5) Il frontend manda la domanda al backend per il retrieval (RAG).
6) Il frontend passa al modello la domanda + contesto verificato.
7) Il modello risponde.
8) La chat viene registrata per reportistica.

## Flusso RAG
1) I file di knowledge vengono divisi in chunk e salvati in DB per tenant.
2) Ogni query genera un embedding.
3) Il backend cerca chunk rilevanti per tenant e filtra per punteggio.
4) Se il punteggio non e sufficiente, usa un fallback su keyword search.
5) Il contesto recuperato viene passato al modello con regole di risposta.

## Workflow knowledge
1) Ogni tenant ha una cartella dedicata (`resources/knowledge/<tenant>`).
2) Un `metadata.json` elenca i file da indicizzare.
3) Il comando rebuild (API o artisan) rigenera chunk ed embedding.
4) La formattazione Markdown influenza la qualita dei chunk.

## Gestione tenant
1) Configurazione in `config/tenants.php` con istruzioni e tone of voice.
2) Tenant selezionato via query string (`?tenant=...`).
3) Ogni tenant usa la sua knowledge e le sue regole di risposta.
4) L’intro message e diverso per distinguere i tenant nei test.

## Chat e reportistica
1) Ogni sessione salva messaggi separati (user/assistant) nel DB.
2) I messaggi sono tracciati con `session_id` e `tenant_id`.
3) Le stime token sono salvate per capire utilizzo/costi.
4) Il report si scarica via endpoint CSV protetto da login.

## Modalita testo e voce
- Testo: query al RAG + risposta immediata.
- Voce: sessione realtime con transcript e contesto inserito.
- Entrambe le modalita condividono lo stesso comportamento di tenant.

## Debug e tuning
- Se il modello non risponde: verificare retrieval, soglia minima e formattazione knowledge.
- Se il modello inventa: alzare soglia o rendere i file piu precisi.
- Se risponde troppo poco: abbassare soglia o aumentare i chunk passati.
- Se risposte troppo corte: agire sulle istruzioni del tenant.

## Deployment logico
1) Build backend + frontend.
2) Deploy su FTP (dist/backend + dist/frontend).
3) Migrazioni DB per nuove tabelle.
4) Rebuild embedding per ogni tenant modificato.

## Checklist operativa
- Tenant configurato con istruzioni coerenti.
- Knowledge formattata e metadata valido.
- Embedding rigenerato dopo modifiche.
- Test con domande reali (case study).
- Report export funzionante per analisi.
