<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowUpDown,
    Download,
    Eye,
    MoreHorizontal,
    Pencil,
    RotateCcw,
    Search,
    Trash2,
    Upload,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
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
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
        pdfExportUrl: string;
        filters: Form1702ExRowFilters;
        isBusy?: boolean;
        isDeleteProcessing?: boolean;
        pageUrl: string;
        pagination: Form1702ExRowPagination;
        rows: Form1702ExBatchRow[];
        rowsExportState: Form1702ExCompletedExportState;
        rowsPdfExportState: Form1702ExCompletedExportState;
    }>(),
    {
        isBusy: false,
        isDeleteProcessing: false,
    },
);

const emit = defineEmits<{
    openRecipientEditor: [row: Form1702ExBatchRow];
    openSignature: [row: Form1702ExBatchRow];
    openSignaturePreview: [row: Form1702ExBatchRow];
    openReceipt: [row: Form1702ExBatchRow];
    openRemoveReceipt: [row: Form1702ExBatchRow];
    openTemporaryReceipt: [row: Form1702ExBatchRow];
    regenerate: [row: Form1702ExBatchRow];
    requestDelete: [rowIds: string[]];
}>();

const searchValue = ref(props.filters.search);
const statusValue = ref(props.filters.status);
const searchTimeoutId = ref<number | null>(null);
const selectedRowIds = ref<string[]>([]);
const tableTop = ref<HTMLElement | null>(null);
const paginationControls = ref<HTMLElement | null>(null);

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
const isRowsPdfExportBusy = computed(
    () =>
        props.rowsPdfExportState.status === 'queued'
        || props.rowsPdfExportState.status === 'processing',
);
const canExportList = computed(
    () => props.pagination.total > 0 && !isRowsExportBusy.value,
);
const canExportPdfList = computed(
    () => props.pagination.total > 0 && !isRowsPdfExportBusy.value,
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
const showingFrom = computed(() => props.pagination.total === 0 ? 0 : (props.pagination.from ?? 0));
const showingTo = computed(() => props.pagination.total === 0 ? 0 : (props.pagination.to ?? 0));

watch(
    () => props.filters.search,
    (value) => {
        searchValue.value = value;
    },
);
watch(
    () => props.filters.status,
    (value) => {
        statusValue.value = value;
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
watch(statusValue, (value) => {
    visitIndex({
        page: 1,
        status: value,
    });
});

onBeforeUnmount(() => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }
});

function visitIndex(overrides: Partial<{ page: number; search: string; sort: Form1702ExRowFilters['sort']; direction: Form1702ExRowFilters['direction']; status: Form1702ExRowFilters['status'] }>): void {
    router.get(
        props.pageUrl,
        {
            page: overrides.page ?? props.pagination.currentPage,
            search: overrides.search ?? props.filters.search,
            sort: overrides.sort ?? props.filters.sort,
            direction: overrides.direction ?? props.filters.direction,
            status: overrides.status ?? props.filters.status,
        },
        {
            preserveScroll: true,
            preserveState: true,
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

function exportPdfList(): void {
    if (!canExportPdfList.value) {
        return;
    }

    router.get(
        props.pdfExportUrl,
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
            <div class="flex flex-1 flex-col gap-2 md:flex-row">
                <div class="relative flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        v-model="searchValue"
                        type="search"
                        placeholder="Search company, client, TIN, recipient, or status"
                        class="pl-10"
                    />
                </div>
                <Select v-model="statusValue">
                    <SelectTrigger class="w-full md:w-[220px]">
                        <SelectValue placeholder="Filter status" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="all">All statuses</SelectItem>
                        <SelectItem value="generated">Generated</SelectItem>
                        <SelectItem value="processing">Processing</SelectItem>
                        <SelectItem value="signed">Signed</SelectItem>
                        <SelectItem value="not_signed">Not signed</SelectItem>
                        <SelectItem value="receipt_attached">Receipt attached</SelectItem>
                        <SelectItem value="no_receipt">No receipt</SelectItem>
                    </SelectContent>
                </Select>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2 md:self-start"
                    :disabled="!canExportPdfList"
                    @click="exportPdfList"
                >
                    <Download class="size-4" />
                    {{
                        isRowsPdfExportBusy
                            ? 'Preparing PDF ZIP...'
                            : 'Export PDF List'
                    }}
                </Button>
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

        <div ref="tableTop" class="overflow-hidden rounded-2xl border bg-background">
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
                        <TableHead>Company</TableHead>
                        <TableHead>Client</TableHead>
                        <TableHead>TIN</TableHead>
                        <TableHead>
                            Accepted receipts from
                        </TableHead>
                        <TableHead>
                            <Button
                                type="button"
                                variant="ghost"
                                class="h-auto px-0 font-medium"
                                @click="toggleSort('uploadedAt')"
                            >
                                Uploaded
                                <ArrowUpDown class="ml-2 size-4 text-muted-foreground" />
                            </Button>
                        </TableHead>
                        <TableHead>
                            <Button
                                type="button"
                                variant="ghost"
                                class="h-auto px-0 font-medium"
                                @click="toggleSort('pdfStatus')"
                            >
                                PDF status
                                <ArrowUpDown class="ml-2 size-4 text-muted-foreground" />
                            </Button>
                        </TableHead>
                        <TableHead>Signature</TableHead>
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
                                <p class="font-medium text-foreground">
                                    {{ row.taxpayerName }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ row.sourceName }} row {{ row.sourceRowNumber }}
                                </p>
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ row.clientName || '-' }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ row.tin || '-' }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDateTime(row.receiptAcceptanceStartDate) }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDateTime(row.uploadedAt) }}
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
                            <TableCell>
                                <Button
                                    v-if="row.signatureApplied && row.signaturePreviewUrl"
                                    type="button"
                                    variant="link"
                                    class="h-auto p-0 text-emerald-700"
                                    @click="emit('openSignaturePreview', row)"
                                >
                                    Applied
                                </Button>
                                <span v-else class="text-sm text-muted-foreground">Not applied</span>
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
                                                @select="emit('openSignature', row)"
                                            >
                                                <Upload class="size-4" />
                                                Add signature
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

                    <TableEmpty v-else :colspan="11">
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
            ref="paginationControls"
            class="flex flex-col gap-3 pt-2 md:flex-row md:items-center md:justify-between"
        >
            <div class="text-sm text-muted-foreground">
                Showing {{ showingFrom }} to {{ showingTo }} of {{ props.pagination.total }} rows
            </div>

            <div
                v-if="props.pagination.lastPage > 1"
                class="flex items-center gap-2"
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
                    :disabled="props.pagination.currentPage >= props.pagination.lastPage"
                    @click="visitPage(props.pagination.currentPage + 1)"
                >
                    Next
                </Button>
            </div>
        </div>
    </div>
</template>
