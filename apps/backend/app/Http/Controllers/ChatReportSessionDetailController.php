<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;

class ChatReportSessionDetailController extends Controller
{
    public function __invoke(Request $request, string $sessionId)
    {
        $tenantId = (string) $request->query('tenant', config('knowledge.default_tenant', 'demo'));

        $messages = ChatMessage::query()
            ->where('tenant_id', $tenantId)
            ->where('session_id', $sessionId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($message) => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'source' => $message->source,
                'topics' => $message->metadata['topics'] ?? [],
                'created_at' => optional($message->created_at)->toIso8601String(),
            ]);

        return response()->json([
            'tenant' => $tenantId,
            'session_id' => $sessionId,
            'messages' => $messages,
        ]);
    }
}
