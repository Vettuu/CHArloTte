<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_at',
        'session_id',
        'tenant_id',
        'pipeline',
        'model',
        'knowledge_tenant',
        'role',
        'source',
        'intent',
        'fallback',
        'contradiction_flag',
        'contradiction_type',
        'confidence_score',
        'confidence_bucket',
        'rag_hits',
        'accepted_hits_count',
        'diagnostic_hits_count',
        'top_score',
        'semantic_level',
        'query_token_count',
        'latency_ms',
        'reply_len',
        'token_in',
        'token_out',
        'policy_path',
        'metadata',
    ];

    protected $casts = [
        'event_at' => 'datetime',
        'fallback' => 'boolean',
        'contradiction_flag' => 'boolean',
        'top_score' => 'float',
        'metadata' => 'array',
    ];
}
