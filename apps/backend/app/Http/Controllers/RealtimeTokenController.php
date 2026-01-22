<?php

namespace App\Http\Controllers;

use App\Http\Requests\RealtimeTokenRequest;
use App\Models\RealtimeSession;
use App\Services\OpenAIRealtimeService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RealtimeTokenController extends Controller
{
    public function __construct(private readonly OpenAIRealtimeService $realtime)
    {
    }

    public function __invoke(RealtimeTokenRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $sessionOverrides = $payload['session'] ?? [];
        $tenantId = data_get($payload, 'metadata.tenant') ?: config('tenants.default', 'demo');
        $tenantConfig = config("tenants.map.{$tenantId}") ?? config('tenants.map.'.config('tenants.default', 'demo'));

        if (! empty($payload['mode'])) {
            $sessionOverrides['output_modalities'] = [$payload['mode']];
        }

        if (! empty($tenantConfig['instructions'])) {
            $sessionOverrides['instructions'] = $tenantConfig['instructions'];
        }

        $metadata = array_merge(
            ['tenant' => $tenantId],
            $payload['metadata'] ?? []
        );

        try {
            $result = $this->realtime->createClientSecret($sessionOverrides);
        } catch (RequestException $exception) {
            $message = $exception->response?->json('error.message') ?? $exception->getMessage();

            Log::warning('Unable to create realtime client secret', [
                'message' => $message,
                'payload' => $payload,
            ]);

            return response()->json([
                'message' => 'Unable to create realtime client secret',
                'details' => $message,
            ], Response::HTTP_BAD_GATEWAY);
        }

        RealtimeSession::create([
            'session_id' => data_get($result, 'session.id'),
            'mode' => data_get($result, 'session.output_modalities.0', config('realtime.default_mode', 'audio')),
            'status' => 'issued',
            'session_payload' => data_get($result, 'session'),
            'metadata' => $metadata,
        ]);

        Log::info('Realtime token issued', [
            'session_id' => data_get($result, 'session.id'),
            'mode' => data_get($result, 'session.output_modalities'),
        ]);

        $result['tenant'] = [
            'id' => $tenantId,
            'name' => $tenantConfig['name'] ?? $tenantId,
            'intro_message' => $tenantConfig['intro_message'] ?? null,
        ];

        return response()->json($result, Response::HTTP_CREATED);
    }
}
