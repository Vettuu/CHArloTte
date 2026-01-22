<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatReportExportController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $tenantId = $request->query('tenant', config('knowledge.default_tenant', 'demo'));
        $filename = sprintf('chat_report_%s_%s.csv', $tenantId, now()->format('Ymd_His'));

        $messages = ChatMessage::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('session_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor();

        return response()->streamDownload(function () use ($messages): void {
            $output = fopen('php://output', 'w');

            fputcsv($output, [
                'session_id',
                'message_index',
                'role',
                'content',
                'created_at',
                'tokens_est',
                'source',
                'message_id',
            ]);

            $currentSession = null;
            $index = 0;

            foreach ($messages as $message) {
                if ($currentSession !== $message->session_id) {
                    $currentSession = $message->session_id;
                    $index = 0;
                }

                $index++;

                fputcsv($output, [
                    $message->session_id,
                    $index,
                    $message->role,
                    $message->content,
                    optional($message->created_at)->toIso8601String(),
                    $message->tokens_est,
                    $message->source,
                    $message->message_id,
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
