# Guida completa - Riprodurre il core di un AI Chatbot multi-tenant

Questa guida e pensata come documento unico per ricreare un progetto AI chatbot generico con:
- Backend che gestisce RAG multi-tenant
- Frontend con chat realtime (testo/voce)
- Knowledge base indicizzata
- Reportistica CSV per analisi

## 1. Visione generale
Il sistema e un assistente AI realtime per siti e servizi digitali. Ogni installazione puo supportare piu tenant (es. cliente_a, cliente_b). Ogni tenant ha:
- istruzioni personalizzate
- conoscenza dedicata
- risposte calibrate sul proprio dominio

Logica centrale:
1) utente apre la chat
2) frontend chiede un token realtime al backend
3) backend genera token e salva sessione
4) utente invia domanda
5) backend recupera contesto (RAG)
6) frontend invia domanda + contesto al modello
7) modello risponde
8) chat viene loggata per report

## 2. Struttura progetto
Monorepo consigliato:
```
/apps
  /backend   (Laravel)
  /frontend  (Next.js)
/docs
  /workflow
  /how_to
```

Cartelle chiave backend:
- `app/Knowledge` (indicizzazione e retrieval)
- `resources/knowledge/<tenant>` (file knowledge per tenant)
- `config/knowledge.php` e `config/tenants.php` (parametri core)

Cartelle chiave frontend:
- `src/app/page.tsx` (logica chat e realtime)
- `src/app/page.module.css` (UI)

## 3. Backend
### 3.1 Setup base
1) Crea progetto backend.
2) Configura `.env` (locale) e `.env.production` (prod).
3) Imposta chiavi modello e DB.

Variabili minime:
```
OPENAI_API_KEY=...
OPENAI_REALTIME_MODEL=gpt-realtime
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
KNOWLEDGE_CHUNK_SIZE=900
KNOWLEDGE_CHUNK_OVERLAP=150
KNOWLEDGE_MIN_SCORE=0.55
KNOWLEDGE_REBUILD_TOKEN=...
```

### 3.2 Tabelle principali (con campi)
1) `knowledge_chunks`
- `id` (pk)
- `tenant_id` (string)
- `document_id` (string)
- `content` (longtext)
- `metadata` (json)
- `embedding` (json)
- `embedding_norm` (float)
- `created_at`, `updated_at`

2) `realtime_sessions`
- `id` (pk)
- `session_id` (string)
- `mode` (string: text/audio)
- `status` (string)
- `session_payload` (json)
- `metadata` (json)
- `created_at`, `updated_at`

3) `chat_messages`
- `id` (pk)
- `session_id` (string)
- `tenant_id` (string)
- `message_id` (string)
- `role` (user/assistant)
- `content` (longtext)
- `source` (text/voice)
- `tokens_est` (int)
- `metadata` (json)
- `created_at`, `updated_at`

### 3.3 Configurazioni principali
`config/knowledge.php`
- `embedding_model`: modello embedding
- `chunk_size`, `chunk_overlap`: lunghezza chunk
- `min_score`: soglia di rilevanza
- `default_tenant`: tenant di default

`config/tenants.php`
- lista tenant con:
  - `name`
  - `intro_message`
  - `support_email`
  - `instructions`

Esempio tenant:
```
'cliente_a' => [
  'name' => 'Cliente A',
  'intro_message' => 'Ciao, sono l’assistente...',
  'support_email' => 'supporto@clientea.it',
  'instructions' => <<<TEXT
  RUOLO
  ...
  TEXT,
],
```

### 3.4 Servizi backend fondamentali
- Realtime service: genera token realtime e invia tool result.
- Embedding service: genera embedding.
- Knowledge repository: carica file da metadata.json.
- Knowledge indexer: chunkizza e salva in DB.
- Knowledge search service: retrieval embedding + fallback keyword.

### 3.5 Endpoint principali
- `POST /api/realtime/token` → token realtime
- `POST /api/knowledge/search` → query RAG
- `POST /api/knowledge/rebuild` → rebuild embedding
- `GET /api/tenant/config` → config tenant
- `POST /api/report/log` → salva messaggi
- `GET /api/report/export?tenant=...` → CSV report

## 4. Knowledge base
### 4.1 Struttura tenant
Ogni tenant ha una cartella:
`resources/knowledge/<tenant>/`

### 4.2 metadata.json
Esempio:
```
[
  {
    "id": "servizio-x",
    "file": "servizio_x.md",
    "title": "Servizio X",
    "tags": ["tag1", "tag2"],
    "summary": "Descrizione breve del servizio."
  }
]
```

