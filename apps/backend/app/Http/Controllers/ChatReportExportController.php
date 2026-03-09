<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatReportExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
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

        $filename = sprintf(
            'chat_report_%s_%s.csv',
            implode('-', $tenantFilter),
            now()->format('Ymd_His')
        );

        $messages = ChatMessage::query()->whereIn('tenant_id', $tenantFilter);

        if ($from !== null) {
            $messages->where('created_at', '>=', $from->startOfDay());
        }

        if ($to !== null) {
            $messages->where('created_at', '<=', $to->endOfDay());
        }

        if ($pipelineFilter !== []) {
            $messages->where(function ($builder) use ($pipelineFilter) {
                foreach ($pipelineFilter as $pipeline) {
                    $builder->orWhere('metadata->pipeline', $pipeline);
                }
            });
        }

        if ($modelFilter !== []) {
            $messages->where(function ($builder) use ($modelFilter) {
                foreach ($modelFilter as $model) {
                    $builder->orWhere('metadata->model', $model);
                }
            });
        }

        if ($knowledgeTenantFilter !== []) {
            $messages->where(function ($builder) use ($knowledgeTenantFilter) {
                foreach ($knowledgeTenantFilter as $knowledgeTenant) {
                    $builder->orWhere('metadata->knowledge_tenant', $knowledgeTenant);
                }
            });
        }

        $cursor = $messages
            ->orderBy('tenant_id')
            ->orderBy('session_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        return response()->streamDownload(function () use ($cursor): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                'tenant_id',
                'session_id',
                'message_index',
                'role',
                'content',
                'created_at',
                'tokens_est',
                'source',
                'message_id',
                'pipeline',
                'model',
                'knowledge_tenant',
                'intent',
                'confidence_score',
                'confidence_bucket',
                'fallback',
                'rag_hits',
                'top_score',
                'policy_path',
                'contradiction_flag',
                'latency_ms',
                'token_in',
                'token_out',
            ]);

            $currentTenant = null;
            $currentSession = null;
            $index = 0;

            foreach ($cursor as $message) {
                if ($currentTenant !== $message->tenant_id || $currentSession !== $message->session_id) {
                    $currentTenant = $message->tenant_id;
                    $currentSession = $message->session_id;
                    $index = 0;
                }

                $index++;
                $metadata = is_array($message->metadata) ? $message->metadata : [];

                fputcsv($output, [
                    $message->tenant_id,
                    $message->session_id,
                    $index,
                    $message->role,
                    $message->content,
                    optional($message->created_at)->toIso8601String(),
                    $message->tokens_est,
                    $message->source,
                    $message->message_id,
                    $metadata['pipeline'] ?? null,
                    $metadata['model'] ?? null,
                    $metadata['knowledge_tenant'] ?? null,
                    $metadata['intent'] ?? null,
                    $metadata['confidence_score'] ?? null,
                    $metadata['confidence_bucket'] ?? null,
                    $metadata['fallback'] ?? null,
                    $metadata['rag_hits'] ?? null,
                    $metadata['top_score'] ?? null,
                    $metadata['policy_path'] ?? null,
                    $metadata['contradiction_flag'] ?? null,
                    $metadata['latency_ms'] ?? null,
                    $metadata['token_in'] ?? null,
                    $metadata['token_out'] ?? null,
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
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
}
