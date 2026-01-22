<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatReportSessionsController extends Controller
{
    public function __invoke(Request $request)
    {
        $tenantId = (string) $request->query('tenant', config('knowledge.default_tenant', 'demo'));
        $query = ChatMessage::query()->where('tenant_id', $tenantId);

        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        if ($from !== null) {
            $query->where('created_at', '>=', $from->startOfDay());
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to->endOfDay());
        }

        $topicParam = trim((string) $request->query('topic', ''));
        $topics = collect(explode(',', $topicParam))
            ->map(fn ($item) => trim($item))
            ->filter(fn ($item) => $item !== '')
            ->values()
            ->all();

        $fallbackParam = $request->query('fallback');
        $fallbackOnly = in_array((string) $fallbackParam, ['1', 'true', 'yes'], true);

        if (! empty($topics)) {
            $topicSubquery = (clone $query)
                ->where('role', 'user')
                ->where(function ($builder) use ($topics) {
                    foreach ($topics as $topic) {
                        $builder->orWhereJsonContains('metadata->topics', $topic);
                    }
                })
                ->select('session_id')
                ->distinct();
            $query->whereIn('session_id', $topicSubquery);
        }

        if ($fallbackOnly) {
            $fallbackSubquery = (clone $query)
                ->where('role', 'assistant')
                ->where(function ($builder) {
                    $builder->where('metadata->fallback', true)
                        ->orWhere('metadata->fallback', 'true')
                        ->orWhere('metadata->fallback', 1)
                        ->orWhere('metadata->fallback', '1');
                })
                ->select('session_id')
                ->distinct();
            $query->whereIn('session_id', $fallbackSubquery);
        }

        $rows = (clone $query)
            ->select([
                'session_id',
                DB::raw('count(*) as messages_total'),
                DB::raw("sum(case when role = 'user' then 1 else 0 end) as messages_user"),
                DB::raw("sum(case when role = 'assistant' then 1 else 0 end) as messages_assistant"),
                DB::raw('max(created_at) as last_at'),
            ])
            ->groupBy('session_id')
            ->orderByDesc('last_at')
            ->limit(200)
            ->get();

        $sessions = $rows->map(fn ($row) => [
            'session_id' => $row->session_id,
            'messages_total' => (int) $row->messages_total,
            'messages_user' => (int) $row->messages_user,
            'messages_assistant' => (int) $row->messages_assistant,
            'last_at' => (string) $row->last_at,
        ]);

        return response()->json([
            'tenant' => $tenantId,
            'sessions' => $sessions,
        ]);
    }

    private function parseDate(?string $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $clean = trim($value);
        if (! Str::match('/\d{4}-\d{2}-\d{2}/', $clean)) {
            return null;
        }

        try {
            return Carbon::parse($clean);
        } catch (\Throwable) {
            return null;
        }
    }
}
