<?php

return [
    'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    'chunk_size' => env('KNOWLEDGE_CHUNK_SIZE', 900),
    'chunk_overlap' => env('KNOWLEDGE_CHUNK_OVERLAP', 150),
    'rebuild_token' => env('KNOWLEDGE_REBUILD_TOKEN'),
    'index_batch_size' => env('KNOWLEDGE_INDEX_BATCH_SIZE', 6),
    'min_score' => env('KNOWLEDGE_MIN_SCORE', 0.7),
    'score_levels' => [
        // >= high: hit affidabile, usabile per risposta completa.
        'high' => (float) env('KNOWLEDGE_SCORE_HIGH', 0.70),
        // >= medium_min e < high: hit medio, usabile con risposta + chiarimento.
        'medium_min' => (float) env('KNOWLEDGE_SCORE_MEDIUM_MIN', 0.36),
    ],
    // Soglia keyword: percentuale minima di token query che devono comparire nel testo candidato.
    'keyword_min_match_ratio' => (float) env('KNOWLEDGE_KEYWORD_MIN_MATCH_RATIO', 0.50),
    // Numero minimo di token (post-normalizzazione) per applicare la logica a ratio.
    'keyword_min_tokens_for_ratio' => (int) env('KNOWLEDGE_KEYWORD_MIN_TOKENS_FOR_RATIO', 2),
    // Lunghezza minima token per entrare nel matching keyword.
    'keyword_min_token_length' => (int) env('KNOWLEDGE_KEYWORD_MIN_TOKEN_LENGTH', 3),
    // Allowlist token corti (sotto la soglia) ammessi perche business-critical.
    // Evitare token ambigui (es: "ai" in italiano è spesso preposizione articolata).
    'keyword_short_token_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('KNOWLEDGE_KEYWORD_SHORT_TOKEN_ALLOWLIST', 'qr'))
    ))),
    // Stopwords italiane base ignorate nel match keyword (tuning da fare sui test reali).
    'keyword_stopwords' => [
        'a', 'ad', 'ai', 'al', 'alla', 'allo', 'anche', 'che', 'chi', 'ci', 'con', 'come',
        'cosa', 'da', 'dal', 'dalla', 'dalle', 'dei', 'del', 'della', 'delle', 'di', 'e',
        'ed', 'gli', 'ha', 'ho', 'i', 'il', 'in', 'io', 'la', 'le', 'li', 'lo', 'ma', 'mi',
        'mio', 'mia', 'mie', 'miei', 'ne', 'nei', 'nella', 'nelle', 'noi', 'non', 'o', 'per',
        'pero', 'piu', 'poi', 'quale', 'quali', 'quando', 'se', 'sei', 'si', 'sono', 'su',
        'tra', 'tu', 'un', 'una', 'uno', 'vi', 'voi',
    ],
    // Mappa sinonimi keyword (canonical => varianti). Espandila per migliorare recall su query naturali.
    'keyword_synonyms' => [
        'phone' => ['telefono', 'numero', 'cellulare', 'tel', 'phone', 'mobile'],
        'email' => ['email', 'mail', 'indirizzo', 'e-mail'],
        'name' => ['nome', 'name'],
        'responsabile' => ['responsabile', 'referente', 'manager'],
        'secretariat' => ['segreteria', 'secretariat', 'supporto'],
        // Dominio eventi / Echelon (IT/EN varianti comuni)
        'accredito' => ['accredito', 'checkin', 'check-in', 'registrazione', 'registration'],
        'badge' => ['badge', 'pass', 'cartellino', 'namebadge'],
        'totem' => ['totem', 'kiosk', 'selfregistration', 'self-registration'],
        'qrcode' => ['qrcode', 'qr', 'qr-code'],
        'ecm' => ['ecm', 'crediti', 'formazione', 'fad', 'cme'],
        'rfid' => ['r-fid', 'uhf', 'tag'],
        'app' => ['app', 'applicazione', 'application', 'mobileapp'],
        'streaming' => ['streaming', 'webinar', 'live', 'videoconferenza', 'videocall'],
        'votazioni' => ['voto', 'votazioni', 'televoto', 'evote', 'e-vote', 'election', 'elezioni'],
        'costi' => ['costo', 'costi', 'prezzo', 'prezzi', 'preventivo', 'tariffa', 'stima', 'quote'],
        'servizi' => ['servizio', 'servizi', 'soluzione', 'soluzioni', 'offerta', 'offerte'],
    ],
    // Termini business forti (vuoti di default; popolabili tenant-by-tenant in futuro).
    'keyword_strong_terms' => ['accredito', 'badge', 'totem', 'ecm', 'rfid', 'votazioni', 'streaming', 'servizi'],
    // Ranking interno keyword candidates (KnowledgeRepository::search).
    // Permette di ordinare i documenti per qualita match invece che per ordine metadata.
    'keyword_ranking' => [
        'enabled' => filter_var(env('KNOWLEDGE_KEYWORD_RANKING_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        // Peso del match ratio (0..1). Più alto = più dipendenza dalla copertura token.
        'ratio_weight' => (float) env('KNOWLEDGE_KEYWORD_RANKING_RATIO_WEIGHT', 0.68),
        // Bonus se la query completa (needle normalizzato) è presente nel testo.
        'direct_needle_bonus' => (float) env('KNOWLEDGE_KEYWORD_RANKING_DIRECT_NEEDLE_BONUS', 0.18),
        // Bonus se scatta strong_term_match (Dipende da quanto è ben curato keyword_strong_terms).
        'strong_term_bonus' => (float) env('KNOWLEDGE_KEYWORD_RANKING_STRONG_TERM_BONUS', 0.12),
        // Bonus leggero proporzionale ai token matchati (matched_tokens * bonus, con cap).
        'matched_token_bonus' => (float) env('KNOWLEDGE_KEYWORD_RANKING_MATCHED_TOKEN_BONUS', 0.025),
        // Cap massimo del bonus token per evitare che query lunghe esplodano nello score.
        'max_token_bonus' => (float) env('KNOWLEDGE_KEYWORD_RANKING_MAX_TOKEN_BONUS', 0.12),
        // Clamp finale score (di default 1.0).
        'max_score' => (float) env('KNOWLEDGE_KEYWORD_RANKING_MAX_SCORE', 1.0),
        // Bonus applicato al match trovato nel summary (fonte più sintetica/curata).
        'summary_bonus' => (float) env('KNOWLEDGE_KEYWORD_RANKING_SUMMARY_BONUS', 0.05),
    ],
    // Topic boost sul ranking semantic (bonus contestuale e piccolo).
    // Ogni regola: se la query contiene uno dei termini "when_any"
    // e il chunk matcha almeno uno tra:
    // - "target_any" (testo title/content),
    // - "target_document_ids" (metadato document_id),
    // - "target_tags" (metadato tags[]),
    // aggiungi "boost" allo score finale.
    'topic_boost' => [
        'enabled' => (bool) env('KNOWLEDGE_TOPIC_BOOST_ENABLED', true),
        'max_boost' => (float) env('KNOWLEDGE_TOPIC_BOOST_MAX', 0.06),
        'rules' => [
            [
                'when_any' => ['badge', 'stampa', 'print'],
                'target_tags' => ['badge', 'stampa'],
                'target_document_ids' => ['stampa-veloce-badge'],
                'boost' => 0.03,
            ],
            [
                'when_any' => ['badge', 'totem', 'kiosk'],
                'target_tags' => ['totem', 'self registration', 'badge'],
                'target_document_ids' => ['totem-multimediali'],
                'boost' => 0.03,
            ],
            [
                'when_any' => ['accredito', 'checkin', 'registrazione'],
                'target_tags' => ['accredito', 'qrcode', 'registrazione'],
                'target_document_ids' => ['accredito-ipad'],
                'boost' => 0.025,
            ],
            [
                'when_any' => ['ecm', 'crediti', 'rfid', 'uhf'],
                'target_tags' => ['ecm', 'rfid', 'presenze'],
                'boost' => 0.03,
            ],
        ],
    ],
    'default_tenant' => env('KNOWLEDGE_DEFAULT_TENANT', 'demo'),
];
