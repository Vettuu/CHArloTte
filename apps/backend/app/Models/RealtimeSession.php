<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RealtimeSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'mode',
        'status',
        'session_payload',
        'metadata',
    ];

    protected $casts = [
        'session_payload' => 'array',
        'metadata' => 'array',
    ];
}
