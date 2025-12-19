# Flusso RAG in `acoinazionale2026`

Questa applicazione Laravel implementa un ciclo Retrieval-Augmented Generation completo basato su documenti PDF/DOCX caricati dagli utenti. Di seguito l’intero percorso, con riferimenti ai file interessati in caso serva replicarlo altrove.

## 1. Configurazione e connettività

- Le credenziali e i parametri principali vivono in `backend/.env` e vengono lette tramite `config('ai.*')`.
- Il database MySQL è remoto (Aruba). Tutti i processi PHP che avviano ingestione o query devono poter raggiungere `DB_HOST=31.11.39.167` (`backend/.env:11-16`). Non esiste un DB locale: la connessione predefinita `mysql` usa direttamente queste credenziali.
- Il bucket dei documenti è lo `Storage` Laravel sul disco `public` (`AI_DOCUMENTS_DISK=local`, `AI_DOCUMENTS_PATH=public/posters`). Chi esegue `ai:ingest` deve avere accesso al filesystem con i file già caricati (nel server di produzione coincidono).
- Parametri OpenAI: chiave API, modello embeddings (`text-embedding-3-large`), modello responses (`gpt-4.1-mini`), batch size e timeout sono sotto `OPENAI_*`.

## 2. Generazione degli embedding (ingest)

### Comando di ingestione
Il comando Artisan `php artisan ai:ingest` (`backend/app/Console/Commands/IngestPosterFiles.php`) è responsabile di:

1. Scansione della cartella configurata (`Storage::disk(...)->allFiles($basePath)`).
2. Filtraggio per estensioni (`pdf`, `docx`) e (opzionale) per ID poster (`--poster=ID`).
3. Estrazione del testo:
   - PDF: `smalot/pdfparser`.
   - DOCX: lettura di `word/document.xml` da `ZipArchive`.
4. Normalizzazione (`sanitizeText`) e slicing in chunk di lunghezza `AI_CHUNK_SIZE` con sovrapposizione `AI_CHUNK_OVERLAP`.
5. Calcolo degli embedding a batch (`generateEmbeddings`): divide i chunk in blocchi di `OPENAI_EMBEDDINGS_BATCH`, poi chiama `OpenAiEmbeddingService::embedTexts`.
6. Persistenza su DB: ogni chunk genera una riga in `acoinazionale_chunks` (model `PosterChunk`). I campi salvati includono idUtente, path sorgente, indice chunk, testo, embedding (JSON), token stimati e checksum.

Il comando accetta opzioni utili:
- `--poster=ID` (multiplo) limita a specifici poster.
- `--force` rigenera cancellando i chunk esistenti per quel file.
- `--limit=N` ferma dopo N chunk per file.
- `--dry-run` mostra il processo senza salvare (embedding sostituiti da array vuoti).

### Servizio embedding
`OpenAiEmbeddingService` (`backend/app/Services/OpenAiEmbeddingService.php`) incapsula la chiamata HTTP:

- Usa `GuzzleHttp\Client` verso `config('ai.openai.base_uri')`.
- Prepara payload `model + input[]`, invoca `POST /embeddings` con header Authorization bearer.
- Converte i vettori in array di float.

Ogni errore o risposta malformata viene loggata e rilanciata come `RuntimeException`.

### Trigger automatico
In `PosterController@uploadFiles` (linee ~330+) dopo l’upload di un documento viene eseguito:
```php
Artisan::call('ai:ingest', [
    '--poster' => [$poster->id],
    '--force' => true,
]);
```
Quindi ogni nuovo file rigenera immediatamente i chunk corrispondenti, purché il server web possa lanciare comandi Artisan (nel deployment attuale sì).

## 3. Struttura dei dati

- Migrazione `2025_09_18_084735_create_poster_chunks_table` crea la tabella `acoinazionale_chunks` con:
  - `id`, `idUtente`, `source_path`, `chunk_index`.
  - `content` (testo), `embedding` (JSON), `token_count`, `checksum`, timestamps.
  - Vincolo unique su `(source_path, chunk_index)` per evitare duplicati.
- Model `PosterChunk` (`backend/app/Models/PosterChunk.php`) abilita `fillable` per tutti i campi e fa cast automatico dell’`embedding` in array.

Finché il `.env` punta al DB remoto, tutte queste scritture vanno direttamente a MySQL Aruba.

## 4. Retrieval runtime

### Calcolo similitudine
`AiRetrievalService` (`backend/app/Services/AiRetrievalService.php`):

1. Riceve la domanda dell’utente e chiama `OpenAiEmbeddingService::embedTexts([$question])` per ottenere il vettore della query.
2. Recupera *tutti* i chunk con embedding non nullo dalla tabella `acoinazionale_chunks`.
3. Calcola la similarità coseno (`cosineSimilarity`) fra query e chunk.
4. Ordina per similarità decrescente e restituisce i migliori `N` (default 8, configurabile).

### Costruzione del contesto e risposta
`AiController@query` (`backend/app/Http/Controllers/AiController.php`):

1. Valida input (`question`, `max_sources`).
2. Richiede i chunk rilevanti al servizio di retrieval.
3. Costruisce un blocco testo “context” con label Documento X, percorso, idUtente, similarità e snippet.
4. Passa domanda + contesto a `OpenAiResponseService::generateAnswer`, che chiama l’endpoint `/responses` di OpenAI con un prompt system in italiano orientato alla commissione e restituisce l’output testuale. In caso nessun chunk rilevante, risponde con stringa fissa (“Non ho trovato…”).

La risposta JSON contiene sia `answer` sia `sources` per mostrare al frontend da dove arriva l’informazione.

## 5. Considerazioni per replicare

- Porta con te i servizi `OpenAiEmbeddingService`, `AiRetrievalService`, `OpenAiResponseService`, il comando `IngestPosterFiles`, il modello/migrazione `PosterChunk` e la configurazione `config/ai.php`.
- Assicurati che il nuovo progetto possa raggiungere il DB remoto (o clona la tabella sui tuoi ambienti). Se non hai accesso di rete, devi eseguire RAG sul server che lo ha.
- Mantieni coerente lo storage dei file: se esegui l’ingest da una macchina diversa, devi sincronizzare la directory `public/posters`.
- Eventuali timeouts su OpenAI o batch troppo grandi possono essere gestiti modificando `OPENAI_TIMEOUT` e `OPENAI_EMBEDDINGS_BATCH`.

Con queste componenti lo stesso RAG funzionerà anche altrove, purché il processo che lancia ingest/query abbia accesso sia ai file sia al database remoto con le stesse credenziali.
