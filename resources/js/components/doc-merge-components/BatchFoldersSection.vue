<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { Download, FolderClosed, FolderOpen, MoreHorizontal } from 'lucide-vue-next';
import { computed, h } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { CardContent } from '@/components/ui/card';
import type {
    BatchPaginationState,
    BatchSummary,
} from '@/components/doc-merge-components/types';
import {
    batchProcessingStatusLabel,
    formatDateTime,
} from '@/components/doc-merge-components/utils';
import docMerge from '@/routes/doc-merge';

const props = defineProps<{
    batches: BatchSummary[];
    pagination: BatchPaginationState;
}>();

function visitPage(page: number): void {
    if (page === props.pagination.currentPage) {
        return;
    }

    router.get(
        docMerge.index.url({
            query: {
                page,
                per_page: props.pagination.perPage,
            },
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function updatePerPage(perPage: number): void {
    if (perPage === props.pagination.perPage) {
        return;
    }

    router.get(
        docMerge.index.url({
            query: {
                page: 1,
                per_page: perPage,
            },
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}

const tableMeta = computed(() => ({
    current_page: props.pagination.currentPage,
    last_page: props.pagination.lastPage,
    per_page: props.pagination.perPage,
    total: props.pagination.total,
}));

const batchColumns = computed<ColumnDef<BatchSummary>[]>(() => [
    {
        id: 'index',
        header: () => h('div', { class: 'text-left' }, '#'),
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'span',
                { class: 'text-sm text-muted-foreground' },
                String(
                    (props.pagination.currentPage - 1) * props.pagination.perPage
                    + row.index
                    + 1,
                ),
            ),
    },
    {
        accessorKey: 'name',
        header: () => h('div', { class: 'text-left' }, 'Batch'),
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2' }, [
                h(FolderClosed, { class: 'size-4 text-muted-foreground' }),
                h(
                    Link,
                    {
                        href: row.original.showUrl,
                        class: 'font-medium text-foreground underline-offset-4 hover:underline',
                    },
                    () => row.original.name,
                ),
            ]),
    },
    {
        accessorKey: 'lastProcessedAt',
        header: () => h('div', { class: 'text-left' }, 'Last Processed'),
        enableSorting: false,
        cell: ({ row }) =>
            row.original.lastProcessedAt ? formatDateTime(row.original.lastProcessedAt) : 'Not processed yet',
    },
    {
        accessorKey: 'processingStatus',
        header: () => h('div', { class: 'text-center' }, 'Status'),
        enableSorting: false,
        cell: ({ row }) => {
            const status = row.original.processingStatus;

            if (!status) {
                return h('div', { class: 'flex justify-center' }, [
                    h(
                    Badge,
                    {
                        variant: 'outline',
                        class: 'border-muted-foreground/30 text-muted-foreground',
                    },
                    () => 'Idle',
                )]);
            }

            return h('div', { class: 'flex justify-center' }, [h(
                Badge,
                {
                    variant: status === 'failed' ? 'destructive' : 'outline',
                    class:
                        status === 'queued'
                            ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'
                            : status === 'processing'
                              ? 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300'
                              : undefined,
                },
                () => batchProcessingStatusLabel(status),
            )]);
        },
    },
    {
        accessorKey: 'mergedCount',
        header: () => h('div', { class: 'text-center' }, 'Merged'),
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'text-center' }, String(row.original.mergedCount)),
    },
    {
        accessorKey: 'failedCount',
        header: () => h('div', { class: 'text-center' }, 'Failed'),
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'text-center' }, String(row.original.failedCount)),
    },
    {
        id: 'actions',
        header: () => h('div', { class: 'w-full text-right' }, 'Actions'),
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'flex w-full justify-end' }, [
                h(
                    DropdownMenu,
                    () => [
                        h(
                            DropdownMenuTrigger,
                            { asChild: true },
                            [
                                h(
                                    Button,
                                    { variant: 'ghost', size: 'icon', class: 'size-8' },
                                    () => h(MoreHorizontal, { class: 'size-4' }),
                                ),
                            ],
                        ),
                        h(
                            DropdownMenuContent,
                            { align: 'end', class: 'w-44' },
                            () => [
                                h(
                                    DropdownMenuItem,
                                    {
                                        asChild: true,
                                    },
                                    () =>
                                        h(
                                            Link,
                                            {
                                                href: row.original.showUrl,
                                            },
                                            () => [
                                                h(FolderOpen, { class: 'mr-2 size-4' }),
                                                'Open batch',
                                            ],
                                        ),
                                ),
                                h(
                                    DropdownMenuItem,
                                    {
                                        asChild: true,
                                    },
                                    () =>
                                        h(
                                            'a',
                                            {
                                                href: row.original.downloadUrl,
                                            },
                                            [
                                                h(Download, { class: 'mr-2 size-4' }),
                                                'Download ZIP',
                                            ],
                                        ),
                                ),
                            ],
                        ),
                    ],
                ),
            ]),
    },
]);
</script>

<template>
    <CardContent class="space-y-4">
        <DataTable
            :columns="batchColumns"
            :data="props.batches"
            :meta="tableMeta"
            empty-message="No batch folders yet. Create one to start uploading and merging."
            @page-change="visitPage"
            @per-page-change="updatePerPage"
        />
    </CardContent>
</template>
