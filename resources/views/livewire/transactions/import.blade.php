<?php

use App\Services\TransactionImportService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public $file;
    public string $format = 'csv';
    public ?int $imported = null;
    public array $errors = [];

    public function import(TransactionImportService $importService): void
    {
        $this->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,ofx', 'max:10240'],
            'format' => ['required', 'string', 'in:csv,ofx'],
        ]);

        $path = $this->file->store('imports');

        try {
            if ($this->format === 'csv') {
                $result = $importService->importFromCsv(Auth::user(), storage_path("app/{$path}"));
            } else {
                $result = $importService->importFromOfx(Auth::user(), storage_path("app/{$path}"));
            }

            $this->imported = $result['imported'];
            $this->errors = $result['errors'];

            if ($this->imported > 0) {
                session()->flash('message', "{$this->imported} transações importadas com sucesso!");
                $this->redirect(route('transactions.index'), navigate: true);
            }
        } catch (\Exception $e) {
            $this->addError('file', 'Erro ao importar: '.$e->getMessage());
        } finally {
            \Storage::delete($path);
        }
    }
}; ?>

<div class="p-6 space-y-6">
    <div>
        <h1 class="text-2xl font-bold">Importar Transações</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Importe transações de arquivos CSV ou OFX</p>
    </div>

    <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-6">
        <form wire:submit="import">
            <div class="space-y-6">
                <flux:field>
                    <flux:label>Formato do arquivo</flux:label>
                    <flux:radio.group wire:model="format">
                        <flux:radio value="csv" label="CSV" />
                        <flux:radio value="ofx" label="OFX" />
                    </flux:radio.group>
                    <flux:error name="format" />
                </flux:field>

                <flux:field>
                    <flux:label>Arquivo</flux:label>
                    <flux:input type="file" wire:model="file" accept=".csv,.txt,.ofx" />
                    <flux:error name="file" />
                    <flux:description>
                        Formatos aceitos: CSV, TXT, OFX. Tamanho máximo: 10MB
                    </flux:description>
                </flux:field>

                @if($imported !== null)
                    <div class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                        <p class="text-sm text-green-800 dark:text-green-200">
                            <strong>{{ $imported }}</strong> transações importadas com sucesso!
                        </p>
                        @if(count($errors) > 0)
                            <p class="text-xs text-green-700 dark:text-green-300 mt-2">
                                {{ count($errors) }} erros encontrados durante a importação.
                            </p>
                        @endif
                    </div>
                @endif

                <div class="flex items-center gap-4">
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>Importar</span>
                        <span wire:loading>Importando...</span>
                    </flux:button>
                    <flux:button href="{{ route('transactions.index') }}" wire:navigate variant="ghost">
                        Cancelar
                    </flux:button>
                </div>
            </div>
        </form>
    </div>
</div>
