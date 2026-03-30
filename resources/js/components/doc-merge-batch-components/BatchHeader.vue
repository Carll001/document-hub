<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Download,
    FolderClosed,
    Trash2,
    Upload,
} from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { BatchDetail } from '@/components/doc-merge-batch-components/types';
import { formatDateTime } from '@/components/doc-merge-batch-components/utils';
import docMerge from '@/routes/doc-merge';

const props = defineProps<{
    batch: BatchDetail;
    deleteBatchProcessing: boolean;
    variant: 'toolbar' | 'summary';
}>();

const emit = defineEmits<{
    openBulkFolder: [];
    openBulkZip: [];
    openDeleteBatch: [];
}>();
</script>

<template>
    <div
        v-if="props.variant === 'toolbar'"
        class="flex flex-wrap items-center justify-end gap-2"
    >
        <Button as-child variant="outline" size="sm" class="gap-2 text-xs">
            <Link :href="docMerge.index().url">
                <ArrowLeft class="size-4" />
                Back to Doc Merge
            </Link>
        </Button>
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="gap-2 text-xs"
            @click="emit('openBulkFolder')"
        >
            <Upload class="size-4" />
            Bulk merge folders
        </Button>
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="gap-2 text-xs"
            @click="emit('openBulkZip')"
        >
            <Upload class="size-4" />
            Bulk merge ZIP
        </Button>
        <Button as-child variant="outline" size="sm" class="gap-2 text-xs">
            <a :href="props.batch.downloadUrl">
                <Download class="size-4" />
                Download batch ZIP
            </a>
        </Button>
        <Button
            type="button"
            variant="destructive"
            size="sm"
            class="gap-2 text-xs"
            :disabled="props.deleteBatchProcessing"
            @click="emit('openDeleteBatch')"
        >
            <Trash2 class="size-4" />
            Delete batch
        </Button>
    </div>

    <Card v-else class="rounded-3xl">
        <CardHeader class="space-y-1">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="rounded-2xl bg-muted p-3">
                        <FolderClosed class="size-6 text-foreground" />
                    </div>
                    <div class="space-y-1">
                        <CardTitle class="text-xl">
                            {{ props.batch.name }}
                        </CardTitle>
                        <CardDescription>
                            This folder contains generated PDFs and failed
                            results for this batch.
                        </CardDescription>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Badge
                        variant="outline"
                        class="border-emerald-200 bg-emerald-50 text-emerald-700"
                    >
                        {{
                            props.batch.mergedCount === 1
                                ? '1 generated PDF'
                                : `${props.batch.mergedCount} generated PDFs`
                        }}
                    </Badge>
                    <Badge variant="outline">
                        {{ props.batch.failedCount }} failed
                    </Badge>
                </div>
            </div>
        </CardHeader>

        <CardContent>
            <p class="text-sm text-muted-foreground">
                Last processed:
                {{
                    props.batch.lastProcessedAt
                        ? formatDateTime(props.batch.lastProcessedAt)
                        : 'Not processed yet'
                }}
            </p>
        </CardContent>
    </Card>
</template>
