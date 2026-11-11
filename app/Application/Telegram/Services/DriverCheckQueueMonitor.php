<?php

declare(strict_types=1);

namespace App\Application\Telegram\Services;

use App\Telegram\QueueWorkerHeartbeat;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Behind the queue page: the `database` queue read straight from its
 * tables, plus the worker's own pulse (QueueWorkerHeartbeat).
 *
 * Job payloads are never handed out. The queue is shared with every
 * department's mailings, and a payload carries their phones and message
 * texts; the class name, attempts and timing are all this page needs.
 */
final class DriverCheckQueueMonitor
{
    /**
     * The queue the worker runs (queue:work --queue=telegram). Everything
     * belongs here; a job anywhere else waits for nobody.
     */
    public const QUEUE = 'telegram';

    public const STATES = ['ready', 'delayed', 'reserved', 'stuck'];

    /**
     * @return array<string, mixed>
     */
    public function summary(bool $withClasses = true): array
    {
        $now = time();
        $stuckBefore = $now - $this->retryAfter();

        $worker = QueueWorkerHeartbeat::read();
        $pulse = $worker['pulse'];
        $listened = $pulse['queues'] ?? null;

        /*
         * Every alias carries a suffix: DELAYED is a reserved word in MySQL
         * (INSERT DELAYED), and a bare "AS delayed" is a syntax error there
         * while SQLite, which the tests run on, accepts it.
         */
        $rows = $this->jobs()
            ->selectRaw('queue')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS ready_count', [$now])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS delayed_count', [$now])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved_count')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL AND reserved_at < ? THEN 1 ELSE 0 END) AS stuck_count', [$stuckBefore])
            ->selectRaw('MIN(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN available_at END) AS oldest_ready_at', [$now])
            ->groupBy('queue')
            ->get()
            ->keyBy('queue');

        if (! $rows->has(self::QUEUE)) {
            $rows->put(self::QUEUE, (object) [
                'queue' => self::QUEUE,
                'total_count' => 0, 'ready_count' => 0, 'delayed_count' => 0,
                'reserved_count' => 0, 'stuck_count' => 0,
                'oldest_ready_at' => null,
            ]);
        }

        $queues = $rows
            ->map(fn (object $row): array => [
                'queue' => $row->queue,
                'total' => (int) $row->total_count,
                'ready' => (int) $row->ready_count,
                'delayed' => (int) $row->delayed_count,
                'reserved' => (int) $row->reserved_count,
                'stuck' => (int) $row->stuck_count,
                'oldest_wait_seconds' => $row->oldest_ready_at !== null
                    ? max(0, $now - (int) $row->oldest_ready_at)
                    : null,
                'is_main' => $row->queue === self::QUEUE,
                /*
                 * Null until the worker has pulsed at least once: then
                 * nobody knows what it listens to.
                 */
                'listened' => is_array($listened) ? in_array($row->queue, $listened, true) : null,
            ])
            ->sortByDesc('is_main')
            ->values()
            ->all();

        return [
            'worker' => [
                'alive' => $pulse !== null
                    && $now - (int) ($pulse['at'] ?? 0) <= QueueWorkerHeartbeat::ALIVE_WITHIN_SECONDS,
                'pulse_at' => $this->iso($pulse['at'] ?? null),
                'pid' => $pulse['pid'] ?? null,
                'queues' => $listened,
                'last_processed' => $this->withIso($worker['last_processed']),
                'last_failed' => $this->withIso($worker['last_failed']),
            ],
            'queues' => $queues,
            /* What is waiting by job class reads the payload of every job: the page asks for it apart (classesNow). */
            ...($withClasses ? ['classes' => $this->classes($now)] : []),
            'failed' => [
                'total' => $this->failedJobs()->count(),
                'last_day' => $this->failedJobs()
                    ->where('failed_at', '>=', now()->subDay())
                    ->count(),
            ],
            'retry_after' => $this->retryAfter(),
            'main_queue' => self::QUEUE,
            'checked_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function pending(array $filters): LengthAwarePaginator
    {
        $now = time();

        $query = $this->jobs()
            ->select(['id', 'queue', 'payload', 'attempts', 'reserved_at', 'available_at', 'created_at']);

        if (($filters['queue'] ?? '') !== '') {
            $query->where('queue', $filters['queue']);
        }

        $this->whereJobName($query, $filters['search'] ?? null);

        match ($filters['state'] ?? '') {
            'ready' => $query->whereNull('reserved_at')->where('available_at', '<=', $now),
            'delayed' => $query->whereNull('reserved_at')->where('available_at', '>', $now),
            'reserved' => $query->whereNotNull('reserved_at'),
            'stuck' => $query->whereNotNull('reserved_at')->where('reserved_at', '<', $now - $this->retryAfter()),
            default => null,
        };

        // Newest first: what was just queued is what someone is looking for.
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginator = $query
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $paginator->through(function (object $row) use ($now): array {
            $payload = $this->decode($row->payload);

            $state = match (true) {
                $row->reserved_at !== null && (int) $row->reserved_at < $now - $this->retryAfter() => 'stuck',
                $row->reserved_at !== null => 'reserved',
                (int) $row->available_at > $now => 'delayed',
                default => 'ready',
            };

            return [
                'id' => (int) $row->id,
                'queue' => $row->queue,
                'job' => $this->shortName($payload['displayName'] ?? null),
                'job_class' => $payload['displayName'] ?? null,
                'attempts' => (int) $row->attempts,
                'max_tries' => $payload['maxTries'] ?? null,
                'state' => $state,
                'created_at' => $this->iso($row->created_at),
                'available_at' => $this->iso($row->available_at),
                'reserved_at' => $this->iso($row->reserved_at),
            ];
        });

        return $paginator;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function failed(array $filters): LengthAwarePaginator
    {
        $query = $this->failedJobs()
            ->select(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at']);

        if (($filters['queue'] ?? '') !== '') {
            $query->where('queue', $filters['queue']);
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $query->where(function (Builder $q) use ($search): void {
                $this->whereJobName($q, $search);
                $q->orWhere('exception', 'like', '%' . $search . '%');
            });
        }

        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $paginator = $query
            ->orderBy('failed_at', $direction)
            ->orderBy('id', $direction)
            ->paginate((int) ($filters['per_page'] ?? 20))
            ->withQueryString();

        $paginator->through(fn (object $row): array => $this->failedRow($row));

        return $paginator;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function failedJob(string $uuid): ?array
    {
        $row = $this->failedJobs()->where('uuid', $uuid)->first();

        if ($row === null) {
            return null;
        }

        return [
            ...$this->failedRow($row),
            'exception' => mb_substr((string) $row->exception, 0, 20000),
        ];
    }

    /**
     * Back onto the queue - always the main one: a job that failed while
     * it sat in another queue would only wait there again.
     *
     * @param list<string>|null $uuids null for every failed job
     */
    public function retry(?array $uuids, ?int $byUserId): int
    {
        $query = $this->failedJobs();

        if ($uuids !== null) {
            $query->whereIn('uuid', $uuids);
        }

        $ids = $query->pluck('uuid')->all();

        if ($ids === []) {
            return 0;
        }

        $this->failedJobs()->whereIn('uuid', $ids)->update(['queue' => self::QUEUE]);

        Artisan::call('queue:retry', ['id' => $ids]);

        Log::info('Failed jobs retried from the queue panel', [
            'user_id' => $byUserId,
            'count' => count($ids),
        ]);

        return count($ids);
    }

    /**
     * @param string|null $uuid null for every failed job
     */
    public function forget(?string $uuid, ?int $byUserId): int
    {
        $query = $this->failedJobs();

        if ($uuid !== null) {
            $query->where('uuid', $uuid);
        }

        $count = $query->delete();

        Log::warning('Failed jobs deleted from the queue panel', [
            'user_id' => $byUserId,
            'uuid' => $uuid ?? 'all',
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * Moves the waiting jobs of another queue onto the main one. A job a
     * worker has already taken is left where it is.
     */
    public function moveToMain(string $queue, ?int $byUserId): int
    {
        if ($queue === self::QUEUE) {
            return 0;
        }

        $count = $this->jobs()
            ->where('queue', $queue)
            ->whereNull('reserved_at')
            ->update(['queue' => self::QUEUE]);

        Log::info('Jobs moved to the main queue from the queue panel', [
            'user_id' => $byUserId,
            'from' => $queue,
            'count' => $count,
        ]);

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function classesNow(): array
    {
        return $this->classes(time());
    }

    /**
     * What is waiting, by job class. Mailings and driver checks share the
     * queue, and this is where a mailing burying the bot shows.
     *
     * @return list<array<string, mixed>>
     */
    private function classes(int $now): array
    {
        $name = $this->jobNameSql();

        return $this->jobs()
            ->selectRaw("{$name} AS job_name")
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at <= ? THEN 1 ELSE 0 END) AS ready_count', [$now])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NULL AND available_at > ? THEN 1 ELSE 0 END) AS delayed_count', [$now])
            ->selectRaw('SUM(CASE WHEN reserved_at IS NOT NULL THEN 1 ELSE 0 END) AS reserved_count')
            ->groupByRaw($name)
            ->orderByDesc('total_count')
            ->limit(20)
            ->get()
            ->map(fn (object $row): array => [
                'job' => $this->shortName($row->job_name),
                'job_class' => $row->job_name,
                'total' => (int) $row->total_count,
                'ready' => (int) $row->ready_count,
                'delayed' => (int) $row->delayed_count,
                'reserved' => (int) $row->reserved_count,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function failedRow(object $row): array
    {
        $payload = $this->decode($row->payload);

        $exception = (string) $row->exception;

        return [
            'id' => (int) $row->id,
            'uuid' => $row->uuid,
            'queue' => $row->queue,
            'job' => $this->shortName($payload['displayName'] ?? null),
            'job_class' => $payload['displayName'] ?? null,
            'error' => mb_substr(strtok($exception, "\n") ?: $exception, 0, 500),
            'failed_at' => $row->failed_at ? Carbon::parse($row->failed_at)->toIso8601String() : null,
        ];
    }

    private function whereJobName(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if ($search === '') {
            return;
        }

        $query->whereRaw(
            $this->jobNameSql() . ' LIKE ?',
            ['%' . str_replace('\\', '\\\\', $search) . '%'],
        );
    }

    /**
     * displayName straight out of the JSON payload, without shipping the
     * payload to PHP.
     */
    private function jobNameSql(): string
    {
        return DB::connection($this->connection())->getDriverName() === 'sqlite'
            ? "json_extract(payload, '$.displayName')"
            : "JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName'))";
    }

    private function jobs(): Builder
    {
        return DB::connection($this->connection())
            ->table(config('queue.connections.database.table', 'jobs'));
    }

    private function failedJobs(): Builder
    {
        return DB::connection(config('queue.failed.database') ?: null)
            ->table(config('queue.failed.table', 'failed_jobs'));
    }

    private function connection(): ?string
    {
        return config('queue.connections.database.connection') ?: null;
    }

    private function retryAfter(): int
    {
        return (int) config('queue.connections.database.retry_after', 90);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(?string $payload): array
    {
        $decoded = json_decode((string) $payload, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function shortName(?string $class): ?string
    {
        if ($class === null || $class === '') {
            return null;
        }

        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    private function iso(mixed $timestamp): ?string
    {
        return $timestamp === null || $timestamp === ''
            ? null
            : Carbon::createFromTimestamp((int) $timestamp)->toIso8601String();
    }

    /**
     * @param array<string, mixed>|null $entry
     * @return array<string, mixed>|null
     */
    private function withIso(?array $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            ...$entry,
            'job' => $this->shortName($entry['job'] ?? null),
            'at' => $this->iso($entry['at'] ?? null),
        ];
    }
}
