# ConversationInputResolver - Guida Tecnica

## Cos'e
`ConversationInputResolver` e il primo layer logico del nuovo sistema conversazionale.
Non esegue retrieval, non chiama il modello e non salva stato: interpreta il nuovo input utente usando il contesto breve gia disponibile.

File principale:
- [ConversationInputResolver.php](/home/daniele/CharloTte/apps/backend/app/Chat/ConversationInputResolver.php)

File collegati:
- [ConversationStateService.php](/home/daniele/CharloTte/apps/backend/app/Chat/ConversationStateService.php)
- [ChatRespondController.php](/home/daniele/CharloTte/apps/backend/app/Http/Controllers/ChatRespondController.php)
- [models.php](/home/daniele/CharloTte/apps/backend/config/models.php)
- [chat_convesazionale.md](/home/daniele/CharloTte/docs/workflow/chat_convesazionale.md)

## Perche e importante
Il problema di partenza e questo:
- l'utente parla spesso in modo breve, implicito o ellittico
- il backend oggi lavora molto bene quando la query e esplicita
- il RAG peggiora quando riceve input tipo `standard`, `ok`, `e i costi?`

`ConversationInputResolver` serve proprio a evitare che quel tipo di input arrivi al retrieval in forma troppo povera o ambigua.

In pratica:
- decide se l'input e autosufficiente
- oppure se dipende dal contesto recente
- e costruisce la `resolved_query` che poi andra usata dal controller

## Ruolo nella pipeline
Posizione logica:
1. arriva il nuovo messaggio utente
2. il controller carica lo stato sessione con `ConversationStateService`
3. `ConversationInputResolver` interpreta l'input
4. il controller usa:
- `original_input` per log e continuita
- `resolved_query` per il RAG quando necessario
5. il controller aggiorna lo stato salvando topic e query piu recenti

Quindi il file si colloca:
- prima del RAG
- prima del prompt builder
- come layer di interpretazione conversazionale

## Cosa restituisce
Output principale di `resolve(...)`:
- `original_input`
- `normalized_input`
- `resolved_query`
- `input_mode`
- `input_is_short`
- `input_is_elliptic`
- `active_topic`
- `resolved_active_topic`
- `context_source`
- `used_context`

### `original_input`
Messaggio utente reale, cosi come arriva.

### `normalized_input`
Versione normalizzata per fare matching logico sui pattern conversazionali.

### `resolved_query`
Query finale proposta dal resolver per il retrieval.

Esempi:
- input autosufficiente -> resta uguale
- input ellittico -> viene arricchita con il contesto

### `input_mode`
Classificazione del messaggio.

Valori attuali:
- `empty`
- `standalone_short`
- `confirmative`
- `thematic_follow_up`
- `selective_follow_up`
- `self_contained`

### `input_is_short`
Flag euristico: input breve o no.
Serve come segnale, ma non basta da solo a decidere lo stitching.

### `input_is_elliptic`
Dice se l'input e stato interpretato come dipendente dal contesto.

### `active_topic`
Topic attivo letto dallo stato sessione in ingresso.

### `resolved_active_topic`
Topic che il resolver propone di salvare come nuovo stato.

Questo e il campo importante per evitare che il topic vecchio resti attaccato quando l'utente cambia argomento.

### `context_source`
Indica quale pezzo di contesto e stato usato:
- `active_topic`
- `last_resolved_query`
- `last_bot_question`

### `used_context`
Flag booleano: il resolver ha davvero usato il contesto oppure no.

## Cosa fa davvero il metodo `resolve(...)`

### 1. Legge config conversazionale
Le euristiche non sono hardcoded nel codice: arrivano da
[`models.php`](/home/daniele/CharloTte/apps/backend/config/models.php)
nel blocco:
- `models.pipelines.text.conversation`

Questo permette tuning successivo senza rifare la logica.

### 2. Normalizza l'input
Usa `TextNormalizer::forEmbedding(...)` e poi ripulisce ulteriormente la stringa per confrontare pattern tipo:
- `si`
- `standard`
- `e i costi`

### 3. Legge il contesto sintetico
Usa questi campi dallo stato:
- `active_topic`
- `last_resolved_query`
- `last_bot_question`

Nota:
in questa versione il resolver usa ancora poco i `turns` grezzi; si appoggia soprattutto alla memoria sintetica.

### 4. Classifica l'input
Ordine logico:
1. input vuoto
2. query breve ma autosufficiente
3. conferma pura
4. follow-up tematico
5. follow-up selettivo
6. query autosufficiente normale

### 5. Produce `resolved_query`
Se l'input e autosufficiente:
- `resolved_query = original_input`

Se l'input dipende dal contesto:
- costruisce una query arricchita usando il contesto migliore disponibile

