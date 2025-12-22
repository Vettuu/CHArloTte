<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class KnowledgeChunk extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_id',
        'content',
        'metadata',
        'embedding',
        'embedding_norm',
    ];

    protected $casts = [
        'metadata' => 'array',
        'embedding' => 'array',
        'embedding_norm' => 'float',
    ];
}