### 4.3 Formattazione file
- Titoli chiari (H1/H2/H3)
- Sezioni dedicate
- Regole di costo con formula esplicita

Esempio costi:
```
Costo stimato = Costo A + Costo B + Costo C
Costo A = quantita x prezzo unitario
Costo B = costo fisso
Costo C = costo x giorni
```

## 5. Pipeline RAG
1) I file vengono chunkizzati.
2) Ogni chunk ha embedding.
3) Il retrieval filtra per tenant.
4) `min_score` decide se usare embedding o fallback keyword.
5) Il contesto viene passato al modello.

## 6. Frontend
### 6.1 Setup
- Next.js App Router
- TypeScript
- UI chat
- Supporto microfono

### 6.2 Flusso realtime
1) Leggi `?tenant=` dalla URL.
2) Recupera config tenant dal backend.
3) Richiedi token realtime.
4) Invia messaggio user.
5) Recupera contesto RAG.
6) Passa domanda + contesto al modello.
7) Mostra risposta.

### 6.3 Logging report
- Ogni messaggio user/assistant viene inviato a `POST /api/report/log`.
- I messaggi sono separati per tenant e sessione.

## 7. Configurazione modelli e tuning
Parametri fondamentali:
- `OPENAI_REALTIME_MODEL`
- `OPENAI_EMBEDDING_MODEL`
- `KNOWLEDGE_CHUNK_SIZE`
- `KNOWLEDGE_CHUNK_OVERLAP`
- `KNOWLEDGE_MIN_SCORE`

Motivazioni:
- Realtime = bassa latenza e conversazione
- Embedding = retrieval affidabile

Regole pratiche:
- Score alto = meno errori ma piu fallback
- Score basso = piu risposte ma piu rischio

## 8. Reportistica
- Endpoint CSV protetto da Basic Auth
- Campi: session_id, role, content, timestamp, tokens_est
- Serve per analisi marketing e debug

Esempio download:
```
curl -u report:password \
  "https://.../api/report/export?tenant=cliente_a" \
  -o report.csv
```

## 9. Deployment
1) Build backend + frontend
2) Deploy su FTP (`dist/backend` e `dist/frontend`)
3) Migrazioni DB
4) Rebuild embedding per tenant modificati

## 10. Checklist finale (spiegata)
1) Tenant configurato
- Ogni tenant e presente in `config/tenants.php` con `intro_message`, `support_email` e `instructions`.
- L'URL di test usa `?tenant=...` per verificare che il tenant corretto sia attivo.

2) Knowledge formattata e metadata valido
- Ogni tenant ha `resources/knowledge/<tenant>/metadata.json`.
- I file elencati in metadata esistono e sono ben formattati (titoli, sezioni, formule costi).
- Il JSON e valido (nessuna virgola o virgolette errate).

3) Embedding rigenerati
- Dopo ogni modifica ai file knowledge, eseguire il rebuild embedding.
- Verificare che la tabella `knowledge_chunks` contenga righe per quel tenant.

4) Test con domande reali completato
- Almeno 10 domande reali per tenant.
- Verifica che il bot risponda con dati corretti e non inventati.
- Per le stime costi, il bot deve applicare le formule del contesto.

5) Report CSV funzionante
- Endpoint `/api/report/export?tenant=...` restituisce CSV.
- Le sessioni sono separate e i messaggi sono in ordine.
- Il CSV e apribile in Excel senza errori.

## 11. Esempi API (request/response)
### Token realtime
Request:
```
POST /api/realtime/token
Content-Type: application/json

{
  "mode": "text",
  "metadata": { "tenant": "cliente_a" }
}
```
Response (esempio):
```
{
  "value": "sk-realtime-...",
  "session": { "id": "sess_123", "output_modalities": ["text"] },
  "tenant": { "id": "cliente_a", "name": "Cliente A" }
}
```

### Knowledge search
Request:
```
POST /api/knowledge/search
Content-Type: application/json

{ "query": "servizio x", "limit": 5, "tenant": "cliente_a" }
```
Response (esempio):
```
{
  "data": [
    {
      "id": "123",
      "title": "Servizio X",
      "excerpt": "Costo stimato = ...",
      "score": 0.71
    }
  ]
}
```

### Export report CSV
Request:
```
GET /api/report/export?tenant=cliente_a
Authorization: Basic base64(user:pass)
```

