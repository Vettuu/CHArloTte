PARTE 1 — DESCRITTIVA LOGICA (ARCHITETTURA FUNZIONALE)
🎯 Obiettivo della Dashboard

Progettare una dashboard analitica avanzata per il monitoraggio di:

Performance tecnica del modello AI (RAG + LLM)

Qualità delle risposte

Comportamento utenti

Insight business sui topic e sulle domande

Diagnostica sessioni e debugging

La dashboard è single-user (uso interno), multi-tenant, multi-modello, orientata all’ottimizzazione continua.

🧭 Struttura Gerarchica della Dashboard

La dashboard è organizzata in 5 layer verticali:

1️⃣ CONTROL HEADER (Livello di Filtro Globale)

Barra superiore persistente con:

Tenant selector (multi)

Date range

Pipeline (text / voice / future)

Model selector

Knowledge version

Export CSV

Toggle modalità:

Vista Business

Vista Tecnica

Questa sezione controlla l’intera dashboard.

2️⃣ OVERVIEW KPI GENERALI (Radar Strategico)

Obiettivo: avere in 10 secondi lo stato di salute del sistema.

Blocchi KPI divisi in 3 macro categorie:

Utilizzo

Sessioni

Utenti unici

Messaggi user

Messaggi assistant

Messaggi per sessione

Qualità

Fallback rate

Contradiction rate

Confidence media

Distribuzione confidence bucket

Performance

Latency media

Latency p95

Token medi in/out

Costo stimato per sessione

Include:

Trend temporale principale

Micro-grafico per KPI

3️⃣ ANALISI BUSINESS (Insight Utente)

Obiettivo: capire cosa vogliono gli utenti.

Sezioni:

Top Topic

Top 10 per volume

Topic con fallback alto

Topic con confidence bassa

Topic emergenti

Intent Distribution

Distribuzione intent

Intent problematici

Short query rate

Keyword & Coverage

Keyword ricorrenti

Query non coperte dal RAG

Query con top_score basso

4️⃣ ANALISI TECNICA (ML-Oriented)

Obiettivo: diagnosticare il comportamento del modello.

Sezioni:

RAG Quality

Media rag_hits

Distribuzione top_score

Accepted vs diagnostic hits

Semantic level distribution

Confidence Diagnostics

Distribuzione confidence_score

Correlazione confidence vs fallback

Correlazione top_score vs confidence

Contradiction Monitor

Contradiction rate

Breakdown per tipo

Topic colpiti

Performance Breakdown

Latency distribuzione

Error rate

Timeout rate

Breakdown RAG vs LLM vs DB

5️⃣ SESSION DRILLDOWN

Sezione investigativa.

Include:

Lista sessioni filtrabile

Filtri avanzati:

fallback only

contradiction only

low confidence

high latency

topic specifico

Quando si apre una sessione:

Timeline eventi

JSON tecnico collapsabile

Evidenza rag_hits

Score evidenziati

Confidence + semantic diagnostics

🟣 Filosofia Visuale

Business e Technical separati visivamente

Dark theme tecnico

KPI grandi, grafici secondari

Layout modulare

Approccio data-driven

🔴 PARTE 2 — DESCRITTIVA TECNICA (PROCEDURALE E STRUTTURA DATI)
🧱 Architettura Dati

La dashboard deve aggregare eventi da log strutturati.

Schema evento minimo unificato:
analytics_events
- id
- timestamp
- session_id
- tenant
- pipeline
- model
- knowledge_tenant
- intent
- fallback
- contradiction_flag
- contradiction_type
- confidence_score
- confidence_bucket
- rag_hits
- accepted_hits_count
- diagnostic_hits_count
- top_score
- semantic_level
- query_token_count
- latency_ms
- reply_len
- token_in
- token_out
📊 Aggregazioni Richieste
KPI aggregati per periodo

count(session_id distinct)

avg(latency_ms)

percentile(latency_ms, 95)

