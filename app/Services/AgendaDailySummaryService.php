<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Throwable;

class AgendaDailySummaryService
{
    public function __construct(
        private readonly GoogleCalendarService $calendar,
        private readonly ?GoogleTasksService $tasks = null,
    ) {}

    public function summarize(User $user, CarbonImmutable $day): string
    {
        $timezone = $day->getTimezone()->getName();
        $today = $day->setTimezone($timezone)->startOfDay();
        $events = $this->calendar->listEvents($user, $today, $today->endOfDay());

        $lines = ['Resumo de hoje ('.$today->format('d/m/Y').'):'];
        if ($events === []) {
            $lines[] = 'Agenda: sem compromissos.';
        } else {
            $lines[] = 'Agenda:';
            foreach (array_slice($events, 0, 20) as $event) {
                $start = data_get($event, 'start.dateTime') ?? data_get($event, 'start.date');
                if (! $start) {
                    continue;
                }
                $label = isset($event['start']['dateTime'])
                    ? CarbonImmutable::parse($start)->setTimezone($timezone)->format('H:i')
                    : 'Dia todo';
                $lines[] = '• '.$label.' — '.($event['summary'] ?? 'Sem título');
            }
        }

        $taskScope = 'https://www.googleapis.com/auth/tasks';
        $authorizedForTasks = in_array($taskScope, (array) ($user->googleCalendarConnection?->scopes ?? []), true);
        if ($this->tasks !== null && $authorizedForTasks) {
            try {
                $tasks = $this->tasks->listTasks($user, 100);
                $todayDate = $today->format('Y-m-d');
                $dueTasks = collect($tasks)
                    ->filter(function (array $task) use ($todayDate): bool {
                        $due = substr((string) ($task['due'] ?? ''), 0, 10);

                        return ($task['status'] ?? 'needsAction') !== 'completed'
                            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) === 1
                            && $due <= $todayDate;
                    })
                    ->sortBy(static fn (array $task): string => substr((string) ($task['due'] ?? ''), 0, 10))
                    ->take(20);

                $lines[] = $dueTasks->isEmpty() ? 'Tarefas: sem prazos vencidos ou para hoje na lista principal.' : 'Tarefas vencidas ou para hoje:';
                foreach ($dueTasks as $task) {
                    $due = substr((string) $task['due'], 0, 10);
                    $label = $due < $todayDate ? 'Atrasada desde '.CarbonImmutable::parse($due, $timezone)->format('d/m') : 'Hoje';
                    $lines[] = '• '.$label.' — '.($task['title'] ?? 'Sem título');
                }
            } catch (Throwable) {
                $lines[] = 'Tarefas: não consegui consultar agora.';
            }
        } elseif ($this->tasks !== null) {
            $lines[] = 'Tarefas não incluídas: autorize o acesso ao Google Tasks na conexão Google.';
        }

        return implode("\n", $lines);
    }
}
