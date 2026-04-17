<script setup lang="ts">
import type { Cell, SortingState } from '@tanstack/vue-table';
import {
    createColumnHelper,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    useVueTable,
} from '@tanstack/vue-table';
import {
    ArrowUpDown,
    ChevronDown,
    ChevronUp,
    Download,
    Eye,
    FileText,
    MoreHorizontal,
    Pencil,
    RotateCcw,
    Search,
    Trash2,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
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
import { valueUpdater } from '@/components/ui/table/utils';
import type { Form1702ExBatchRow } from '@/components/form-1702-ex-components/types';
import {
    formatDateTime,
    pdfStatusClass,
    pdfStatusLabel,
    receiptJobIsActive,
    receiptJobStatusLabel,
} from '@/components/form-1702-ex-components/utils';

const props = withDefaults(
    defineProps<{
        rows: Form1702ExBatchRow[];
        isBatchBusy?: boolean;
        isDeleteProcessing?: boolean;
    }>(),
    {
        isBatchBusy: false,
        isDeleteProcessing: false,
    },
);

const emit = defineEmits<{
    openRecipientEditor: [row: Form1702ExBatchRow];
    openReceipt: [row: Form1702ExBatchRow];
    openRemoveReceipt: [row: Form1702ExBatchRow];
    regenerate: [row: Form1702ExBatchRow];
    requestDelete: [rowIds: string[]];
}>();

const sorting = ref<SortingState>([
    {
        id: 'uploadedAt',
        desc: true,
    },
]);
const globalFilter = ref('');
const selectedRowIds = ref<string[]>([]);

const columnHelper = createColumnHelper<Form1702ExBatchRow>();
const columns = [
    columnHelper.display({
        id: 'selection',
        header: '',
        enableSorting: false,
    }),
    columnHelper.display({
        id: 'index',
        header: '#',
        enableSorting: false,
    }),
    columnHelper.accessor('fileName', {
        header: 'File name',
    }),
    columnHelper.accessor('taxpayerName', {
        header: 'Taxpayer',
    }),
    columnHelper.accessor('tin', {
        header: 'TIN',
    }),
    columnHelper.accessor('uploadedAt', {
        header: 'Uploaded',
    }),
    columnHelper.accessor('receiptAcceptanceStartDate', {
        header: 'Accepted receipts from',
    }),
    columnHelper.accessor('pdfStatus', {
        header: 'PDF status',
    }),
    columnHelper.accessor('generatedAt', {
        header: 'Generated',
    }),
    columnHelper.display({
        id: 'actions',
        header: 'Actions',
        enableSorting: false,
    }),
];

const table = useVueTable({
    get data() {
        return props.rows;
    },
    columns,
    state: {
        get sorting() {
            return sorting.value;
        },
        get globalFilter() {
            return globalFilter.value;
        },
    },
    onSortingChange: (updater) => valueUpdater(updater, sorting),
    onGlobalFilterChange: (updater) => valueUpdater(updater, globalFilter),
    getCoreRowModel: getCoreRowModel(),
    getFilteredRowModel: getFilteredRowModel(),
    getSortedRowModel: getSortedRowModel(),
    globalFilterFn: (row, _columnId, filterValue) => {
        const search = String(filterValue ?? '')
            .trim()
            .toLowerCase();

        if (search === '') {
            return true;
        }

        return [
            row.original.fileName,
            row.original.taxpayerName,
            row.original.tin,
            row.original.pdfStatus,
            row.original.receiptFileName ?? '',
            row.original.receiptJobStatus ?? '',
            row.original.receiptJobError ?? '',
            row.original.recipientEmail ?? '',
        ].some((value) => value.toLowerCase().includes(search));
    },
});

const visibleRows = computed(() => table.getRowModel().rows);
const visibleRowIds = computed(() =>
    visibleRows.value.map((row) => row.original.id),
);
const selectedVisibleCount = computed(
    () =>
        visibleRowIds.value.filter((rowId) =>
            selectedRowIds.value.includes(rowId),
        ).length,
);
const selectAllState = computed<boolean | 'indeterminate'>(() => {
    if (visibleRowIds.value.length === 0 || selectedVisibleCount.value === 0) {
        return false;
    }

    if (selectedVisibleCount.value === visibleRowIds.value.length) {
        return true;
    }

    return 'indeterminate';
});
const canBulkDelete = computed(
    () =>
        selectedRowIds.value.length > 0 &&
        !props.isBatchBusy &&
        !props.isDeleteProcessing,
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

function isRowSelected(row: Form1702ExBatchRow): boolean {
    return selectedRowIds.value.includes(row.id);
}

function toggleRowSelection(
    row: Form1702ExBatchRow,
    checked: boolean | 'indeterminate',
): void {
    if (checked === 'indeterminate') {
        return;
    }

    if (checked) {
        if (!selectedRowIds.value.includes(row.id)) {
            selectedRowIds.value = [...selectedRowIds.value, row.id];
        }

        return;
    }

    selectedRowIds.value = selectedRowIds.value.filter(
        (rowId) => rowId !== row.id,
    );
}

function toggleAllVisibleRows(checked: boolean | 'indeterminate'): void {
    if (checked === 'indeterminate') {
        return;
    }

    if (checked) {
        selectedRowIds.value = Array.from(
            new Set([...selectedRowIds.value, ...visibleRowIds.value]),
        );

        return;
    }

    selectedRowIds.value = selectedRowIds.value.filter(
        (rowId) => !visibleRowIds.value.includes(rowId),
    );
}

function requestDelete(rowIds: string[]): void {
    const uniqueRowIds = Array.from(
        new Set(rowIds.filter((rowId) => rowId !== '')),
    );

    if (uniqueRowIds.length === 0) {
        return;
    }

    emit('requestDelete', uniqueRowIds);
}

function requestDeleteSelected(): void {
    requestDelete(selectedRowIds.value);
}

function deleteDisabled(row: Form1702ExBatchRow): boolean {
    return (
        props.isBatchBusy ||
        props.isDeleteProcessing ||
        row.pdfStatus === 'queued' ||
        row.pdfStatus === 'processing'
    );
}

function regenerateDisabled(row: Form1702ExBatchRow): boolean {
    return (
        props.isBatchBusy ||
        props.isDeleteProcessing ||
        row.pdfStatus === 'queued' ||
        row.pdfStatus === 'processing' ||
        receiptJobIsActive(row.receiptJobStatus)
    );
}

function receiptMutationDisabled(row: Form1702ExBatchRow): boolean {
    return (
        props.isBatchBusy ||
        props.isDeleteProcessing ||
        row.pdfStatus !== 'generated' ||
        receiptJobIsActive(row.receiptJobStatus)
    );
}

function columnLabel(id: string): string {
    const labels: Record<string, string> = {
        index: '#',
        fileName: 'File name',
        taxpayerName: 'Taxpayer',
        tin: 'TIN',
        uploadedAt: 'Uploaded',
        receiptAcceptanceStartDate: 'Accepted receipts from',
        pdfStatus: 'PDF status',
        generatedAt: 'Generated',
        actions: 'Actions',
    };

    return labels[id] ?? id;
}

function formatCellValue(cell: Cell<Form1702ExBatchRow, unknown>): string {
    const value = cell.getValue();

    if (
        cell.column.id === 'uploadedAt'
        || cell.column.id === 'generatedAt'
        || cell.column.id === 'receiptAcceptanceStartDate'
    ) {
        return formatDateTime(typeof value === 'string' ? value : null);
    }

    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return String(value);
}

function emptyMessage(): string {
    return props.rows.length === 0
        ? 'No imported rows yet.'
        : 'No rows match your search.';
}
</script>

<template>
    <div class="space-y-4">
        <div
            class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
        >
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    :model-value="globalFilter"
                    type="search"
                    placeholder="Search taxpayer, TIN, recipient, or status"
                    class="pl-10"
                    @update:model-value="globalFilter = String($event)"
                />
            </div>

            <Button
                type="button"
                variant="destructive"
                size="sm"
                class="gap-2 self-end md:self-auto"
                :disabled="!canBulkDelete"
                @click="requestDeleteSelected"
            >
                <Trash2 class="size-4" />
                {{
                    selectedRowIds.length > 0
                        ? `Delete selected (${selectedRowIds.length})`
                        : 'Delete selected'
                }}
            </Button>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-background">
            <Table>
                <TableHeader>
                    <TableRow
                        v-for="headerGroup in table.getHeaderGroups()"
                        :key="headerGroup.id"
                    >
                        <TableHead
                            v-for="header in headerGroup.headers"
                            :key="header.id"
                            :class="[
                                header.column.id === 'selection' ||
                                header.column.id === 'index'
                                    ? 'w-[1%]'
                                    : undefined,
                                header.column.id === 'actions'
                                    ? 'w-[1%] text-right'
                                    : undefined,
                            ]"
                        >
                            <Checkbox
                                v-if="header.column.id === 'selection'"
                                :model-value="selectAllState"
                                :disabled="
                                    visibleRows.length === 0 ||
                                    props.isBatchBusy ||
                                    props.isDeleteProcessing
                                "
                                aria-label="Select visible imported rows"
                                @update:model-value="
                                    toggleAllVisibleRows($event)
                                "
                            />
                            <span v-else-if="header.column.id === 'index'">
                                {{ columnLabel(header.column.id) }}
                            </span>
                            <Button
                                v-else-if="
                                    header.isPlaceholder === false &&
                                    header.column.getCanSort()
                                "
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="h-auto px-10 font-medium"
                                @click="
                                    header.column.toggleSorting(
                                        header.column.getIsSorted() === 'asc',
                                    )
                                "
                            >
                                {{ columnLabel(header.column.id) }}
                                <ChevronUp
                                    v-if="header.column.getIsSorted() === 'asc'"
                                    class="ml-2 size-4 text-muted-foreground"
                                />
                                <ChevronDown
                                    v-else-if="
                                        header.column.getIsSorted() === 'desc'
                                    "
                                    class="ml-2 size-4 text-muted-foreground"
                                />
                                <ArrowUpDown
                                    v-else
                                    class="ml-2 size-4 text-muted-foreground"
                                />
                            </Button>
                            <span v-else-if="header.isPlaceholder === false">
                                {{ columnLabel(header.column.id) }}
                            </span>
                        </TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <template v-if="visibleRows.length > 0">
                        <TableRow
                            v-for="(row, index) in visibleRows"
                            :key="row.original.id"
                        >
                            <TableCell
                                v-for="cell in row.getVisibleCells()"
                                :key="cell.id"
                            >
                                <template v-if="cell.column.id === 'selection'">
                                    <Checkbox
                                        :model-value="
                                            isRowSelected(row.original)
                                        "
                                        :disabled="
                                            props.isBatchBusy ||
                                            props.isDeleteProcessing
                                        "
                                        :aria-label="`Select ${row.original.taxpayerName}`"
                                        @update:model-value="
                                            toggleRowSelection(
                                                row.original,
                                                $event,
                                            )
                                        "
                                    />
                                </template>

                                <template
                                    v-else-if="cell.column.id === 'index'"
                                >
                                    <span class="text-sm text-muted-foreground">
                                        {{ index + 1 }}
                                    </span>
                                </template>

                                <template
                                    v-else-if="cell.column.id === 'fileName'"
                                >
                                    <div class="space-y-1">
                                        <p
                                            class="max-w-[16rem] truncate text-sm text-foreground"
                                            :title="row.original.fileName"
                                        >
                                            {{ row.original.fileName }}
                                        </p>
                                        <p
                                            v-if="
                                                row.original.hasReceipt &&
                                                row.original.receiptFileName
                                            "
                                            class="max-w-[16rem] truncate text-xs text-muted-foreground"
                                            :title="
                                                row.original.receiptFileName
                                            "
                                        >
                                            Receipt:
                                            {{ row.original.receiptFileName }}
                                        </p>
                                    </div>
                                </template>

                                <template
                                    v-else-if="
                                        cell.column.id === 'taxpayerName'
                                    "
                                >
                                    <p class="font-medium text-foreground">
                                        {{ row.original.taxpayerName }}
                                    </p>
                                </template>

                                <template
                                    v-else-if="cell.column.id === 'pdfStatus'"
                                >
                                    <div class="space-y-2">
                                        <Badge
                                            :variant="
                                                row.original.pdfStatus ===
                                                'failed'
                                                    ? 'destructive'
                                                    : 'outline'
                                            "
                                            :class="
                                                pdfStatusClass(
                                                    row.original.pdfStatus,
                                                )
                                            "
                                        >
                                            {{
                                                pdfStatusLabel(
                                                    row.original.pdfStatus,
                                                )
                                            }}
                                        </Badge>
                                        <Badge
                                            v-if="row.original.receiptJobStatus"
                                            :variant="
                                                row.original
                                                    .receiptJobStatus ===
                                                'failed'
                                                    ? 'destructive'
                                                    : 'outline'
                                            "
                                        >
                                            Receipt
                                            {{
                                                receiptJobStatusLabel(
                                                    row.original
                                                        .receiptJobStatus,
                                                )
                                            }}
                                        </Badge>
                                        <p
                                            v-else-if="row.original.hasReceipt"
                                            class="text-xs text-muted-foreground"
                                        >
                                            Receipt attached
                                        </p>
                                        <p
                                            v-if="
                                                row.original.pdfStatus ===
                                                    'failed' &&
                                                row.original.pdfError
                                            "
                                            class="max-w-[18rem] text-xs text-destructive"
                                        >
                                            {{ row.original.pdfError }}
                                        </p>
                                        <p
                                            v-if="
                                                row.original
                                                    .receiptJobStatus ===
                                                    'failed' &&
                                                row.original.receiptJobError
                                            "
                                            class="max-w-[18rem] text-xs text-destructive"
                                        >
                                            {{ row.original.receiptJobError }}
                                        </p>
                                    </div>
                                </template>

                                <template
                                    v-else-if="cell.column.id === 'actions'"
                                >
                                    <div class="flex justify-end">
                                        <DropdownMenu>
                                            <DropdownMenuTrigger as-child>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="icon-sm"
                                                >
                                                    <MoreHorizontal
                                                        class="size-4"
                                                    />
                                                    <span class="sr-only"
                                                        >Open actions</span
                                                    >
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent
                                                align="end"
                                                class="w-48 rounded-lg"
                                            >
                                                <DropdownMenuItem
                                                    v-if="
                                                        row.original.previewUrl
                                                    "
                                                    :as-child="true"
                                                >
                                                    <a
                                                        :href="
                                                            row.original
                                                                .previewUrl
                                                        "
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        class="flex w-full items-center gap-2"
                                                    >
                                                        <Eye class="size-4" />
                                                        Preview
                                                    </a>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="
                                                        row.original.downloadUrl
                                                    "
                                                    :as-child="true"
                                                >
                                                    <a
                                                        :href="
                                                            row.original
                                                                .downloadUrl
                                                        "
                                                        class="flex w-full items-center gap-2"
                                                    >
                                                        <Download
                                                            class="size-4"
                                                        />
                                                        Download
                                                    </a>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    @select="
                                                        emit(
                                                            'openRecipientEditor',
                                                            row.original,
                                                        )
                                                    "
                                                >
                                                    <Pencil class="size-4" />
                                                    {{
                                                        row.original.recipientEmail
                                                            ? 'Edit recipient'
                                                            : 'Add recipient'
                                                    }}
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="row.original.hasReceipt"
                                                    :disabled="
                                                        receiptMutationDisabled(
                                                            row.original,
                                                        )
                                                    "
                                                    @select="
                                                        emit(
                                                            'openReceipt',
                                                            row.original,
                                                        )
                                                    "
                                                >
                                                    <FileText class="size-4" />
                                                    Replace receipt
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="
                                                        row.original
                                                            .receiptDownloadUrl
                                                    "
                                                    :as-child="true"
                                                >
                                                    <a
                                                        :href="
                                                            row.original
                                                                .receiptDownloadUrl
                                                        "
                                                        class="flex w-full items-center gap-2"
                                                    >
                                                        <Download
                                                            class="size-4"
                                                        />
                                                        Download receipt
                                                    </a>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    v-if="
                                                        row.original
                                                            .receiptRemoveUrl
                                                    "
                                                    :disabled="
                                                        receiptMutationDisabled(
                                                            row.original,
                                                        )
                                                    "
                                                    @select="
                                                        emit(
                                                            'openRemoveReceipt',
                                                            row.original,
                                                        )
                                                    "
                                                >
                                                    <Trash2 class="size-4" />
                                                    Remove receipt
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    :disabled="
                                                        regenerateDisabled(
                                                            row.original,
                                                        )
                                                    "
                                                    @select="
                                                        emit(
                                                            'regenerate',
                                                            row.original,
                                                        )
                                                    "
                                                >
                                                    <RotateCcw class="size-4" />
                                                    Regenerate
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    variant="destructive"
                                                    :disabled="
                                                        deleteDisabled(
                                                            row.original,
                                                        )
                                                    "
                                                    @select="
                                                        requestDelete([
                                                            row.original.id,
                                                        ])
                                                    "
                                                >
                                                    <Trash2 class="size-4" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </template>

                                <template v-else>
                                    {{ formatCellValue(cell) }}
                                </template>
                            </TableCell>
                        </TableRow>
                    </template>

                    <TableEmpty v-else :colspan="10">
                        {{ emptyMessage() }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>
    </div>
</template>
