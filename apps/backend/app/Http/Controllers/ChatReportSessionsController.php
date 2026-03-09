<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ChatReportSessionsController extends Controller
{
    public function __invoke(Request $request)
    {
        $tenantFilter = $this->parseCsvFilter($request->query('tenant'));
        if ($tenantFilter === []) {
            $tenantFilter = [(string) config('knowledge.default_tenant', 'demo')];
        }

        $pipelineFilter = $this->parseCsvFilter($request->query('pipeline'));
        $modelFilter = $this->parseCsvFilter($request->query('model'));
        $knowledgeTenantFilter = $this->parseCsvFilter($request->query('knowledge_tenant'));

        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        $messageQuery = ChatMessage::query()->whereIn('tenant_id', $tenantFilter);
        $eventQuery = AnalyticsEvent::query()->whereIn('tenant_id', $tenantFilter);

        if ($from !== null) {
            $messageQuery->where('created_at', '>=', $from->startOfDay());
            $eventQuery->where('event_at', '>=', $from->startOfDay());
        }

        if ($to !== null) {
            $messageQuery->where('created_at', '<=', $to->endOfDay());
            $eventQuery->where('event_at', '<=', $to->endOfDay());
        }

        if ($pipelineFilter !== []) {
            $eventQuery->whereIn('pipeline', $pipelineFilter);
        }

        if ($modelFilter !== []) {
            $eventQuery->whereIn('model', $modelFilter);
        }

        if ($knowledgeTenantFilter !== []) {
            $eventQuery->whereIn('knowledge_tenant', $knowledgeTenantFilter);
        }

        $topicParam = trim((string) $request->query('topic', ''));
        $topics = collect(explode(',', $topicParam))
            ->map(fn ($item) => trim($item))
            ->filter(fn ($item) => $item !== '')
            ->values()
            ->all();

        $fallbackOnly = $this->toBool($request->query('fallback'));
        $contradictionOnly = $this->toBool($request->query('contradiction'));
        $lowConfidenceOnly = $this->toBool($request->query('low_confidence'));
        $highLatencyOnly = $this->toBool($request->query('high_latency'));

        $confidenceThreshold = is_numeric($request->query('confidence_threshold'))
            ? (int) $request->query('confidence_threshold')
            : 45;
        $latencyThreshold = is_numeric($request->query('latency_threshold_ms'))
            ? (int) $request->query('latency_threshold_ms')
            : 4000;

        if (! empty($topics)) {
            $topicSubquery = (clone $messageQuery)
                ->where('role', 'user')
                ->where(function ($builder) use ($topics) {
                    foreach ($topics as $topic) {
                        $builder->orWhereJsonContains('metadata->topics', $topic);
                    }
                })
                ->select('session_id')
                ->distinct();
            $messageQuery->whereIn('session_id', $topicSubquery);
            $eventQuery->whereIn('session_id', $topicSubquery);
        }

        $eventSessionSubquery = (clone $eventQuery)
            ->where('role', 'assistant');

        if ($fallbackOnly) {
            $eventSessionSubquery->where('fallback', true);
        }

        if ($contradictionOnly) {
            $eventSessionSubquery->where('contradiction_flag', true);
        }

        if ($lowConfidenceOnly) {
            $eventSessionSubquery->whereNotNull('confidence_score')->where('confidence_score', '<', $confidenceThreshold);
        }

        if ($highLatencyOnly) {
            $eventSessionSubquery->whereNotNull('latency_ms')->where('latency_ms', '>=', $latencyThreshold);
        }

        $filteredSessionIds = $eventSessionSubquery->select('session_id')->distinct();

        if ($fallbackOnly || $contradictionOnly || $lowConfidenceOnly || $highLatencyOnly || $pipelineFilter !== [] || $modelFilter !== [] || $knowledgeTenantFilter !== []) {
            $messageQuery->whereIn('session_id', $filteredSessionIds);
        }

        $rows = (clone $messageQuery)
            ->selectRaw('session_id, count(*) as messages_total, sum(case when role = "user" then 1 else 0 end) as messages_user, sum(case when role = "assistant" then 1 else 0 end) as messages_assistant, max(created_at) as last_at')
            ->groupBy('session_id')
            ->orderByDesc('last_at')
            ->limit(300)
            ->get();

        $sessionIds = $rows->pluck('session_id')->all();

        $eventSummary = [];
        if ($sessionIds !== []) {
            (clone $eventQuery)
                ->where('role', 'assistant')
                ->whereIn('session_id', $sessionIds)
                ->select([
                    'session_id',
                    'pipeline',
                    'model',
                    'knowledge_tenant',
                    'fallback',
                    'contradiction_flag',
                    'confidence_score',
                    'latency_ms',
                    'intent',
                    'event_at',
                ])
                ->orderBy('event_at')
                ->chunk(500, function ($items) use (&$eventSummary): void {
                    foreach ($items as $event) {
                        $id = (string) $event->session_id;
                        $eventSummary[$id]['fallback_count'] = ($eventSummary[$id]['fallback_count'] ?? 0) + ((bool) $event->fallback ? 1 : 0);
                        $eventSummary[$id]['contradiction_count'] = ($eventSummary[$id]['contradiction_count'] ?? 0) + ((bool) $event->contradiction_flag ? 1 : 0);
                        $eventSummary[$id]['confidence'][] = is_numeric($event->confidence_score) ? (int) $event->confidence_score : null;
                        $eventSummary[$id]['latency'][] = is_numeric($event->latency_ms) ? (int) $event->latency_ms : null;

                        if (is_string($event->pipeline) && $event->pipeline !== '') {
                            $eventSummary[$id]['pipeline'] = $event->pipeline;
                        }
                        if (is_string($event->model) && $event->model !== '') {
                            $eventSummary[$id]['model'] = $event->model;
                        }
                        if (is_string($event->knowledge_tenant) && $event->knowledge_tenant !== '') {
                            $eventSummary[$id]['knowledge_tenant'] = $event->knowledge_tenant;
                        }
                        if (is_string($event->intent) && $event->intent !== '') {
                            $eventSummary[$id]['intent'] = $event->intent;
                        }
                    }
                });
        }

        $sessions = $rows->map(function ($row) use ($eventSummary): array {
            $sessionId = (string) $row->session_id;
            $summary = $eventSummary[$sessionId] ?? [];
            $confidenceValues = collect($summary['confidence'] ?? [])->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (int) $value)->values()->all();
            $latencyValues = collect($summary['latency'] ?? [])->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (int) $value)->values()->all();

            return [
                'session_id' => $sessionId,
                'messages_total' => (int) $row->messages_total,
                'messages_user' => (int) $row->messages_user,
                'messages_assistant' => (int) $row->messages_assistant,
                'last_at' => (string) $row->last_at,
                'pipeline' => $summary['pipeline'] ?? null,
                'model' => $summary['model'] ?? null,
                'knowledge_tenant' => $summary['knowledge_tenant'] ?? null,
                'intent' => $summary['intent'] ?? null,
                'fallback_count' => (int) ($summary['fallback_count'] ?? 0),
                'contradiction_count' => (int) ($summary['contradiction_count'] ?? 0),
                'avg_confidence' => $this->average($confidenceValues),
                'max_latency_ms' => $latencyValues !== [] ? max($latencyValues) : 0,
            ];
        })->values()->all();

        return response()->json([
            'tenant' => implode(',', $tenantFilter),
            'tenants' => $tenantFilter,
            'pipeline_filter' => $pipelineFilter,
            'model_filter' => $modelFilter,
            'knowledge_tenant_filter' => $knowledgeTenantFilter,
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

    private function parseCsvFilter(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        return collect(explode(',', $value))
            ->map(fn ($item) => trim((string) $item))
            ->filter(fn ($item) => $item !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        return in_array((string) $value, ['1', 'true', 'yes'], true);
    }

    private function average(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), 2);
    }
}
