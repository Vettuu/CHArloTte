<?php

namespace App\Chat;

use App\Support\TextNormalizer;
use Illuminate\Support\Str;

class ConversationInputResolver
{
    // Decide se il nuovo input e autonomo oppure dipende dal contesto recente.
    /**
     * @param array{
     *   turns?: array<int, array{role: string, message: string, created_at: string|null}>,
     *   active_topic?: string|null,
     *   last_resolved_query?: string|null,
     *   last_bot_question?: string|null,
     *   updated_at?: string|null
     * } $conversationState
     * @return array{
     *   original_input: string,
     *   normalized_input: string,
     *   resolved_query: string,
     *   input_mode: string,
     *   input_is_short: bool,
     *   input_is_elliptic: bool,
     *   active_topic: string|null,
     *   resolved_active_topic: string|null,
     *   context_source: string|null,
     *   used_context: bool
     * }
     */
    public function resolve(string $message, array $conversationState): array
    {
        // Carica le euristiche da config per evitare liste hardcoded nel codice.
        $config = $this->conversationConfig();
        $originalInput = trim($message);
        $normalizedInput = $this->normalizeForAnalysis($originalInput);
        $activeTopic = $this->sanitizeNullableString($conversationState['active_topic'] ?? null);
        $lastResolvedQuery = $this->sanitizeNullableString($conversationState['last_resolved_query'] ?? null);
        $lastBotQuestion = $this->sanitizeNullableString($conversationState['last_bot_question'] ?? null);
        $inputIsShort = $this->isShortInput($originalInput, $normalizedInput, $config);

        // Input vuoto: nessuna ricostruzione, mantiene il topic corrente se esiste.
        if ($normalizedInput === '') {
            return [
                'original_input' => $originalInput,
                'normalized_input' => $normalizedInput,
                'resolved_query' => $originalInput,
                'input_mode' => 'empty',
                'input_is_short' => true,
                'input_is_elliptic' => false,
                'active_topic' => $activeTopic,
                'resolved_active_topic' => $activeTopic,
                'context_source' => null,
                'used_context' => false,
            ];
        }

        // Query breve ma autosufficiente: non va stitchata col contesto precedente.
        if ($this->isStandaloneShortInput($normalizedInput, $config)) {
            return [
                'original_input' => $originalInput,
                'normalized_input' => $normalizedInput,
                'resolved_query' => $originalInput,
                'input_mode' => 'standalone_short',
                'input_is_short' => $inputIsShort,
                'input_is_elliptic' => false,
                'active_topic' => $activeTopic,
                'resolved_active_topic' => $this->deriveStandaloneTopic($originalInput, $normalizedInput),
                'context_source' => null,
                'used_context' => false,
            ];
        }

        // Conferma pura: prosegue il ramo gia aperto usando il contesto corrente.
        if ($this->isConfirmationInput($normalizedInput, $config) && $this->hasConversationContext($activeTopic, $lastResolvedQuery, $lastBotQuestion)) {
            $resolvedQuery = $lastResolvedQuery ?? $activeTopic ?? $originalInput;

            return [
                'original_input' => $originalInput,
                'normalized_input' => $normalizedInput,
                'resolved_query' => $resolvedQuery,
                'input_mode' => 'confirmative',
                'input_is_short' => $inputIsShort,
                'input_is_elliptic' => true,
                'active_topic' => $activeTopic,
                'resolved_active_topic' => $activeTopic ?? $this->deriveStandaloneTopic($resolvedQuery, $this->normalizeForAnalysis($resolvedQuery)),
                'context_source' => $lastResolvedQuery !== null ? 'last_resolved_query' : 'active_topic',
                'used_context' => true,
            ];
        }

        // Follow-up tematico: mantiene il topic ma cambia asse (es. costi, per webinar, ecc.).
        if ($this->isThematicFollowUp($normalizedInput, $config) && $this->hasConversationContext($activeTopic, $lastResolvedQuery, $lastBotQuestion)) {
            $base = $lastResolvedQuery ?? $activeTopic ?? $originalInput;
            $resolvedQuery = trim($normalizedInput.' '.$base);

            return [
                'original_input' => $originalInput,
                'normalized_input' => $normalizedInput,
                'resolved_query' => $resolvedQuery,
                'input_mode' => 'thematic_follow_up',
                'input_is_short' => $inputIsShort,
                'input_is_elliptic' => true,
                'active_topic' => $activeTopic,
                'resolved_active_topic' => $activeTopic ?? $this->deriveStandaloneTopic($base, $this->normalizeForAnalysis($base)),
                'context_source' => $lastResolvedQuery !== null ? 'last_resolved_query' : 'active_topic',
                'used_context' => true,
            ];
        }

        // Follow-up selettivo: sceglie una variante nel contesto gia aperto.
        if ($this->isSelectiveInput($normalizedInput, $inputIsShort, $config) && $this->hasConversationContext($activeTopic, $lastResolvedQuery, $lastBotQuestion)) {
            $contextBase = $this->bestContextBase($activeTopic, $lastResolvedQuery, $lastBotQuestion);
            $resolvedQuery = trim($contextBase.' '.$normalizedInput);

            return [
                'original_input' => $originalInput,
                'normalized_input' => $normalizedInput,
                'resolved_query' => $resolvedQuery,
                'input_mode' => 'selective_follow_up',
                'input_is_short' => $inputIsShort,
                'input_is_elliptic' => true,
                'active_topic' => $activeTopic,
                'resolved_active_topic' => $activeTopic ?? $this->deriveStandaloneTopic($contextBase, $this->normalizeForAnalysis($contextBase)),
                'context_source' => $this->contextSource($activeTopic, $lastResolvedQuery, $lastBotQuestion),
                'used_context' => true,
            ];
        }

        // Caso standard: il nuovo input apre o conferma un topic autonomo.
        return [
            'original_input' => $originalInput,
            'normalized_input' => $normalizedInput,
            'resolved_query' => $originalInput,
            'input_mode' => 'self_contained',
            'input_is_short' => $inputIsShort,
            'input_is_elliptic' => false,
            'active_topic' => $activeTopic,
            'resolved_active_topic' => $this->deriveStandaloneTopic($originalInput, $normalizedInput),
            'context_source' => null,
            'used_context' => false,
        ];
    }

