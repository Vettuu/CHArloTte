<?php

namespace App\Http\Controllers;

use App\Models\AnalyticsEvent;
use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChatReportKpiController extends Controller
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
            $messageQuery->where(function ($builder) use ($pipelineFilter) {
                foreach ($pipelineFilter as $pipeline) {
                    $builder->orWhere('metadata->pipeline', $pipeline);
                }
            });
        }

        if ($modelFilter !== []) {
            $eventQuery->whereIn('model', $modelFilter);
            $messageQuery->where(function ($builder) use ($modelFilter) {
                foreach ($modelFilter as $model) {
                    $builder->orWhere('metadata->model', $model);
                }
            });
        }

        if ($knowledgeTenantFilter !== []) {
            $eventQuery->whereIn('knowledge_tenant', $knowledgeTenantFilter);
            $messageQuery->where(function ($builder) use ($knowledgeTenantFilter) {
                foreach ($knowledgeTenantFilter as $knowledgeTenant) {
                    $builder->orWhere('metadata->knowledge_tenant', $knowledgeTenant);
                }
            });
        }

        $messagesTotal = (clone $messageQuery)->count();
        $userMessages = (clone $messageQuery)->where('role', 'user')->count();
        $assistantMessages = (clone $messageQuery)->where('role', 'assistant')->count();
        $sessionsTotal = (clone $messageQuery)->distinct('session_id')->count('session_id');

        $usersUnique = $this->computeUniqueUsers((clone $messageQuery)->where('role', 'user'));

        $assistantEvents = (clone $eventQuery)
            ->where('role', 'assistant')
            ->orderBy('event_at')
            ->get();

        $fallbackMessages = $assistantEvents->filter(fn ($event) => (bool) $event->fallback)->count();
        $contradictionMessages = $assistantEvents->filter(fn ($event) => (bool) $event->contradiction_flag)->count();

        $fallbackRate = $assistantMessages > 0 ? round(($fallbackMessages / $assistantMessages) * 100, 2) : 0.0;
        $contradictionRate = $assistantMessages > 0 ? round(($contradictionMessages / $assistantMessages) * 100, 2) : 0.0;

        $confidenceValues = $assistantEvents
            ->pluck('confidence_score')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $latencyValues = $assistantEvents
            ->pluck('latency_ms')
            ->filter(fn ($value) => is_numeric($value) && (int) $value >= 0)
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $tokenInValues = $assistantEvents
            ->pluck('token_in')
            ->filter(fn ($value) => is_numeric($value) && (int) $value >= 0)
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $tokenOutValues = $assistantEvents
            ->pluck('token_out')
            ->filter(fn ($value) => is_numeric($value) && (int) $value >= 0)
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $confidenceAvg = $this->average($confidenceValues);
        $latencyAvg = $this->average($latencyValues);
        $latencyP95 = $this->percentile($latencyValues, 95.0);
        $tokenInAvg = $this->average($tokenInValues);
        $tokenOutAvg = $this->average($tokenOutValues);

        $messagesPerSession = $sessionsTotal > 0
            ? round($messagesTotal / $sessionsTotal, 2)
            : 0.0;

        $topScoreValues = $assistantEvents
            ->pluck('top_score')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (float) $value)
            ->values()
            ->all();

        $ragHitsValues = $assistantEvents
            ->pluck('rag_hits')
            ->filter(fn ($value) => is_numeric($value))
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $topScoreAvg = $this->average($topScoreValues, 4);
        $ragHitsAvg = $this->average($ragHitsValues);

        $acceptedHitsAvg = $this->average(
            $assistantEvents->pluck('accepted_hits_count')->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (int) $value)->values()->all()
        );
        $diagnosticHitsAvg = $this->average(
            $assistantEvents->pluck('diagnostic_hits_count')->filter(fn ($value) => is_numeric($value))->map(fn ($value) => (int) $value)->values()->all()
        );

        $confidenceBuckets = $this->countBy($assistantEvents->pluck('confidence_bucket')->all());
        $semanticLevels = $this->countBy($assistantEvents->pluck('semantic_level')->all());
        $intentDistribution = $this->countBy($assistantEvents->pluck('intent')->all());

        $daily = $this->buildDailyMetrics($messageQuery, $eventQuery, $from, $to);
        [$topicCounts, $topicDaily] = $this->buildTopicMetrics($messageQuery);
        $confidenceByTopic = $this->buildConfidenceByTopic($messageQuery, $assistantEvents);
        $confidenceBySemanticLevel = $this->buildConfidenceBySemanticLevel($assistantEvents);
        $businessCoverage = $this->buildBusinessCoverage($messageQuery, $assistantEvents);

        arsort($topicCounts);
        $topTopics = array_slice($topicCounts, 0, 10, true);

        $topScoreHistogram = $this->buildFloatHistogram($topScoreValues, 0.0, 1.0, 10, 3);
        $confidenceHistogram = $this->buildIntHistogram($confidenceValues, 0, 100, 5);
        $latencyHistogram = $this->buildAdaptiveHistogram($latencyValues, 8);

        $correlationConfidenceVsTopScore = $assistantEvents
            ->filter(fn ($event) => is_numeric($event->confidence_score) && is_numeric($event->top_score))
            ->take(400)
            ->map(fn ($event) => [
                'x' => (float) $event->top_score,
                'y' => (int) $event->confidence_score,
                'fallback' => (bool) $event->fallback,
                'semantic_level' => is_string($event->semantic_level) ? $event->semantic_level : null,
            ])
            ->values()
            ->all();

        $correlationLatencyVsReplyLen = $assistantEvents
            ->filter(fn ($event) => is_numeric($event->latency_ms) && is_numeric($event->reply_len))
            ->take(400)
            ->map(fn ($event) => [
                'x' => (int) $event->latency_ms,
                'y' => (int) $event->reply_len,
            ])
            ->values()
            ->all();

        $correlationRagHitsVsConfidence = $assistantEvents
            ->filter(fn ($event) => is_numeric($event->rag_hits) && is_numeric($event->confidence_score))
            ->take(400)
            ->map(fn ($event) => [
                'x' => (int) $event->rag_hits,
                'y' => (int) $event->confidence_score,
            ])
            ->values()
            ->all();

        $costEstimatedPerSession = $this->estimateCostPerSession(
            tokenInAvg: $tokenInAvg,
            tokenOutAvg: $tokenOutAvg,
            sessionsTotal: $sessionsTotal,
        );

        return response()->json([
            'tenant' => implode(',', $tenantFilter),
            'tenants' => $tenantFilter,
            'pipeline_filter' => $pipelineFilter,
            'model_filter' => $modelFilter,
            'knowledge_tenant_filter' => $knowledgeTenantFilter,
            'from' => $from?->toDateString(),
            'to' => $to?->toDateString(),

            // Legacy fields (compat)
            'sessions' => $sessionsTotal,
            'messages_total' => $messagesTotal,
            'messages_user' => $userMessages,
            'messages_assistant' => $assistantMessages,
            'fallback_messages' => $fallbackMessages,
            'fallback_rate_percent' => $fallbackRate,
            'top_topics' => $topTopics,
            'confidence_by_topic' => $confidenceByTopic,
            'confidence_by_semantic_level' => $confidenceBySemanticLevel,
            'topic_daily' => $topicDaily,
            'daily' => $daily,

            // New KPI
            'users_unique' => $usersUnique,
            'messages_per_session' => $messagesPerSession,
            'contradiction_messages' => $contradictionMessages,
            'contradiction_rate_percent' => $contradictionRate,
            'confidence_avg' => $confidenceAvg,
            'latency_avg_ms' => $latencyAvg,
            'latency_p95_ms' => $latencyP95,
            'token_in_avg' => $tokenInAvg,
            'token_out_avg' => $tokenOutAvg,
            'cost_estimated_per_session' => $costEstimatedPerSession,
            'rag_hits_avg' => $ragHitsAvg,
            'top_score_avg' => $topScoreAvg,
            'accepted_hits_avg' => $acceptedHitsAvg,
            'diagnostic_hits_avg' => $diagnosticHitsAvg,

            'confidence_buckets' => $confidenceBuckets,
            'semantic_levels' => $semanticLevels,
            'intent_distribution' => $intentDistribution,
            'business_coverage' => $businessCoverage,

            'histograms' => [
                'top_score' => $topScoreHistogram,
                'confidence_score' => $confidenceHistogram,
                'latency_ms' => $latencyHistogram,
            ],

            'correlations' => [
                'confidence_vs_top_score' => $correlationConfidenceVsTopScore,
                'latency_vs_reply_len' => $correlationLatencyVsReplyLen,
                'rag_hits_vs_confidence' => $correlationRagHitsVsConfidence,
            ],
        ]);
    }

    private function computeUniqueUsers($query): int
    {
        $users = [];

        $query
            ->select(['metadata'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$users): void {
                foreach ($rows as $row) {
                    $hash = data_get($row, 'metadata.ip_hash');
                    if (is_string($hash) && trim($hash) !== '') {
                        $users[$hash] = true;
                    }
                }
            });

        return count($users);
    }

    private function buildDailyMetrics($messageQuery, $eventQuery, ?Carbon $from, ?Carbon $to): array
    {
        $dailyRows = (clone $messageQuery)
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

        $eventByDay = [];
        (clone $eventQuery)
            ->where('role', 'assistant')
            ->select(['event_at', 'fallback', 'confidence_score', 'latency_ms'])
            ->orderBy('event_at')
            ->chunk(500, function ($rows) use (&$eventByDay): void {
                foreach ($rows as $row) {
                    $day = optional($row->event_at)->toDateString();
                    if (! is_string($day) || $day === '') {
                        continue;
                    }

                    $eventByDay[$day]['fallback'] = ($eventByDay[$day]['fallback'] ?? 0) + ((bool) $row->fallback ? 1 : 0);

                    if (is_numeric($row->confidence_score)) {
                        $eventByDay[$day]['confidence'][] = (int) $row->confidence_score;
                    }

                    if (is_numeric($row->latency_ms)) {
                        $eventByDay[$day]['latency'][] = (int) $row->latency_ms;
                    }
                }
            });

        $mapped = $dailyRows->map(function ($row) use ($eventByDay): array {
            $day = (string) $row->day;
            $confidenceValues = $eventByDay[$day]['confidence'] ?? [];
            $latencyValues = $eventByDay[$day]['latency'] ?? [];
            $assistantCount = (int) $row->messages_assistant;
            $fallbackCount = (int) ($eventByDay[$day]['fallback'] ?? 0);
            $fallbackRate = $assistantCount > 0
                ? round(($fallbackCount / $assistantCount) * 100, 2)
                : 0.0;

            return [
                'date' => $day,
                'messages_total' => (int) $row->messages_total,
                'messages_user' => (int) $row->messages_user,
                'messages_assistant' => $assistantCount,
                'fallback_messages' => $fallbackCount,
                'fallback_rate_percent' => $fallbackRate,
                'sessions' => (int) $row->sessions,
                'confidence_avg' => $this->average($confidenceValues),
                'latency_avg_ms' => $this->average($latencyValues),
            ];
        })->values()->all();

        return $this->fillMissingDailyRows($mapped, $from, $to);
    }

    private function buildTopicMetrics($messageQuery): array
    {
        $topicCounts = [];
        $topicDaily = [];

        (clone $messageQuery)
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

        return [$topicCounts, $topicDaily];
    }

    private function buildConfidenceByTopic($messageQuery, $assistantEvents): array
    {
        $confidenceBySession = [];

        foreach ($assistantEvents as $event) {
            $sessionId = (string) $event->session_id;
            if ($sessionId === '') {
                continue;
            }
            if (! is_numeric($event->confidence_score)) {
                continue;
            }

            $confidenceBySession[$sessionId][] = (int) $event->confidence_score;
        }

        $topicsBySession = [];
        (clone $messageQuery)
            ->where('role', 'user')
            ->select(['session_id', 'metadata'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (&$topicsBySession): void {
                foreach ($rows as $row) {
                    $sessionId = (string) $row->session_id;
                    if ($sessionId === '') {
                        continue;
                    }

                    $topics = data_get($row, 'metadata.topics', []);
                    if (! is_array($topics)) {
                        continue;
                    }

                    foreach ($topics as $topic) {
                        if (! is_string($topic)) {
                            continue;
                        }
                        $cleanTopic = trim($topic);
                        if ($cleanTopic === '') {
                            continue;
                        }
                        $topicsBySession[$sessionId][$cleanTopic] = true;
                    }
                }
            });

        $topicSum = [];
        $topicCount = [];

        foreach ($topicsBySession as $sessionId => $topicSet) {
            $sessionConfidence = $confidenceBySession[$sessionId] ?? [];
            if ($sessionConfidence === []) {
                continue;
            }

            $sessionAvg = $this->average($sessionConfidence, 1);
            foreach (array_keys($topicSet) as $topic) {
                $topicSum[$topic] = ($topicSum[$topic] ?? 0.0) + $sessionAvg;
                $topicCount[$topic] = ($topicCount[$topic] ?? 0) + 1;
            }
        }

        $rows = [];
        foreach ($topicCount as $topic => $count) {
            if ($count <= 0) {
                continue;
            }

            $avg = round(($topicSum[$topic] ?? 0.0) / $count, 1);
            $rows[] = [
                'topic' => $topic,
                'confidence_avg' => $avg,
                'sessions' => $count,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            if ($a['confidence_avg'] === $b['confidence_avg']) {
                return $b['sessions'] <=> $a['sessions'];
            }

            return $b['confidence_avg'] <=> $a['confidence_avg'];
        });

        return array_slice($rows, 0, 12);
    }

    private function buildConfidenceBySemanticLevel($assistantEvents): array
    {
        $sum = [];
        $count = [];

        foreach ($assistantEvents as $event) {
            if (! is_numeric($event->confidence_score)) {
                continue;
            }
            $level = is_string($event->semantic_level) && trim($event->semantic_level) !== ''
                ? trim($event->semantic_level)
                : 'unknown';

            $sum[$level] = ($sum[$level] ?? 0.0) + (int) $event->confidence_score;
            $count[$level] = ($count[$level] ?? 0) + 1;
        }

        $order = ['low', 'medium', 'high', 'unknown'];
        $rows = [];
        foreach ($order as $level) {
            if (! isset($count[$level]) || $count[$level] <= 0) {
                continue;
            }
            $rows[] = [
                'semantic_level' => $level,
                'confidence_avg' => round(($sum[$level] ?? 0.0) / $count[$level], 1),
                'count' => $count[$level],
            ];
        }

        return $rows;
    }

    private function fillMissingDailyRows(array $rows, ?Carbon $from, ?Carbon $to): array
    {
        if ($rows === []) {
            return [];
        }

        $byDate = [];
        foreach ($rows as $row) {
            $date = (string) ($row['date'] ?? '');
            if ($date !== '') {
                $byDate[$date] = $row;
            }
        }

        $start = $from?->copy()->startOfDay();
        $end = $to?->copy()->startOfDay();

        if ($start === null || $end === null) {
            $firstDate = array_key_first($byDate);
            $lastDate = array_key_last($byDate);
            if (! is_string($firstDate) || ! is_string($lastDate)) {
                return $rows;
            }
            $start = Carbon::parse($firstDate)->startOfDay();
            $end = Carbon::parse($lastDate)->startOfDay();
        }

        if ($start->gt($end)) {
            return $rows;
        }

        $filled = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $date = $cursor->toDateString();
            $filled[] = $byDate[$date] ?? [
                'date' => $date,
                'messages_total' => 0,
                'messages_user' => 0,
                'messages_assistant' => 0,
                'fallback_messages' => 0,
                'fallback_rate_percent' => 0.0,
                'sessions' => 0,
                'confidence_avg' => 0.0,
                'latency_avg_ms' => 0.0,
            ];
            $cursor->addDay();
        }

        return $filled;
    }

    private function buildBusinessCoverage($messageQuery, $assistantEvents): array
    {
        $stopwords = [
            'che', 'con', 'per', 'sono', 'della', 'delle', 'degli', 'dello', 'anche', 'sulla', 'sulle',
            'dove', 'come', 'quali', 'quale', 'mi', 'ti', 'gli', 'noi', 'voi', 'loro', 'una', 'uno', 'del',
            'dei', 'nel', 'nella', 'nelle', 'dei', 'all', 'alla', 'alle', 'agli', 'dai', 'delle', 'the', 'and',
        ];

        $sessionFlags = [];
        foreach ($assistantEvents as $event) {
            $sessionId = (string) $event->session_id;
            if ($sessionId === '') {
                continue;
            }

            $topScore = is_numeric($event->top_score) ? (float) $event->top_score : null;
            $confidence = is_numeric($event->confidence_score) ? (int) $event->confidence_score : null;
            $fallback = (bool) $event->fallback;

            $sessionFlags[$sessionId] = [
                'fallback' => $fallback || (($sessionFlags[$sessionId]['fallback'] ?? false) === true),
                'top_score' => $topScore ?? ($sessionFlags[$sessionId]['top_score'] ?? null),
                'confidence_score' => $confidence ?? ($sessionFlags[$sessionId]['confidence_score'] ?? null),
            ];
        }

        $keywordCounts = [];
        $queryTotal = 0;
        $queryUncovered = 0;
        $queryLowTopScore = 0;
        $queryLowConfidence = 0;
        $fallbackByTopic = [];
        $volumeByTopic = [];
        $queriesNotCovered = [];

        (clone $messageQuery)
            ->where('role', 'user')
            ->select(['session_id', 'content', 'metadata'])
            ->orderBy('id')
            ->chunk(500, function ($rows) use (
                &$keywordCounts,
                &$queryTotal,
                &$queryUncovered,
                &$queryLowTopScore,
                &$queryLowConfidence,
                &$fallbackByTopic,
                &$volumeByTopic,
                &$queriesNotCovered,
                $sessionFlags,
                $stopwords
            ): void {
                foreach ($rows as $row) {
                    $queryTotal++;
                    $sessionId = (string) $row->session_id;
                    $content = trim((string) $row->content);
                    if ($content === '') {
                        continue;
                    }

                    $flags = $sessionFlags[$sessionId] ?? [
                        'fallback' => false,
                        'top_score' => null,
                        'confidence_score' => null,
                    ];

                    $isLowTop = is_numeric($flags['top_score']) && (float) $flags['top_score'] < 0.45;
                    $isLowConfidence = is_numeric($flags['confidence_score']) && (int) $flags['confidence_score'] < 45;
                    $isUncovered = ($flags['fallback'] ?? false) === true || $isLowTop;

                    if ($isUncovered) {
                        $queryUncovered++;
                        $queriesNotCovered[] = [
                            'session_id' => $sessionId,
                            'query' => Str::limit($content, 160),
                            'fallback' => (bool) ($flags['fallback'] ?? false),
                            'top_score' => $flags['top_score'],
                            'confidence_score' => $flags['confidence_score'],
                        ];
                    }
                    if ($isLowTop) {
                        $queryLowTopScore++;
                    }
                    if ($isLowConfidence) {
                        $queryLowConfidence++;
                    }

                    $topics = data_get($row, 'metadata.topics', []);
                    if (! is_array($topics)) {
                        $topics = [];
                    }
                    foreach ($topics as $topic) {
                        if (! is_string($topic) || trim($topic) === '') {
                            continue;
                        }
                        $topic = trim($topic);
                        $volumeByTopic[$topic] = ($volumeByTopic[$topic] ?? 0) + 1;
                        if (($flags['fallback'] ?? false) === true) {
                            $fallbackByTopic[$topic] = ($fallbackByTopic[$topic] ?? 0) + 1;
                        }
                    }

                    $normalized = Str::of($content)
                        ->lower()
                        ->ascii()
                        ->replaceMatches('/[^a-z0-9\\s]/', ' ')
                        ->squish()
                        ->value();
                    if ($normalized === '') {
                        continue;
                    }

                    $tokens = array_values(array_filter(explode(' ', $normalized), function ($token) use ($stopwords): bool {
                        if (! is_string($token) || strlen($token) < 3) {
                            return false;
                        }
                        return ! in_array($token, $stopwords, true);
                    }));

                    foreach ($tokens as $token) {
                        $keywordCounts[$token] = ($keywordCounts[$token] ?? 0) + 1;
                    }
                }
            });

        arsort($keywordCounts);

        $topicProblematic = [];
        foreach ($volumeByTopic as $topic => $volume) {
            $fallbackCount = $fallbackByTopic[$topic] ?? 0;
            $rate = $volume > 0 ? round(($fallbackCount / $volume) * 100, 1) : 0.0;
            $topicProblematic[] = [
                'topic' => $topic,
                'volume' => $volume,
                'fallback_count' => $fallbackCount,
                'fallback_rate_percent' => $rate,
            ];
        }

        usort($topicProblematic, function (array $a, array $b): int {
            if ($a['fallback_rate_percent'] === $b['fallback_rate_percent']) {
                return $b['volume'] <=> $a['volume'];
            }
            return $b['fallback_rate_percent'] <=> $a['fallback_rate_percent'];
        });

        return [
            'queries_total' => $queryTotal,
            'queries_uncovered_count' => $queryUncovered,
            'queries_uncovered_percent' => $queryTotal > 0 ? round(($queryUncovered / $queryTotal) * 100, 2) : 0.0,
            'queries_low_top_score_count' => $queryLowTopScore,
            'queries_low_confidence_count' => $queryLowConfidence,
            'recurring_keywords' => collect($keywordCounts)
                ->take(12)
                ->map(fn ($count, $keyword) => ['keyword' => $keyword, 'count' => $count])
                ->values()
                ->all(),
            'queries_not_covered' => collect($queriesNotCovered)->take(12)->values()->all(),
            'problematic_topics' => collect($topicProblematic)->take(10)->values()->all(),
        ];
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

    private function countBy(array $values): array
    {
        $counts = [];
        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        arsort($counts);
        return $counts;
    }

    private function average(array $values, int $precision = 2): float
    {
        if ($values === []) {
            return 0.0;
        }

        return round(array_sum($values) / count($values), $precision);
    }

    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;
        $index = max(0, min($index, count($values) - 1));

        return (float) $values[$index];
    }

    private function buildFloatHistogram(array $values, float $min, float $max, int $bins, int $precision = 2): array
    {
        if ($bins <= 0) {
            return [];
        }

        $step = ($max - $min) / $bins;
        $histogram = [];

        for ($i = 0; $i < $bins; $i++) {
            $from = $min + ($i * $step);
            $to = $from + $step;
            $histogram[] = [
                'from' => round($from, $precision),
                'to' => round($to, $precision),
                'count' => 0,
            ];
        }

        foreach ($values as $value) {
            if (! is_numeric($value)) {
                continue;
            }
            $number = (float) $value;
            if ($number < $min || $number > $max) {
                continue;
            }

            $index = $number === $max
                ? $bins - 1
                : (int) floor(($number - $min) / $step);

            if (isset($histogram[$index])) {
                $histogram[$index]['count']++;
            }
        }

        return $histogram;
    }

    private function buildIntHistogram(array $values, int $min, int $max, int $bins): array
    {
        return $this->buildFloatHistogram($values, (float) $min, (float) $max, $bins, 0);
    }

    private function buildAdaptiveHistogram(array $values, int $bins): array
    {
        if ($values === []) {
            return [];
        }

        $min = (float) min($values);
        $max = (float) max($values);

        if ($min === $max) {
            return [[
                'from' => $min,
                'to' => $max,
                'count' => count($values),
            ]];
        }

        return $this->buildFloatHistogram($values, $min, $max, $bins, 0);
    }

    private function estimateCostPerSession(float $tokenInAvg, float $tokenOutAvg, int $sessionsTotal): float
    {
        if ($sessionsTotal <= 0) {
            return 0.0;
        }

        // Stima prudenziale generica: 0.000005 per token totale
        $estimatedPerMessage = ($tokenInAvg + $tokenOutAvg) * 0.000005;

        return round($estimatedPerMessage, 4);
    }
}
