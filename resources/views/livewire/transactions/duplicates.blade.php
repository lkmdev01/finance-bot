<?php

use App\Services\TransactionDuplicateDetectionService;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public array $duplicates = [];

    public function mount(TransactionDuplicateDetectionService $duplicateService): void
    {
        $this->duplicates = $duplicateService->detectDuplicates(Auth::user(), 30)
            ->map(function ($duplicate) {
                return [
                    'id' => $duplicate['transaction']->id,
                    'duplicate_id' => $duplicate['duplicate']->id,
                    'transaction' => $duplicate['transaction'],
                    'duplicate' => $duplicate['duplicate'],
                    'similarity' => $duplicate['similarity'],
                ];
            })
            ->toArray();
    }

    public function resolve(int $duplicateId, bool $keepFirst = true, TransactionDuplicateDetectionService $duplicateService): void
    {
        $duplicateService->resolveDuplicate($duplicateId, $keepFirst);
        
        session()->flash('message', 'Duplicata resolvida com sucesso!');
        $this->redirect(route('transactions.duplicates'), navigate: true);
    }
}; ?>

<div class="p-6 space-y-6">
    <div>
        <h1 class="text-2xl font-bold">Transações Duplicadas</h1>
        <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">Transações que podem estar duplicadas</p>
    </div>

    @if(count($duplicates) > 0)
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Transação</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Duplicata</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Similaridade</th>
                            <th class="px-6 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($duplicates as $duplicate)
                            <tr>
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        <p class="font-medium">{{ $duplicate['transaction']->description }}</p>
                                        <p class="text-zinc-500 dark:text-zinc-400">{{ $duplicate['transaction']->date->format('d/m/Y') }}</p>
                                        <p class="text-sm font-semibold {{ $duplicate['transaction']->type === 'income' ? 'text-green-600' : 'text-red-600' }}">
                                            R$ {{ number_format($duplicate['transaction']->amount, 2, ',', '.') }}
                                        </p>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm">
                                        <p class="font-medium">{{ $duplicate['duplicate']->description }}</p>
                                        <p class="text-zinc-500 dark:text-zinc-400">{{ $duplicate['duplicate']->date->format('d/m/Y') }}</p>
                                        <p class="text-sm font-semibold {{ $duplicate['duplicate']->type === 'income' ? 'text-green-600' : 'text-red-600' }}">
                                            R$ {{ number_format($duplicate['duplicate']->amount, 2, ',', '.') }}
                                        </p>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200">
                                        {{ number_format($duplicate['similarity'], 1) }}%
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <flux:button 
                                            wire:click="resolve({{ $duplicate['id'] }}, true)"
                                            wire:confirm="Manter a primeira transação e excluir a duplicata?"
                                            variant="ghost"
                                            size="sm"
                                        >
                                            Manter Primeira
                                        </flux:button>
                                        <flux:button 
                                            wire:click="resolve({{ $duplicate['id'] }}, false)"
                                            wire:confirm="Manter a segunda transação e excluir a primeira?"
                                            variant="ghost"
                                            size="sm"
                                        >
                                            Manter Segunda
                                        </flux:button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-12 text-center">
            <p class="text-zinc-500 dark:text-zinc-400">Nenhuma transação duplicada encontrada.</p>
        </div>
    @endif
</div>
