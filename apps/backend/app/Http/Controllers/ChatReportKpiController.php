<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatReportKpiController extends Controller
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

        $messagesTotal = (clone $query)->count();
        $userMessages = (clone $query)->where('role', 'user')->count();
        $assistantMessages = (clone $query)->where('role', 'assistant')->count();
        $sessionsTotal = (clone $query)->distinct('session_id')->count('session_id');

        $fallbackMessages = (clone $query)
            ->where('role', 'assistant')
            ->where(function ($builder) {
                $builder->where('metadata->fallback', true)
                    ->orWhere('metadata->fallback', 'true');
            })
            ->count();

        $fallbackRate = $assistantMessages > 0
            ? round(($fallbackMessages / $assistantMessages) * 100, 2)
            : 0.0;

        $topicCounts = [];
        $topicDaily = [];
        (clone $query)
            ->where('role', 'user')
            ->select(['metadata', 'created_at'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$topicCounts, &$topicDaily): void {
                foreach ($rows as $row) {
                    $topics = data_get($row, 'metadata.topics', []);
                    if (! is_array($topics)) {
                        continue;
                    }
                    $day = optional($row->created_at)->toDateString();
                    foreach ($topics as $topic) {
                        if (! is_string($topic) || $topic === '') {
                            continue;
                        }
                        $topicCounts[$topic] = ($topicCounts[$topic] ?? 0) + 1;
                        if ($day) {
                            $topicDaily[$day][$topic] = ($topicDaily[$day][$topic] ?? 0) + 1;
                        }
                    }
                }
            });

        arsort($topicCounts);
        $topTopics = array_slice($topicCounts, 0, 5, true);

        $dailyRows = (clone $query)
            ->select([
                DB::raw('date(created_at) as day'),
                DB::raw('count(*) as messages_total'),
                DB::raw("sum(case when role = 'user' then 1 else 0 end) as messages_user"),
                DB::raw("sum(case when role = 'assistant' then 1 else 0 end) as messages_assistant"),
                DB::raw('count(distinct session_id) as sessions'),
            ])
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        $fallbackByDay = [];
        (clone $query)
            ->where('role', 'assistant')
            ->select(['created_at', 'metadata'])
            ->orderBy('created_at')
            ->chunk(500, function ($rows) use (&$fallbackByDay): void {
                foreach ($rows as $row) {
                    $flag = data_get($row, 'metadata.fallback', false);
                    if ($flag !== true && $flag !== 'true' && $flag !== 1 && $flag !== '1') {
                        continue;
                    }
                    $day = optional($row->created_at)->toDateString();
                    if (! $day) {
                        continue;
                    }
                    $fallbackByDay[$day] = ($fallbackByDay[$day] ?? 0) + 1;
                }
            });

        $daily = $dailyRows->map(fn ($row) => [
            'date' => $row->day,
            'messages_total' => (int) $row->messages_total,
            'messages_user' => (int) $row->messages_user,
            'messages_assistant' => (int) $row->messages_assistant,
            'fallback_messages' => (int) ($fallbackByDay[$row->day] ?? 0),
            'sessions' => (int) $row->sessions,
        ]);

        return response()->json([
            'tenant' => $tenantId,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),
            'sessions' => $sessionsTotal,
            'messages_total' => $messagesTotal,
            'messages_user' => $userMessages,
            'messages_assistant' => $assistantMessages,
            'fallback_messages' => $fallbackMessages,
            'fallback_rate_percent' => $fallbackRate,
            'top_topics' => $topTopics,
            'topic_daily' => $topicDaily,
            'daily' => $daily,
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