avg(confidence_score)

sum(fallback) / total_sessions

sum(contradiction_flag) / total_sessions

avg(rag_hits)

avg(top_score)

📈 Grafici Richiesti
Distribuzioni

Histogram top_score

Histogram confidence_score

Boxplot latency

Heatmap topic vs fallback

Correlazioni

Scatter confidence vs top_score

Scatter latency vs reply_len

Scatter rag_hits vs confidence

🔍 Drilldown Tecnico Sessione

Per ogni sessione:

Query originale

Preview risposta

Lista accepted_hits con score

keyword_candidates

contradiction_evidence

latency breakdown

token usage

⚙️ Modalità Vista Business

Mostra:

KPI utilizzo

Topic

Intent

Query coverage

Trend

Nasconde:

Diagnostic_hits dettagliati

Breakdown latency interno

Keyword raw debug

⚙️ Modalità Vista Tecnica

Mostra tutto, inclusi:

accepted_hits

diagnostic_hits

raw score

semantic_level

bucket distribution

correlazioni

🧠 Metriche Derivate Avanzate (Opzionali ma Nerd-Level)

Resolution Rate = 1 - fallback_rate

Knowledge Coverage Index = avg(top_score * accepted_hits_count)

Model Stability Index = varianza confidence_score nel tempo

RAG Efficiency Ratio = accepted_hits / diagnostic_hits

Confidence Calibration Drift

🎯 Obiettivo Finale

Creare una dashboard che:

Permette tuning continuo del modello

Evidenzia problemi prima che esplodano

Identifica opportunità contenuto

Fornisce insight strategici

Permette debugging tecnico avanzato

---

## Roadmap operativa (step-by-step)

### Obiettivo
- Evolvere la dashboard report in modalita Business + Tecnica
- Mantenere compatibilita con la struttura attuale
- Abilitare KPI avanzati e session drilldown tecnico

### Step 1 - Quick Win (riuso dati attuali)
1. Estendere API esistenti:
   - `report/kpi`: confidence media, contradiction rate, avg rag_hits, avg top_score
   - `report/sessions`: filtri `low_confidence`, `contradiction_only`, `high_latency`
   - `report/session/{id}`: includere metadata tecnico completo per messaggio
2. Aggiornare frontend `/report`:
   - Header globale con filtri tenant, data range, pipeline, model
   - Toggle vista Business / Tecnica
   - KPI cards + trend principali

### Step 2 - Telemetria unificata (strato analytics)
1. Introdurre tabella eventi analytics (es. `analytics_events`), campi minimi:
   - `timestamp`, `session_id`, `tenant`, `pipeline`, `model`
   - `intent`, `fallback`, `contradiction_flag`, `contradiction_type`
   - `confidence_score`, `confidence_bucket`
   - `rag_hits`, `accepted_hits_count`, `diagnostic_hits_count`
   - `top_score`, `semantic_level`, `query_token_count`
   - `latency_ms`, `reply_len`, `token_in`, `token_out`
2. Scrittura evento unificata lato backend per text/realtime

### Step 3 - KPI avanzati e grafici
1. Distribuzioni:
   - Histogram `top_score`
   - Histogram `confidence_score`
   - Latency distribution + p95
2. Correlazioni:
   - Scatter `confidence` vs `top_score`
   - Scatter `latency` vs `reply_len`
   - Scatter `rag_hits` vs `confidence`
3. Topic intelligence:
   - Top topic per volume
   - Topic con fallback alto
   - Topic con confidence bassa

### Step 4 - Drilldown sessione
1. Timeline eventi della sessione
2. JSON tecnico collassabile
3. Evidenza `accepted_hits` / `diagnostic_hits` / `keyword_candidates`
4. Diagnostica `contradiction` e `policy_path`

### Step 5 - Export e hardening
1. Export CSV per vista corrente filtrata
2. Endpoint ottimizzati con indici e limiti
3. Test feature + smoke test in produzione
