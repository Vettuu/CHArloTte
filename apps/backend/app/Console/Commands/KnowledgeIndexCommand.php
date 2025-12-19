<?php

namespace App\Console\Commands;

use App\Knowledge\KnowledgeIndexer;
use Illuminate\Console\Command;

class KnowledgeIndexCommand extends Command
{
    protected $signature = 'knowledge:index';

    protected $description = 'Rigenera gli embedding della knowledge base locale';

    public function handle(KnowledgeIndexer $indexer): int
    {
        $this->info('Indicizzazione knowledge base in corso...');
        $count = $indexer->rebuild();
        $this->info("Completato: {$count} chunk indicizzati.");

        return self::SUCCESS;
    }
}
