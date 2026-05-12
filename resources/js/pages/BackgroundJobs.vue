<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Activity, AlertTriangle, Clock3 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type PendingByQueue = {
    queue: string;
    pendingCount: number;
};

type FailedByQueue = {
    queue: string;
    failedCount: number;
};

type FailedJobRow = {
    id: string;
    queue: string;
    failedAt: string | null;
    exceptionSummary: string;
};

const props = defineProps<{
    indexUrl: string;
    filters: {
        queue: string;
    };
    summary: {
        pendingTotal: number;
        failedTotal: number;
    };
    pendingByQueue: PendingByQueue[];
    failedByQueue: FailedByQueue[];
    failedJobs: FailedJobRow[];
    knownQueues: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Background Jobs',
        href: '/background-jobs',
    },
];

const queueFilter = ref(props.filters.queue ?? '');

watch(
    () => props.filters.queue,
    (value) => {
        queueFilter.value = value ?? '';
    },
);

const sortedKnownQueues = computed(() =>
    [...props.knownQueues].sort((a, b) => a.localeCompare(b)),
);

function applyFilters(): void {
    const queue = queueFilter.value.trim();

    router.get(
        props.indexUrl,
        queue !== '' ? { queue } : {},
        {
            preserveScroll: true,
            preserveState: true,
            replace: true,
            only: [
                'filters',
                'summary',
                'pendingByQueue',
                'failedByQueue',
                'failedJobs',
                'knownQueues',
            ],
        },
    );
}

function clearFilters(): void {
    queueFilter.value = '';
    applyFilters();
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Unknown';
    }

    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}
</script>

<template>
    <Head title="Background Jobs" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardHeader>
                    <div class="flex items-start gap-3">
                        <div class="rounded-2xl bg-muted p-3">
                            <Activity class="size-6 text-foreground" />
                        </div>
                        <div class="space-y-1">
                            <CardTitle>Background Jobs</CardTitle>
                            <CardDescription>
                                Superadmin visibility for pending and failed queue jobs.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-col gap-3 md:flex-row md:items-end">
                        <div class="w-full md:max-w-sm space-y-2">
                            <Label for="queue-filter">Queue</Label>
                            <Input
                                id="queue-filter"
                                v-model="queueFilter"
                                list="known-queues"
                                placeholder="Filter by queue name"
                                @keyup.enter="applyFilters"
                            />
                            <datalist id="known-queues">
                                <option
                                    v-for="queue in sortedKnownQueues"
                                    :key="queue"
                                    :value="queue"
                                />
                            </datalist>
                        </div>
                        <div class="flex gap-2">
                            <Button type="button" @click="applyFilters">
                                Apply
                            </Button>
                            <Button type="button" variant="outline" @click="clearFilters">
                                Clear
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-4 md:grid-cols-2">
                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <Clock3 class="size-4" />
                            Pending Jobs
                        </CardTitle>
                        <CardDescription>
                            {{ props.summary.pendingTotal }} pending total
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="props.pendingByQueue.length === 0" class="text-sm text-muted-foreground">
                            No pending jobs found.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="row in props.pendingByQueue"
                                :key="`pending-${row.queue}`"
                                class="flex items-center justify-between rounded-lg border px-3 py-2"
                            >
                                <span class="font-medium">{{ row.queue }}</span>
                                <Badge variant="secondary">{{ row.pendingCount }}</Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2">
                            <AlertTriangle class="size-4" />
                            Failed Jobs
                        </CardTitle>
                        <CardDescription>
                            {{ props.summary.failedTotal }} failed total
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="props.failedByQueue.length === 0" class="text-sm text-muted-foreground">
                            No failed jobs found.
                        </div>
                        <div v-else class="space-y-2">
                            <div
                                v-for="row in props.failedByQueue"
                                :key="`failed-${row.queue}`"
                                class="flex items-center justify-between rounded-lg border px-3 py-2"
                            >
                                <span class="font-medium">{{ row.queue }}</span>
                                <Badge variant="destructive">{{ row.failedCount }}</Badge>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle>Recent Failed Job Details</CardTitle>
                    <CardDescription>
                        Most recent 50 failed jobs{{ props.filters.queue ? ` for ${props.filters.queue}` : '' }}.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="props.failedJobs.length === 0" class="text-sm text-muted-foreground">
                        No failed jobs to show.
                    </div>
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b text-left">
                                    <th class="px-2 py-2">ID</th>
                                    <th class="px-2 py-2">Queue</th>
                                    <th class="px-2 py-2">Failed At</th>
                                    <th class="px-2 py-2">Exception</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="job in props.failedJobs"
                                    :key="`job-${job.id}`"
                                    class="border-b align-top"
                                >
                                    <td class="px-2 py-2 font-mono">{{ job.id }}</td>
                                    <td class="px-2 py-2">{{ job.queue }}</td>
                                    <td class="px-2 py-2 whitespace-nowrap">
                                        {{ formatDateTime(job.failedAt) }}
                                    </td>
                                    <td class="px-2 py-2 font-mono text-xs">
                                        {{ job.exceptionSummary }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>

