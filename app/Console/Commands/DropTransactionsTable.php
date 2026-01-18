<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DropTransactionsTable extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:drop-table {table : Nome da tabela a ser removida}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove uma tabela do banco de dados';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $table = $this->argument('table');
        
        try {
            $this->info("Removendo tabela {$table}...");
            
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::statement("DROP TABLE IF EXISTS {$table}");
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            $this->info("✅ Tabela {$table} removida com sucesso!");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('❌ Erro ao remover tabela: ' . $e->getMessage());
            
            return Command::FAILURE;
        }
    }
}
