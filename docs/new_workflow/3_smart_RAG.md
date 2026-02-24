# Workflow - RAG smart

## Obiettivo
Migliorare l'affidabilita del retrieval senza introdurre overfitting. Il miglioramento deve essere misurabile, reversibile e attivabile con feature flag.

## Principi
- Precisione > quantita.
- A/B test per ogni modifica critica.
- Possibilita di rollback immediato.

## Workflow tecnico
### Fase 1 - Baseline
1) Definire KPI attuali:
   - % fallback
   - % risposte valutate corrette
   - tempo medio risposta
2) Definire dataset test con domande reali.

### Fase 2 - Query expansion controllata
1) Dizionario sinonimi per tenant.
2) Espansione query solo su keyword chiave.
3) Misurare aumento recall.

### Fase 3 - Reranking
1) Recuperare top N chunk con embedding.
2) Passare chunk a un modello di ranking (o regole).
3) Usare solo top 1-3 per contesto.

### Fase 4 - Context guard
1) Se score basso o contesto debole, richiedere chiarimento all'utente.
2) Se la domanda chiede costo, chiedere parametri mancanti prima di rispondere.

### Fase 5 - Feature flag e A/B test
1) Attivare smart RAG su una percentuale di utenti.
2) Confrontare KPI con baseline.
3) Rollout graduale solo se migliora.

## Dati necessari
- Log report completo.
- Dataset test con risposte attese.
- Dizionario sinonimi per tenant.

## Pro
- Migliora affidabilita senza cambiare knowledge.
- Riduce fallback inutili.
- Reversibile con feature flag.

## Contro
- Aumenta complessita e costi.
- Richiede tuning costante.

## Deliverable
- Pipeline smart RAG con feature flag.
- Query expansion attiva.
- Reranking per top chunk.
- KPI comparativi baseline vs smart.

## Checklist rilascio
- KPI baseline definiti.
- Smart RAG attivo solo su subset.
- Rollback testato.

## Roadmap di sviluppo (operativa)
### Sprint 1 - Baseline e dataset
- [ ] Definire KPI baseline (fallback, accuratezza percepita, tempo risposta).
- [ ] Creare dataset test per tenant (domande reali + risposta attesa).
- [ ] Salvare punteggi baseline per confronto.

### Sprint 2 - Query expansion
- [ ] Dizionario sinonimi per tenant.
- [ ] A/B test su 10% traffico.
- [ ] Misurare miglioramento recall.

### Sprint 3 - Reranking
- [ ] Recuperare top N chunk.
- [ ] Ordinare con ranker o regole.
- [ ] Usare top 1-3 nel contesto finale.

### Sprint 4 - Context guard
- [ ] Se score basso, chiedere chiarimento.
- [ ] Se query su costi, chiedere parametri mancanti.
- [ ] Misurare impatto su qualità percepita.

### Sprint 5 - Feature flag e rollout
- [ ] Feature flag per tenant.
- [ ] Rollout graduale se KPI migliorano.
- [ ] Rollback immediato se peggiora.
