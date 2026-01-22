<?php

return [
    'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
    'chunk_size' => env('KNOWLEDGE_CHUNK_SIZE', 900),
    'chunk_overlap' => env('KNOWLEDGE_CHUNK_OVERLAP', 150),
    'rebuild_token' => env('KNOWLEDGE_REBUILD_TOKEN'),
    'index_batch_size' => env('KNOWLEDGE_INDEX_BATCH_SIZE', 6),
    'min_score' => env('KNOWLEDGE_MIN_SCORE', 0.7),
    'default_tenant' => env('KNOWLEDGE_DEFAULT_TENANT', 'demo'),
];
