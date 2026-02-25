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
            'policy' => [
                // Numero massimo di hit RAG passati al modello.
                // 3-5 in genere bilanciato; valori alti aumentano rumore/token.
                'max_hits' => (int) env('OPENAI_TEXT_POLICY_MAX_HITS', 4),

                // Se true: con 0 hit RAG va in fallback rigido (niente risposta generativa).
                // Se false: anche con 0 hit il modello può rispondere con fallback guidato.
                'strict_fallback_on_zero_hits' => filter_var(
                    env('OPENAI_TEXT_POLICY_STRICT_FALLBACK_ON_ZERO_HITS', true),
                    FILTER_VALIDATE_BOOL
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
            ],
            'web_search' => [
                // Abilita/disabilita web search per pipeline text.
                // true = il controller può attivarla in base alla policy intent.
                'enabled' => filter_var(env('OPENAI_TEXT_WEB_SEARCH_ENABLED', true), FILTER_VALIDATE_BOOL),

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
            ],
        ],
    ],
];
