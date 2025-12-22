# Guida rapida alla qualità del RAG

Questa checklist aiuta a mantenere il Retrieval-Augmented Generation di Charlotte coerente e affidabile in entrambi i canali (chat testuale e voce).

---

## 1. Prompt base (`AGENT_INSTRUCTIONS`)

- **Identità chiara**: ribadisci chi è l’assistente, il contesto dell’evento e il tono (es. “rispondi SEMPRE in italiano, max 3 frasi”).
- **Uso delle fonti**: specifica che i messaggi di contesto (con marker `__CHARLOTTE_CONTEXT__`) contengono estratti ufficiali e vanno citati testualmente.
- **Credenziali/codici**: aggiungi un paragrafo esplicito “Quando ricevi credenziali, codici o numeri di telefono, ripetili esattamente come sono (maiuscole/minuscole incluse). Non inventare varianti.”
- **Fallback**: spiega cosa fare se non arriva alcun contesto (“dichiara che il dato non è disponibile e invita a contattare la segreteria”).
- **Personalità**: richiama eventuali regole extra (citare il titolo completo dell’evento alla prima risposta, indicare i recapiti ufficiali quando mancano info, ecc.).

Aggiorna il prompt in `apps/frontend/src/app/page.tsx` e ricordati di ricompilare/redeploy perché il frontend incapsula queste istruzioni.

---

## 2. Parametrizzazione Knowledge

`config/knowledge.php` legge tutti i valori da `.env`. Gioca con loro in base al dataset:

| Parametro | Effetto quando lo **aumenti** | Effetto quando lo **riduci** |
| --- | --- | --- |
| **KNOWLEDGE_CHUNK_SIZE** (default 900) | Chunk più lunghi ⇒ meno righe in tabella, contesto più ricco ma rischi di mescolare argomenti diversi. | Chunk più corti ⇒ più precisione ma aumentano i duplicati e il rumore; verifica che rimangano almeno 200-300 caratteri utili. |
| **KNOWLEDGE_CHUNK_OVERLAP** (default 150) | Maggiore continuità tra i chunk ma più duplicazioni; utile se i paragrafi sono lunghi. | Meno overlap ⇒ chunk più unici ma potresti “tagliare” concetti a metà. Mantieni circa il 15% del chunk size come compromesso. |
| **KNOWLEDGE_INDEX_BATCH_SIZE** (default 8) | Riduce le chiamate a OpenAI ma espone i batch a timeout se troppo grandi. | Aumenta il numero di request ma migliora la resilienza; usa valori 4–16 in funzione della latenza. |
| **KNOWLEDGE_MIN_SCORE** (default 0.79) | Più precisione: restituisce solo snippet molto simili ⇒ rischio di “non ho trovato” anche se c’è il dato. | Più recall: aggancia ogni variante di domanda ⇒ assicurati di filtrare i risultati irrilevanti nel prompt. |

Ricorda di eseguire `php artisan config:clear` o redeploy dopo aver modificato l’env.

---

## 3. Formattazione dei file Markdown

1. **Struttura a sezioni**: usa heading (`##`, `###`), tabelle e liste per separare i temi. Ogni paragrafo dovrebbe concentrarsi su un solo argomento.
2. **Etichette esplicite**: preferisci “Password Wi-Fi (case sensitive): `chirurgia2026`” anziché frasi generiche. Inserisci sinonimi comuni per aiutare il matching (“credenziali”, “wifi”, “internet”).
3. **Tabelle per dati strutturati**: per recapiti, orari, credenziali, usa tabelle Markdown o elenchi puntati con label in grassetto.
4. **Fornisci contesto narrativo**: aggiungi una breve introduzione (“Congresso medico di tre giorni…”) per aiutare il modello a capire di quale documento si tratta.
5. **Evita muri di testo**: spezza i blocchi ogni 2-3 frasi per facilitare il chunking (900 caratteri ≈ 150 parole).
6. **Aggiorna `metadata.json`** quando aggiungi un file nuovo e lancia subito `php artisan knowledge:index` per rigenerare gli embedding.

---

## 4. Tips & trick operativi

- **Rigenera l’indice dopo ogni modifica** (`php artisan knowledge:index` localmente + rebuild remoto con `POST /api/knowledge/rebuild?token=...`). Altrimenti i chunk rimangono con embedding vecchi.
- **Verifica le query critiche** (password, recapiti, crediti ECM, orari) sia in chat che in voce usando la stessa frase. Se noti differenze, controlla:
  1. che la ricerca REST `/api/knowledge/search` restituisca risultati (loggali lato frontend);
  2. che il messaggio di contesto arrivi (nel realtime log vedrai il marker `__CHARLOTTE_CONTEXT__`);
  3. che l’istruzione “non inventare” sia presente nel prompt.
- **Sinonimi/keyword**: se i partecipanti usano termini diversi (“bagni” vs “servizi igienici”), aggiungi quelle parole nel markdown oppure nel repository (`KnowledgeRepository::tokenize`/`expandTokens`) per coprire tutte le varianti.
- **Fallback manuali**: per dati sensibili (responsabile info point, email, telefonia) puoi impostare risposte deterministiche via `structuredLookup` in `KnowledgeRepository`, così non dipendi dagli embedding.
- **Logging**: monitora i log Laravel (`storage/logs/laravel.log`) per verificare quali chunk vengono selezionati e quali score hanno. Puoi aggiungere temporaneamente un dump in `KnowledgeSearchService` per tracciare le query difficili.

Seguendo questi punti mantieni il RAG coerente e puoi iterare su prompt e contenuti senza toccare il codice core. Buon tuning!
