<?php

namespace App\Knowledge;

use App\Knowledge\DTOs\ToolResponse;
use Illuminate\Support\Str;

class KnowledgeService
{
    public function __construct(private readonly KnowledgeRepository $repository)
    {
    }

    public function handle(string $toolName, array $payload = []): ToolResponse
    {
        return match ($toolName) {
            'conference.general_info' => $this->generalInfo($payload),
            'conference.schedule_lookup' => $this->scheduleLookup($payload),
            'conference.location_lookup' => $this->locationLookup($payload),
            default => new ToolResponse(
                tool: $toolName,
                text: 'Spiacente, non ho trovato informazioni per la tua richiesta.',
            ),
        };
    }

    private function generalInfo(array $payload): ToolResponse
    {
        $document = $this->repository->find('programma-generale-chirurgia');

        $topic = Str::lower($payload['topic'] ?? '');
        $content = $document['content'] ?? '';

        $response = match (true) {
            Str::contains($topic, 'ecm') => $this->extractSection($content, 'ECM'),
            Str::contains($topic, 'contatti') || Str::contains($topic, 'segreteria') => $this->extractSection($content, 'Segreteria'),
            default => $document['summary'] ?? 'Informazioni generali non disponibili.',
        };

        return new ToolResponse(
            tool: 'conference.general_info',
            text: $response,
            data: [
                'topic' => $topic ?: 'overview',
            ],
        );
    }

    private function scheduleLookup(array $payload): ToolResponse
    {
        $document = $this->repository->find('programma-generale-chirurgia');
        $content = $document['content'] ?? '';
        $target = $payload['query'] ?? '';

        $lines = collect(preg_split('/\r\n|\r|\n/', $content))
            ->filter(fn ($line) => trim($line) !== '');

        if ($target) {
            $lines = $lines->filter(function (string $line) use ($target): bool {
                return Str::contains(Str::lower($line), Str::lower($target));
            });
        } else {
            $lines = $lines->take(20);
        }

        $text = $lines->isEmpty()
            ? 'Non ho trovato sessioni corrispondenti. Prova a specificare giorno o sala.'
            : implode("\n", $lines->all());

        return new ToolResponse(
            tool: 'conference.schedule_lookup',
            text: $text,
        );
    }

    private function locationLookup(array $payload): ToolResponse
    {
        $document = $this->repository->find('piantina-centro-congressi-demo');
        $content = $document['content'] ?? '';

        $place = Str::lower($payload['place'] ?? '');

        if (Str::contains($place, 'bagni')) {
            $text = 'I bagni sono al piano terra dietro l’area registrazione e al piano 1 a destra uscendo dagli ascensori.';
        } elseif (Str::contains($place, 'sala s1')) {
            $text = 'La Sala Workshop S1 è al piano 1, in fondo al corridoio centrale.';
        } elseif (Str::contains($place, 'aula magna') || Str::contains($place, 'plenaria')) {
            $text = 'L’Aula Magna è al piano 1, sulla sinistra appena usciti da scala/ascensore.';
        } elseif (Str::contains($place, 'catering')) {
            $text = 'L’area catering è nella hall principale al piano terra, lato sinistro.';
        } else {
            $text = $document['summary'] ?? 'Posizione non trovata nella piantina.';
        }

        return new ToolResponse(
            tool: 'conference.location_lookup',
            text: $text,
            data: [
                'place' => $place ?: 'general',
            ],
        );
    }

    private function extractSection(string $content, string $keyword): string
    {
        $lines = preg_split('/\r\n|\r|\n/', $content);
        $start = null;
        $collected = [];

        foreach ($lines as $lineNumber => $line) {
            if ($start === null && Str::contains(Str::upper($line), Str::upper($keyword))) {
                $start = $lineNumber;
            }

            if ($start !== null) {
                if (Str::startsWith($line, '## ')) {
                    break;
                }
                $collected[] = $line;
            }
        }

        return trim(implode("\n", $collected)) ?: 'Informazione non trovata.';
    }
}
