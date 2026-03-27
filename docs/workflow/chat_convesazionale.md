# 1) Assunzioni

- Il chatbot è **informativo**, con conversazioni in media **brevi (2-6 turni)**.
- Il backend dispone già di:
  - **RAG funzionante**
  - **prompt builder centralizzato**
  - **policy path** (`full_answer`, `partial_answer`, `partial_answer_clarify`, `soft_fallback`, `strict_fallback`)
  - **logging diagnostico** già abbastanza ricco
- Il backend **non dispone ancora** di:
  - stato conversazionale reale tra i turni
  - rilevamento robusto di input ellittici
  - ricostruzione della query prima del RAG
- KPI implicito:
  - **qualità percepita e continuità conversazionale > latenza minima assoluta**

👉 Assunzione critica da evitare:
**“basta aggiungere memoria” = falso**

Quello che serve davvero è:
- memoria breve
- interpretazione del follow-up
- query RAG ricostruita quando necessario

---

# 2) Problema reale

## Problema

Il sistema oggi è sostanzialmente **stateless**, mentre l’utente comunica in modo:
- contestuale
- implicito
- ellittico

## Sintomi

- Input brevi tipo `standard`, `ok`, `rfid`, `e i costi?`
- Query semanticamente deboli passate al RAG
- Risposte corrette nel singolo turno ma incoerenti nel flusso
- Chiarimenti ripetuti anche quando il contesto conversazionale esiste già

## Root cause

Manca un layer intermedio di **conversation state management leggero**, posto tra:
- input utente
- retrieval
- prompt finale

---

# 3) Obiettivo architetturale

Ricostruire il significato dell’input **prima** di:
1. retrieval RAG
2. chiamata al modello

Questo layer non deve simulare una memoria lunga stile ChatGPT.  
Deve invece:
- leggere pochi turni recenti
- capire se l’input nuovo è autosufficiente oppure no
- arricchire la query solo quando serve
- evitare di contaminare query già buone con contesto vecchio

---

# 4) Architettura target

```text
User Input
↓
[Conversation Layer]
  - short memory
  - follow-up detection
  - context stitching
  - query enrichment guardrails
↓
[RAG Layer]
  - query originale oppure query arricchita
↓
[Prompt Builder]
  - include input originale
  - include eventuale contesto ricostruito
↓
GPT-4.1
```

---

# 5) Cosa NON fare

## ❌ Non usare una memory lunga completa

Per questo caso d’uso sarebbe:
- più costosa
- più rumorosa
- più lenta
- poco utile

## ❌ Non concatenare sempre storico + nuovo input

Esempi:
- `badge?` può essere già una query valida da sola
- `SICAM` può essere una named entity valida da sola

Se concateni sempre il turno precedente rischi di sporcare il retrieval.

## ❌ Non usare solo la regola “input corto < 15 caratteri”

È una euristica utile ma insufficiente.  
La lunghezza da sola non distingue:
- query breve ma autonoma
- query breve ma ellittica

---

# 6) Soluzione corretta

## Principio

Non basta rilevare se l’input è corto.  
Bisogna capire se è **semanticamente autosufficiente**.

## Regola base

Per ogni nuovo input il backend deve decidere tra due strade:

### A) Input autosufficiente
Usare:
- query originale per il RAG
- storico minimo solo come supporto conversazionale nel prompt

### B) Input ellittico / dipendente dal contesto
Usare:
- query ricostruita per il RAG
- input originale + contesto ricostruito nel prompt

---

# 7) Tipologie di follow-up da gestire

## 1. Follow-up confermativo

Esempi:
- `sì`
- `ok`
- `va bene`
- `perfetto`

### Comportamento atteso
Non aggiungono un nuovo topic.  
Servono a confermare o sbloccare il ramo aperto dal bot.

### Azione
Usare il turno precedente come base e ricostruire l’intento.

---

## 2. Follow-up selettivo

