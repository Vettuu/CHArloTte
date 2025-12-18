<?php

return [
    'default_mode' => env('OPENAI_REALTIME_OUTPUT_MODALITIES', 'audio'),
    'session' => [
        'type' => 'realtime',
        'model' => env('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
        'instructions' => env('OPENAI_REALTIME_INSTRUCTIONS'),
        'output_modalities' => array_filter(
            array_map('trim', explode(',', env('OPENAI_REALTIME_OUTPUT_MODALITIES', 'audio')))
        ),
        'audio' => [
            'input' => [
                'format' => [
                    'type' => 'audio/pcm',
                    'rate' => 24000,
                ],
                'turn_detection' => [
                    'type' => 'server_vad',
                    'threshold' => 0.5,
                    'prefix_padding_ms' => 300,
                    'silence_duration_ms' => 200,
                    'idle_timeout_ms' => null,
                    'create_response' => true,
                    'interrupt_response' => true,
                ],
            ],
            'output' => [
                'format' => [
                    'type' => 'audio/pcm',
                    'rate' => 24000,
                ],
                'voice' => env('OPENAI_REALTIME_VOICE', 'marin'),
                'speed' => 1.0,
            ],
        ],
    ],
];