    // Normalizzazione orientata all'analisi lessicale, non alla risposta finale.
    private function normalizeForAnalysis(string $message): string
    {
        return Str::of(TextNormalizer::forEmbedding($message))
            ->replaceMatches('/[^a-z0-9\\s]/', ' ')
            ->squish()
            ->value();
    }

    // "Breve" e solo un segnale euristico, non equivale automaticamente a "ellittico".
    private function isShortInput(string $originalInput, string $normalizedInput, array $config): bool
    {
        if ($normalizedInput === '') {
            return true;
        }

        $tokenCount = count(preg_split('/\s+/', $normalizedInput) ?: []);
        $shortInputLength = max(1, (int) ($config['short_input_length'] ?? 15));

        return mb_strlen($originalInput) < $shortInputLength || $tokenCount <= 1;
    }

    // Query brevi ma forti: vanno lasciate vivere da sole.
    private function isStandaloneShortInput(string $normalizedInput, array $config): bool
    {
        return in_array($normalizedInput, $config['standalone_short_terms'] ?? [], true);
    }

    // Risposte tipo "ok", "si", "perfetto": non aggiungono topic nuovo.
    private function isConfirmationInput(string $normalizedInput, array $config): bool
    {
        return in_array($normalizedInput, $config['confirmation_terms'] ?? [], true);
    }

    // Pattern che cambiano il focus ma non il tema generale della conversazione.
    private function isThematicFollowUp(string $normalizedInput, array $config): bool
    {
        foreach (($config['thematic_prefixes'] ?? []) as $prefix) {
            if (str_starts_with($normalizedInput, $prefix)) {
                return true;
            }
        }

        return false;
    }

    // Pattern che selezionano una modalita o variante gia aperta dal contesto.
    private function isSelectiveInput(string $normalizedInput, bool $inputIsShort, array $config): bool
    {
        if (! $inputIsShort) {
            return false;
        }

        foreach (($config['selective_terms'] ?? []) as $term) {
            if ($normalizedInput === $term || str_contains($normalizedInput, $term)) {
                return true;
            }
        }

        return false;
    }

    // Il resolver usa il contesto solo se c'e davvero qualcosa da ereditare.
    private function hasConversationContext(?string $activeTopic, ?string $lastResolvedQuery, ?string $lastBotQuestion): bool
    {
        return $activeTopic !== null || $lastResolvedQuery !== null || $lastBotQuestion !== null;
    }

    // Priorita conservativa: prima topic generale, poi query risolta, poi ultima domanda del bot.
    private function bestContextBase(?string $activeTopic, ?string $lastResolvedQuery, ?string $lastBotQuestion): string
    {
        return $activeTopic
            ?? $lastResolvedQuery
            ?? $lastBotQuestion
            ?? '';
    }

    // Utile per log/debug: indica da quale campo e arrivato il contesto usato.
    private function contextSource(?string $activeTopic, ?string $lastResolvedQuery, ?string $lastBotQuestion): ?string
    {
        if ($activeTopic !== null) {
            return 'active_topic';
        }

        if ($lastResolvedQuery !== null) {
            return 'last_resolved_query';
        }

        if ($lastBotQuestion !== null) {
            return 'last_bot_question';
        }

        return null;
    }

    // Uniforma i campi nullable dello stato conversazionale.
    private function sanitizeNullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string !== '' ? $string : null;
    }

    /**
     * @return array{
     *   short_input_length: int,
     *   standalone_short_terms: array<int, string>,
     *   confirmation_terms: array<int, string>,
     *   selective_terms: array<int, string>,
     *   thematic_prefixes: array<int, string>
     * }
     */
    private function conversationConfig(): array
    {
        // Centralizza tutte le euristiche in config per facilitare tuning e manutenzione.
        $config = (array) config('models.pipelines.text.conversation', []);

        return [
            'short_input_length' => (int) ($config['short_input_length'] ?? 15),
            'standalone_short_terms' => $this->normalizeTerms($config['standalone_short_terms'] ?? []),
            'confirmation_terms' => $this->normalizeTerms($config['confirmation_terms'] ?? []),
            'selective_terms' => $this->normalizeTerms($config['selective_terms'] ?? []),
            'thematic_prefixes' => $this->normalizeTerms($config['thematic_prefixes'] ?? []),
        ];
    }

    /**
     * @param mixed $terms
     * @return array<int, string>
     */
    private function normalizeTerms(mixed $terms): array
    {
        if (! is_array($terms)) {
            return [];
        }

        return collect($terms)
            ->filter(fn ($term): bool => is_string($term) && trim($term) !== '')
            ->map(fn (string $term): string => $this->normalizeForAnalysis($term))
            ->unique()
            ->values()
            ->all();
    }

    // Per input autosufficienti o standalone, il nuovo topic diventa il messaggio stesso.
    private function deriveStandaloneTopic(string $originalInput, string $normalizedInput): ?string
    {
        if ($normalizedInput === '') {
            return null;
        }

        return trim($originalInput) !== '' ? trim($originalInput) : $normalizedInput;
    }
}
