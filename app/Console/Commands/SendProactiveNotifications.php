<?php

namespace App\Console\Commands;

use App\Services\ProactiveNotificationService;
use Illuminate\Console\Command;

class SendProactiveNotifications extends Command
{
    protected $signature = 'notifications:proactive';

    protected $description = 'Envia notificações proativas para usuários';

    public function handle(ProactiveNotificationService $service): int
    {
        $this->info('Enviando notificações proativas...');

        $service->sendProactiveNotifications();

        $this->info('Notificações proativas enviadas.');

        return Command::SUCCESS;
    }
}
