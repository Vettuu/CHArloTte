# KnowledgeRepository - Guida Tecnica

## Cos'e
`KnowledgeRepository` e il layer che gestisce la knowledge documentale del tenant:
- carica file da `resources/knowledge/<tenant>/`
- trasforma i contenuti in documenti ricercabili
- esegue ricerca keyword (non embedding)
- espone lookup strutturato da JSON

File collegati:
- [KnowledgeRepository.php](/home/daniele/CharloTte/apps/backend/app/Knowledge/KnowledgeRepository.php)
- [knowledge.php](/home/daniele/CharloTte/apps/backend/config/knowledge.php)

## Perche e importante
Influenza direttamente:
- recall keyword (quanti documenti utili trovi)
- qualita candidati passati a `KnowledgeSearchService`
- fallback quando semantic retrieval e debole
- precisione su domande naturali/non tecniche

In pratica: e uno dei file che impatta di piu il comportamento finale del modello.

## Cosa fa (pipeline interna)
1. Legge `metadata.json` del tenant.
2. Costruisce documenti unificati (`id`, `title`, `tags`, `summary`, `content`).
3. Se trova JSON, lo appiattisce in testo (`flattenJson`) e popola `structuredData`.
4. Su query utente:
- normalizza
- tokenizza
- espande sinonimi
- applica matching keyword su `summary` e `content`
- ritorna documenti candidati con `excerpt`

## Metodi principali

### `all($tenantId)`
- Carica tutti i documenti del tenant.
- Unisce anche file multipli per singolo documento.
- Parsing JSON incluso.

### `find($id, $tenantId)`
- Recupera un documento specifico da `all()`.

### `search($query, $tenantId)`
- E il cuore keyword/fuzzy.
- Cerca prima nei `summary`, poi nel `content`.
- Ritorna lista documenti ordinata di match, con `keyword_match` per diagnostica.

### `structuredLookup($query, $tenantId)`
- Risposte "fatto certo" da dati strutturati (contatti, segreteria, ecc.).
- Utile per evitare passaggi modello su dati deterministici.

## Funzioni di supporto
- `tokenize`: crea token query
- `expandTokens`: applica sinonimi
- `normalize`: normalizza testo
- `matches`: wrapper booleano sul risultato di `matchAnalysis`
- `matchAnalysis`: calcola matched/tokens/ratio/needle/strong terms
- `loadContent`: legge file md/json
- `flattenJson`: converte JSON in righe testuali

## Aggiornamenti gia implementati

### 1) Da matching booleano rigido a matching a ratio
Prima: match solo se tutti i token erano presenti (`every`).
Ora: match se rapporto `matched_tokens / total_tokens` supera soglia configurabile.

### 2) Nuovi parametri in config/knowledge.php
- `keyword_min_match_ratio` (default `0.50`)
- `keyword_min_tokens_for_ratio` (default `2`)
- `keyword_stopwords` (lista base italiana)
- `keyword_synonyms` (mappa canonical => varianti, attiva nel matching)
- `keyword_strong_terms` (lista attiva di termini business forti, ampliabile)
- `keyword_ranking.*` (score keyword composito per ordinare candidati)

### 3) Needle direct match prioritario
Se la query normalizzata intera e contenuta nel testo candidato, il match passa subito.

### 4) Diagnostica estesa
Per ogni keyword candidate sono ora disponibili:
- `matched_tokens`
- `total_tokens`
- `match_ratio`
- `direct_needle_match`
- `strong_term_match`

Questo e fondamentale per tuning empirico dei parametri.

### 5) Ranking keyword a punteggio (attivo)
- Ogni candidato keyword ha `keyword_score`.
- `search()` ordina candidati per `keyword_score` discendente.
- Formula pratica:
- `keyword_score = ratio_weight*match_ratio + direct_needle_bonus + strong_term_bonus + token_bonus`
- `token_bonus = min(max_token_bonus, matched_tokens * matched_token_bonus)`
- Effetto: i documenti più pertinenti entrano prima nei candidati del semantic retrieval.

## Dettaglio tecnico richiesto

### Sinonimi: come funzionano davvero
- I sinonimi sono definiti in `config/knowledge.php` dentro `keyword_synonyms`.
- La logica e `canonical => [varianti]`.
- Durante `expandTokens()`, ogni token query viene ricondotto al canonical.
- Esempio: se `totem => [totem, kiosk, self-registration]`, query con `kiosk` viene trattata come `totem`.
- Effetto pratico: aumenta recall su query naturali/varianti lessicali senza dover duplicare contenuti nei file.