## 12. Snippet reali di configurazione
### config/knowledge.php (esempio)
```
return [
  'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
  'chunk_size' => env('KNOWLEDGE_CHUNK_SIZE', 900),
  'chunk_overlap' => env('KNOWLEDGE_CHUNK_OVERLAP', 150),
  'rebuild_token' => env('KNOWLEDGE_REBUILD_TOKEN'),
  'index_batch_size' => env('KNOWLEDGE_INDEX_BATCH_SIZE', 6),
  'min_score' => env('KNOWLEDGE_MIN_SCORE', 0.55),
  'default_tenant' => env('KNOWLEDGE_DEFAULT_TENANT', 'default'),
];
```

### config/tenants.php (esempio sintetico)
```
'cliente_a' => [
  'name' => 'Cliente A',
  'intro_message' => 'Ciao, sono l’assistente...',
  'support_email' => 'supporto@clientea.it',
  'instructions' => <<<TEXT
RUOLO
...
TEXT,
],
```

## 13. Troubleshooting rapido
### Risposte troppo vaghe o in fallback
- Abbassa `KNOWLEDGE_MIN_SCORE` di 0.05.
- Aumenta `limit` nel search frontend.
- Migliora la formattazione del Markdown.

### Risposte inventate
- Alza `KNOWLEDGE_MIN_SCORE`.
- Stringi le istruzioni del tenant.

### Errori su knowledge/search
- Controlla `metadata.json` (JSON valido).
- Verifica che i file indicati esistano.

### Stime costi non calcolate
- Verifica che la sezione costi sia scritta con formula esplicita.
- Assicurati che il chunk contenente la formula venga recuperato.

## 14. Esempio .env completo
```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com/chatbot
OPENAI_API_KEY=...
OPENAI_REALTIME_MODEL=gpt-realtime
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
KNOWLEDGE_CHUNK_SIZE=900
KNOWLEDGE_CHUNK_OVERLAP=150
KNOWLEDGE_MIN_SCORE=0.55
KNOWLEDGE_REBUILD_TOKEN=...
REPORT_USER=report
REPORT_PASS=change-me
DB_CONNECTION=mysql
DB_HOST=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
```

## 15. Flow completo build + deploy + rebuild
1) Build:
```
./scripts/deploy_ftp.sh
```
2) Migrazioni DB:
```
php artisan migrate
```
3) Rebuild embedding per tenant:
```
curl -X POST "https://.../api/knowledge/rebuild?token=...&tenant=cliente_a"
```

## 16. Mini checklist test qualita
- Testa 10 domande reali per tenant.
- Verifica che non inventi servizi.
- Controlla 2-3 richieste di costo/stima.
- Verifica report CSV con sessioni multiple.
- Confronta risposte tra tenant diversi.

## 17. Best practice per istruzioni tenant
### Regole base
- Scrivi istruzioni brevi e a punti.
- Separa RUOLO, PRIORITA, COMPORTAMENTO, CALCOLI, OUT OF SCOPE.
- Evita testi troppo lunghi o ambigui.
- Specifica sempre cosa fare in caso di dati mancanti.

### Esempio tenant per supporto clienti
```
RUOLO
Sei un assistente di supporto clienti. Rispondi in italiano con tono chiaro e cortese (2-4 frasi).

PRIORITA
1) Usa solo info ufficiali dal contesto.
2) Non inventare procedure o policy.

COMPORTAMENTO
- Se manca un dato, spiega che non e disponibile e suggerisci il contatto.
- Se la domanda riguarda problemi tecnici, chiedi un dettaglio minimo per proseguire.

OUT OF SCOPE
- Se non e legato al servizio, reindirizza al supporto.
```

### Esempio tenant per e-commerce
```
RUOLO
Sei un assistente per un e-commerce. Rispondi in italiano in modo sintetico e operativo.

PRIORITA
1) Usa i dati di prodotto e spedizione dal contesto.
2) Non inventare disponibilita o prezzi.

COMPORTAMENTO
- Se manca il prezzo, invita a controllare la scheda prodotto.
- Se l'utente chiede resi, fornisci la policy dal contesto.

OUT OF SCOPE
- Per richieste non coperte, suggerisci il supporto clienti.
```

### Esempio tenant per eventi
```
RUOLO
Sei un assistente per eventi. Rispondi in italiano con tono professionale e sintetico (max 3 frasi).

PRIORITA
1) Usa informazioni ufficiali su programma e logistica.
2) Non inventare orari o location.

COMPORTAMENTO
- Se manca un dato, indica dove reperirlo o il contatto.
- Se la domanda riguarda costi, usa regole di stima se presenti.

OUT OF SCOPE
- Reindirizza a segreteria/organizzazione.
```
