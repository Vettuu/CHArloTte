<?php

namespace App\Chat;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\Arr;

class ConversationStateService
{
    private const CACHE_PREFIX = 'chat:conversation_state';

    private const MAX_TURNS = 4;

    private const TTL_SECONDS = 10800;

    public function __construct(private readonly CacheFactory $cache)
    {
    }

    /**
     * @return array{
     *   turns: array<int, array{role: string, message: string, created_at: string|null}>,
     *   active_topic: string|null,
     *   last_resolved_query: string|null,
     *   last_bot_question: string|null,
     *   updated_at: string|null
     * }
     */
    public function load(string $sessionId, string $tenantId): array
    {
        $state = $this->cache->store()->get($this->cacheKey($sessionId, $tenantId), []);

        return $this->normalizeState(is_array($state) ? $state : []);
    }

    /**
     * @param array{
     *   turns?: array<int, array<string, mixed>>,
     *   active_topic?: string|null,
     *   last_resolved_query?: string|null,
     *   last_bot_question?: string|null,
     *   updated_at?: string|null
     * } $state
     */
    public function save(string $sessionId, string $tenantId, array $state): array
    {
        $normalized = $this->normalizeState($state);

        $this->cache->store()->put(
            $this->cacheKey($sessionId, $tenantId),
            $normalized,
            self::TTL_SECONDS
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{
     *   turns: array<int, array{role: string, message: string, created_at: string|null}>,
     *   active_topic: string|null,
     *   last_resolved_query: string|null,
     *   last_bot_question: string|null,
     *   updated_at: string|null
     * }
     */
    public function appendTurn(
        string $sessionId,
        string $tenantId,
        string $role,
        string $message,
        array $meta = [],
    ): array {
        $state = $this->load($sessionId, $tenantId);
        $turns = $state['turns'];
        $trimmedMessage = trim($message);

        if ($trimmedMessage !== '') {
            $turns[] = [
                'role' => $role,
                'message' => $trimmedMessage,
                'created_at' => $this->sanitizeNullableString($meta['created_at'] ?? now()->toIso8601String()),
            ];
        }

        $state['turns'] = array_slice($turns, -self::MAX_TURNS);
        $state['active_topic'] = $this->sanitizeNullableString($meta['active_topic'] ?? $state['active_topic']);
        $state['last_resolved_query'] = $this->sanitizeNullableString($meta['last_resolved_query'] ?? $state['last_resolved_query']);
        $state['last_bot_question'] = $this->sanitizeNullableString($meta['last_bot_question'] ?? $state['last_bot_question']);

        return $this->save($sessionId, $tenantId, $state);
    }

    /**
     * @param array<string, mixed> $patch
     * @return array{
     *   turns: array<int, array{role: string, message: string, created_at: string|null}>,
     *   active_topic: string|null,
     *   last_resolved_query: string|null,
     *   last_bot_question: string|null,
     *   updated_at: string|null
     * }
     */
    public function updateContext(string $sessionId, string $tenantId, array $patch): array
    {
        $state = $this->load($sessionId, $tenantId);

        if (array_key_exists('active_topic', $patch)) {
            $state['active_topic'] = $this->sanitizeNullableString($patch['active_topic']);
        }

        if (array_key_exists('last_resolved_query', $patch)) {
            $state['last_resolved_query'] = $this->sanitizeNullableString($patch['last_resolved_query']);
        }

        if (array_key_exists('last_bot_question', $patch)) {
            $state['last_bot_question'] = $this->sanitizeNullableString($patch['last_bot_question']);
        }

        return $this->save($sessionId, $tenantId, $state);
    }

    public function clear(string $sessionId, string $tenantId): void
    {
        $this->cache->store()->forget($this->cacheKey($sessionId, $tenantId));
    }

    private function cacheKey(string $sessionId, string $tenantId): string
    {
        return sprintf('%s:%s:%s', self::CACHE_PREFIX, trim($tenantId), trim($sessionId));
    }

    /**
     * @param array<string, mixed> $state
     * @return array{
     *   turns: array<int, array{role: string, message: string, created_at: string|null}>,
     *   active_topic: string|null,
     *   last_resolved_query: string|null,
     *   last_bot_question: string|null,
     *   updated_at: string|null
     * }
     */
    private function normalizeState(array $state): array
    {
        $turns = collect(Arr::get($state, 'turns', []))
            ->filter(fn ($turn): bool => is_array($turn))
            ->map(function (array $turn): array {
                return [
                    'role' => $this->sanitizeRole($turn['role'] ?? ''),
                    'message' => $this->sanitizeMessage($turn['message'] ?? ''),
                    'created_at' => $this->sanitizeNullableString($turn['created_at'] ?? null),
                ];
            })
            ->filter(fn (array $turn): bool => $turn['message'] !== '')
            ->take(-self::MAX_TURNS)
            ->values()
            ->all();

        return [
            'turns' => $turns,
            'active_topic' => $this->sanitizeNullableString(Arr::get($state, 'active_topic')),
            'last_resolved_query' => $this->sanitizeNullableString(Arr::get($state, 'last_resolved_query')),
            'last_bot_question' => $this->sanitizeNullableString(Arr::get($state, 'last_bot_question')),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function sanitizeRole(mixed $value): string
    {
        $role = strtolower(trim((string) $value));

        return in_array($role, ['user', 'assistant'], true) ? $role : 'user';
    }

    private function sanitizeMessage(mixed $value): string
    {
        return trim((string) $value);
    }

    private function sanitizeNullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : null;
    }
}
