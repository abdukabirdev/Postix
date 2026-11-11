<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Application\Telegram\Services\DriverCheckQueueMonitor;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Behind the queue page. See DriverCheckQueueMonitor for why no payload
 * ever leaves this controller.
 *
 * Access is enforced by the `role:driverCheck,superadmin` middleware on the
 * route group (see routes/web.php), matching the rest of the driver-check
 * panel.
 */
final class DriverCheckQueueController extends Controller
{
    public function __construct(
        private readonly DriverCheckQueueMonitor $monitor,
    ) {}

    /**
     * `part=classes`: only what is waiting by job class (a scan of every
     * payload, the slow part); `part=core`: the rest without it; none: both.
     */
    public function summary(Request $request): JsonResponse
    {
        return match ($request->query('part')) {
            'classes' => response()->json(['data' => ['classes' => $this->monitor->classesNow()]]),
            'core' => response()->json(['data' => $this->monitor->summary(false)]),
            default => response()->json(['data' => $this->monitor->summary()]),
        };
    }

    public function jobs(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'queue' => ['nullable', 'string', 'max:255'],
            'state' => ['nullable', Rule::in(DriverCheckQueueMonitor::STATES)],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->paginated($this->monitor->pending($filters));
    }

    public function failed(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'queue' => ['nullable', 'string', 'max:255'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->paginated($this->monitor->failed($filters));
    }

    public function failedJob(string $uuid): JsonResponse
    {
        $job = $this->monitor->failedJob($uuid);

        abort_if($job === null, 404);

        return response()->json(['data' => $job]);
    }

    public function retry(Request $request, string $uuid): JsonResponse
    {
        return $this->retrying([$uuid], $request);
    }

    public function retryAll(Request $request): JsonResponse
    {
        return $this->retrying(null, $request);
    }

    /**
     * A job that can no longer be rebuilt (its class was renamed, its
     * payload was encrypted with another key) makes queue:retry throw.
     * That is an answer for the operator, not a server error.
     *
     * @param list<string>|null $uuids
     */
    private function retrying(?array $uuids, Request $request): JsonResponse
    {
        try {
            $count = $this->monitor->retry($uuids, $request->user()?->id);
        } catch (Throwable $e) {
            Log::error('Failed job retry from the queue panel failed', [
                'uuids' => $uuids ?? 'all',
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return response()->json([
                'message' => __('telegram.queue.errors.retry', ['error' => $e->getMessage()]),
            ], 422);
        }

        abort_if($uuids !== null && $count === 0, 404);

        return response()->json([
            'message' => __('telegram.queue.messages.retried', ['count' => $count]),
            'count' => $count,
        ]);
    }

    public function forget(Request $request, string $uuid): JsonResponse
    {
        $count = $this->monitor->forget($uuid, $request->user()?->id);

        abort_if($count === 0, 404);

        return response()->json([
            'message' => __('telegram.queue.messages.deleted', ['count' => $count]),
        ]);
    }

    public function flush(Request $request): JsonResponse
    {
        $count = $this->monitor->forget(null, $request->user()?->id);

        return response()->json([
            'message' => __('telegram.queue.messages.deleted', ['count' => $count]),
            'count' => $count,
        ]);
    }

    public function move(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'queue' => ['required', 'string', 'max:255'],
        ]);

        $count = $this->monitor->moveToMain($validated['queue'], $request->user()?->id);

        return response()->json([
            'message' => __('telegram.queue.messages.moved', ['count' => $count]),
            'count' => $count,
        ]);
    }

    /**
     * Same shape as a Laravel resource collection, which is what the
     * panel's list pages read.
     */
    private function paginated(LengthAwarePaginator $page): JsonResponse
    {
        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }
}
