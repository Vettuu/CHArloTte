<?php

return [
    'pipelines' => [
        'realtime' => [
            'default_model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
            'transport' => 'websocket',
        ],
        'text' => [
            'default_model' => env('OPENAI_TEXT_MODEL', 'gpt-4.1'),
            'temperature' => (float) env('OPENAI_TEXT_TEMPERATURE', 0.3),
            'max_output_tokens' => (int) env('OPENAI_TEXT_MAX_OUTPUT_TOKENS', 800),
        ],
    ],
];
