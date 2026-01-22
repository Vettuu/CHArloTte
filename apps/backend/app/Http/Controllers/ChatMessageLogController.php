<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChatMessageLogRequest;
use App\Models\ChatMessage;
use App\Models\RealtimeSession;
use Illuminate\Http\JsonResponse;
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

        $data = [
            'session_id' => $sessionId,
            'tenant_id' => $tenantId,
            'message_id' => $messageId,
            'role' => $payload['role'],
            'content' => $content,
            'source' => $payload['source'] ?? 'text',
            'tokens_est' => $this->estimateTokens($content),
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

        Log::info('Chat message logged', [
            'tenant' => $tenantId,
            'session_id' => $sessionId,
            'role' => $payload['role'],
        ]);

        return response()->json(['status' => 'ok'], Response::HTTP_CREATED);
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
