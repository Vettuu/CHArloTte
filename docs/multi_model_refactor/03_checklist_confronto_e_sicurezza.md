# Checklist: confronto modelli, qualità e sicurezza

## A. Prima di iniziare
- [ ] Backup DB (almeno `chat_messages`, `knowledge_chunks`, `realtime_sessions`).
- [ ] Baseline KPI salvata (stesso periodo temporale).
- [ ] Tenant ufficiale non toccato durante test iniziale.

## B. Regressione funzionale
- [ ] Prompt in-scope: risposte coerenti con knowledge ufficiale.
- [ ] Prompt out-of-scope: fallback corretto.
- [ ] Prompt con costi/stime: calcoli coerenti alle regole.
- [ ] Prompt multilingua: comportamento atteso.
- [ ] Nessun peggioramento su tempi risposta percepiti.

## C. Confronto A/B corretto
Condizione necessaria: stessi input, stesso tenant, stessa knowledge, stesse istruzioni.

Misure minime da tracciare:
- [ ] Accuratezza percepita risposta (review manuale).
- [ ] Fallback rate.
- [ ] Completezza risposta.
- [ ] Hallucination rate.
- [ ] Latenza media.
- [ ] Costo medio per risposta.

## D. Logging minimo consigliato
Per ogni messaggio assistant:
- [ ] `tenant_id`
- [ ] `pipeline` (`realtime` | `text`)
- [ ] `model`
- [ ] `fallback` (true/false)
- [ ] `rag_hits_count`
- [ ] timestamp

## E. Sicurezza operativa
- [ ] Endpoint report protetti (Basic Auth o equivalente).
- [ ] Token rebuild knowledge non esposto lato client.
- [ ] Nessun secret in frontend bundle.
- [ ] Rate limiting su endpoint sensibili.
- [ ] CORS/headers verificati.

## F. Go-live gate
Promuovere la nuova pipeline solo se:
- [ ] Qualità uguale o migliore della baseline.
- [ ] Fallback non peggiora oltre soglia definita.
- [ ] Nessun aumento anomalo di costo.
- [ ] Nessuna regressione report/dashboard.

## G. Convenzione pratica team
- Tenant ufficiale: pipeline stabile.
- Tenant test: pipeline sperimentale.
- Change log obbligatorio su:
  - modello,
  - istruzioni,
  - parametri retrieval,
  - mapping tenant->pipeline.