Esempi:
- `standard`
- `rfid`
- `con ipad`
- `quello con qr`

### Comportamento atteso
Selezionano una variante tra opzioni già presenti nel contesto conversazionale.

### Azione
Recuperare:
- ultima domanda del bot
- ultimo topic attivo
- eventuali opzioni nominate nei turni recenti

Poi costruire una query completa.

Esempio:

```text
Input utente: standard
Contesto attivo: differenza badge standard vs badge RFID
Query ricostruita: badge standard stampa badge Echelon
```

---

## 3. Follow-up tematico

Esempi:
- `e i costi?`
- `e per webinar?`
- `e su iphone?`
- `e per eventi grandi?`

### Comportamento atteso
Non confermano una variante, ma cambiano l’asse della domanda mantenendo però il topic aperto.

### Azione
Mantenere il topic attivo e sostituire il focus della richiesta.

Esempio:

```text
Topic attivo: stampa badge on-site
Input utente: e i costi?
Query ricostruita: costi stampa badge on-site Echelon
```

---

# 8) Detection: quando l’input NON è autosufficiente

## Euristiche minime

Un input è candidato ad essere ellittico se:
- ha una sola parola
- oppure ha meno di una certa lunghezza
- oppure è composto da conferme/discorso breve (`ok`, `sì`, `perfetto`)
- oppure contiene pattern di continuità (`e i costi?`, `e per`, `quello`, `questa opzione`)

## Guardrail obbligatorio

L’euristica di brevità **non basta**.

Prima di fare stitching bisogna verificare almeno uno di questi segnali:
- l’input contiene pronomi/dimostrativi ambigui (`quello`, `questa`, `standard`)
- l’input non ha topic forte autonomo
- l’input non produce una query RAG sufficientemente sensata da solo

## Esempi da NON trattare come ellittici automaticamente

- `badge`
- `qrcode`
- `rfid`
- `SICAM`

Sono input brevi, ma possono essere query autosufficienti.

---

# 9) Context stitching

## Obiettivo

Trasformare un input debole in una query completa e utile al retrieval.

## Fonti da usare per la ricostruzione

Ordine di priorità:
1. ultimo topic attivo
2. ultima domanda del bot
3. ultimo messaggio utente precedente
4. ultimi 2-3 turni della conversazione

## Regola pratica

Non bisogna incollare tutto lo storico.  
Bisogna estrarre solo:
- topic
- variante
- asse della domanda

## Output desiderato

La query ricostruita deve essere:
- più esplicita
- più utile per il RAG
- corta ma completa

Esempi:

```text
Input originale: standard
Query ricostruita: badge standard per stampa badge on-site
```

```text
Input originale: e i costi?
Query ricostruita: costi accredito con iPad per eventi Echelon
```

---

# 10) Query enrichment per il RAG

## Regola corretta

Il RAG non deve ricevere sempre la query ricostruita.  
Deve ricevere:

- **query originale**, se autosufficiente
- **query arricchita**, se ellittica

## Motivazione

Se arricchisci sempre:
- aumenti rumore
- peggiori ranking
- rischi di trascinarti dietro il topic sbagliato

## Decisione target

Per ogni request bisogna avere due campi distinti:
- `original_input`
- `resolved_query`

Con regola:

```text
if input_autosufficiente:
    resolved_query = original_input
else:
    resolved_query = query_ricostruita
```

---

# 11) Prompt engineering

## Obiettivo

Il modello deve sapere quando la query è stata ricostruita e come interpretarla.

## Regola

Nel prompt finale conviene includere:
- input originale dell’utente
- eventuale query risolta
- minimo storico conversazionale utile

## Da evitare

Non bisogna demandare tutto al modello con una frase generica tipo:
> “interpreta le risposte brevi come follow-up”

Quella istruzione è utile, ma da sola è insufficiente.  
La parte decisiva deve avvenire **nel backend**, prima del retrieval.

## Istruzione consigliata

Da aggiungere come supporto, non come unica soluzione:

