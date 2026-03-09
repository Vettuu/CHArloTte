<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatMessageLogRequest;
use App\Models\AnalyticsEvent;
use App\Models\ChatMessage;
use App\Models\RealtimeSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ChatMessageLogController extends Controller
{
    public function __invoke(ChatMessageLogRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $sessionId = $payload['session_id'];
        $tenantId = $payload['tenant_id'];
        $messageId = $payload['message_id'] ?? null;
        $content = trim($payload['content']);

        if ($content === '') {
            return response()->json(['status' => 'ignored'], Response::HTTP_OK);
        }

        $metadata = $payload['metadata'] ?? [];
        $metadata['ip_hash'] = $this->hashIp($request->ip());
        $metadata['user_agent'] = $request->userAgent();
        $metadata['referer'] = $request->headers->get('referer');
        $metadata['topics'] = $metadata['topics'] ?? $this->detectTopics($content);

        $tokensEst = $this->estimateTokens($content);

        $data = [
            'session_id' => $sessionId,
            'tenant_id' => $tenantId,
            'message_id' => $messageId,
            'role' => $payload['role'],
            'content' => $content,
            'source' => $payload['source'] ?? 'text',
            'tokens_est' => $tokensEst,
            'metadata' => $metadata,
        ];

        if ($messageId) {
            ChatMessage::updateOrCreate([
                'session_id' => $sessionId,
                'message_id' => $messageId,
            ], $data);
        } else {
            ChatMessage::create($data);
        }

        $session = RealtimeSession::where('session_id', $sessionId)->first();
        if ($session === null) {
            RealtimeSession::create([
                'session_id' => $sessionId,
                'mode' => 'text',
                'status' => 'logged',
                'metadata' => ['tenant' => $tenantId],
            ]);
        }

        $this->writeAnalyticsEvent(
            payload: $payload,
            metadata: $metadata,
            tokensEst: $tokensEst,
            content: $content,
        );

        Log::info('Chat message logged', [
            'tenant' => $tenantId,
            'session_id' => $sessionId,
            'role' => $payload['role'],
        ]);

        return response()->json(['status' => 'ok'], Response::HTTP_CREATED);
    }

    private function writeAnalyticsEvent(array $payload, array $metadata, int $tokensEst, string $content): void
    {
        try {
            $role = (string) ($payload['role'] ?? 'assistant');
            $tokenIn = is_numeric($metadata['token_in'] ?? null)
                ? (int) $metadata['token_in']
                : ($role === 'user' ? $tokensEst : null);
            $tokenOut = is_numeric($metadata['token_out'] ?? null)
                ? (int) $metadata['token_out']
                : ($role === 'assistant' ? $tokensEst : null);

            $assistantRefs = is_array($metadata['rag_hit_refs'] ?? null)
                ? $metadata['rag_hit_refs']
                : [];
            $diagnosticRefs = is_array($metadata['diagnostic_hit_refs'] ?? null)
                ? $metadata['diagnostic_hit_refs']
                : [];

            $acceptedCount = is_numeric($metadata['accepted_hits_count'] ?? null)
                ? (int) $metadata['accepted_hits_count']
                : (is_numeric($metadata['rag_hits'] ?? null)
                    ? (int) $metadata['rag_hits']
                    : count($assistantRefs));

            $diagnosticCount = is_numeric($metadata['diagnostic_hits_count'] ?? null)
                ? (int) $metadata['diagnostic_hits_count']
                : count($diagnosticRefs);

            $eventAt = $this->parseTimestamp($payload['timestamp'] ?? null);

            AnalyticsEvent::create([
                'event_at' => $eventAt,
                'session_id' => (string) ($payload['session_id'] ?? ''),
                'tenant_id' => (string) ($payload['tenant_id'] ?? ''),
                'pipeline' => $this->stringOrNull($metadata['pipeline'] ?? null),
                'model' => $this->stringOrNull($metadata['model'] ?? null),
                'knowledge_tenant' => $this->stringOrNull($metadata['knowledge_tenant'] ?? null),
                'role' => $role,
                'source' => $this->stringOrNull($payload['source'] ?? null),
                'intent' => $this->stringOrNull($metadata['intent'] ?? null),
                'fallback' => $this->boolOrNull($metadata['fallback'] ?? null),
                'contradiction_flag' => $this->boolOrNull($metadata['contradiction_flag'] ?? null),
                'contradiction_type' => $this->stringOrNull($metadata['contradiction_type'] ?? null),
                'confidence_score' => $this->intOrNull($metadata['confidence_score'] ?? null),
                'confidence_bucket' => $this->stringOrNull($metadata['confidence_bucket'] ?? null),
                'rag_hits' => $this->intOrNull($metadata['rag_hits'] ?? null),
                'accepted_hits_count' => $acceptedCount,
                'diagnostic_hits_count' => $diagnosticCount,
                'top_score' => $this->floatOrNull($metadata['top_score'] ?? null),
                'semantic_level' => $this->stringOrNull($metadata['semantic_level'] ?? null),
                'query_token_count' => $this->intOrNull($metadata['query_token_count'] ?? null),
                'latency_ms' => $this->intOrNull($metadata['latency_ms'] ?? null),
                'reply_len' => $this->intOrNull($metadata['reply_len'] ?? null) ?? ($role === 'assistant' ? mb_strlen($content) : null),
                'token_in' => $tokenIn,
                'token_out' => $tokenOut,
                'policy_path' => $this->stringOrNull($metadata['policy_path'] ?? null),
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('Analytics event write failed', [
                'session_id' => $payload['session_id'] ?? null,
                'tenant_id' => $payload['tenant_id'] ?? null,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (bool) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no'], true)) {
            return false;
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }

    private function estimateTokens(string $content): int
    {
        $chars = mb_strlen($content);
        return (int) ceil($chars / 4);
    }

    /**
     * @return array<int, string>
     */
    private function detectTopics(string $content): array
    {
        $normalized = Str::of($content)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\\s]/', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            return [];
        }

        $topics = [
            'costi' => ['costo', 'prezzo', 'preventivo', 'tariffa', 'stima', 'budget'],
            'accredito' => [
                'accredito',
                'check in',
                'checkin',
                'qr',
                'qrcode',
                'badge',
                'registrazione',
                'ipad',
                'accessi',
                'controllo accessi',
            ],
            'sponsor' => ['sponsor', 'espositore', 'stand', 'lead', 'contatti'],
            'logistica' => [
                'logistica',
                'sede',
                'location',
                'ingresso',
                'ingressi',
                'uscite',
                'parcheggio',
                'orari',
                'navetta',
                'sale',
                'aula',
                'aule',
                'monitorare',
            ],
            'app' => ['app', 'agenda', 'programma', 'notifiche', 'push'],
            'streaming' => ['streaming', 'webinar', 'online', 'zoom'],
            'votazioni' => ['voto', 'votazioni', 'televoto', 'e-vote', 'elezioni'],
            'ecm' => ['ecm', 'crediti', 'presenze', 'rfid'],
        ];

        $matches = [];

        foreach ($topics as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $matches[] = $topic;
                    break;
                }
            }
        }

        return $matches;
    }

    private function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            return hash('sha256', $ip);
        }

        return hash_hmac('sha256', $ip, $key);
    }
}
