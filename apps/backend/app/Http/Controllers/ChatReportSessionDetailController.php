<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
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
                'message_id' => $message->message_id,
                'role' => $message->role,
                'content' => $message->content,
                'source' => $message->source,
                'tokens_est' => $message->tokens_est,
                'topics' => $message->metadata['topics'] ?? [],
                'metadata' => $message->metadata,
                'created_at' => optional($message->created_at)->toIso8601String(),
            ])
            ->values();

        $events = AnalyticsEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('session_id', $sessionId)
            ->orderBy('event_at')
            ->orderBy('id')
            ->get()
            ->map(fn ($event) => [
                'id' => $event->id,
                'event_at' => optional($event->event_at)->toIso8601String(),
                'role' => $event->role,
                'source' => $event->source,
                'pipeline' => $event->pipeline,
                'model' => $event->model,
                'knowledge_tenant' => $event->knowledge_tenant,
                'intent' => $event->intent,
                'fallback' => (bool) $event->fallback,
                'contradiction_flag' => (bool) $event->contradiction_flag,
                'contradiction_type' => $event->contradiction_type,
                'confidence_score' => $event->confidence_score,
                'confidence_bucket' => $event->confidence_bucket,
                'rag_hits' => $event->rag_hits,
                'accepted_hits_count' => $event->accepted_hits_count,
                'diagnostic_hits_count' => $event->diagnostic_hits_count,
                'top_score' => $event->top_score,
                'semantic_level' => $event->semantic_level,
                'query_token_count' => $event->query_token_count,
                'latency_ms' => $event->latency_ms,
                'reply_len' => $event->reply_len,
                'token_in' => $event->token_in,
                'token_out' => $event->token_out,
                'policy_path' => $event->policy_path,
                'metadata' => $event->metadata,
            ])
            ->values();

        $timeline = collect($messages)
            ->map(fn ($message) => [
                'type' => 'message',
                'at' => $message['created_at'],
                'role' => $message['role'],
                'summary' => mb_substr((string) $message['content'], 0, 120),
                'payload' => $message,
            ])
            ->concat(
                collect($events)->map(fn ($event) => [
                    'type' => 'event',
                    'at' => $event['event_at'],
                    'role' => $event['role'],
                    'summary' => sprintf(
                        '%s | confidence=%s | fallback=%s | latency=%s',
                        (string) ($event['pipeline'] ?? 'n/a'),
                        (string) ($event['confidence_score'] ?? 'n/a'),
                        (bool) ($event['fallback'] ?? false) ? '1' : '0',
                        (string) ($event['latency_ms'] ?? 'n/a')
                    ),
                    'payload' => $event,
                ])
            )
            ->sortBy('at')
            ->values();

        return response()->json([
            'tenant' => $tenantId,
            'session_id' => $sessionId,
            'messages' => $messages,
            'events' => $events,
            'timeline' => $timeline,
        ]);
    }
}