> Se l’utente risponde in modo breve o selettivo, interpreta l’input come follow-up del contesto recente solo se coerente con il topic attivo e con la query risolta fornita dal sistema.

---

# 12) Stato conversazionale minimo da salvare

## Sliding window consigliata

Salvare gli ultimi **3-4 messaggi utili**, non tutta la chat.

## Dati minimi utili

Per ogni sessione conviene tenere:
- ultimi messaggi utente e assistant
- ultimo topic attivo
- ultima domanda di chiarimento del bot
- eventuale ultima query risolta
- timestamp ultimo aggiornamento

## Storage

Soluzione consigliata:
- Redis / cache Laravel con TTL

## Motivazione

È abbastanza leggero per:
- continuità conversazionale
- buon debugging
- impatto basso su complessità

---

# 13) Logging necessario

## Logging minimo richiesto

Per ogni turno loggare:
- `original_input`
- `input_is_short`
- `input_is_elliptic`
- `active_topic`
- `resolved_query`
- `rag_query`
- `policy_path`
- `rag_hits`
- `diagnostic_hits`
- `final_reply`

## Perché è fondamentale

Senza questi log non puoi capire:
- quando hai stitchato bene
- quando hai stitchato male
- quando dovevi lasciare la query invariata

---

# 14) Punti backend da toccare davvero

## 1. Prima del RAG

Nel controller text bisogna inserire un layer che:
- recupera lo stato conversazionale della sessione
- classifica l’input
- decide se ricostruire o no la query

## 2. KnowledgeSearchService / search call

Il RAG deve usare `resolved_query`, non sempre il messaggio originale.

## 3. Prompt builder

Il prompt deve ricevere:
- messaggio originale
- query risolta
- minimo contesto utile

## 4. Logging

Va esteso con i campi del conversation layer.

## 5. Persistenza stato sessione

Va introdotta una cache breve per sessione.

---

# 15) Roadmap implementativa corretta

## FASE 1 — Session state minimo

### Step 1: session memory
- Redis / cache Laravel
- TTL breve
- ultimi 3-4 messaggi utili
- topic attivo e ultima query risolta

### Step 2: storico minimo nel prompt
- includere ultimi 2-3 turni solo quando utili
- non serializzare tutto lo storico

---

## FASE 2 — Follow-up detection

### Step 3: classificazione input
Classificare l’input in almeno:
- autosufficiente
- confermativo
- selettivo
- tematico

### Step 4: guardrail anti-overwrite
Se l’input è breve ma semanticamente forte, non stitchare.

---

## FASE 3 — Context reconstruction

### Step 5: query resolution
Costruire `resolved_query` usando:
- topic attivo
- ultima domanda del bot
- ultimo turno utile

### Step 6: fallback di sicurezza
Se la ricostruzione è incerta, mantenere la query originale.

---

## FASE 4 — RAG integration

### Step 7: usare `resolved_query` nel retrieval
- non nel 100% dei casi
- solo se la classificazione lo richiede

### Step 8: tracciare query originale vs query risolta
- indispensabile per tuning

---

## FASE 5 — Prompt refinement

### Step 9: aggiornare le istruzioni
- spiegare al modello che può ricevere follow-up risolti dal sistema
- non delegare al modello la ricostruzione principale

---

## FASE 6 — Hardening

### Step 10: logging avanzato
- log dedicati al conversation layer
- casi riusciti / falliti

### Step 11: tuning su casi reali
Testare almeno su:
- `standard`
- `ok`
- `e i costi?`
- `rfid`
- `con ipad`
- `quello con qr`
- query brevi ma autosufficienti (`badge`, `qrcode`, `sicam`)

---

# 16) Mapping tecnico nel backend attuale

## File principali da toccare

### 1. `apps/backend/app/Http/Controllers/ChatRespondController.php`

È il punto centrale di orchestrazione della pipeline text.

