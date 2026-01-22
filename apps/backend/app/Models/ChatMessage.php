<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'tenant_id',
        'message_id',
        'role',
        'content',
        'source',
        'tokens_est',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];
}
