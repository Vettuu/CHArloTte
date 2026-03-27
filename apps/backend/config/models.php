<?php

return [
    'pipelines' => [
        'realtime' => [
            // Modello usato dalla pipeline realtime (websocket, utile per chat live/audio).
            'default_model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),

            // Tipo trasporto usato lato client.
            // Valore atteso nel progetto attuale: websocket.
            'transport' => 'websocket',
        ],
        'text' => [
            // Modello usato dalla pipeline text (request/response classica lato backend).
            'default_model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1'),

            // Controlla variabilità/creatività della risposta.
            // 0.1-0.3 = molto stabile/rigido (consigliato per QA aziendale)
            // 0.3-0.6 = bilanciato
            // 0.7+   = più creativo ma più rischio deviazioni
            'temperature' => (float) env('OPENAI_TEXT_TEMPERATURE', 0.3),

            // Limite massimo token in output per singola risposta.
            // 300-600  = risposte brevi, costo/latency minori
            // 600-1000 = bilanciato (default consigliato)
            // 1000+    = risposte più lunghe/complesse ma più costo/latency
            'max_output_tokens' => (int) env('OPENAI_TEXT_MAX_OUTPUT_TOKENS', 800),
            'conversation' => [
                // Soglia euristica per considerare un input "breve".
                'short_input_length' => (int) env('OPENAI_TEXT_CONVERSATION_SHORT_INPUT_LENGTH', 15),
                // Query brevi ma autosufficienti: non vanno stitchate automaticamente.
                'standalone_short_terms' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'OPENAI_TEXT_CONVERSATION_STANDALONE_SHORT_TERMS',
                        'badge,qrcode,qr,rfid,ecm,totem,charlotte,sicam'
                    ))
                ))),
                // Conferme pure: normalmente proseguono il ramo attivo.
                'confirmation_terms' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'OPENAI_TEXT_CONVERSATION_CONFIRMATION_TERMS',
                        'si,sì,ok,va bene,perfetto,certo,esatto,confermo,bene,direi di si,direi di sì'
                    ))
                ))),
                // Pattern selettivi: scelgono una variante nel contesto già aperto.
                'selective_terms' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'OPENAI_TEXT_CONVERSATION_SELECTIVE_TERMS',
                        'standard,quello,questo,questa,quella,con rfid,con ipad,con qr,con qrcode,con barcode,solo qr,solo qrcode,solo barcode,solo rfid,versione standard'
                    ))
                ))),
                // Pattern tematici: mantengono il topic ma cambiano l’asse della richiesta.
                'thematic_prefixes' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'OPENAI_TEXT_CONVERSATION_THEMATIC_PREFIXES',
                        'e i costi,e il costo,e per,e su,e invece,e quindi,e poi,e allora,come funziona,quanto costa,per quanti,per webinar,per eventi grandi'
                    ))
                ))),
            ],
            'policy' => [
                // Numero massimo di hit RAG passati al modello.
                // 3-5 in genere bilanciato; valori alti aumentano rumore/token.
                'max_hits' => (int) env('OPENAI_TEXT_POLICY_MAX_HITS', 4),

                // Se true: con 0 hit RAG va in fallback rigido (niente risposta generativa).
                // Se false: anche con 0 hit il modello può rispondere con fallback guidato.
                'strict_fallback_on_zero_hits' => filter_var(
                    env('OPENAI_TEXT_POLICY_STRICT_FALLBACK_ON_ZERO_HITS', true),
                    FILTER_VALIDATE_BOOLEAN
                ),
                // Se true: con 0 accepted hit ma diagnostic hit > 0
                // passa a "partial_answer_clarify" invece di "soft_fallback".
                // Utile per query ambigue ma correlate (es. venue/nomi parziali).
                'clarify_on_zero_hits_with_diagnostics' => filter_var(
                    env('OPENAI_TEXT_POLICY_CLARIFY_ON_ZERO_HITS_WITH_DIAGNOSTICS', true),
                    FILTER_VALIDATE_BOOLEAN
                ),

                // Numero minimo di hit richiesti per percorso "full_answer".
                // Sotto questa soglia il controller usa "partial_answer".
                'full_answer_requires_hits' => (int) env('OPENAI_TEXT_POLICY_FULL_ANSWER_REQUIRES_HITS', 4),
                'confidence_thresholds' => [
                    // Soglia per bucket "high" (0-100).
                    // 70-85 tipicamente buono in contesti RAG aziendali.
                    'high' => (int) env('OPENAI_TEXT_POLICY_CONFIDENCE_HIGH', 75),

                    // Soglia per bucket "medium" (0-100).
                    // Sotto medium il bucket diventa "low".
                    'medium' => (int) env('OPENAI_TEXT_POLICY_CONFIDENCE_MEDIUM', 45),
                ],
                'short_query' => [
                    // Se la query è corta, consenti il flusso normale solo se
                    // confidence bucket >= questo valore.
                    // Valori: low | medium | high
                    'min_confidence_bucket' => env('OPENAI_TEXT_POLICY_SHORT_QUERY_MIN_CONFIDENCE_BUCKET', 'medium'),
                    // E semantic level >= questo valore.
                    // Valori: low | medium | high
                    'min_semantic_level' => env('OPENAI_TEXT_POLICY_SHORT_QUERY_MIN_SEMANTIC_LEVEL', 'medium'),
                ],
                'confidence_formula' => [
                    // Numero massimo di chunk considerati nel calcolo confidence robusto.
                    'top_n' => (int) env('OPENAI_TEXT_POLICY_CONFIDENCE_TOP_N', 4),
                    // Pesi formula: Confidence = (alpha*c1 + beta*mu) * (1 - sigma_n)
                    // dove c1=max score, mu=media top_n, sigma_n=deviazione standard normalizzata.
                    'alpha' => (float) env('OPENAI_TEXT_POLICY_CONFIDENCE_ALPHA', 0.60),
                    'beta' => (float) env('OPENAI_TEXT_POLICY_CONFIDENCE_BETA', 0.40),
                    // Scala score embedding attuale: 0..1, quindi range_max=1.
                    'range_max' => (float) env('OPENAI_TEXT_POLICY_CONFIDENCE_RANGE_MAX', 1.0),
                ],

                // Intent per cui è consentito attivare web search.
                // Esempio attuale: showcase_web (dimostrazioni/eventi passati/social).
                'web_search_intents' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env('OPENAI_TEXT_POLICY_WEB_SEARCH_INTENTS', 'showcase_web'))
                ))),
                'intent_keywords' => [
                    // Keyword che classificano una richiesta come "showcase_web".
                    'showcase_web' => array_values(array_filter(array_map(
                        'trim',
                        explode(',', (string) env(
                            'OPENAI_TEXT_POLICY_INTENT_SHOWCASE_WEB_KEYWORDS',
                            'foto,immagini,video,post,social,linkedin,instagram,facebook,esempi,case study,eventi passati,portfolio,dimostrazione'
                        ))
                    ))),

                    // Keyword che classificano una richiesta come "pricing_estimate".
                    'pricing_estimate' => array_values(array_filter(array_map(
                        'trim',
                        explode(',', (string) env(
                            'OPENAI_TEXT_POLICY_INTENT_PRICING_KEYWORDS',
                            'costo,preventivo,prezzo,tariffa,budget,stima'
                        ))
                    ))),
                ],
                'contradiction' => [
                    // Minimo numero di evidenze per considerare reale una contraddizione.
                    'min_evidence' => (int) env('OPENAI_TEXT_POLICY_CONTRADICTION_MIN_EVIDENCE', 2),
                    // Divergenza relativa minima sui prezzi (0..1) per flaggare mismatch.
                    // Esempio 0.20 = 20%.
                    'price_relative_delta' => (float) env('OPENAI_TEXT_POLICY_CONTRADICTION_PRICE_RELATIVE_DELTA', 0.20),
                ],
                'log' => [
                    // Limiti blocchi compact nei log backend text.
                    // Tienili alti per vedere tutti gli hit reali nella maggior parte dei casi.
                    'hit_scores_limit' => (int) env('OPENAI_TEXT_POLICY_LOG_HIT_SCORES_LIMIT', 20),
                    'hit_refs_limit' => (int) env('OPENAI_TEXT_POLICY_LOG_HIT_REFS_LIMIT', 20),
                    'keyword_items_limit' => (int) env('OPENAI_TEXT_POLICY_LOG_KEYWORD_ITEMS_LIMIT', 8),
                ],
            ],
            'web_search' => [
                // Abilita/disabilita web search per pipeline text.
                // true = il controller può attivarla in base alla policy intent.
                'enabled' => filter_var(env('OPENAI_TEXT_WEB_SEARCH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

                // Quantità di contesto web usato dal tool.
                // Valori comuni: low | medium | high
                // low = più veloce/economico, meno copertura
                // medium = bilanciato
                // high = più copertura ma più latenza/costo
                'search_context_size' => env('OPENAI_TEXT_WEB_SEARCH_CONTEXT_SIZE', 'medium'),

                // Allowlist domini per la ricerca web.
                // Nota: il filtro è per dominio, non per URL specifico.
                'allowed_domains' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'OPENAI_TEXT_WEB_SEARCH_ALLOWED_DOMAINS',
                        'echelonitalia.it,instagram.com,facebook.com,linkedin.com'
                    ))
                ))),

                // Domini sempre interrogabili in aggiunta al RAG (anche senza intent showcase).
                // Uso consigliato: sito ufficiale + Linkedin ufficiale.
                'always_allowed_domains' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'OPENAI_TEXT_WEB_SEARCH_ALWAYS_ALLOWED_DOMAINS',
                        'echelonitalia.it,linkedin.com'
                    ))
                ))),

                // Domini solo per intent showcase_web (esempi visuali/case/eventi passati).
                // Uso consigliato: social visuali.
                'showcase_allowed_domains' => array_values(array_filter(array_map(
                    'trim',
                    explode(',', (string) env(
                        'OPENAI_TEXT_WEB_SEARCH_SHOWCASE_ALLOWED_DOMAINS',
                        'instagram.com,facebook.com'
                    ))
                ))),
            ],
        ],
    ],
];