### 6. Produce `resolved_active_topic`
Regola generale:
- follow-up veri -> mantieni il topic corrente
- input autosufficiente -> proponi come nuovo topic il messaggio stesso

Questo evita che il sistema continui a ragionare con un topic vecchio quando la conversazione ha gia cambiato direzione.

## Tipologie di input gestite

### `standalone_short`
Input breve ma autonomo.

Esempi tipici:
- `badge`
- `rfid`
- `sicam`
- `totem`

Effetto:
- non usa contesto
- non stitcha
- puo aggiornare il topic al nuovo input

### `confirmative`
Input che conferma o sblocca il ramo gia aperto.

Esempi:
- `si`
- `ok`
- `perfetto`

Effetto:
- eredita il contesto
- non apre topic nuovo

### `thematic_follow_up`
Input che mantiene il topic ma cambia l'asse della richiesta.

Esempi:
- `e i costi?`
- `e per webinar?`
- `e su iphone?`

Effetto:
- eredita il topic
- arricchisce la query per il RAG

### `selective_follow_up`
Input che seleziona una modalita o una variante nel contesto gia aperto.

Esempi:
- `standard`
- `con ipad`
- `quello con qr`

Effetto:
- usa topic/query precedenti come base
- concatena la selezione in una `resolved_query` piu chiara

### `self_contained`
Input normale e gia sufficientemente autonomo.

Effetto:
- niente stitching
- il nuovo input puo diventare il topic da salvare

## Interazione con `ConversationStateService`
I due file hanno ruoli distinti:

### `ConversationStateService`
E il contenitore dello stato breve:
- legge/salva
- mantiene sliding window
- conserva contesto sintetico

### `ConversationInputResolver`
E il layer decisionale:
- interpreta il nuovo input
- decide se usare il contesto
- propone query e topic da salvare

In pratica:
- uno conserva memoria
- l'altro la usa

## Interazione con `ChatRespondController`
Quando verra integrato nel controller, il flusso target sara:
1. `load(...)` dello stato
2. `resolve(...)` del nuovo input
3. `searchWithDiagnostics($resolvedQuery, ...)`
4. build prompt con input originale + query risolta
5. update stato con:
- turni
- `resolved_active_topic`
- `last_resolved_query`
- eventuale `last_bot_question`

## Config usata
Da `models.php`:
- `short_input_length`
- `standalone_short_terms`
- `confirmation_terms`
- `selective_terms`
- `thematic_prefixes`

Questo e importante perche sposta il tuning:
- fuori dal codice
- dentro configurazione

## Priorita del contesto
Quando deve costruire una query arricchita, il resolver usa una priorita conservativa:
1. `active_topic`
2. `last_resolved_query`
3. `last_bot_question`

Motivo:
- il topic e piu generale e meno fragile
- la query risolta e piu specifica ma anche piu legata al turno precedente
- la domanda del bot e fallback finale

## Cosa influenza nella pipeline
Direttamente:
- qualita della query usata dal RAG
- continuita conversazionale percepita
- riduzione dei chiarimenti inutili
- miglioramento dei follow-up brevi

Indirettamente:
- confidence finale
- semantic retrieval
- policy path nei casi oggi borderline

## Cosa NON fa (ad oggi)
- non salva stato
- non chiama direttamente il RAG
- non costruisce prompt
- non usa ancora davvero i `turns` grezzi come fallback avanzato
- non fa una vera riscrittura semantica sofisticata: la `resolved_query` e ancora costruita in modo semplice

## Limiti attuali

### 1. Query reconstruction semplice
La costruzione della `resolved_query` e ancora basata su concatenazione conservativa.
Va bene per partire, ma in futuro puo essere raffinata.

### 2. Uso limitato dei `turns`
Per ora il resolver si appoggia soprattutto al contesto sintetico:
- `active_topic`
- `last_resolved_query`
- `last_bot_question`

I `turns` completi saranno utili in versioni successive per casi borderline.

### 3. Tuning lessicale da fare nel tempo
Le liste in config sono corrette per partire, ma andranno rifinite su casi reali.

## Perche e una base buona per produzione
Perche adesso il file:
- non stitcha in modo aggressivo
- protegge le query brevi ma autosufficienti
- separa bene input originale e query risolta
- aggiorna il topic in modo coerente
- sposta il tuning in config

Quindi non e una soluzione finale definitiva, ma e gia una base solida e prudente per iniziare l'integrazione reale nel backend.

## Sintesi pratica
`ConversationInputResolver` e il componente che decide:
- quando lasciare stare l'input
- quando usarlo come nuovo topic
- quando invece ricostruirlo con il contesto

E il primo vero passo che trasforma la chat da stateless a contestuale, senza introdurre memoria lunga o logiche troppo pesanti.
