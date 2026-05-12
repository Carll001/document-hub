<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

class BackgroundJobsController extends Controller
{
    /**
     * Show superadmin queue/failed-job visibility page.
     */
    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'queue' => ['nullable', 'string', 'max:120'],
        ]);

        $queueFilter = isset($validated['queue']) ? trim((string) $validated['queue']) : '';

        $pendingByQueue = [];
        $pendingTotal = 0;
        $failedRows = [];
        $failedByQueue = [];
        $failedTotal = 0;

        if (Schema::hasTable('jobs')) {
            $pendingQuery = DB::table('jobs')
                ->select('queue', DB::raw('COUNT(*) as pending_count'))
                ->groupBy('queue')
                ->orderBy('queue');

            if ($queueFilter !== '') {
                $pendingQuery->where('queue', $queueFilter);
            }

            $pendingByQueue = $pendingQuery
                ->get()
                ->map(fn (object $row): array => [
                    'queue' => (string) ($row->queue ?? ''),
                    'pendingCount' => (int) ($row->pending_count ?? 0),
                ])
                ->all();

            $pendingTotal = array_sum(array_map(
                static fn (array $row): int => (int) ($row['pendingCount'] ?? 0),
                $pendingByQueue,
            ));
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedSummaryQuery = DB::table('failed_jobs')
                ->select('queue', DB::raw('COUNT(*) as failed_count'))
                ->groupBy('queue')
                ->orderBy('queue');

            if ($queueFilter !== '') {
                $failedSummaryQuery->where('queue', $queueFilter);
            }

            $failedByQueue = $failedSummaryQuery
                ->get()
                ->map(fn (object $row): array => [
                    'queue' => (string) ($row->queue ?? ''),
                    'failedCount' => (int) ($row->failed_count ?? 0),
                ])
                ->all();

            $failedTotal = array_sum(array_map(
                static fn (array $row): int => (int) ($row['failedCount'] ?? 0),
                $failedByQueue,
            ));

            $failedQuery = DB::table('failed_jobs')
                ->select('id', 'queue', 'exception', 'failed_at')
                ->orderByDesc('failed_at')
                ->orderByDesc('id')
                ->limit(50);

            if ($queueFilter !== '') {
                $failedQuery->where('queue', $queueFilter);
            }

            $failedRows = $failedQuery
                ->get()
                ->map(function (object $row): array {
                    $exception = is_string($row->exception ?? null)
                        ? $row->exception
                        : '';
                    $firstLine = trim(strtok($exception, "\n") ?: '');

                    return [
                        'id' => (string) ($row->id ?? ''),
                        'queue' => (string) ($row->queue ?? ''),
                        'failedAt' => is_string($row->failed_at ?? null)
                            ? $row->failed_at
                            : null,
                        'exceptionSummary' => $firstLine !== ''
                            ? $firstLine
                            : 'No exception message available.',
                    ];
                })
                ->all();
        }

        $knownQueues = collect([
            ...array_map(
                static fn (array $row): string => (string) ($row['queue'] ?? ''),
                $pendingByQueue,
            ),
            ...array_map(
                static fn (array $row): string => (string) ($row['queue'] ?? ''),
                $failedByQueue,
            ),
            'document-content',
            'afs-filing',
            'filing-1702',
            'document-merger',
            'email-sync',
            'default',
        ])
            ->filter(static fn (mixed $queue): bool => is_string($queue) && trim($queue) !== '')
            ->unique()
            ->values()
            ->all();

        return Inertia::render('BackgroundJobs', [
            'indexUrl' => route('background-jobs.index'),
            'filters' => [
                'queue' => $queueFilter,
            ],
            'summary' => [
                'pendingTotal' => $pendingTotal,
                'failedTotal' => $failedTotal,
            ],
            'pendingByQueue' => $pendingByQueue,
            'failedByQueue' => $failedByQueue,
            'failedJobs' => $failedRows,
            'knownQueues' => $knownQueues,
        ]);
    }
}

