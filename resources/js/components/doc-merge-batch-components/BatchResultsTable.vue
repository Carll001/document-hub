<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Download,
    Eye,
    FileText,
    Mail,
    MoreHorizontal,
    Search,
    Trash2,
} from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    BatchMergeHistoryRecord,
    BatchResultsPaginationState,
} from '@/components/doc-merge-batch-components/types';
import {
    receiptJobIsActive,
    receiptJobStatusLabel,
    formatDateTime,
    formatFileSize,
    formatTinDigitsOnly,
    isMergedRecord,
    mergeHistoryRecordKey,
} from '@/components/doc-merge-batch-components/utils';

const props = defineProps<{
    canBulkDelete: boolean;
    isBatchBusy: boolean;
    isRecordSelected: (record: BatchMergeHistoryRecord) => boolean;
    pageUrl: string;
    pagination: BatchResultsPaginationState;
    results: BatchMergeHistoryRecord[];
    search: string;
    selectedCount: number;
    selectAllState: boolean | 'indeterminate';
    totalResults: number;
}>();
const paginationControls = ref<HTMLElement | null>(null);

const emit = defineEmits<{
    deleteRecord: [record: BatchMergeHistoryRecord];
    openBulkDelete: [];
    openFailure: [record: BatchMergeHistoryRecord];
    openPreview: [record: BatchMergeHistoryRecord];
    openReceipt: [record: BatchMergeHistoryRecord];
    openRemoveReceipt: [record: BatchMergeHistoryRecord];
    openSendEmail: [record: BatchMergeHistoryRecord];
    toggleAll: [checked: boolean | 'indeterminate'];
    toggleRecord: [payload: { record: BatchMergeHistoryRecord; checked: boolean | 'indeterminate' }];
    'update:search': [value: string];
}>();

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
        props.pageUrl,
        { page },
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

function receiptMutationDisabled(record: BatchMergeHistoryRecord): boolean {
    return props.isBatchBusy || (isMergedRecord(record) && receiptJobIsActive(record.receiptJobStatus));
}
</script>

