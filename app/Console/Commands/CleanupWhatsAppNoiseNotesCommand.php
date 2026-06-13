<?php

namespace App\Console\Commands;

use App\Models\Note;
use App\Models\User;
use Illuminate\Console\Command;

class CleanupWhatsAppNoiseNotesCommand extends Command
{
    protected $signature = 'notes:cleanup-whatsapp-noise
        {--user= : ID, email ou telefone do usuario}
        {--apply : Apaga de verdade. Sem isso, roda apenas em dry-run}';

    protected $description = 'Lista ou remove notas criadas por falsos positivos antigos do WhatsApp.';

    public function handle(): int
    {
        $user = $this->resolveUser((string) $this->option('user'));

        if (! $user instanceof User) {
            $this->error('Informe um usuario valido com --user=ID|email|telefone.');

            return self::FAILURE;
        }

        $query = Note::query()
            ->where('user_id', $user->id)
            ->where('source', 'whatsapp')
            ->where(function ($builder) {
                foreach ($this->noisePairs() as [$title, $body]) {
                    $builder->orWhere(function ($nested) use ($title, $body) {
                        $nested->where('title', $title)->where('body', $body);
                    });
                }
            })
            ->latest('id');

        $notes = $query->get(['id', 'title', 'body', 'created_at']);

        if ($notes->isEmpty()) {
            $this->info('Nenhuma nota poluida encontrada para esse usuario.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'titulo', 'conteudo', 'criada_em'],
            $notes->map(fn (Note $note) => [
                $note->id,
                $note->title,
                $note->body,
                $note->created_at?->format('Y-m-d H:i:s'),
            ])->all()
        );

        if (! $this->option('apply')) {
            $this->warn("Dry-run: {$notes->count()} nota(s) seriam apagadas. Rode com --apply para apagar.");

            return self::SUCCESS;
        }

        Note::query()->whereIn('id', $notes->pluck('id'))->delete();
        $this->info("Removidas {$notes->count()} nota(s) poluidas.");

        return self::SUCCESS;
    }

    private function resolveUser(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return User::query()
            ->where('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->orWhere('email', $identifier)
            ->orWhere('phone_number', preg_replace('/\D+/', '', $identifier) ?? $identifier)
            ->first();
    }

    /**
     * Falsos positivos que foram gerados quando consultas entravam no fluxo de criacao de notas.
     */
    private function noisePairs(): array
    {
        return [
            ['Minhas Notas', 'minhas notas'],
            ['Quais Sao Minhas Notas Ativas', 'quais sao minhas notas ativas'],
            ['No Drive', 'no drive'],
            ['Mim', 'mim'],
        ];
    }
}
