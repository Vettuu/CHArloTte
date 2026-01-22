<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChatReportRetagController extends Controller
{
    public function __invoke(Request $request)
    {
        $tenant = (string) $request->query('tenant', '');
        $dryRun = filter_var($request->query('dry_run', '0'), FILTER_VALIDATE_BOOLEAN);

        $query = ChatMessage::query();
        if ($tenant !== '') {
            $query->where('tenant_id', $tenant);
        }

        $total = $query->count();
        $updated = 0;

        $query->orderBy('id')->chunk(300, function ($rows) use (&$updated, $dryRun): void {
            foreach ($rows as $row) {
                $topics = $this->detectTopics($row->content ?? '');
                $metadata = $row->metadata ?? [];
                $metadata['topics'] = $topics;

                if (! $dryRun) {
                    $row->metadata = $metadata;
                    $row->save();
                }
                $updated++;
            }
        });

        return response()->json([
            'tenant' => $tenant !== '' ? $tenant : null,
            'dry_run' => $dryRun,
            'total' => $total,
            'processed' => $updated,
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function detectTopics(string $content): array
    {
        $normalized = Str::of($content)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->squish()
            ->value();

        if ($normalized === '') {
            return [];
        }

        $topics = [
            'costi' => ['costo', 'prezzo', 'preventivo', 'tariffa', 'stima', 'budget'],
            'accredito' => [
                'accredito',
                'check in',
                'checkin',
                'qr',
                'qrcode',
                'badge',
                'registrazione',
                'ipad',
                'accessi',
                'controllo accessi',
            ],
            'sponsor' => ['sponsor', 'espositore', 'stand', 'lead', 'contatti'],
            'logistica' => [
                'logistica',
                'sede',
                'location',
                'ingresso',
                'ingressi',
                'uscite',
                'parcheggio',
                'orari',
                'navetta',
                'sale',
                'aula',
                'aule',
                'monitorare',
            ],
            'app' => ['app', 'agenda', 'programma', 'notifiche', 'push'],
            'streaming' => ['streaming', 'webinar', 'online', 'zoom'],
            'votazioni' => ['voto', 'votazioni', 'televoto', 'e-vote', 'elezioni'],
            'ecm' => ['ecm', 'crediti', 'presenze', 'rfid'],
        ];

        $matches = [];

        foreach ($topics as $topic => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($normalized, $keyword)) {
                    $matches[] = $topic;
                    break;
                }
            }
        }

        return $matches;
    }
}