### Keyword per conteggio token: come si calcola
- Dopo normalizzazione e stopwords, la query diventa un set di token utili.
- In `matchAnalysis()` si misura:
- `matched_tokens`: quanti token query compaiono nel testo candidato.
- `total_tokens`: quanti token query totali stiamo valutando.
- `match_ratio = matched_tokens / total_tokens`.
- Regole:
- se c'e `direct_needle_match` (frase intera trovata), match immediato;
- altrimenti si usa il `match_ratio` contro `keyword_min_match_ratio`;
- per query molto corte (`total_tokens < keyword_min_tokens_for_ratio`), il match e piu permissivo.
- Effetto pratico: controlli in modo esplicito quanta copertura keyword serve per far passare un candidato.

### Boost: come influenza il ranking
- Il boost non avviene in `KnowledgeRepository`, ma nel ranking semantico di `KnowledgeSearchService`.
- Il repository prepara i candidati keyword; poi il search service calcola score embedding e applica `topic_boost`.
- Il boost oggi puo attivarsi su:
- `target_any` (match testuale su title/content),
- `target_document_ids` (match sul `document_id`),
- `target_tags` (match su `metadata.tags` del chunk).
- Formula pratica: `final_score = min(1.0, base_score + topic_boost)`.
- Effetto pratico: a parita di similarita semantica, puoi favorire i contenuti "giusti" (es. un documento specifico o tag specifici).

## Parametri modificabili: riferimento completo

### `keyword_min_match_ratio`
- Dove: `config/knowledge.php`
- Tipo/range: `float` tra `0.0` e `1.0` (pratico: `0.40 - 0.65`)
- Impatto:
- più alto = più precisione, meno recall (rischio fallback)
- più basso = più recall, più rumore
- Default consigliato (vostro progetto): `0.50`

### `keyword_min_tokens_for_ratio`
- Tipo/range: `int` (pratico: `2 - 4`)
- Impatto:
- più alto = query corte trattate come ambigue più spesso
- più basso = query corte più permissive
- Default consigliato: `2`

### `keyword_stopwords`
- Tipo: `array<string>`
- Impatto:
- lista ricca = meno rumore lessicale
- lista eccessiva = rischi rimozione token utili
- Nota: metti parole funzione, non termini dominio (es. non mettere `evento`, `badge`, `accredito`)

### `keyword_synonyms`
- Tipo: `array<canonical => variants[]>`
- Impatto:
- più copertura sinonimi = migliore recall su query naturali
- sinonimi troppo larghi = collisioni semantiche e rumore
- Regola: preferire sinonimi strettamente equivalenti nel contesto business

### `keyword_strong_terms`
- Tipo: `array<string>`
- Impatto:
- più termini forti = più match su temi core (accredito, badge, totem, ecc.)
- lista troppo ampia = bonus distribuito ovunque, perde utilita
- Regola: includi solo parole realmente discriminatorie

### `keyword_ranking.enabled`
- Tipo: `bool`
- Impatto:
- `true` = ordinamento intelligente per qualità match
- `false` = score ridotto al solo `match_ratio`
- Default consigliato: `true`

### `keyword_ranking.ratio_weight`
- Tipo/range: `float >= 0` (pratico: `0.55 - 0.85`)
- Impatto:
- più alto = domina copertura token (`match_ratio`)
- più basso = dominano i bonus (needle/strong/token)
- Default consigliato: `0.68`

### `keyword_ranking.direct_needle_bonus`
- Tipo/range: `float >= 0` (pratico: `0.05 - 0.30`)
- Impatto:
- più alto = query quasi letterali vengono fortemente favorite
- più basso = approccio più semantico/meno letterale
- Default consigliato: `0.18`

### `keyword_ranking.strong_term_bonus`
- Tipo/range: `float >= 0` (pratico: `0.05 - 0.20`)
- Impatto:
- più alto = temi business core salgono di priorità
- più basso = minor effetto dei termini forti
- Default consigliato: `0.12`

### `keyword_ranking.matched_token_bonus`
- Tipo/range: `float >= 0` (pratico: `0.005 - 0.04`)
- Impatto:
- più alto = query con molti token matchati guadagnano rapidamente score
- più basso = effetto quasi neutro del numero token
- Default consigliato: `0.025`

### `keyword_ranking.max_token_bonus`
- Tipo/range: `float >= 0` (pratico: `0.05 - 0.20`)
- Impatto:
- più alto = query lunghe possono accumulare più bonus
- più basso = freno forte, evita che query verbose dominino
- Default consigliato: `0.12`

### `keyword_ranking.max_score`
- Tipo/range: `float > 0` (pratico: `1.0`)
- Impatto:
- normalmente resta `1.0` per mantenere scala interpretabile
- alzarlo raramente serve

### `topic_boost.enabled`
- Tipo: `bool`
- Impatto:
- `true` = applica bonus contestuali su semantic ranking
- `false` = ranking solo embedding puro

