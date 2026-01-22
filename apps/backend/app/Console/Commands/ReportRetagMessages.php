<?php

namespace App\Console\Commands;

use App\Models\ChatMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ReportRetagMessages extends Command
{
    protected $signature = 'report:retag {--tenant=} {--dry-run}';
    protected $description = 'Ricalcola i topic per i messaggi già salvati in chat_messages.';

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        $dryRun = (bool) $this->option('dry-run');

        $query = ChatMessage::query();
        if (is_string($tenant) && $tenant !== '') {
            $query->where('tenant_id', $tenant);
        }

        $total = $query->count();
        $updated = 0;

        $this->info(sprintf('Messaggi trovati: %d', $total));

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

        if ($dryRun) {
            $this->info(sprintf('Dry-run completato. Messaggi processati: %d', $updated));
        } else {
            $this->info(sprintf('Ritag completato. Messaggi aggiornati: %d', $updated));
        }

        return Command::SUCCESS;
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
