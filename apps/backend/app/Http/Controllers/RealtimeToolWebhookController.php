<?php

namespace App\Http\Controllers;

use App\Http\Requests\RealtimeWebhookRequest;
use App\Jobs\ProcessRealtimeWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class RealtimeToolWebhookController extends Controller
{
    public function __invoke(RealtimeWebhookRequest $request): JsonResponse
    {
        $payload = $request->validated();

        Log::info('Realtime tool webhook received', [
            'event' => $payload['event'],
            'session_id' => $payload['session_id'] ?? null,
        ]);

        ProcessRealtimeWebhookJob::dispatch($payload);

        return response()->json([
            'status' => 'accepted',
        ], Response::HTTP_ACCEPTED);
    }
}
