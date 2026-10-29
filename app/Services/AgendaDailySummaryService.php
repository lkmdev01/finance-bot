<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
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
        $events = collect($this->calendar->listEvents($user, $today, $today->endOfDay()))
            ->filter(fn (array $event): bool => data_get($event, 'start.dateTime') !== null || data_get($event, 'start.date') !== null)
            ->sortBy(fn (array $event): int => CarbonImmutable::parse(
                data_get($event, 'start.dateTime') ?? data_get($event, 'start.date'),
                $timezone,
            )->getTimestamp())
            ->values()
            ->all();
        $overlappingEvents = $this->overlappingEventIndexes($events, $timezone);

        $lines = ['Resumo de hoje ('.$today->format('d/m/Y').'):'];
        if ($events === []) {
            $lines[] = 'Agenda: sem compromissos.';
        } else {
            $lines[] = 'Agenda:';
            foreach (array_slice($events, 0, 20, true) as $index => $event) {
                $start = data_get($event, 'start.dateTime') ?? data_get($event, 'start.date');
                if (! $start) {
                    continue;
                }
                $label = isset($event['start']['dateTime'])
                    ? CarbonImmutable::parse($start)->setTimezone($timezone)->format('H:i')
                    : 'Dia todo';
                $overlap = in_array($index, $overlappingEvents, true) ? ' (horário sobreposto)' : '';
                $lines[] = '• '.$label.' — '.($event['summary'] ?? 'Sem título').$overlap;
            }
            if (count($events) > 20) {
                $lines[] = '… e mais '.(count($events) - 20).' compromisso(s). Peça um período menor para ver os detalhes.';
            }
        }

        $taskScope = 'https://www.googleapis.com/auth/tasks';
        $authorizedForTasks = in_array($taskScope, (array) ($user->googleCalendarConnection?->scopes ?? []), true);
        if ($this->tasks !== null && $authorizedForTasks) {
            try {
                $tasks = $this->tasks->listTasks($user, 100);
                $todayDate = $today->format('Y-m-d');
                $allDueTasks = collect($tasks)
                    ->filter(function (array $task) use ($todayDate): bool {
                        $due = substr((string) ($task['due'] ?? ''), 0, 10);

                        return ($task['status'] ?? 'needsAction') !== 'completed'
                            && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) === 1
                            && $due <= $todayDate;
                    })
                    ->sortBy(static fn (array $task): string => substr((string) ($task['due'] ?? ''), 0, 10))
                    ->values();
                $dueTasks = $allDueTasks->take(20);

                $lines[] = $dueTasks->isEmpty() ? 'Tarefas: sem prazos vencidos ou para hoje na lista principal.' : 'Tarefas vencidas ou para hoje:';
                foreach ($dueTasks as $task) {
                    $due = substr((string) $task['due'], 0, 10);
                    $label = $due < $todayDate ? 'Atrasada desde '.CarbonImmutable::parse($due, $timezone)->format('d/m') : 'Hoje';
                    $lines[] = '• '.$label.' — '.($task['title'] ?? 'Sem título');
                }
                if ($allDueTasks->count() > 20) {
                    $lines[] = '… e mais '.($allDueTasks->count() - 20).' tarefa(s) com prazo vencido ou para hoje.';
                }
            } catch (Throwable $exception) {
                Log::warning('Falha ao consultar tarefas no resumo diário da agenda', [
                    'user_id' => $user->id,
                    'exception' => $exception::class,
                ]);
                $lines[] = 'Tarefas: não consegui consultar agora.';
            }
        } elseif ($this->tasks !== null) {
            $lines[] = 'Tarefas não incluídas: autorize o acesso ao Google Tasks na conexão Google.';
        }

        return implode("\n", $lines);
    }

    /** @return array<int, int> */
    private function overlappingEventIndexes(array $events, string $timezone): array
    {
        $overlaps = [];
        foreach ($events as $leftIndex => $left) {
            $leftStart = data_get($left, 'start.dateTime');
            $leftEnd = data_get($left, 'end.dateTime');
            if (! is_string($leftStart) || ! is_string($leftEnd)) {
                continue;
            }

            foreach ($events as $rightIndex => $right) {
                if ($rightIndex <= $leftIndex) {
                    continue;
                }
                $rightStart = data_get($right, 'start.dateTime');
                $rightEnd = data_get($right, 'end.dateTime');
                if (! is_string($rightStart) || ! is_string($rightEnd)) {
                    continue;
                }

                $startsBeforeOtherEnds = CarbonImmutable::parse($leftStart, $timezone)->lessThan(CarbonImmutable::parse($rightEnd, $timezone))
                    && CarbonImmutable::parse($leftEnd, $timezone)->greaterThan(CarbonImmutable::parse($rightStart, $timezone));
                if ($startsBeforeOtherEnds) {
                    $overlaps[] = $leftIndex;
                    $overlaps[] = $rightIndex;
                }
            }
        }

        return array_values(array_unique($overlaps));
    }
}
