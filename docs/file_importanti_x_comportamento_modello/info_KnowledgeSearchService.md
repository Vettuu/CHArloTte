# KnowledgeSearchService - Guida Tecnica

## Cos'e
`KnowledgeSearchService` e il motore di retrieval RAG lato backend.
Orchestra:
- `KnowledgeRepository` (keyword + structured lookup)
- `OpenAIEmbeddingService` (embedding query)
- `KnowledgeChunk` (chunk salvati in DB)

File:
- [KnowledgeSearchService.php](/home/daniele/CharloTte/apps/backend/app/Knowledge/KnowledgeSearchService.php)

## Perche e importante
Decide se il controller riceve:
- contesto utile (`accepted_hits`)
- solo diagnostica (`diagnostic_hits`)
- nessun hit (fallback duro)

Quindi impatta direttamente:
- accuratezza risposte
- fallback rate
- qualita percepita del modello

## Flusso interno
1. Riceve query + tenant.
2. Prova `structuredLookup` (dati certi).
3. Calcola embedding query.
4. Prende candidati documenti keyword (da `KnowledgeRepository->search`).
5. Limita i documenti candidati (`take(10)`).
6. Calcola cosine similarity su chunk tenant.
7. Classifica `semantic_level` da `top_score`:
- `high`
- `medium`
- `low`
8. Ritorna `searchWithDiagnostics()`:
- `accepted_hits`
- `diagnostic_hits`
- `keyword_candidates`
- `semantic_level`
- `top_score`

## Output principali
- `accepted_hits`: hit che il controller usa per costruire risposta.
- `diagnostic_hits`: hit utili al debug anche se non accettati.
- `keyword_candidates`: documenti trovati via keyword (con metriche match).
- `semantic_level`: livello qualitativo da top score.
- `top_score`: miglior score semantic trovato.

## Parametri completati (stato attuale)

### 1) Limit hit
- `limit` default allineato a `4`.
- In controller text viene usato `max_hits` (attualmente 4).

### 2) Candidate documents
- `candidateDocuments->take(10)` (prima 5).
- Migliora recall su knowledge ampia/cross-topic.

### 3) Gating per livello semantic
Configurabile da `config/knowledge.php`:
- `score_levels.high` (default 0.70, override env)
- `score_levels.medium_min` (default 0.36)

Logica:
- `high`: `top_score >= high`
- `medium`: `medium_min <= top_score < high`
- `low`: `top_score < medium_min`

### 4) Low confidence handling
Il controller usa il risultato per distinguere:
- `low` + diagnostic presente -> `soft_fallback`
- `low` + zero reale -> `strict_fallback`

## Come influenza il controller/modello
Il controller non "indovina" qualita RAG: la riceve da questo servizio.
Campi critici passati in avanti:
- `rag_hits`
- `top_score`
- `semantic_level`
- `diagnostic_hits`
- `keyword_candidates`

Questi campi guidano `policy_path` (`full/partial/soft_fallback/strict_fallback`).

## Diagnostica attuale (utile per tuning)
Per ogni richiesta hai dati oggettivi:
- score semantic (top + lista)
- refs hit usati
- candidate keyword
- ratio keyword match (propagato da repository)

Serve per tuning empirico di soglie e policy.

## Cose che possiamo migliorare ancora (prossimi step)
1. Soglie dinamiche per intent (`core_info`, `pricing`, `showcase`).
2. Reranking composito (semantic + keyword bonus).
3. Penalita/bonus per documento duplicato nei top hit.
4. Gestione contraddizioni direttamente in retrieval (flag piu robusto).
5. Riduzione rumore su query molto corte (già migliorata lato controller).
6. Estendere diagnostica con tempi per singola fase retrieval.
7. Applicare fallback keyword "controllato" anche quando semantic low.

## Nota pratica
Se cambi i range (high/medium/low), cambi automaticamente:
- quanti hit passano al controller
- quanto spesso scatta fallback
- stile risposta finale (completo vs chiarimento).

## Checklist Test Empirici
Protocollo rapido per tarare `score_levels` e comportamento di fallback.

### 1) Query consigliate (10)
1. "Quali sono i servizi della Echelon?"
2. "Avete totem multimediali?"
3. "Kiosk per registrazione?"
4. "Costo stampa badge per 300 persone"
5. "Controllo accessi con iPad"
6. "Servizi ECM"
7. "Esempi di eventi passati"
8. "Sono Daniele, mi fai una panoramica?"
9. "Come si chiama mio zio?" (fuori contesto)
10. "xqvpt kzrmn 998877" (rumore)

### 2) Metriche da osservare
- `rag_hits`
- `top_score`
- `semantic_level`
- `accepted_hits.count`
- `diagnostic_hits.count`
- `policy_path` (`full_answer`, `partial_answer`, `soft_fallback`, `strict_fallback`)
- `fallback`

### 3) Tuning pratico soglie
- Troppi fallback su query valide: abbassa `score_levels.medium_min` di `0.03-0.05`.
- Troppo rumore in risposta: alza `score_levels.medium_min` di `0.03-0.05`.
- Vuoi risposte complete solo con alta confidenza: alza `score_levels.high` (es. 0.70 -> 0.75).
- Vuoi più risposte complete: abbassa `score_levels.high` (es. 0.70 -> 0.65), ma verifica allucinazioni.

### 4) Criterio di qualità
- Query business frequenti: `partial_answer` o `full_answer`, non fallback duro.
- Query fuori contesto: fallback coerente, senza invenzioni.
- Query corte: preferire `partial_answer_clarify`.
