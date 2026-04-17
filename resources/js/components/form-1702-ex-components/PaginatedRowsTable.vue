<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ChevronLeft,
    ChevronRight,
    Download,
    Eye,
    MoreHorizontal,
    Pencil,
    RotateCcw,
    Search,
    Trash2,
    Upload,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    Form1702ExBatchRow,
    Form1702ExCompletedExportState,
    Form1702ExRowFilters,
    Form1702ExRowPagination,
} from '@/components/form-1702-ex-components/types';
import {
    formatDateTime,
    pdfStatusClass,
    pdfStatusLabel,
    receiptJobIsActive,
    receiptJobStatusLabel,
} from '@/components/form-1702-ex-components/utils';

const props = withDefaults(
    defineProps<{
        exportUrl: string;
        filters: Form1702ExRowFilters;
        isBusy?: boolean;
        isDeleteProcessing?: boolean;
        pageUrl: string;
        pagination: Form1702ExRowPagination;
        rows: Form1702ExBatchRow[];
        rowsExportState: Form1702ExCompletedExportState;
    }>(),
    {
        isBusy: false,
        isDeleteProcessing: false,
    },
);

const emit = defineEmits<{
    openRecipientEditor: [row: Form1702ExBatchRow];
    openReceipt: [row: Form1702ExBatchRow];
    openRemoveReceipt: [row: Form1702ExBatchRow];
    openTemporaryReceipt: [row: Form1702ExBatchRow];
    regenerate: [row: Form1702ExBatchRow];
    requestDelete: [rowIds: string[]];
}>();

const searchValue = ref(props.filters.search);
const searchTimeoutId = ref<number | null>(null);
const selectedRowIds = ref<string[]>([]);
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
        return [1, 'ellipsis-start', lastPage - 4, lastPage - 3, lastPage - 2, lastPage - 1, lastPage];
    }

    return [1, 'ellipsis-start', currentPage - 1, currentPage, currentPage + 1, 'ellipsis-end', lastPage];
});

const canBulkDelete = computed(
    () =>
        selectedRowIds.value.length > 0
        && !props.isBusy
        && !props.isDeleteProcessing,
);
const isRowsExportBusy = computed(
    () =>
        props.rowsExportState.status === 'queued'
        || props.rowsExportState.status === 'processing',
);
const canExportList = computed(
    () => props.pagination.total > 0 && !isRowsExportBusy.value,
);
const selectAllState = computed<boolean | 'indeterminate'>(() => {
    if (props.rows.length === 0) {
        return false;
    }

    if (selectedRowIds.value.length === 0) {
        return false;
    }

    if (selectedRowIds.value.length === props.rows.length) {
        return true;
    }

    return 'indeterminate';
});

watch(
    () => props.filters.search,
    (value) => {
        searchValue.value = value;
    },
);

watch(
    () => props.rows.map((row) => row.id),
    (rowIds) => {
        const availableRowIds = new Set(rowIds);

        selectedRowIds.value = selectedRowIds.value.filter((rowId) =>
            availableRowIds.has(rowId),
        );
    },
    { immediate: true },
);

watch(searchValue, (value) => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }

    searchTimeoutId.value = window.setTimeout(() => {
        visitIndex({
            page: 1,
            search: value.trim(),
        });
    }, 300);
});

onBeforeUnmount(() => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }
});