## Cosa fa oggi
- riceve `message`, `session_id`, `tenant`
- calcola `intent`
- invoca il RAG con `searchWithDiagnostics($message, ...)`
- calcola confidence / policy path
- costruisce il prompt
- chiama `OpenAITextService`
- logga l’intero flusso

## Cosa va aggiunto qui

### Nuovo layer prima del RAG
Da inserire subito dopo:
- lettura tenant
- normalizzazione input

e prima di:
- `detectIntent()`
- `searchWithDiagnostics()`

### Responsabilità nuove
- recuperare lo stato conversazionale della sessione
- classificare il nuovo input
- decidere se è autosufficiente o ellittico
- produrre:
  - `original_input`
  - `resolved_query`
  - `input_mode`
  - `active_topic`

### Metodi da introdurre qui o delegare a un service dedicato
- `loadConversationState(string $sessionId, string $tenantId): array`
- `resolveConversationInput(string $message, array $conversationState): array`
- `storeConversationState(string $sessionId, string $tenantId, array $state): void`

### Variabili nuove da far scorrere nel flusso
- `originalInput`
- `resolvedQuery`
- `inputMode`
- `inputIsElliptic`
- `activeTopic`
- `conversationTurns`

### Metodi esistenti da aggiornare

#### `__invoke()`
Va aggiornato per:
- usare `resolvedQuery` nel RAG
- mantenere `message` originale per log e risposta
- passare il contesto conversazionale al prompt builder

#### `buildPrompt(...)`
Va esteso per ricevere anche:
- input originale
- query risolta
- breve storico utile
- topic attivo

Non deve ricevere tutto lo storico grezzo.

#### `detectIntent(string $query)`
Idealmente deve lavorare su:
- `resolvedQuery` quando l’input è ellittico
- `originalInput` quando è autosufficiente

In alternativa può essere lasciato sul messaggio originale, ma con casi di follow-up tematico rischia di perdere segnale.

#### `resolvePolicyPath(...)`
Non è il primo punto da toccare, ma va tenuto presente perché dopo l’introduzione del conversation layer:
- alcune query oggi `short_query`
- potranno diventare query risolte più forti

Quindi i bucket potrebbero cambiare naturalmente.

---

### 2. `apps/backend/app/Knowledge/KnowledgeSearchService.php`

Questo è il punto in cui oggi il RAG riceve direttamente la query.

## Cosa fa oggi
- prova `structuredLookup`
- genera embedding della query
- cerca keyword candidates
- calcola cosine similarity sui chunk
- restituisce:
  - `accepted_hits`
  - `diagnostic_hits`
  - `keyword_candidates`
  - `semantic_level`
  - `top_score`

## Cosa va cambiato

### Nessuna rivoluzione interna obbligatoria
La logica di retrieval può restare quasi invariata.

### Modifica necessaria
Deve ricevere `resolved_query` invece di `message` quando il conversation layer decide che serve stitching.

### Possibile estensione utile
Senza toccare troppo la logica, può essere utile aggiungere nei log o nel return:
- `query_used`
- eventualmente `query_origin = original|resolved`

Così è più facile fare debugging.

---

### 3. `apps/backend/app/Knowledge/KnowledgeRepository.php`

## Cosa fa oggi
- keyword search
- synonyms / normalization / ranking
- structured lookup

## Cosa va fatto qui

### In prima battuta
Probabilmente **nessuna modifica obbligatoria**.

Il repository deve continuare a lavorare su una query già buona.

### Possibile impatto indiretto
Se la query risolta diventa più ricca:
- i match keyword
- i sinonimi
- il ranking composito

diventeranno automaticamente più efficaci.

Quindi questo file non è il punto primario dell’intervento conversazionale.

---

### 4. `apps/backend/app/Services/OpenAITextService.php`

## Cosa fa oggi
- invia `instructions`, `input`, `model`, `tools`
- opzionalmente abilita `web_search`
- estrae `text` e `sources`

## Cosa va fatto qui

