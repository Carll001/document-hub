<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Download, FolderClosed } from 'lucide-vue-next';
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
import {
    batchProcessingStatusLabel,
    formatDateTime,
} from '@/components/doc-merge-components/utils';
import docMerge from '@/routes/doc-merge';

const props = defineProps<{
    batches: BatchSummary[];
    pagination: BatchPaginationState;
}>();
const paginationControls = ref<HTMLElement | null>(null);

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
                    <Badge
                        v-if="batch.processingStatus"
                        :variant="
                            batch.processingStatus === 'failed'
                                ? 'destructive'
                                : 'outline'
                        "
                        :class="
                            batch.processingStatus === 'queued'
                                ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'
                                : batch.processingStatus === 'processing'
                                  ? 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300'
                                  : undefined
                        "
                    >
                        {{ batchProcessingStatusLabel(batch.processingStatus) }}
                    </Badge>
                </div>

                <p
                    v-if="batch.processingStatus === 'failed' && batch.processingError"
                    class="mt-3 text-sm text-destructive"
                >
                    {{ batch.processingError }}
                </p>

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
            class="flex items-center justify-center gap-2 pt-2"
        >
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="props.pagination.currentPage <= 1"
                @click="visitPage(props.pagination.currentPage - 1)"
            >
                Previous
            </Button>
            <span class="text-sm">Page {{ props.pagination.currentPage }} / {{ props.pagination.lastPage }}</span>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="
                    props.pagination.currentPage >= props.pagination.lastPage
                "
                @click="visitPage(props.pagination.currentPage + 1)"
            >
                Next
            </Button>
        </div>
    </CardContent>
</template>
