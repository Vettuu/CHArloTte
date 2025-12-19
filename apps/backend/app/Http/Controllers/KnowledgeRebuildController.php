<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\Response;

class KnowledgeRebuildController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = config('knowledge.rebuild_token');

        if (empty($token)) {
            abort(Response::HTTP_FORBIDDEN, 'Rebuild token non configurato.');
        }

        $provided = $request->query('token') ?? $request->header('X-Rebuild-Token');

        if (! hash_equals($token, (string) $provided)) {
            abort(Response::HTTP_FORBIDDEN, 'Token non valido.');
        }

        Artisan::call('knowledge:index');

        return response()->json([
            'status' => 'ok',
            'message' => 'Indicizzazione avviata.',
        ]);
    }
}
