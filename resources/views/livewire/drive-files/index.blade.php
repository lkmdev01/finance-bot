<?php

use App\Models\DriveFile;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component {
    public string $q = '';

    public function with(): array
    {
        $user = Auth::user();
        $query = $user->driveFiles()->orderByDesc('id');

        $q = trim($this->q);
        if ($q !== '') {
            $query->where(function ($builder) use ($q) {
                $builder
                    ->where('title', 'like', '%'.$q.'%')
                    ->orWhere('original_name', 'like', '%'.$q.'%')
                    ->orWhere('drive_path', 'like', '%'.$q.'%')
                    ->orWhere('extracted_text', 'like', '%'.$q.'%');
            });
        }

        return [
            'connected' => (bool) ($user->googleDriveConnection && ! $user->googleDriveConnection->revoked_at),
            'files' => $query->limit(50)->get(),
        ];
    }

    public function prettySize(?int $bytes): string
    {
        if (! $bytes || $bytes <= 0) {
            return '-';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $size = (float) $bytes;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return number_format($size, $i === 0 ? 0 : 1, ',', '.').' '.$units[$i];
    }

    public function webUrl(DriveFile $file): ?string
    {
        if (blank($file->drive_file_id)) {
            return null;
        }
        return 'https://drive.google.com/file/d/'.rawurlencode((string) $file->drive_file_id).'/view';
    }
}; ?>

<div class="p-6 space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold">Drive Inteligente</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400 mt-1">
                Arquivos enviados no WhatsApp e organizados no seu Google Drive.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <flux:input wire:model.live="q" placeholder="Buscar arquivos..." class="min-w-[220px]" />
            @if(! $connected)
                <flux:button href="{{ route('integrations.google-drive') }}" wire:navigate variant="primary">
                    Conectar Drive
                </flux:button>
            @endif
        </div>
    </div>

    @if(! $connected)
        <div class="rounded-xl border border-amber-400/30 bg-amber-50/60 dark:bg-amber-950/20 p-4 text-amber-900 dark:text-amber-100">
            <p class="text-sm font-semibold">Conecte seu Google Drive</p>
            <p class="mt-1 text-sm opacity-90">
                Para salvar arquivos pelo WhatsApp, conecte seu Drive em <span class="font-mono">Integrações</span>.
            </p>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-4">
        @forelse($files as $file)
            <div class="bg-white dark:bg-zinc-900 rounded-xl border border-zinc-200 dark:border-zinc-700 p-5">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold truncate">{{ $file->title ?: ($file->original_name ?: 'Arquivo') }}</h2>
                            @if(! blank($file->drive_path))
                                <span class="px-2 py-1 text-xs rounded-full bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ $file->drive_path }}
                                </span>
                            @endif
                            @if(! blank($file->mime_type))
                                <span class="px-2 py-1 text-xs rounded-full bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">
                                    {{ $file->mime_type }}
                                </span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            {{ $file->original_name }} · {{ $this->prettySize($file->size_bytes) }} · {{ $file->created_at?->format('d/m/Y H:i') }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2 shrink-0">
                        @if($this->webUrl($file))
                            <flux:button href="{{ $this->webUrl($file) }}" target="_blank" variant="ghost">
                                Abrir no Drive
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 dark:border-zinc-700 p-10 text-center">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">Nenhum arquivo encontrado.</p>
                <p class="mt-2 text-xs text-zinc-500">Dica: no WhatsApp, envie um arquivo e diga: "salva isso no drive".</p>
            </div>
        @endforelse
    </div>
</div>