function visitIndex(overrides: Partial<{ page: number; search: string; sort: Form1702ExRowFilters['sort']; direction: Form1702ExRowFilters['direction'] }>): void {
    router.get(
        props.pageUrl,
        {
            page: overrides.page ?? props.pagination.currentPage,
            search: overrides.search ?? props.filters.search,
            sort: overrides.sort ?? props.filters.sort,
            direction: overrides.direction ?? props.filters.direction,
        },
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

function visitPage(page: number): void {
    if (page === props.pagination.currentPage) {
        return;
    }

    visitIndex({ page });
}

function toggleSort(sort: Form1702ExRowFilters['sort']): void {
    const nextDirection = props.filters.sort === sort && props.filters.direction === 'asc'
        ? 'desc'
        : 'asc';

    visitIndex({
        page: 1,
        sort,
        direction: nextDirection,
    });
}

function sortIndicator(sort: Form1702ExRowFilters['sort']): string {
    if (props.filters.sort !== sort) {
        return '';
    }

    return props.filters.direction === 'asc' ? '^' : 'v';
}

function isRowSelected(row: Form1702ExBatchRow): boolean {
    return selectedRowIds.value.includes(row.id);
}

function toggleRowSelection(row: Form1702ExBatchRow, checked: boolean | 'indeterminate'): void {
    if (checked === 'indeterminate') {
        return;
    }

    if (checked) {
        selectedRowIds.value = Array.from(new Set([...selectedRowIds.value, row.id]));

        return;
    }

    selectedRowIds.value = selectedRowIds.value.filter((rowId) => rowId !== row.id);
}

function toggleAllVisibleRows(checked: boolean | 'indeterminate'): void {
    if (checked === 'indeterminate') {
        return;
    }

    if (checked) {
        selectedRowIds.value = props.rows.map((row) => row.id);

        return;
    }

    selectedRowIds.value = [];
}

function requestDelete(rowIds: string[]): void {
    const uniqueRowIds = Array.from(new Set(rowIds.filter((rowId) => rowId !== '')));

    if (uniqueRowIds.length === 0) {
        return;
    }

    emit('requestDelete', uniqueRowIds);
}

function exportList(): void {
    if (!canExportList.value) {
        return;
    }

    router.get(
        props.exportUrl,
        {},
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function deleteDisabled(row: Form1702ExBatchRow): boolean {
    return (
        props.isBusy
        || props.isDeleteProcessing
        || row.pdfStatus === 'queued'
        || row.pdfStatus === 'processing'
        || receiptJobIsActive(row.receiptJobStatus)
    );
}

function regenerateDisabled(row: Form1702ExBatchRow): boolean {
    return (
        props.isBusy
        || props.isDeleteProcessing
        || row.pdfStatus === 'queued'
        || row.pdfStatus === 'processing'
        || receiptJobIsActive(row.receiptJobStatus)
    );
}

function receiptMutationDisabled(row: Form1702ExBatchRow): boolean {
    return (
        props.isBusy
        || props.isDeleteProcessing
        || row.pdfStatus !== 'generated'
        || receiptJobIsActive(row.receiptJobStatus)
    );
}

function autoReceiptLabel(row: Form1702ExBatchRow): string | null {
    switch (row.autoReceiptStatus) {
        case 'pending_pdf':
            return 'Matched email waiting for PDF';
        case 'queued':
            return 'Auto receipt queued';
        case 'applied':
            return 'Auto receipt applied';
        case 'failed':
            return 'Auto receipt failed';
        default:
            return null;
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    v-model="searchValue"
                    type="search"
                    placeholder="Search taxpayer, TIN, recipient, or status"
                    class="pl-10"
                />
            </div>

            <div class="flex flex-wrap gap-2 self-end md:self-auto">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2"
                    :disabled="!canExportList"
                    @click="exportList"
                >
                    <Download class="size-4" />
                    {{
                        isRowsExportBusy
                            ? 'Preparing export...'
                            : 'Export list'
                    }}
                </Button>

                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    class="gap-2"
                    :disabled="!canBulkDelete"
                    @click="requestDelete(selectedRowIds)"
                >
                    <Trash2 class="size-4" />
                    {{
                        selectedRowIds.length > 0
                            ? `Delete selected (${selectedRowIds.length})`
                            : 'Delete selected'
                    }}
                </Button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-background">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[1%]">
                            <Checkbox
                                :model-value="selectAllState"
                                :disabled="props.rows.length === 0 || props.isBusy || props.isDeleteProcessing"
                                aria-label="Select visible imported rows"
                                @update:model-value="toggleAllVisibleRows($event)"
                            />
                        </TableHead>
                        <TableHead class="w-[1%]">#</TableHead>
                        <TableHead>File name</TableHead>
                        <TableHead>Taxpayer</TableHead>
                        <TableHead>TIN</TableHead>
                        <TableHead>
                            Accepted receipts from
                        </TableHead>
                        <TableHead>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-auto px-0 font-medium"
                                @click="toggleSort('uploadedAt')"
                            >
                                Uploaded
                                <span class="ml-1 text-xs text-muted-foreground">
                                    {{ sortIndicator('uploadedAt') }}
                                </span>
                            </Button>
                        </TableHead>
                        <TableHead>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-auto px-0 font-medium"
                                @click="toggleSort('pdfStatus')"
                            >
                                PDF status
                                <span class="ml-1 text-xs text-muted-foreground">
                                    {{ sortIndicator('pdfStatus') }}
                                </span>
                            </Button>
                        </TableHead>
                        <TableHead>
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-auto px-0 font-medium"
                                @click="toggleSort('generatedAt')"
                            >
                                Generated
                                <span class="ml-1 text-xs text-muted-foreground">
                                    {{ sortIndicator('generatedAt') }}
                                </span>
                            </Button>
                        </TableHead>
                        <TableHead class="w-[1%] text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <template v-if="props.rows.length > 0">
                        <TableRow v-for="(row, index) in props.rows" :key="row.id">
                            <TableCell>
                                <Checkbox
                                    :model-value="isRowSelected(row)"
                                    :disabled="props.isBusy || props.isDeleteProcessing"
                                    :aria-label="`Select ${row.taxpayerName}`"
                                    @update:model-value="toggleRowSelection(row, $event)"
                                />
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{
                                    props.pagination.from
                                        ? props.pagination.from + index
                                        : index + 1
                                }}
                            </TableCell>
                            <TableCell>
                                <div class="space-y-1">
                                    <p
                                        class="max-w-[16rem] truncate text-sm text-foreground"
                                        :title="row.fileName"
                                    >
                                        {{ row.fileName }}
                                    </p>
                                    <p
                                        v-if="row.hasReceipt && row.receiptFileName"
                                        class="max-w-[16rem] truncate text-xs text-muted-foreground"
                                        :title="row.receiptFileName"
                                    >
                                        Receipt: {{ row.receiptFileName }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell>
                                <p class="font-medium text-foreground">
                                    {{ row.taxpayerName }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.sourceName }} row {{ row.sourceRowNumber }}
                                </p>
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ row.tin || '-' }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDateTime(row.uploadedAt) }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDateTime(row.receiptAcceptanceStartDate) }}
                            </TableCell>
                            <TableCell>
                                <div class="space-y-2">
                                    <Badge
                                        :variant="row.pdfStatus === 'failed' ? 'destructive' : 'outline'"
                                        :class="pdfStatusClass(row.pdfStatus)"
                                    >
                                        {{ pdfStatusLabel(row.pdfStatus) }}
                                    </Badge>
                                    <Badge
                                        v-if="row.receiptJobStatus"
                                        :variant="row.receiptJobStatus === 'failed' ? 'destructive' : 'outline'"
                                    >
                                        Receipt {{ receiptJobStatusLabel(row.receiptJobStatus) }}
                                    </Badge>
                                    <p
                                        v-else-if="row.hasReceipt"
                                        class="text-xs text-muted-foreground"
                                    >
                                        Receipt attached
                                    </p>
                                    <p
                                        v-if="autoReceiptLabel(row)"
                                        class="max-w-[18rem] text-xs text-muted-foreground"
                                        :class="{
                                            'text-destructive':
                                                row.autoReceiptStatus === 'failed',
                                        }"
                                    >
                                        {{ autoReceiptLabel(row) }}
                                    </p>
                                    <p
                                        v-if="row.pdfStatus === 'failed' && row.pdfError"
                                        class="max-w-[18rem] text-xs text-destructive"
                                    >
                                        {{ row.pdfError }}
                                    </p>
                                    <p
                                        v-if="row.autoReceiptStatus === 'failed' && row.autoReceiptError"
                                        class="max-w-[18rem] text-xs text-destructive"
                                    >
                                        {{ row.autoReceiptError }}
                                    </p>
                                    <p
                                        v-if="row.receiptJobStatus === 'failed' && row.receiptJobError"
                                        class="max-w-[18rem] text-xs text-destructive"
                                    >
                                        {{ row.receiptJobError }}
                                    </p>
                                </div>
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDateTime(row.generatedAt) }}
                            </TableCell>
                            <TableCell>
                                <div class="flex justify-end">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button type="button" variant="ghost" size="icon-sm">
                                                <MoreHorizontal class="size-4" />
                                                <span class="sr-only">Open actions</span>
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end" class="w-48 rounded-lg">
                                            <DropdownMenuItem
                                                v-if="row.previewUrl"
                                                :as-child="true"
                                            >
                                                <a
                                                    :href="row.previewUrl"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    class="flex w-full items-center gap-2"
                                                >
                                                    <Eye class="size-4" />
                                                    Preview
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="row.downloadUrl"
                                                :as-child="true"
                                            >
                                                <a
                                                    :href="row.downloadUrl"
                                                    class="flex w-full items-center gap-2"
                                                >
                                                    <Download class="size-4" />
                                                    Download
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                @select="emit('openRecipientEditor', row)"
                                            >
                                                <Pencil class="size-4" />
                                                {{ row.recipientEmail ? 'Edit recipient' : 'Add recipient' }}
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="!row.hasReceipt"
                                                :disabled="receiptMutationDisabled(row)"
                                                @select="emit('openTemporaryReceipt', row)"
                                            >
                                                <Upload class="size-4" />
                                                Add temporary receipt
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                v-if="row.receiptDownloadUrl"
                                                :as-child="true"
                                            >
                                                <a
                                                    :href="row.receiptDownloadUrl"
                                                    class="flex w-full items-center gap-2"
                                                >
                                                    <Download class="size-4" />
                                                    Download receipt
                                                </a>
                                            </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                :disabled="regenerateDisabled(row)"
                                                @select="emit('regenerate', row)"
                                                >
                                                    <RotateCcw class="size-4" />
                                                    Regenerate
                                                </DropdownMenuItem>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuItem
                                                variant="destructive"
                                                :disabled="deleteDisabled(row)"
                                                @select="requestDelete([row.id])"
                                            >
                                                <Trash2 class="size-4" />
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </TableCell>
                        </TableRow>
                    </template>

                    <TableEmpty v-else :colspan="10">
                        {{
                            props.pagination.total === 0
                                ? 'No imported rows yet.'
                                : 'No rows match your search.'
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
                    :variant="item === props.pagination.currentPage ? 'default' : 'outline'"
                    :aria-current="item === props.pagination.currentPage ? 'page' : undefined"
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
                :disabled="props.pagination.currentPage >= props.pagination.lastPage"
                @click="visitPage(props.pagination.currentPage + 1)"
            >
                Next
                <ChevronRight class="size-4" />
            </Button>
        </div>
    </div>
</template>
