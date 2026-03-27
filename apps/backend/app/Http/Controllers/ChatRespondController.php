<?php

namespace App\Http\Controllers;

use App\Chat\ConversationInputResolver;
use App\Chat\ConversationStateService;
use App\Http\Requests\ChatRespondRequest;
use App\Knowledge\KnowledgeSearchService;
use App\Services\OpenAITextService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class ChatRespondController extends Controller
{
    public function __construct(
        private readonly KnowledgeSearchService $search,
        private readonly OpenAITextService $text,
        private readonly ConversationStateService $conversationState,
        private readonly ConversationInputResolver $conversationInput,
    ) {
    }

    public function __invoke(ChatRespondRequest $request): JsonResponse
    {
        $textLog = Log::channel('text_chat');
        $startedAt = microtime(true);
        $tenantId = $request->string('tenant')->toString() ?: config('tenants.default', 'charlotte');
        $tenantConfig = config("tenants.map.{$tenantId}") ?? config('tenants.map.'.config('tenants.default', 'charlotte'));

        if (! is_array($tenantConfig)) {
            return response()->json([
                'message' => 'Tenant configuration not found',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $pipeline = (string) ($tenantConfig['pipeline'] ?? 'realtime');
        if ($pipeline !== 'text') {
            return response()->json([
                'message' => 'Chat respond endpoint is available only for text pipeline tenants',
                'tenant' => $tenantId,
                'pipeline' => $pipeline,
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $message = trim($request->string('message')->toString());
        $sessionId = $request->string('session_id')->toString() ?: ('txt_'.Str::uuid()->toString());
        $knowledgeTenantId = (string) ($tenantConfig['knowledge_tenant'] ?? $tenantId);
        $pipeline = (string) ($tenantConfig['pipeline'] ?? 'realtime');
        $model = (string) ($tenantConfig['chat_model'] ?? config('models.pipelines.text.default_model', 'gpt-4.1'));
        $policy = (array) config('models.pipelines.text.policy', []);
        $maxHits = max(1, (int) ($policy['max_hits'] ?? 4));
        $conversationState = $this->conversationState->load($sessionId, $tenantId);
        $conversationResolution = $this->conversationInput->resolve($message, $conversationState);
        $resolvedQuery = trim((string) ($conversationResolution['resolved_query'] ?? $message));
        $resolvedActiveTopic = $conversationResolution['resolved_active_topic'] ?? null;

        $this->writeTextChatLog($textLog, 'info', 'Text chat request received', [
            'session_id' => $sessionId,
            'tenant' => $tenantId,
            'knowledge_tenant' => $knowledgeTenantId,
            'pipeline' => $pipeline,
            'model' => $model,
            'message_len' => mb_strlen($message),
            'message_preview' => Str::limit($message, 120),
            'resolved_query' => $resolvedQuery,
            'input_mode' => $conversationResolution['input_mode'] ?? 'self_contained',
            'input_is_elliptic' => (bool) ($conversationResolution['input_is_elliptic'] ?? false),
            'active_topic' => $conversationResolution['active_topic'] ?? null,
            'resolved_active_topic' => $resolvedActiveTopic,
        ]);

        $intent = $this->detectIntent($resolvedQuery);
        $queryTokenCount = $this->keywordTokenCount($resolvedQuery);
        $minTokensForRatio = max(1, (int) config('knowledge.keyword_min_tokens_for_ratio', 2));
        $shortQuery = $queryTokenCount < $minTokensForRatio;
        $searchDiagnostics = $this->search->searchWithDiagnostics($resolvedQuery, $maxHits, $knowledgeTenantId);
        $hits = collect($searchDiagnostics['accepted_hits'] ?? [])->values();
        $diagnosticHits = collect($searchDiagnostics['diagnostic_hits'] ?? [])->values();
        $keywordCandidates = collect($searchDiagnostics['keyword_candidates'] ?? [])->values();
        $fallback = $hits->isEmpty();
        $confidence = $this->computeConfidence($diagnosticHits);
        $confidenceBucket = $this->confidenceBucket($confidence);
        $topScore = $searchDiagnostics['top_score'] ?? $this->topScore($diagnosticHits);
        $semanticLevel = (string) ($searchDiagnostics['semantic_level'] ?? $this->scoreLevelFromTopScore($topScore));
        $ragHitScores = $this->ragHitScores($hits);
        $ragHitRefs = $this->ragHitRefs($hits);
        $diagnosticHitScores = $this->ragHitScores($diagnosticHits);
        $diagnosticHitRefs = $this->ragHitRefs($diagnosticHits);
        $acceptedHitsSummary = $this->compactHitSummary($ragHitRefs, $ragHitScores);
        $diagnosticHitsSummary = $this->compactHitSummary($diagnosticHitRefs, $diagnosticHitScores);
        $keywordCandidatesSummary = $this->compactKeywordCandidates($keywordCandidates->all());
        $contradiction = $this->analyzeContradiction($hits, $resolvedQuery);
        $contradictionFlag = (bool) ($contradiction['flag'] ?? false);
        $contradictionType = (string) ($contradiction['type'] ?? 'none');
        $contradictionTopic = (string) ($contradiction['topic'] ?? 'none');
        $contradictionEvidenceCount = (int) ($contradiction['evidence_count'] ?? 0);
        $policyPath = $this->resolvePolicyPath(
            hitCount: $hits->count(),
            diagnosticHitCount: $diagnosticHits->count(),
            confidenceBucket: $confidenceBucket,
            semanticLevel: $semanticLevel,
            shortQuery: $shortQuery,
            contradictionFlag: $contradictionFlag,
            policy: $policy,
        );

        $sourceTitles = $hits
            ->pluck('title')
            ->filter(fn ($title) => is_string($title) && $title !== '')
            ->take(5)
            ->values()
            ->all();
        $topKeywordCandidate = $keywordCandidates
            ->sort(function (array $a, array $b): int {
                $ratioA = is_numeric($a['match_ratio'] ?? null) ? (float) $a['match_ratio'] : -1.0;
                $ratioB = is_numeric($b['match_ratio'] ?? null) ? (float) $b['match_ratio'] : -1.0;
                if ($ratioA !== $ratioB) {
                    return $ratioA < $ratioB ? 1 : -1;
                }

                $matchedA = is_numeric($a['matched_tokens'] ?? null) ? (int) $a['matched_tokens'] : -1;
                $matchedB = is_numeric($b['matched_tokens'] ?? null) ? (int) $b['matched_tokens'] : -1;
                if ($matchedA !== $matchedB) {
                    return $matchedA < $matchedB ? 1 : -1;
                }

                $totalA = is_numeric($a['total_tokens'] ?? null) ? (int) $a['total_tokens'] : PHP_INT_MAX;
                $totalB = is_numeric($b['total_tokens'] ?? null) ? (int) $b['total_tokens'] : PHP_INT_MAX;
                if ($totalA !== $totalB) {
                    return $totalA < $totalB ? -1 : 1;
                }

                return 0;
            })
            ->first();
        $keywordTopMatchedTokens = is_array($topKeywordCandidate)
            ? data_get($topKeywordCandidate, 'matched_tokens')
            : null;
        $keywordTopTotalTokens = is_array($topKeywordCandidate)
            ? data_get($topKeywordCandidate, 'total_tokens')
            : null;
        $keywordTopMatchRatio = is_array($topKeywordCandidate)
            ? data_get($topKeywordCandidate, 'match_ratio')
            : null;

        $this->writeTextChatLog($textLog, 'info', 'Text chat RAG resolved', [
            'session_id' => $sessionId,
            'tenant' => $tenantId,
            'rag_hits' => $hits->count(),
            'query_token_count' => $queryTokenCount,
            'short_query' => $shortQuery,
            'fallback' => $fallback,
            'intent' => $intent,
            'confidence_score' => $confidence,
            'confidence_bucket' => $confidenceBucket,
            'top_score' => $topScore,
            'semantic_level' => $semanticLevel,
            'accepted_hits' => $acceptedHitsSummary,
            'diagnostic_hits' => $diagnosticHitsSummary,
            'keyword_candidates' => $keywordCandidatesSummary,
            'keyword_top_matched_tokens' => $keywordTopMatchedTokens,
            'keyword_top_total_tokens' => $keywordTopTotalTokens,
            'keyword_top_match_ratio' => $keywordTopMatchRatio,
            'policy_path' => $policyPath,
            'original_input' => $message,
            'resolved_query' => $resolvedQuery,
            'input_mode' => $conversationResolution['input_mode'] ?? 'self_contained',
            'input_is_elliptic' => (bool) ($conversationResolution['input_is_elliptic'] ?? false),
            'context_source' => $conversationResolution['context_source'] ?? null,
            'active_topic' => $conversationResolution['active_topic'] ?? null,
            'resolved_active_topic' => $resolvedActiveTopic,
            'contradiction_flag' => $contradictionFlag,
            'contradiction_type' => $contradictionType,
            'contradiction_topic' => $contradictionTopic,
            'contradiction_evidence_count' => $contradictionEvidenceCount,
            'sources' => $sourceTitles,
        ]);

        $supportEmail = (string) ($tenantConfig['support_email'] ?? '');
        $fallbackMessage = (string) ($tenantConfig['fallback_message'] ?? '');
        if ($policyPath === 'strict_fallback') {
            $reply = $fallbackMessage !== ''
                ? $fallbackMessage
                : 'Al momento non ho dati ufficiali sufficienti per rispondere con precisione.';

            $contact = $supportEmail !== ''
                ? " Per approfondire puoi contattare {$supportEmail}."
                : ' Per approfondire puoi richiedere un contatto diretto.';

            $finalReply = trim($reply.$contact);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->writeTextChatLog($textLog, 'info', 'Text chat response ready', [
                'session_id' => $sessionId,
                'tenant' => $tenantId,
                'knowledge_tenant' => $knowledgeTenantId,
                'pipeline' => $pipeline,
                'model' => null,
                'fallback' => true,
                'rag_hits' => 0,
                'query_token_count' => $queryTokenCount,
                'short_query' => $shortQuery,
                'intent' => $intent,
                'confidence_score' => $confidence,
                'confidence_bucket' => $confidenceBucket,
                'top_score' => $topScore,
                'semantic_level' => $semanticLevel,
                'accepted_hits_count' => 0,
                'diagnostic_hits_count' => $diagnosticHits->count(),
                'keyword_candidates_count' => $keywordCandidates->count(),
                'policy_path' => $policyPath,
                'contradiction_flag' => $contradictionFlag,
                'contradiction_type' => $contradictionType,
                'contradiction_topic' => $contradictionTopic,
                'contradiction_evidence_count' => $contradictionEvidenceCount,
                'latency_ms' => $latencyMs,
                'reply_len' => mb_strlen($finalReply),
                'reply_preview' => Str::limit($finalReply, 140),
                'web_search_enabled' => false,
                'web_sources_count' => 0,
                'web_sources' => [],
            ]);

            $this->persistConversationTurn(
                sessionId: $sessionId,
                tenantId: $tenantId,
                originalInput: $message,
                resolution: $conversationResolution,
                replyText: $finalReply,
            );

            return response()->json([
                'session_id' => $sessionId,
                'tenant' => [
                    'id' => $tenantId,
                    'name' => $tenantConfig['name'] ?? $tenantId,
                    'pipeline' => $pipeline,
                    'knowledge_tenant' => $knowledgeTenantId,
                ],
                'model' => null,
                'intent' => $intent,
                'confidence_score' => $confidence,
                'confidence_bucket' => $confidenceBucket,
                'policy_path' => $policyPath,
                'contradiction_flag' => $contradictionFlag,
                'contradiction_type' => $contradictionType,
                'contradiction_topic' => $contradictionTopic,
                'contradiction_evidence_count' => $contradictionEvidenceCount,
                'fallback' => true,
                'rag_hits' => 0,
                'query_token_count' => $queryTokenCount,
                'short_query' => $shortQuery,
                'original_input' => $message,
                'resolved_query' => $resolvedQuery,
                'input_mode' => $conversationResolution['input_mode'] ?? 'self_contained',
                'input_is_elliptic' => (bool) ($conversationResolution['input_is_elliptic'] ?? false),
                'active_topic' => $conversationResolution['active_topic'] ?? null,
                'resolved_active_topic' => $resolvedActiveTopic,
                'top_score' => $topScore,
                'semantic_level' => $semanticLevel,
                'rag_hit_scores' => [],
                'rag_hit_refs' => [],
                'diagnostic_hit_scores' => $diagnosticHitScores,
                'diagnostic_hit_refs' => $diagnosticHitRefs,
                'keyword_candidates_count' => $keywordCandidates->count(),
                'keyword_candidates' => $keywordCandidates->all(),
                'latency_ms' => $latencyMs,
                'reply_len' => mb_strlen($finalReply),
                'reply' => $finalReply,
                'web_search' => [
                    'enabled' => false,
                    'allowed_domains' => [],
                    'sources' => [],
                ],
                'sources' => [],
            ]);
        }

        if ($policyPath === 'soft_fallback') {
            $relatedTopics = $hits
                ->pluck('title')
                ->filter(fn ($title) => is_string($title) && trim($title) !== '')
                ->take(2)
                ->values()
                ->all();

            $topicsText = $relatedTopics !== []
                ? ' Ho trovato contenuti correlati su: '.implode(', ', $relatedTopics).'.'
                : '';

            $clarification = 'Se mi dici il servizio o il contesto specifico (es. accredito, totem, badge, ECM), ti rispondo in modo preciso.';
            $contact = $supportEmail !== ''
                ? " In alternativa puoi contattare {$supportEmail}."
                : '';
            $base = $fallbackMessage !== ''
                ? $fallbackMessage
                : 'Ho trovato riferimenti parziali ma non abbastanza solidi per una risposta completa.';

            $finalReply = trim($base.$topicsText.' '.$clarification.$contact);
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

            $this->writeTextChatLog($textLog, 'info', 'Text chat response ready', [
                'session_id' => $sessionId,
                'tenant' => $tenantId,
                'knowledge_tenant' => $knowledgeTenantId,
                'pipeline' => $pipeline,
                'model' => null,
                'fallback' => true,
                'rag_hits' => $hits->count(),
                'query_token_count' => $queryTokenCount,
                'short_query' => $shortQuery,
                'intent' => $intent,
                'confidence_score' => $confidence,
                'confidence_bucket' => $confidenceBucket,
                'top_score' => $topScore,
                'semantic_level' => $semanticLevel,
                'accepted_hits_count' => $hits->count(),
                'diagnostic_hits_count' => $diagnosticHits->count(),
                'keyword_candidates_count' => $keywordCandidates->count(),
                'policy_path' => $policyPath,
                'contradiction_flag' => $contradictionFlag,
                'contradiction_type' => $contradictionType,
                'contradiction_topic' => $contradictionTopic,
                'contradiction_evidence_count' => $contradictionEvidenceCount,
                'latency_ms' => $latencyMs,
                'reply_len' => mb_strlen($finalReply),
                'reply_preview' => Str::limit($finalReply, 140),
                'web_search_enabled' => false,
                'web_sources_count' => 0,
                'web_sources' => [],
            ]);

            $this->persistConversationTurn(
                sessionId: $sessionId,
                tenantId: $tenantId,
                originalInput: $message,
                resolution: $conversationResolution,
                replyText: $finalReply,
            );

            return response()->json([
                'session_id' => $sessionId,
                'tenant' => [
                    'id' => $tenantId,
                    'name' => $tenantConfig['name'] ?? $tenantId,
                    'pipeline' => $pipeline,
                    'knowledge_tenant' => $knowledgeTenantId,
                ],
                'model' => null,
                'intent' => $intent,
                'confidence_score' => $confidence,
                'confidence_bucket' => $confidenceBucket,
                'policy_path' => $policyPath,
                'contradiction_flag' => $contradictionFlag,
                'contradiction_type' => $contradictionType,
                'contradiction_topic' => $contradictionTopic,
                'contradiction_evidence_count' => $contradictionEvidenceCount,
                'fallback' => true,
                'rag_hits' => $hits->count(),
                'query_token_count' => $queryTokenCount,
                'short_query' => $shortQuery,
                'original_input' => $message,
                'resolved_query' => $resolvedQuery,
                'input_mode' => $conversationResolution['input_mode'] ?? 'self_contained',
                'input_is_elliptic' => (bool) ($conversationResolution['input_is_elliptic'] ?? false),
                'active_topic' => $conversationResolution['active_topic'] ?? null,
                'resolved_active_topic' => $resolvedActiveTopic,
                'top_score' => $topScore,
                'semantic_level' => $semanticLevel,
                'rag_hit_scores' => $ragHitScores,
                'rag_hit_refs' => $ragHitRefs,
                'diagnostic_hit_scores' => $diagnosticHitScores,
                'diagnostic_hit_refs' => $diagnosticHitRefs,
                'keyword_candidates_count' => $keywordCandidates->count(),
                'keyword_candidates' => $keywordCandidates->all(),
                'latency_ms' => $latencyMs,
                'reply_len' => mb_strlen($finalReply),
                'reply' => $finalReply,
                'web_search' => [
                    'enabled' => false,
                    'allowed_domains' => [],
                    'sources' => [],
                ],
                'sources' => $hits->map(fn (array $hit): array => [
                    'id' => $hit['id'] ?? null,
                    'title' => $hit['title'] ?? null,
                    'score' => $hit['score'] ?? null,
                ])->values(),
            ]);
        }

        $prompt = $this->buildPrompt(
            query: $message,
            resolvedQuery: $resolvedQuery,
            conversationTurns: $conversationState['turns'] ?? [],
            hits: $hits->toArray(),
            supportEmail: $tenantConfig['support_email'] ?? null,
            fallbackMessage: $tenantConfig['fallback_message'] ?? null,
            policyPath: $policyPath,
            contradictionFlag: $contradictionFlag,
            intent: $intent,
        );

        $temperature = (float) config('models.pipelines.text.temperature', 0.3);
        $maxOutputTokens = (int) config('models.pipelines.text.max_output_tokens', 800);
        $webSearch = (array) config('models.pipelines.text.web_search', []);
        $webSearchConfig = $this->resolveWebSearchConfig($intent, $webSearch, $policy);

        try {
            $result = $this->text->respond(
                model: $model,
                instructions: (string) ($tenantConfig['instructions'] ?? ''),
                input: $prompt,
                temperature: $temperature,
                maxOutputTokens: $maxOutputTokens,
                webSearch: $webSearchConfig,
            );
        } catch (RequestException $exception) {
            $message = $exception->response?->json('error.message') ?? $exception->getMessage();

            $this->writeTextChatLog($textLog, 'warning', 'Text chat model request failed', [
                'session_id' => $sessionId,
                'tenant' => $tenantId,
                'model' => $model,
                'error' => $message,
            ]);

            return response()->json([
                'message' => 'Unable to get text response from OpenAI',
                'details' => $message,
            ], Response::HTTP_BAD_GATEWAY);
        }

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $replyText = trim((string) ($result['text'] ?? ''));

        $this->writeTextChatLog($textLog, 'info', 'Text chat response ready', [
            'session_id' => $sessionId,
            'tenant' => $tenantId,
            'knowledge_tenant' => $knowledgeTenantId,
            'pipeline' => $pipeline,
            'model' => $model,
            'fallback' => $fallback,
            'rag_hits' => $hits->count(),
            'query_token_count' => $queryTokenCount,
            'short_query' => $shortQuery,
            'intent' => $intent,
            'confidence_score' => $confidence,
            'confidence_bucket' => $confidenceBucket,
            'top_score' => $topScore,
            'semantic_level' => $semanticLevel,
            'accepted_hits_count' => $hits->count(),
            'diagnostic_hits_count' => $diagnosticHits->count(),
            'keyword_candidates_count' => $keywordCandidates->count(),
            'policy_path' => $policyPath,
            'original_input' => $message,
            'resolved_query' => $resolvedQuery,
            'input_mode' => $conversationResolution['input_mode'] ?? 'self_contained',
            'input_is_elliptic' => (bool) ($conversationResolution['input_is_elliptic'] ?? false),
            'context_source' => $conversationResolution['context_source'] ?? null,
            'active_topic' => $conversationResolution['active_topic'] ?? null,
            'resolved_active_topic' => $resolvedActiveTopic,
            'contradiction_flag' => $contradictionFlag,
            'contradiction_type' => $contradictionType,
            'contradiction_topic' => $contradictionTopic,
            'contradiction_evidence_count' => $contradictionEvidenceCount,
            'latency_ms' => $latencyMs,
            'reply_len' => mb_strlen($replyText),
            'reply_preview' => Str::limit($replyText, 140),
            'web_search_enabled' => (bool) ($webSearchConfig['enabled'] ?? false),
            'web_sources_count' => count($result['sources'] ?? []),
            'web_sources' => collect($result['sources'] ?? [])->pluck('url')->filter()->take(5)->values()->all(),
        ]);

        $this->persistConversationTurn(
            sessionId: $sessionId,
            tenantId: $tenantId,
            originalInput: $message,
            resolution: $conversationResolution,
            replyText: $replyText,
        );

        return response()->json([
            'session_id' => $sessionId,
            'tenant' => [
                'id' => $tenantId,
                'name' => $tenantConfig['name'] ?? $tenantId,
                'pipeline' => $pipeline,
                'knowledge_tenant' => $knowledgeTenantId,
            ],
            'model' => $model,
            'intent' => $intent,
            'confidence_score' => $confidence,
            'confidence_bucket' => $confidenceBucket,
            'policy_path' => $policyPath,
            'contradiction_flag' => $contradictionFlag,
            'contradiction_type' => $contradictionType,
            'contradiction_topic' => $contradictionTopic,
            'contradiction_evidence_count' => $contradictionEvidenceCount,
            'fallback' => $fallback,
            'rag_hits' => $hits->count(),
            'query_token_count' => $queryTokenCount,
            'short_query' => $shortQuery,
            'original_input' => $message,
            'resolved_query' => $resolvedQuery,
            'input_mode' => $conversationResolution['input_mode'] ?? 'self_contained',
            'input_is_elliptic' => (bool) ($conversationResolution['input_is_elliptic'] ?? false),
            'active_topic' => $conversationResolution['active_topic'] ?? null,
            'resolved_active_topic' => $resolvedActiveTopic,
            'top_score' => $topScore,
            'semantic_level' => $semanticLevel,
            'rag_hit_scores' => $ragHitScores,
            'rag_hit_refs' => $ragHitRefs,
            'diagnostic_hit_scores' => $diagnosticHitScores,
            'diagnostic_hit_refs' => $diagnosticHitRefs,
            'keyword_candidates_count' => $keywordCandidates->count(),
            'keyword_candidates' => $keywordCandidates->all(),
            'latency_ms' => $latencyMs,
            'reply_len' => mb_strlen($replyText),
            'reply' => $result['text'],
            'web_search' => [
                'enabled' => (bool) ($webSearchConfig['enabled'] ?? false),
                'allowed_domains' => array_values($webSearchConfig['allowed_domains'] ?? []),
                'sources' => $result['sources'] ?? [],
            ],
            'sources' => $hits->map(fn (array $hit): array => [
                'id' => $hit['id'] ?? null,
                'title' => $hit['title'] ?? null,
                'score' => $hit['score'] ?? null,
            ])->values(),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $hits
     */
    private function buildPrompt(
        string $query,
        string $resolvedQuery,
        array $conversationTurns,
        array $hits,
        ?string $supportEmail,
        ?string $fallbackMessage,
        string $policyPath,
        bool $contradictionFlag,
        string $intent,
    ): string
    {
        $conversationContext = collect($conversationTurns)
            ->take(-3)
            ->map(function (array $turn): string {
                $role = ($turn['role'] ?? 'user') === 'assistant' ? 'Assistant' : 'Utente';
                $message = trim((string) ($turn['message'] ?? ''));

                return $message !== '' ? "{$role}: {$message}" : '';
            })
            ->filter()
            ->implode("\n");
        $resolvedQueryText = trim($resolvedQuery) !== '' && trim($resolvedQuery) !== trim($query)
            ? "Query risolta dal sistema: {$resolvedQuery}\n"
            : '';
        $historyText = $conversationContext !== ''
            ? "Contesto conversazionale recente:\n{$conversationContext}\n\n"
            : '';

        if ($policyPath === 'partial_answer_clarify') {
            $context = collect($hits)
                ->take(2)
                ->map(function (array $hit): string {
                    $score = isset($hit['score']) ? ' (score '.$hit['score'].')' : '';
                    $title = (string) ($hit['title'] ?? 'Knowledge');
                    $excerpt = (string) ($hit['excerpt'] ?? '');

                    return "Fonte: {$title}{$score}\n{$excerpt}";
                })
                ->implode("\n\n");

            $contextText = $context !== ''
                ? "Contesto disponibile:\n{$context}"
                : "Contesto disponibile: limitato.";

            return "Domanda utente: {$query}\n{$resolvedQueryText}\n{$historyText}La richiesta è troppo breve o ambigua. Fornisci una risposta iniziale prudente (massimo 2 frasi) e poi fai UNA domanda di chiarimento molto mirata per disambiguare l'intento. Non inventare dettagli non presenti nelle fonti.\n\n{$contextText}";
        }

        if ($hits === []) {
            $contact = $supportEmail
                ? "Invita a contattare {$supportEmail} per approfondimenti."
                : 'Invita a richiedere un contatto per ulteriori dettagli.';

            $fallback = $fallbackMessage
                ? "Puoi usare questo messaggio generale: \"{$fallbackMessage}\"."
                : 'Spiega che l\'informazione non è presente nei documenti ufficiali.';

            return "Domanda utente: {$query}\n{$resolvedQueryText}\n{$historyText}Contesto ufficiale: nessuna fonte affidabile trovata. {$fallback} {$contact}";
        }

        $context = collect($hits)
            ->map(function (array $hit): string {
                $score = isset($hit['score']) ? ' (score '.$hit['score'].')' : '';
                $title = (string) ($hit['title'] ?? 'Knowledge');
                $excerpt = (string) ($hit['excerpt'] ?? '');

                return "Fonte: {$title}{$score}\n{$excerpt}";
            })
            ->implode("\n\n");

        $policyInstructions = match ($policyPath) {
            'partial_answer' => 'Fornisci una risposta generale usando SOLO il contesto ufficiale disponibile e aggiungi UNA domanda mirata per chiarire il bisogno dell’utente.',
            default => 'Fornisci una risposta completa e precisa usando SOLO il contesto ufficiale disponibile.',
        };

        $contradictionInstruction = $contradictionFlag
            ? "Le fonti mostrano possibili incongruenze. Dai la versione più prudente e chiedi conferma operativa."
            : '';

        $webIntentInstruction = $intent === 'showcase_web'
            ? "Se utile, puoi integrare con web search SOLO per esempi visivi/eventi passati su domini consentiti."
            : "Non usare web search per integrare dati core dei servizi, usa prioritariamente il contesto ufficiale RAG.";

        return "Domanda utente: {$query}\n{$resolvedQueryText}\n{$historyText}{$policyInstructions}\n{$contradictionInstruction}\n{$webIntentInstruction}\n\nUsa solo queste fonti ufficiali per la risposta:\n{$context}";
    }

    /**
     * @param array<string, mixed> $resolution
     */
    private function persistConversationTurn(
        string $sessionId,
        string $tenantId,
        string $originalInput,
        array $resolution,
        string $replyText,
    ): void {
        $resolvedQuery = trim((string) ($resolution['resolved_query'] ?? $originalInput));
        $resolvedActiveTopic = $resolution['resolved_active_topic'] ?? null;

        $this->conversationState->appendTurn(
            $sessionId,
            $tenantId,
            'user',
            $originalInput,
            [
                'active_topic' => $resolvedActiveTopic,
                'last_resolved_query' => $resolvedQuery,
            ]
        );

        $this->conversationState->appendTurn(
            $sessionId,
            $tenantId,
            'assistant',
            $replyText,
            [
                'active_topic' => $resolvedActiveTopic,
                'last_resolved_query' => $resolvedQuery,
                'last_bot_question' => $this->extractLastQuestion($replyText),
            ]
        );
    }

    private function extractLastQuestion(string $replyText): ?string
    {
        $replyText = trim($replyText);
        if ($replyText === '' || ! str_contains($replyText, '?')) {
            return null;
        }

        preg_match_all('/([^?]*\?)/u', $replyText, $matches);
        $questions = collect($matches[0] ?? [])
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values();

        return $questions->isNotEmpty() ? $questions->last() : null;
    }

    private function detectIntent(string $query): string
    {
        $normalized = Str::of($query)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\\s]/', ' ')
            ->squish()
            ->value();

        $showcaseHints = collect(
            config('models.pipelines.text.policy.intent_keywords.showcase_web', [])
        )->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => Str::of((string) $value)->lower()->ascii()->value())
            ->values()
            ->all();

        foreach ($showcaseHints as $hint) {
            if (str_contains($normalized, $hint)) {
                return 'showcase_web';
            }
        }

        $pricingHints = collect(
            config('models.pipelines.text.policy.intent_keywords.pricing_estimate', [])
        )->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => Str::of((string) $value)->lower()->ascii()->value())
            ->values()
            ->all();

        foreach ($pricingHints as $hint) {
            if (str_contains($normalized, $hint)) {
                return 'pricing_estimate';
            }
        }

        if (str_contains($normalized, 'costo') || str_contains($normalized, 'preventivo') || str_contains($normalized, 'prezzo')) {
            return 'pricing_estimate';
        }

        return 'core_info';
    }

    private function resolveWebSearchConfig(string $intent, array $webSearch, array $policy): array
    {
        if (($webSearch['enabled'] ?? false) !== true) {
            return array_merge($webSearch, [
                'enabled' => false,
                'allowed_domains' => [],
            ]);
        }

        $allowedIntents = collect($policy['web_search_intents'] ?? ['showcase_web'])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->values();

        $alwaysDomains = collect($webSearch['always_allowed_domains'] ?? [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->values();

        $showcaseDomains = collect($webSearch['showcase_allowed_domains'] ?? [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn ($value) => trim((string) $value))
            ->values();

        $domains = $alwaysDomains;
        if ($allowedIntents->contains($intent)) {
            $domains = $domains->concat($showcaseDomains);
        }

        $domains = $domains->unique()->values()->all();

        return array_merge($webSearch, [
            'enabled' => $domains !== [],
            'allowed_domains' => $domains,
        ]);
    }

    /**
     * @param Collection<int, array<string, mixed>> $hits
     */
    private function computeConfidence(Collection $hits): int
    {
        $formula = (array) config('models.pipelines.text.policy.confidence_formula', []);
        $topN = max(1, (int) ($formula['top_n'] ?? 4));
        $alpha = (float) ($formula['alpha'] ?? 0.60);
        $beta = (float) ($formula['beta'] ?? 0.40);
        $rangeMax = max(0.000001, (float) ($formula['range_max'] ?? 1.0));

        // Normalizza i pesi in modo che alpha + beta = 1 anche se configurati male.
        $weightSum = $alpha + $beta;
        if ($weightSum <= 0) {
            $alpha = 0.60;
            $beta = 0.40;
            $weightSum = 1.0;
        }
        $alpha /= $weightSum;
        $beta /= $weightSum;

        $scores = $hits
            ->pluck('score')
            ->filter(fn ($score) => is_numeric($score))
            ->map(fn ($score) => max(0.0, min(1.0, (float) $score)))
            ->sortDesc()
            ->take($topN)
            ->values();

        if ($scores->isEmpty()) {
            return 0;
        }

        $c1 = (float) $scores->first();
        $mu = (float) ($scores->avg() ?? 0.0);
        $count = $scores->count();

        // Deviazione standard popolazione sui top-n score.
        $variance = $scores
            ->map(fn (float $score): float => ($score - $mu) ** 2)
            ->sum() / max(1, $count);
        $sigma = sqrt(max(0.0, $variance));
        $sigmaNormalized = max(0.0, min(1.0, $sigma / $rangeMax));

        // Confidence su scala 0..1, poi convertita 0..100.
        $confidenceRaw = ($alpha * $c1 + $beta * $mu) * (1.0 - $sigmaNormalized);

        return (int) round(max(0.0, min(1.0, $confidenceRaw)) * 100);
    }

    private function confidenceBucket(int $confidence): string
    {
        $high = (int) config('models.pipelines.text.policy.confidence_thresholds.high', 75);
        $medium = (int) config('models.pipelines.text.policy.confidence_thresholds.medium', 45);

        if ($confidence >= $high) {
            return 'high';
        }
        if ($confidence >= $medium) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param Collection<int, array<string, mixed>> $hits
     */
    private function topScore(Collection $hits): ?float
    {
        $scores = $hits->pluck('score')->filter(fn ($score) => is_numeric($score))->map(fn ($score) => (float) $score);
        if ($scores->isEmpty()) {
            return null;
        }

        return round((float) $scores->max(), 3);
    }

    /**
     * @param Collection<int, array<string, mixed>> $hits
     * @return array<int, float>
     */
    private function ragHitScores(Collection $hits): array
    {
        return $hits
            ->pluck('score')
            ->filter(fn ($score) => is_numeric($score))
            ->map(fn ($score) => round((float) $score, 3))
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, array<string, mixed>> $hits
     * @return array<int, array{id: mixed, title: string, score: float|null}>
     */
    private function ragHitRefs(Collection $hits): array
    {
        return $hits
            ->map(fn (array $hit): array => [
                'id' => $hit['id'] ?? null,
                'title' => (string) ($hit['title'] ?? 'Knowledge'),
                'score' => isset($hit['score']) && is_numeric($hit['score'])
                    ? round((float) $hit['score'], 3)
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param Collection<int, array<string, mixed>> $hits
     * @return array{flag: bool, type: string, topic: string, evidence_count: int}
     */
    private function analyzeContradiction(Collection $hits, string $query): array
    {
        $minEvidence = max(2, (int) config('models.pipelines.text.policy.contradiction.min_evidence', 2));
        $priceRelativeDelta = max(0.0, min(1.0, (float) config('models.pipelines.text.policy.contradiction.price_relative_delta', 0.20)));
        $normalizedQuery = Str::of($query)->lower()->ascii()->squish()->value();
        $queryPriceTerms = ['costo', 'costi', 'prezzo', 'prezzi', 'euro', 'preventivo', 'tariffa', 'stima', 'quanto costa'];
        $queryAvailabilityTerms = ['disponibile', 'non disponibile', 'offline', 'online', 'attivo', 'supportato', 'consentito'];
        $queryAsksPrice = collect($queryPriceTerms)->contains(
            fn (string $term): bool => str_contains($normalizedQuery, $term)
        );
        $queryAsksAvailability = collect($queryAvailabilityTerms)->contains(
            fn (string $term): bool => str_contains($normalizedQuery, $term)
        );

        if ($hits->count() < $minEvidence) {
            return ['flag' => false, 'type' => 'none', 'topic' => 'none', 'evidence_count' => 0];
        }

        $texts = $hits
            ->pluck('excerpt')
            ->filter(fn ($excerpt) => is_string($excerpt) && trim($excerpt) !== '')
            ->map(fn ($excerpt) => Str::of((string) $excerpt)->lower()->ascii()->value())
            ->values();

        if ($texts->count() < $minEvidence) {
            return ['flag' => false, 'type' => 'none', 'topic' => 'none', 'evidence_count' => 0];
        }

        $topicLexicon = [
            'accredito' => ['accredito', 'checkin', 'check in', 'registrazione'],
            'badge' => ['badge', 'cartellino'],
            'totem' => ['totem', 'kiosk', 'self registration', 'self-registration'],
            'ecm' => ['ecm', 'crediti', 'rfid', 'uhf', 'presenze'],
            'votazioni' => ['votazioni', 'televoto', 'e-vote', 'evote', 'elezioni'],
            'app' => ['app', 'applicazione', 'mobile app'],
            'streaming' => ['streaming', 'webinar', 'videoconferenza'],
            'costi' => ['costo', 'costi', 'prezzo', 'prezzi', 'euro', 'preventivo', 'tariffa', 'stima'],
            'servizi' => ['servizi', 'soluzioni', 'offerta'],
        ];

        $positivePatterns = [
            '/\bsi\b/u',
            '/\bdisponibile\b/u',
            '/\battivo\b/u',
            '/\bsupportato\b/u',
            '/\bincluso\b/u',
            '/\bprevisto\b/u',
            '/\bconsentito\b/u',
        ];
        $negativePatterns = [
            '/\bno\b/u',
            '/\bnon\s+disponibile\b/u',
            '/\bnon\s+attivo\b/u',
            '/\bnon\s+supportato\b/u',
            '/\bnon\s+incluso\b/u',
            '/\bnon\s+previsto\b/u',
            '/\bnon\s+consentito\b/u',
            '/\bnon\b/u',
        ];

        $availabilityEvidence = collect();
        $priceEvidence = collect();

        foreach ($texts as $text) {
            $topics = collect($topicLexicon)
                ->filter(function (array $terms) use ($text): bool {
                    foreach ($terms as $term) {
                        if (str_contains($text, Str::of($term)->lower()->ascii()->value())) {
                            return true;
                        }
                    }

                    return false;
                })
                ->keys()
                ->values();

            if ($topics->isEmpty()) {
                $topics = collect(['generic']);
            }

            $hasPositive = collect($positivePatterns)->contains(fn (string $pattern): bool => preg_match($pattern, $text) === 1);
            $hasNegative = collect($negativePatterns)->contains(fn (string $pattern): bool => preg_match($pattern, $text) === 1);

            if ($hasPositive || $hasNegative) {
                foreach ($topics as $topic) {
                    $availabilityEvidence->push([
                        'topic' => (string) $topic,
                        'positive' => $hasPositive,
                        'negative' => $hasNegative,
                    ]);
                }
            }

            if (preg_match_all('/\b(\d{2,5})(?:[.,]\d{1,2})?\s?(euro|€)\b/u', $text, $matches) > 0) {
                $values = collect($matches[1] ?? [])
                    ->map(fn (string $raw): float => (float) str_replace(',', '.', $raw))
                    ->filter(fn (float $value): bool => $value > 0)
                    ->values();

                if ($values->isNotEmpty()) {
                    foreach ($topics as $topic) {
                        $priceEvidence->push([
                            'topic' => (string) $topic,
                            'values' => $values->all(),
                        ]);
                    }
                }
            }
        }

        $availabilityByTopic = $availabilityEvidence->groupBy('topic');
        foreach ($availabilityByTopic as $topic => $entries) {
            $hasPositive = collect($entries)->contains(fn (array $entry): bool => (bool) ($entry['positive'] ?? false));
            $hasNegative = collect($entries)->contains(fn (array $entry): bool => (bool) ($entry['negative'] ?? false));
            $evidenceCount = collect($entries)->count();

            if ($hasPositive && $hasNegative && $evidenceCount >= $minEvidence) {
                return [
                    'flag' => true,
                    'type' => 'availability_conflict',
                    'topic' => (string) $topic,
                    'evidence_count' => $evidenceCount,
                ];
            }
        }

        // Se la query è chiaramente di disponibilità, evita di promuovere mismatch prezzi
        // come contraddizione dominante.
        if ($queryAsksPrice || ! $queryAsksAvailability) {
            $pricesByTopic = $priceEvidence->groupBy('topic');
            foreach ($pricesByTopic as $topic => $entries) {
                $values = collect($entries)
                    ->flatMap(fn (array $entry): array => is_array($entry['values'] ?? null) ? $entry['values'] : [])
                    ->map(fn ($value): float => (float) $value)
                    ->filter(fn (float $value): bool => $value > 0)
                    ->values();

                if ($values->count() < $minEvidence) {
                    continue;
                }

                $minValue = (float) $values->min();
                $maxValue = (float) $values->max();
                if ($minValue <= 0.0) {
                    continue;
                }

                $relativeDelta = ($maxValue - $minValue) / $minValue;
                if ($relativeDelta >= $priceRelativeDelta) {
                    return [
                        'flag' => true,
                        'type' => 'price_mismatch',
                        'topic' => (string) $topic,
                        'evidence_count' => $values->count(),
                    ];
                }
            }
        }

        return ['flag' => false, 'type' => 'none', 'topic' => 'none', 'evidence_count' => 0];
    }

    private function resolvePolicyPath(
        int $hitCount,
        int $diagnosticHitCount,
        string $confidenceBucket,
        string $semanticLevel,
        bool $shortQuery,
        bool $contradictionFlag,
        array $policy
    ): string
    {
        $strictFallbackOnZero = (bool) ($policy['strict_fallback_on_zero_hits'] ?? true);
        $clarifyOnZeroWithDiagnostics = (bool) ($policy['clarify_on_zero_hits_with_diagnostics'] ?? true);
        $fullAnswerRequiresHits = max(1, (int) ($policy['full_answer_requires_hits'] ?? 4));

        if ($shortQuery) {
            $shortQueryPolicy = (array) ($policy['short_query'] ?? []);
            $minConfidenceBucket = (string) ($shortQueryPolicy['min_confidence_bucket'] ?? 'medium');
            $minSemanticLevel = (string) ($shortQueryPolicy['min_semantic_level'] ?? 'medium');

            $shortQueryCanProceed =
                $hitCount > 0
                && $this->bucketAtLeast($confidenceBucket, $minConfidenceBucket)
                && $this->bucketAtLeast($semanticLevel, $minSemanticLevel);

            if (! $shortQueryCanProceed) {
                return 'partial_answer_clarify';
            }
        }

        if ($strictFallbackOnZero && $hitCount === 0) {
            if ($diagnosticHitCount > 0) {
                if ($clarifyOnZeroWithDiagnostics) {
                    return 'partial_answer_clarify';
                }
                return 'soft_fallback';
            }
            return 'strict_fallback';
        }

        if ($contradictionFlag) {
            return 'partial_answer';
        }

        if ($hitCount < $fullAnswerRequiresHits) {
            return 'partial_answer';
        }

        return $semanticLevel === 'high' && $confidenceBucket === 'high'
            ? 'full_answer'
            : 'partial_answer';
    }

    private function bucketAtLeast(string $value, string $minimum): bool
    {
        $rank = [
            'low' => 0,
            'medium' => 1,
            'high' => 2,
        ];

        $valueRank = $rank[strtolower($value)] ?? 0;
        $minimumRank = $rank[strtolower($minimum)] ?? 1;

        return $valueRank >= $minimumRank;
    }

    private function keywordTokenCount(string $query): int
    {
        $normalized = Str::of($query)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\\s]/', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            return 0;
        }

        $stopwords = collect(config('knowledge.keyword_stopwords', []))
            ->filter(fn ($item) => is_string($item) && trim($item) !== '')
            ->map(fn (string $item) => Str::of($item)->lower()->ascii()->value())
            ->unique();

        return collect(preg_split('/\s+/', $normalized) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 3)
            ->reject(fn (string $token): bool => $stopwords->contains($token))
            ->unique()
            ->count();
    }

    /**
     * @param array<int, array{id: mixed, title: string, score: float|null}> $refs
     * @param array<int, float> $scores
     * @return array{count:int,scores:array<int,float>,refs:array<int,array{id:mixed,title:string,score:float|null}>}
     */
    private function compactHitSummary(array $refs, array $scores): array
    {
        $logPolicy = (array) config('models.pipelines.text.policy.log', []);
        $scoresLimit = max(1, (int) ($logPolicy['hit_scores_limit'] ?? 20));
        $refsLimit = max(1, (int) ($logPolicy['hit_refs_limit'] ?? 20));

        return [
            'count' => count($refs),
            'scores' => array_slice($scores, 0, $scoresLimit),
            'refs' => array_slice($refs, 0, $refsLimit),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $candidates
     * @return array{count:int,items:array<int,array<string,mixed>>}
     */
    private function compactKeywordCandidates(array $candidates): array
    {
        $logPolicy = (array) config('models.pipelines.text.policy.log', []);
        $itemsLimit = max(1, (int) ($logPolicy['keyword_items_limit'] ?? 8));

        $items = collect($candidates)
            ->take($itemsLimit)
            ->map(fn (array $candidate): array => [
                'id' => $candidate['id'] ?? null,
                'title' => $candidate['title'] ?? null,
                'matched_tokens' => $candidate['matched_tokens'] ?? null,
                'total_tokens' => $candidate['total_tokens'] ?? null,
                'match_ratio' => $candidate['match_ratio'] ?? null,
                'direct_needle_match' => $candidate['direct_needle_match'] ?? null,
                'strong_term_match' => $candidate['strong_term_match'] ?? null,
                'matched_terms' => $candidate['matched_terms'] ?? [],
                'query_terms' => $candidate['query_terms'] ?? [],
                'strong_matched_terms' => $candidate['strong_matched_terms'] ?? [],
            ])
            ->values()
            ->all();

        return [
            'count' => count($candidates),
            'items' => $items,
        ];
    }

    private function scoreLevelFromTopScore(?float $topScore): string
    {
        $highThreshold = (float) config('knowledge.score_levels.high', config('knowledge.min_score', 0.70));
        $mediumMinThreshold = (float) config('knowledge.score_levels.medium_min', 0.36);
        $highThreshold = max($highThreshold, $mediumMinThreshold);

        if ($topScore === null) {
            return 'low';
        }

        if ($topScore >= $highThreshold) {
            return 'high';
        }

        if ($topScore >= $mediumMinThreshold) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function writeTextChatLog(LoggerInterface $logger, string $level, string $event, array $context): void
    {
        $payload = [
            'event' => $event,
            'at' => now()->toIso8601String(),
            'context' => $context,
        ];

        $json = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $message = $json === false ? $event : "\n".$json."\n";

        match ($level) {
            'warning' => $logger->warning($message),
            'error' => $logger->error($message),
            default => $logger->info($message),
        };
    }
}