<template>
    <CardHeader class="space-y-1">
        <CardTitle class="text-xl">Merge table</CardTitle>
        <CardDescription>
            Review merged outputs and failed results inside this batch.
        </CardDescription>
    </CardHeader>

    <CardContent class="space-y-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    :model-value="props.search"
                    type="search"
                    placeholder="Search this batch merge table"
                    class="pl-10"
                    @update:model-value="emit('update:search', String($event))"
                />
            </div>

            <Button
                type="button"
                variant="destructive"
                size="sm"
                class="gap-2 self-end md:self-auto"
                :disabled="!props.canBulkDelete"
                @click="emit('openBulkDelete')"
            >
                <Trash2 class="size-4" />
                {{
                    props.selectedCount > 0
                        ? `Delete selected (${props.selectedCount})`
                        : 'Delete selected'
                }}
            </Button>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-background">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[1%]">
                            <Checkbox
                                :checked="props.selectAllState"
                                :disabled="props.results.length === 0"
                                aria-label="Select all merge results"
                                @update:checked="emit('toggleAll', $event)"
                            />
                        </TableHead>
                        <TableHead class="w-[1%]">#</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead>Result</TableHead>
                        <TableHead>TIN</TableHead>
                        <TableHead>Sources</TableHead>
                        <TableHead>Receipt</TableHead>
                        <TableHead>Size</TableHead>
                        <TableHead>Generated</TableHead>
                        <TableHead class="w-[1%] text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <template v-if="props.results.length > 0">
                        <TableRow
                            v-for="(record, index) in props.results"
                            :key="mergeHistoryRecordKey(record)"
                        >
                            <TableCell>
                                <Checkbox
                                    :checked="props.isRecordSelected(record)"
                                    :aria-label="`Select ${record.fileName}`"
                                    @update:checked="
                                        emit('toggleRecord', { record, checked: $event })
                                    "
                                />
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ index + 1 }}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    v-if="isMergedRecord(record)"
                                    variant="outline"
                                    class="border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                >
                                    Success
                                </Badge>
                                <Badge v-else variant="destructive">
                                    Failed
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <div class="min-w-0 space-y-1">
                                    <p class="max-w-[16rem] truncate font-medium text-foreground">
                                        {{ record.fileName }}
                                    </p>
                                    <p
                                        v-if="isMergedRecord(record)"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ record.sourceCount }} source{{
                                            record.sourceCount === 1 ? ' file' : ' files'
                                        }}
                                    </p>
                                    <template v-else>
                                        <p class="text-xs text-muted-foreground">
                                            Matched PDF: {{ record.groupLabel }}
                                        </p>
                                        <p class="max-w-[18rem] text-xs text-destructive">
                                            {{ record.errorMessage }}
                                        </p>
                                    </template>
                                </div>
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{
                                    isMergedRecord(record) && record.tinNumber
                                        ? formatTinDigitsOnly(record.tinNumber)
                                        : '-'
                                }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                <template v-if="isMergedRecord(record)">
                                    <Badge
                                        v-if="record.sourceFileNames.length > 0"
                                        variant="outline"
                                        class="max-w-[14rem] truncate align-middle"
                                        :title="record.sourceFileNames[0]"
                                    >
                                        {{ record.sourceFileNames[0] }}
                                    </Badge>
                                    <span v-else>
                                        {{ `${record.sourceCount} source${record.sourceCount === 1 ? '' : 's'}` }}
                                    </span>
                                </template>
                                <span v-else>-</span>
                            </TableCell>
                            <TableCell>
                                <div
                                    v-if="isMergedRecord(record)"
                                    class="space-y-1"
                                >
                                    <Badge
                                        v-if="record.receiptJobStatus"
                                        :variant="
                                            record.receiptJobStatus === 'failed'
                                                ? 'destructive'
                                                : 'outline'
                                        "
                                        :class="
                                            record.receiptJobStatus === 'queued'
                                                ? 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300'
                                                : record.receiptJobStatus === 'processing'
                                                  ? 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300'
                                                  : undefined
                                        "
                                    >
                                        {{
                                            receiptJobStatusLabel(
                                                record.receiptJobStatus,
                                            )
                                        }}
                                    </Badge>
                                    <Badge
                                        v-else-if="record.hasReceipt"
                                        variant="outline"
                                        class="border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300"
                                    >
                                        Attached
                                    </Badge>
                                    <span v-else class="text-sm text-muted-foreground">
                                        -
                                    </span>
                                    <p
                                        v-if="
                                            record.receiptJobStatus === 'failed' &&
                                            record.receiptJobError
                                        "
                                        class="max-w-[16rem] text-xs text-destructive"
                                    >
                                        {{ record.receiptJobError }}
                                    </p>
                                </div>
                                <span v-else class="text-sm text-muted-foreground">
                                    -
                                </span>
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{
                                    record.fileSize === null
                                        ? '-'
                                        : formatFileSize(record.fileSize)
                                }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDateTime(record.createdAt) }}
                            </TableCell>
                            <TableCell>
                                <div class="flex justify-end">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                            >
                                                <MoreHorizontal class="size-4" />
                                                <span class="sr-only">Open actions</span>
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-48 rounded-lg"
                                        >
                                            <template v-if="isMergedRecord(record)">
                                                <DropdownMenuItem
                                                    @select="emit('openPreview', record)"
                                                >
                                                    <Eye class="size-4" />
                                                    Preview
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="receiptMutationDisabled(record)"
                                                    @select="emit('openReceipt', record)"
                                                >
                                                    <FileText class="size-4" />
                                                    {{
                                                        record.hasReceipt
                                                            ? 'Replace receipt'
                                                            : 'Add receipt'
                                                    }}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="props.isBatchBusy"
                                                    @select="emit('openSendEmail', record)"
                                                >
                                                    <Mail class="size-4" />
                                                    Send to email
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="
                                                        record.hasReceipt &&
                                                        record.receiptRemoveUrl
                                                    "
                                                    :disabled="receiptMutationDisabled(record)"
                                                    variant="destructive"
                                                    @select="emit('openRemoveReceipt', record)"
                                                >
                                                    <Trash2 class="size-4" />
                                                    Remove receipt
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem :as-child="true">
                                                    <a
                                                        :href="record.downloadUrl ?? undefined"
                                                        class="flex w-full items-center gap-2"
                                                    >
                                                        <Download class="size-4" />
                                                        Download
                                                    </a>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="props.isBatchBusy"
                                                    variant="destructive"
                                                    @select="emit('deleteRecord', record)"
                                                >
                                                    <Trash2 class="size-4" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </template>
                                            <template v-else>
                                                <DropdownMenuItem
                                                    @select="emit('openFailure', record)"
                                                >
                                                    <Eye class="size-4" />
                                                    View error
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="props.isBatchBusy"
                                                    variant="destructive"
                                                    @select="emit('deleteRecord', record)"
                                                >
                                                    <Trash2 class="size-4" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </TableCell>
                        </TableRow>
                    </template>

                    <TableEmpty v-else :colspan="10">
                        {{
                            props.totalResults === 0
                                ? 'No merge results in this batch yet.'
                                : 'No batch results match your search.'
                        }}
                    </TableEmpty>
                </TableBody>
            </Table>
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
