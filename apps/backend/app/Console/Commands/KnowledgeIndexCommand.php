<?php

namespace App\Console\Commands;

use App\Knowledge\KnowledgeIndexer;
use Illuminate\Console\Command;

class KnowledgeIndexCommand extends Command
{
    protected $signature = 'knowledge:index {--tenant= : Tenant id (es. demo, azienda)}';

    protected $description = 'Rigenera gli embedding della knowledge base locale';

    public function handle(KnowledgeIndexer $indexer): int
    {
        $this->info('Indicizzazione knowledge base in corso...');
        $tenantId = $this->option('tenant') ?: config('knowledge.default_tenant', 'demo');
        $count = $indexer->rebuild($tenantId);
        $this->info("Completato: {$count} chunk indicizzati.");

        return self::SUCCESS;
    }
}
