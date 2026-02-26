<?php

namespace App\Http\Controllers;

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

        $this->writeTextChatLog($textLog, 'info', 'Text chat request received', [
            'session_id' => $sessionId,
            'tenant' => $tenantId,
            'knowledge_tenant' => $knowledgeTenantId,
            'pipeline' => $pipeline,
            'model' => $model,
            'message_len' => mb_strlen($message),
            'message_preview' => Str::limit($message, 120),
        ]);

        $intent = $this->detectIntent($message);
        $hits = $this->search->search($message, $maxHits, $knowledgeTenantId)->values();
        $fallback = $hits->isEmpty();
        $confidence = $this->computeConfidence($hits);
        $confidenceBucket = $this->confidenceBucket($confidence);
        $topScore = $this->topScore($hits);
        $ragHitScores = $this->ragHitScores($hits);
        $ragHitRefs = $this->ragHitRefs($hits);
        $contradictionFlag = $this->hasContradiction($hits);
        $policyPath = $this->resolvePolicyPath(
            hitCount: $hits->count(),
            confidenceBucket: $confidenceBucket,
            contradictionFlag: $contradictionFlag,
            policy: $policy,
        );

        $sourceTitles = $hits
            ->pluck('title')
            ->filter(fn ($title) => is_string($title) && $title !== '')
            ->take(5)
            ->values()
            ->all();

        $this->writeTextChatLog($textLog, 'info', 'Text chat RAG resolved', [
            'session_id' => $sessionId,
            'tenant' => $tenantId,
            'rag_hits' => $hits->count(),
            'fallback' => $fallback,
            'intent' => $intent,
            'confidence_score' => $confidence,
            'confidence_bucket' => $confidenceBucket,
            'top_score' => $topScore,
            'rag_hit_scores' => $ragHitScores,
            'rag_hit_refs' => $ragHitRefs,
            'policy_path' => $policyPath,
            'contradiction_flag' => $contradictionFlag,
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
                'intent' => $intent,
                'confidence_score' => $confidence,
                'confidence_bucket' => $confidenceBucket,
                'top_score' => $topScore,
                'rag_hit_scores' => [],
                'rag_hit_refs' => [],
                'policy_path' => $policyPath,
                'contradiction_flag' => $contradictionFlag,
                'latency_ms' => $latencyMs,
                'reply_len' => mb_strlen($finalReply),
                'reply_preview' => Str::limit($finalReply, 140),
                'web_search_enabled' => false,
                'web_sources_count' => 0,
                'web_sources' => [],
            ]);

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
                'fallback' => true,
                'rag_hits' => 0,
                'top_score' => $topScore,
                'rag_hit_scores' => [],
                'rag_hit_refs' => [],
                'reply' => $finalReply,
                'web_search' => [
                    'enabled' => false,
                    'allowed_domains' => [],
                    'sources' => [],
                ],
                'sources' => [],
            ]);
        }

        $prompt = $this->buildPrompt(
            query: $message,
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
        $shouldUseWebSearch = $this->shouldUseWebSearch($intent, $webSearch, $policy);
        $webSearchConfig = $shouldUseWebSearch
            ? $webSearch
            : array_merge($webSearch, ['enabled' => false]);

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
            'intent' => $intent,
            'confidence_score' => $confidence,
            'confidence_bucket' => $confidenceBucket,
            'top_score' => $topScore,
            'rag_hit_scores' => $ragHitScores,
            'rag_hit_refs' => $ragHitRefs,
            'policy_path' => $policyPath,
            'contradiction_flag' => $contradictionFlag,
            'latency_ms' => $latencyMs,
            'reply_len' => mb_strlen($replyText),
            'reply_preview' => Str::limit($replyText, 140),
            'web_search_enabled' => (bool) ($webSearchConfig['enabled'] ?? false),
            'web_sources_count' => count($result['sources'] ?? []),
            'web_sources' => collect($result['sources'] ?? [])->pluck('url')->filter()->take(5)->values()->all(),
        ]);

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
            'fallback' => $fallback,
            'rag_hits' => $hits->count(),
            'top_score' => $topScore,
            'rag_hit_scores' => $ragHitScores,
            'rag_hit_refs' => $ragHitRefs,
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
        array $hits,
        ?string $supportEmail,
        ?string $fallbackMessage,
        string $policyPath,
        bool $contradictionFlag,
        string $intent,
    ): string
    {
        if ($hits === []) {
            $contact = $supportEmail
                ? "Invita a contattare {$supportEmail} per approfondimenti."
                : 'Invita a richiedere un contatto per ulteriori dettagli.';

            $fallback = $fallbackMessage
                ? "Puoi usare questo messaggio generale: \"{$fallbackMessage}\"."
                : 'Spiega che l\'informazione non è presente nei documenti ufficiali.';

            return "Domanda utente: {$query}\n\nContesto ufficiale: nessuna fonte affidabile trovata. {$fallback} {$contact}";
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

        return "Domanda utente: {$query}\n\n{$policyInstructions}\n{$contradictionInstruction}\n{$webIntentInstruction}\n\nUsa solo queste fonti ufficiali per la risposta:\n{$context}";
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

    private function shouldUseWebSearch(string $intent, array $webSearch, array $policy): bool
    {
        if (($webSearch['enabled'] ?? false) !== true) {
            return false;
        }

        $allowedIntents = collect($policy['web_search_intents'] ?? ['showcase_web'])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->values();

        return $allowedIntents->contains($intent);
    }

    /**
     * @param Collection<int, array<string, mixed>> $hits
     */
    private function computeConfidence(Collection $hits): int
    {
        $count = $hits->count();
        if ($count === 0) {
            return 0;
        }

        $countScore = min(45, $count * 11);
        $scores = $hits->pluck('score')->filter(fn ($score) => is_numeric($score))->map(fn ($score) => (float) $score);

        if ($scores->isEmpty()) {
            return min(70, $countScore + 20);
        }

        $avg = $scores->avg() ?? 0.0;
        $top = $scores->max() ?? 0.0;
        $semanticScore = (int) round(min(45, ($avg * 50)));
        $topBonus = $top >= 0.90 ? 10 : ($top >= 0.80 ? 6 : 0);

        return max(0, min(100, $countScore + $semanticScore + $topBonus));
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
     */
    private function hasContradiction(Collection $hits): bool
    {
        if ($hits->count() < 2) {
            return false;
        }

        $texts = $hits
            ->pluck('excerpt')
            ->filter(fn ($excerpt) => is_string($excerpt))
            ->map(fn ($excerpt) => Str::of($excerpt)->lower()->ascii()->value());

        $prices = collect();
        foreach ($texts as $text) {
            if (preg_match_all('/\b(\d{2,5})\s?(euro|€)\b/i', (string) $text, $matches)) {
                foreach ($matches[1] as $value) {
                    $prices->push((int) $value);
                }
            }
        }

        if ($prices->unique()->count() > 1) {
            return true;
        }

        $includesYes = $texts->contains(fn ($text) => str_contains((string) $text, 'si') || str_contains((string) $text, 'disponibile'));
        $includesNo = $texts->contains(fn ($text) => str_contains((string) $text, 'non') || str_contains((string) $text, 'non disponibile'));

        return $includesYes && $includesNo;
    }

    private function resolvePolicyPath(int $hitCount, string $confidenceBucket, bool $contradictionFlag, array $policy): string
    {
        $strictFallbackOnZero = (bool) ($policy['strict_fallback_on_zero_hits'] ?? true);
        $fullAnswerRequiresHits = max(1, (int) ($policy['full_answer_requires_hits'] ?? 4));

        if ($strictFallbackOnZero && $hitCount === 0) {
            return 'strict_fallback';
        }

        if ($contradictionFlag) {
            return 'partial_answer';
        }

        if ($hitCount < $fullAnswerRequiresHits) {
            return 'partial_answer';
        }

        return $confidenceBucket === 'high' ? 'full_answer' : 'partial_answer';
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
