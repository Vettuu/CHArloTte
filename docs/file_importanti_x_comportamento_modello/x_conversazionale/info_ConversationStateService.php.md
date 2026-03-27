# ConversationStateService - Guida Tecnica

## Cos'e
`ConversationStateService` e il layer che introduce uno stato conversazionale minimo nella pipeline `text`.
Non interpreta ancora i messaggi: si occupa di memorizzare e recuperare dalla cache il contesto recente di una sessione.

File principale:
- [ConversationStateService.php](/home/daniele/CharloTte/apps/backend/app/Chat/ConversationStateService.php)

File collegati:
- [ChatRespondController.php](/home/daniele/CharloTte/apps/backend/app/Http/Controllers/ChatRespondController.php)
- [chat_convesazionale.md](/home/daniele/CharloTte/docs/workflow/chat_convesazionale.md)

## Perche e importante
Oggi la chat text e quasi stateless: ogni turno viene trattato come se fosse isolato.

Questo service e il primo pezzo che permette di correggere quel limite, perche conserva:
- ultimi turni utili
- topic attivo
- ultima query risolta
- ultima domanda del bot

In pratica: non migliora ancora da solo la risposta, ma crea la base tecnica necessaria per farlo nei passaggi successivi.

## Ruolo nella pipeline
Posizione logica target:
1. arriva il nuovo input utente
2. il controller legge lo stato sessione tramite `ConversationStateService`
3. un resolver conversazionale decide se l'input e autosufficiente o ellittico
4. il RAG usa eventualmente una `resolved_query`
5. a fine turno il controller aggiorna lo stato con nuovi messaggi e nuovo contesto

Quindi il service si colloca:
- prima del RAG, per fornire contesto
- dopo la risposta, per aggiornare la memoria breve

## Cosa salva davvero
Lo stato normalizzato contiene:
- `turns`
- `active_topic`
- `last_resolved_query`
- `last_bot_question`
- `updated_at`

### `turns`
Lista corta di messaggi recenti, ciascuno con:
- `role` (`user` o `assistant`)
- `message`
- `created_at`

### `active_topic`
Topic conversazionale attualmente piu probabile.
Esempio:
- `stampa badge`
- `accredito ipad`
- `televotazioni`

### `last_resolved_query`
Ultima query gia ricostruita e considerata valida per il retrieval.
Servira nelle fasi successive per follow-up tipo:
- `e i costi?`
- `standard`
- `quello con qr`

### `last_bot_question`
Ultima domanda del bot utile alla disambiguazione.
Serve soprattutto per follow-up confermativi o selettivi.

### `updated_at`
Timestamp tecnico di aggiornamento stato.

## Sliding window e TTL

### `MAX_TURNS`
Valore attuale: `4`

Significa che il service mantiene solo gli ultimi 4 turni utili.

Motivo:
- sufficiente per conversazioni brevi
- limita rumore
- mantiene basso il payload

### `TTL_SECONDS`
Valore attuale: `10800`

Significa che lo stato della sessione resta in cache per `10800` secondi, cioe circa `3 ore`.

Effetto pratico:
- se l'utente continua la conversazione entro quella finestra, il contesto e ancora disponibile
- se passa troppo tempo, la sessione viene considerata scaduta e il contesto sparisce da solo

Perche serve:
- evita che contesti vecchi restino vivi troppo a lungo
- evita pulizie manuali
- mantiene la memoria breve e coerente con il caso d'uso

## Metodi principali

### `load($sessionId, $tenantId)`
Carica lo stato dalla cache e lo normalizza.

Se non trova nulla, ritorna comunque una struttura valida vuota.

### `save($sessionId, $tenantId, array $state)`
Salva uno stato completo in cache dopo normalizzazione.

### `appendTurn($sessionId, $tenantId, $role, $message, array $meta = [])`
Metodo pratico per aggiungere un nuovo turno alla finestra conversazionale.

Oltre al messaggio, puo aggiornare anche:
- `active_topic`
- `last_resolved_query`
- `last_bot_question`

E applica automaticamente:
- trim messaggi
- ruolo valido
- limite massimo turni

### `updateContext($sessionId, $tenantId, array $patch)`
Aggiorna solo la parte contestuale senza aggiungere turni.

Utile quando il resolver capisce qualcosa di nuovo sul topic o sulla query.

### `clear($sessionId, $tenantId)`
Cancella lo stato della sessione.

Utile per:
- reset manuale
- test
- casi di invalidazione esplicita

## Scelte implementative rilevanti

### Cache key
La chiave e composta da:
- prefisso fisso
- tenant
- session id

Questo evita collisioni tra:
- tenant diversi
- sessioni diverse

### Normalizzazione
Il service normalizza sempre i dati in ingresso:
- messaggi vuoti scartati
- role non valido corretto
- stringhe vuote trasformate in `null` dove serve

Questo e importante per non propagare sporco nel layer conversazionale.

### Nessuna logica semantica interna
Il service non decide:
- se l'input e ellittico
- quale topic usare
- come costruire la query risolta

Questa e una scelta voluta.

Il file serve solo come storage/state layer, non come resolver.

## Cosa influenza nel comportamento del modello
Direttamente: poco o nulla, finche non viene integrato nel controller.

Indirettamente: tantissimo, perche rende possibili tutte le evoluzioni successive:
- follow-up detection
- context stitching
- query enrichment prima del RAG
- continuita conversazionale reale

## Cosa NON fa (ad oggi)
- non e ancora integrato nel `ChatRespondController`
- non classifica input
- non aggiorna topic in automatico
- non costruisce `resolved_query`
- non logga ancora campi dedicati nel `text_chat`

## Parametri tecnici da conoscere
- `CACHE_PREFIX`
  - prefisso chiave cache
- `MAX_TURNS`
  - numero massimo turni mantenuti
- `TTL_SECONDS`
  - durata vita stato sessione in cache

## Modifiche future probabili
Quando verra integrato nella pipeline, i punti piu probabili da rifinire saranno:
1. `MAX_TURNS`
2. durata `TTL_SECONDS`
3. struttura di `turns`
4. eventuale aggiunta di:
- `last_user_intent`
- `input_mode`
- `last_semantic_level`

## Sintesi pratica
`ConversationStateService` non e il cervello conversazionale.
E il contenitore tecnico minimo che permettera al cervello conversazionale di esistere.

Senza questo file:
- niente memoria breve affidabile
- niente follow-up robusti
- niente query ricostruite in modo consistente
