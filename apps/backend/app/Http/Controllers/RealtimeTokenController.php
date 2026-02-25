<?php

namespace App\Http\Controllers;

use App\Chat\ChatPipelineResolver;
use App\Chat\Exceptions\UnknownPipelineException;
use App\Chat\Exceptions\UnsupportedPipelineOperationException;
use App\Http\Requests\RealtimeTokenRequest;
use App\Models\RealtimeSession;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RealtimeTokenController extends Controller
{
    public function __construct(private readonly ChatPipelineResolver $resolver)
    {
    }

    public function __invoke(RealtimeTokenRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $tenantId = data_get($payload, 'metadata.tenant') ?: config('tenants.default', 'demo');
        $tenantConfig = config("tenants.map.{$tenantId}") ?? config('tenants.map.'.config('tenants.default', 'demo'));
        $pipelineKey = (string) ($tenantConfig['pipeline'] ?? 'realtime');

        try {
            $pipeline = $this->resolver->resolveTokenPipeline($pipelineKey);
            $issued = $pipeline->issueToken($payload, $tenantId, $tenantConfig);
            $result = $issued['result'];
            $metadata = $issued['metadata'];
            $mode = $issued['mode'];
        } catch (UnknownPipelineException|UnsupportedPipelineOperationException $exception) {
            return response()->json([
                'message' => 'Token flow not available for selected pipeline',
                'details' => $exception->getMessage(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            'mode' => $mode,
            'status' => 'issued',
            'session_payload' => data_get($result, 'session'),
            'metadata' => $metadata,
        ]);

        Log::info('Realtime token issued', [
            'session_id' => data_get($result, 'session.id'),
            'mode' => data_get($result, 'session.output_modalities'),
            'pipeline' => $pipelineKey,
        ]);

        $result['tenant'] = [
            'id' => $tenantId,
            'name' => $tenantConfig['name'] ?? $tenantId,
            'intro_message' => $tenantConfig['intro_message'] ?? null,
            'pipeline' => $pipelineKey,
            'chat_model' => $tenantConfig['chat_model'] ?? null,
        ];

        return response()->json($result, Response::HTTP_CREATED);
    }
}