### `topic_boost.max_boost`
- Tipo/range: `float` (pratico: `0.03 - 0.12`)
- Impatto:
- più alto = boost più aggressivo (rischio distorsione ranking)
- più basso = boost leggero e prudente
- Default consigliato: `0.06`

### `topic_boost.rules[].when_any`
- Tipo: `array<string>`
- Significato: trigger sulla query
- Impatto:
- trigger troppo generici = boost attivo troppo spesso
- trigger mirati = boost più affidabile

### `topic_boost.rules[].target_any`
- Tipo: `array<string>`
- Significato: match testuale su `title/content` chunk
- Impatto:
- utile come fallback, ma meno preciso dei metadati

### `topic_boost.rules[].target_document_ids`
- Tipo: `array<string>`
- Significato: match esatto su `document_id`
- Impatto:
- alta precisione, ottimo quando i metadata sono curati

### `topic_boost.rules[].target_tags`
- Tipo: `array<string>`
- Significato: match su `metadata.tags[]`
- Impatto:
- ottimo compromesso tra controllo e flessibilita
- qualità direttamente dipendente da come mantieni `metadata.json`

### `topic_boost.rules[].boost`
- Tipo/range: `float` (pratico: `0.01 - 0.05`)
- Impatto:
- più alto = regola più influente
- più basso = effetto sottile
- Nota: somma regole limitata da `max_boost`

## Come influenza il modello (in pratica)
- Più match utili -> più candidati buoni al RAG semantic -> meno fallback.
- Match troppo permissivo -> più rumore -> risposte meno precise.
- Match troppo rigido -> falsi 0 hit -> fallback inutili.

Il bilanciamento corretto si trova con test reali (query vere utente).

## Tuning consigliato (approccio pratico)
1. Parti da `keyword_min_match_ratio = 0.50`.
2. Testa 20 query reali.
3. Se troppo fallback falsi: scendi a `0.45`.
4. Se troppo rumore: sali a `0.55`.
5. Tieni traccia di `match_ratio` nei log e confronta con qualita risposta.

## Cose che possiamo ancora migliorare (roadmap 10 punti)
1. (fatto) `tokenize()`: soglia minima lunghezza token.
2. (fatto) `expandTokens()`: manutenzione periodica del dizionario sinonimi/canonical (copertura, varianti business, pulizia duplicati).
3. (fatto) `normalize()`: regole pulizia testo/query.
4. (fatto) Priorita `summary` vs `content` in `search()`.
5. () Strategia excerpt (contesto piu utile).
6. () Estensione `structuredLookup()` per nuovi intent deterministici.
7. () Robustezza parsing `metadata.json`/`loadContent()`.
8. () Miglioramento `flattenJson()` per JSON complessi.
9. (Fatto) Refinement del ranking keyword composito (pesi/bonus in base ai test reali).
10. () Cache documenti tenant per latenza/stabilita.

## Nota operativa
Le modifiche in questo file impattano entrambi:
- pipeline `text` (direttamente)
- pipeline `realtime` (indirettamente, via candidate/query behavior)

## Checklist Test Empirici
Usa questo mini protocollo quando cambi `keyword_min_match_ratio`, stopwords o sinonimi.

### 1) Set minimo query (10)
1. "Quali servizi offrite?"
2. "Avete totem?"
3. "Kiosk per accredito?"
4. "Costo badge per 300 persone"
5. "Gestite ECM e presenze?"
6. "Fate app evento?"
7. "Controllo accessi con iPad?"
8. "Esempi di lavori fatti"
9. "Come si chiama mio zio?" (fuori contesto)
10. "xqvpt kzrmn 998877" (rumore)

### 2) Cosa guardare nei log
- `keyword_candidates.count`
- `keyword_top_match_ratio`
- `keyword_top_matched_tokens` / `keyword_top_total_tokens`
- `semantic_level`
- `policy_path`
- `fallback`

### 3) Regole di tuning rapide
- Troppi fallback falsi su query business: abbassa `keyword_min_match_ratio` di `0.05`.
- Troppo rumore su query vaghe: alza `keyword_min_match_ratio` di `0.05`.
- Query corte troppo generiche: aumenta `keyword_min_tokens_for_ratio` (es. da 2 a 3) e verifica.
- Se "kiosk" non matcha "totem": aggiorna `expandTokens()` con sinonimi.
- Se parole irrilevanti disturbano: amplia `keyword_stopwords`.

### 4) Criterio di accettazione
- Le query business principali devono evitare fallback duro.
- Le query fuori contesto devono andare in fallback (soft o hard) senza risposte inventate.
- Le query corte devono tendere a `partial_answer_clarify` con domanda mirata.
