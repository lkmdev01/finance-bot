<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;

class TestDatabaseConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:test-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Testa a conexão com o banco de dados configurado';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = Config::get('database.default');
        
        $this->info("Testando conexão com banco de dados: {$connection}");
        
        try {
            DB::connection()->getPdo();
            
            $database = DB::connection()->getDatabaseName();
            $driver = DB::connection()->getDriverName();
            
            $this->info("✅ Conexão bem-sucedida!");
            $this->line("   Driver: {$driver}");
            $this->line("   Database: {$database}");
            
            // Tenta fazer uma query simples
            $result = DB::select('SELECT 1 as test');
            $this->info("✅ Query de teste executada com sucesso!");
            
            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error("❌ Erro ao conectar ao banco de dados:");
            $this->error("   {$e->getMessage()}");
            
            $this->newLine();
            $this->warn("Verifique suas configurações no arquivo .env:");
            $this->line("   DB_CONNECTION={$connection}");
            $this->line("   DB_HOST=" . Config::get('database.connections.' . $connection . '.host'));
            $this->line("   DB_PORT=" . Config::get('database.connections.' . $connection . '.port'));
            $this->line("   DB_DATABASE=" . Config::get('database.connections.' . $connection . '.database'));
            $this->line("   DB_USERNAME=" . Config::get('database.connections.' . $connection . '.username'));
            
            return Command::FAILURE;
        }
    }
}
