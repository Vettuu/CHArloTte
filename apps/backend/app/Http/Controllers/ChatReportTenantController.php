<?php

namespace App\Http\Controllers;

class ChatReportTenantController extends Controller
{
    public function __invoke()
    {
        $tenants = config('tenants.map', []);
        $list = [];

        foreach ($tenants as $id => $config) {
            $list[] = [
                'id' => $id,
                'name' => $config['name'] ?? $id,
                'pipeline' => $config['pipeline'] ?? null,
                'chat_model' => $config['chat_model'] ?? null,
                'knowledge_tenant' => $config['knowledge_tenant'] ?? null,
            ];
        }

        return response()->json([
            'tenants' => $list,
        ]);
    }
}
