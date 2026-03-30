<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { ChevronLeft, ChevronRight, Download, FolderClosed } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    BatchPaginationState,
    BatchSummary,
} from '@/components/doc-merge-components/types';
import { formatDateTime } from '@/components/doc-merge-components/utils';
import docMerge from '@/routes/doc-merge';

const props = defineProps<{
    batches: BatchSummary[];
    pagination: BatchPaginationState;
}>();
const paginationControls = ref<HTMLElement | null>(null);

type PaginationItem = number | 'ellipsis-start' | 'ellipsis-end';

const paginationItems = computed<PaginationItem[]>(() => {
    const { currentPage, lastPage } = props.pagination;

    if (lastPage <= 1) {
        return [1];
    }

    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, index) => index + 1);
    }

    if (currentPage <= 4) {
        return [1, 2, 3, 4, 5, 'ellipsis-end', lastPage];
    }

    if (currentPage >= lastPage - 3) {
        return [
            1,
            'ellipsis-start',
            lastPage - 4,
            lastPage - 3,
            lastPage - 2,
            lastPage - 1,
            lastPage,
        ];
    }

    return [
        1,
        'ellipsis-start',
        currentPage - 1,
        currentPage,
        currentPage + 1,
        'ellipsis-end',
        lastPage,
    ];
});

function visitPage(page: number): void {
    if (page === props.pagination.currentPage) {
        return;
    }

    router.get(
        docMerge.index.url({
            query: { page },
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                void nextTick(() => {
                    paginationControls.value?.scrollIntoView({
                        block: 'end',
                    });
                });
            },
        },
    );
}
</script>

<template>
    <CardHeader class="space-y-1">
        <CardTitle class="text-xl">Batch folders</CardTitle>
        <CardDescription>
            Create a folder-like batch, open it, then manage bulk uploads and
            the merge table inside that workspace.
        </CardDescription>
    </CardHeader>

    <CardContent class="space-y-4">
        <div
            v-if="props.batches.length === 0"
            class="rounded-2xl border border-dashed px-4 py-6 text-sm text-muted-foreground"
        >
            No batch folders yet. Create one to start uploading and merging
            inside a dedicated workspace.
        </div>

        <div v-else class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="batch in props.batches"
                :key="batch.id"
                class="rounded-3xl border bg-background p-5 shadow-sm transition hover:border-foreground/20"
            >
                <div class="flex items-center gap-3">
                    <div class="rounded-2xl bg-muted p-3">
                        <FolderClosed class="size-6 text-foreground" />
                    </div>
                    <div class="space-y-1">
                        <p class="font-medium text-foreground">
                            {{ batch.name }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{
                                batch.lastProcessedAt
                                    ? `Last run ${formatDateTime(batch.lastProcessedAt)}`
                                    : 'Not processed yet'
                            }}
                        </p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Badge variant="outline">{{ batch.mergedCount }} merged</Badge>
                    <Badge variant="outline">{{ batch.failedCount }} failed</Badge>
                </div>

                <div class="mt-5 flex flex-wrap gap-2">
                    <Button as-child size="sm" class="gap-2">
                        <Link :href="batch.showUrl">
                            <FolderClosed class="size-4" />
                            Open folder
                        </Link>
                    </Button>
                    <Button
                        as-child
                        type="button"
                        variant="outline"
                        size="sm"
                        class="gap-2"
                    >
                        <a :href="batch.downloadUrl">
                            <Download class="size-4" />
                            Download ZIP
                        </a>
                    </Button>
                </div>
            </div>
        </div>

        <div
            v-if="props.pagination.lastPage > 1"
            ref="paginationControls"
            class="flex flex-wrap items-center justify-center gap-2 pt-2"
        >
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="gap-2"
                :disabled="props.pagination.currentPage <= 1"
                @click="visitPage(props.pagination.currentPage - 1)"
            >
                <ChevronLeft class="size-4" />
                Previous
            </Button>

            <template v-for="item in paginationItems" :key="String(item)">
                <Button
                    v-if="typeof item === 'number'"
                    type="button"
                    size="sm"
                    :variant="
                        item === props.pagination.currentPage ? 'default' : 'outline'
                    "
                    :aria-current="
                        item === props.pagination.currentPage ? 'page' : undefined
                    "
                    @click="visitPage(item)"
                >
                    {{ item }}
                </Button>
                <span
                    v-else
                    class="px-1 text-sm text-muted-foreground"
                    aria-hidden="true"
                >
                    ...
                </span>
            </template>

            <Button
                type="button"
                variant="outline"
                size="sm"
                class="gap-2"
                :disabled="
                    props.pagination.currentPage >= props.pagination.lastPage
                "
                @click="visitPage(props.pagination.currentPage + 1)"
            >
                Next
                <ChevronRight class="size-4" />
            </Button>
        </div>
    </CardContent>
</template>
