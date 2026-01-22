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
            ];
        }

        return response()->json([
            'tenants' => $list,
        ]);
    }
}