### In prima battuta
Nessuna modifica strutturale obbligatoria.

### Possibile estensione secondaria
Se in futuro vuoi tracciare meglio il comportamento conversazionale, potresti voler loggare a monte:
- prompt size
- storico incluso
- query risolta passata al prompt

Ma non è necessario per la prima implementazione.

---

### 5. `apps/backend/config/tenants.php`

## Cosa va fatto

Le istruzioni tenant-specifiche andranno aggiornate dopo il conversation layer.

### Obiettivo
Dire al modello che:
- può ricevere follow-up brevi
- il backend potrebbe aver già risolto il contesto
- non deve reinterpretare aggressivamente il turno se la query risolta è già presente

### Nota
Questa è una rifinitura successiva, non il primo step.

---

### 6. Cache / stato conversazionale

## Punto tecnico da introdurre

Serve un layer di persistenza breve per sessione.

### Soluzione consigliata
- cache Laravel / Redis

### Possibili posizioni implementative

#### Opzione A: logica nel controller
Più veloce da realizzare ma meno pulita.

#### Opzione B: nuovo service dedicato
Consigliata.

Esempio:
- `apps/backend/app/Chat/ConversationStateService.php`
- `apps/backend/app/Chat/ConversationInputResolver.php`

### Scelta consigliata
Usare due service distinti:

#### `ConversationStateService`
Responsabilità:
- load/save stato sessione
- sliding window
- TTL

#### `ConversationInputResolver`
Responsabilità:
- classificazione input
- detection follow-up
- costruzione `resolved_query`
- estrazione `active_topic`

Questa separazione riduce il coupling nel controller.

---

### 7. Logging / analytics

## File coinvolti indirettamente

### `apps/backend/app/Http/Controllers/ChatReportKpiController.php`
Non è il primo file da modificare, ma se vuoi analizzare in dashboard il nuovo layer conversazionale, poi potresti aggiungere KPI tipo:
- percentuale input ellittici
- percentuale query risolte
- differenza performance tra `original_input` e `resolved_query`

### `text_chat` logs
Il logging operativo vero andrà esteso subito nel controller text.

Campi consigliati:
- `original_input`
- `resolved_query`
- `input_mode`
- `input_is_elliptic`
- `active_topic`
- `conversation_turns_count`

---

## Sequenza concreta di implementazione nel codice

### Step A
Creare il service stato sessione:
- `ConversationStateService`

### Step B
Creare il service di risoluzione input:
- `ConversationInputResolver`

### Step C
Integrare entrambi in:
- `ChatRespondController::__invoke()`

### Step D
Far usare al RAG:
- `resolved_query`

### Step E
Aggiornare:
- `buildPrompt(...)`

### Step F
Estendere i log del controller text

### Step G
Solo dopo:
- rifinire `tenants.php`
- aggiungere analytics dedicati

---

# 17) Trade-off realistici

| Aspetto | Impatto realistico |
|--------|--------|
| Qualità percepita | alto |
| Robustezza conversazionale | alta |
| Latenza | +100-300ms circa |
| Complessità | medio-bassa |
| Rischio overfitting | medio se mancano guardrail |

## Nota

Il miglioramento atteso è significativo, ma non va descritto con percentuali arbitrarie.  
La bontà reale si misura su:
- casi di follow-up recuperati correttamente
- riduzione dei chiarimenti inutili
- riduzione delle risposte incoerenti

---

# 18) Sintesi finale

La soluzione giusta per questo progetto non è:
- memory lunga
- Conversations API
- prompt più lungo e basta

La soluzione giusta è:
- **sliding window corta**
- **classificazione del follow-up**
- **context stitching solo quando serve**
- **query RAG arricchita con guardrail**
- **logging forte per tuning reale**

## Ordine di implementazione consigliato

1. Session memory breve
2. Classificazione input
3. Context stitching con guardrail
4. `resolved_query` per il RAG
5. Prompt aggiornato
6. Logging e tuning
