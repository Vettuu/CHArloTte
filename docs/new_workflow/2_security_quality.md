# Workflow - Sicurezza e qualita

## Obiettivo
Garantire risposte corrette e coerenti con il knowledge ufficiale, riducendo al minimo allucinazioni e rischi reputazionali. L'approccio e progressivo: prima monitoraggio, poi mitigazioni soft, infine (se necessario) blocco attivo.

## Principi
- Proteggere il brand evitando risposte inventate.
- Massima trasparenza quando un dato non e ufficiale.
- Nessuna modifica drastica senza metriche.

## Workflow tecnico
### Fase 1 - Monitoring soft (senza blocco)
1) Analizzare risposte assistant per segnali di rischio:
   - numeri/prezzi presenti senza contesto
   - servizi non presenti nel knowledge
   - nomi competitor
2) Salvare flag nel metadata messaggio.
3) Creare report interno "risposte a rischio".

### Fase 2 - Mitigazione soft
1) Se risposta contiene costo/numero senza contesto, aggiungere disclaimer automatico.
2) Se risponde su servizi non presenti, invitare a conferma con supporto.
3) Non bloccare la risposta, solo aggiungere cautela.

### Fase 3 - Guardrail attivo (opzionale)
1) Confrontare risposta con contesto recuperato.
2) Se mismatch elevato, riscrivere risposta usando solo contesto.
3) Loggare tutte le risposte bloccate per audit.

## Dati necessari
- Contesto RAG associato alla risposta.
- Lista servizi ufficiali per tenant.
- Keyword blacklist (competitor, comparazioni, promesse legali).

## Metriche di controllo
- % risposte con flag rischio.
- % risposte con disclaimer.
- % risposte bloccate (se attivo guardrail).

## Pro
- Riduce allucinazioni.
- Protegge l'affidabilita del brand.
- Scalabile per tutti i tenant.

## Contro
- Rischio risposte piu rigide.
- Maggior complessita di gestione.

## Deliverable
- Sistema di flagging.
- Mitigazioni soft automatiche.
- (Opzionale) blocco/riscrittura.

## Checklist rilascio
- Flagging attivo e verificato.
- Nessun impatto negativo sulla UX.
- Report rischio consultabile.

## Roadmap di sviluppo (operativa)
### Sprint 1 - Flagging e monitoraggio
- [ ] Definire lista keyword rischio (prezzi, sconti, competitor, promesse legali).
- [ ] Analizzare risposte assistant e aggiungere flag in metadata.
- [ ] Creare report interno \"risposte a rischio\".

### Sprint 2 - Mitigazioni soft
- [ ] Regola: se prezzo/numero senza contesto, aggiungere disclaimer automatico.
- [ ] Regola: se servizio non nel knowledge, invitare a conferma.
- [ ] Loggare quando una mitigazione viene applicata.

### Sprint 3 - Guardrail attivo (opzionale)
- [ ] Confrontare risposta con contesto e calcolare mismatch.
- [ ] Se mismatch alto, riscrivere con fallback controllato.
- [ ] Salvare log di blocco e motivazione.

### Sprint 4 - QA e tuning
- [ ] Campione di sessioni per audit manuale.
- [ ] Riduzione falsi positivi.
- [ ] Aggiornare istruzioni tenant per chiarezza.
