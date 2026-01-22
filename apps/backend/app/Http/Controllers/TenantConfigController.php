<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantConfigController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenant') ?: config('tenants.default', 'demo');
        $tenantConfig = config("tenants.map.{$tenantId}") ?? config('tenants.map.'.config('tenants.default', 'demo'));

        return response()->json([
            'tenant' => [
                'id' => $tenantId,
                'name' => $tenantConfig['name'] ?? $tenantId,
                'intro_message' => $tenantConfig['intro_message'] ?? null,
                'support_email' => $tenantConfig['support_email'] ?? null,
                'fallback_message' => $tenantConfig['fallback_message'] ?? null,
                'instructions' => $tenantConfig['instructions'] ?? null,
            ],
        ]);
    }
}
