<script setup lang="ts">
import type { ColumnDef, PaginationState, SortingState } from '@tanstack/vue-table';
import { FlexRender, getCoreRowModel, useVueTable } from '@tanstack/vue-table';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';

type SortDirection = 'asc' | 'desc';

type PaginatedMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
};

const props = defineProps<{
    columns: ColumnDef<any, any>[];
    data: any[];
    meta: PaginatedMeta;
    loading?: boolean;
    sortBy?: string;
    sortDirection?: SortDirection;
    emptyMessage?: string;
}>();

const emit = defineEmits<{
    pageChange: [page: number];
    perPageChange: [perPage: number];
    sortChange: [column: string, direction: SortDirection];
}>();

const sorting = computed<SortingState>(() => {
    if (!props.sortBy) {
        return [];
    }

    return [{ id: props.sortBy, desc: props.sortDirection === 'desc' }];
});

const pagination = computed<PaginationState>(() => ({
    pageIndex: Math.max(0, props.meta.current_page - 1),
    pageSize: props.meta.per_page,
}));

const table = useVueTable({
    get data() {
        return props.data;
    },
    get columns() {
        return props.columns;
    },
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    manualSorting: true,
    get pageCount() {
        return Math.max(1, props.meta.last_page);
    },
    state: {
        get pagination() {
            return pagination.value;
        },
        get sorting() {
            return sorting.value;
        },
    },
});

const perPageOptions = [10, 20, 50, 100];

const onSort = (columnId: string) => {
    if (!columnId) {
        return;
    }

    const isCurrent = props.sortBy === columnId;
    const nextDirection: SortDirection = isCurrent && props.sortDirection === 'asc' ? 'desc' : 'asc';
    emit('sortChange', columnId, nextDirection);
};

const showingFrom = computed(() => {
    if (props.meta.total === 0) {
        return 0;
    }

    return (props.meta.current_page - 1) * props.meta.per_page + 1;
});

const showingTo = computed(() => {
    return Math.min(props.meta.current_page * props.meta.per_page, props.meta.total);
});
</script>

<template>
    <div class="space-y-3">
        <div class="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow v-for="headerGroup in table.getHeaderGroups()" :key="headerGroup.id">
                        <TableHead v-for="header in headerGroup.headers" :key="header.id">
                            <template v-if="!header.isPlaceholder">
                                <button
                                    v-if="header.column.getCanSort()"
                                    class="inline-flex items-center gap-1"
                                    type="button"
                                    @click="onSort(header.column.id)"
                                >
                                    <FlexRender :render="header.column.columnDef.header" :props="header.getContext()" />
                                    <span
                                        v-if="sortBy === header.column.id"
                                        class="text-muted-foreground text-xs"
                                    >
                                        {{ sortDirection === 'asc' ? 'ASC' : 'DESC' }}
                                    </span>
                                </button>
                                <FlexRender
                                    v-else
                                    :render="header.column.columnDef.header"
                                    :props="header.getContext()"
                                />
                            </template>
                        </TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-if="loading">
                        <TableCell :colspan="columns.length" class="h-24 text-center">
                            Loading...
                        </TableCell>
                    </TableRow>
                    <template v-else-if="table.getRowModel().rows?.length">
                        <TableRow v-for="row in table.getRowModel().rows" :key="row.id">
                            <TableCell v-for="cell in row.getVisibleCells()" :key="cell.id">
                                <FlexRender :render="cell.column.columnDef.cell" :props="cell.getContext()" />
                            </TableCell>
                        </TableRow>
                    </template>
                    <TableRow v-else>
                        <TableCell :colspan="columns.length" class="h-24 text-center">
                            {{ emptyMessage ?? 'No results.' }}
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="text-muted-foreground text-sm">
                Showing {{ showingFrom }} to {{ showingTo }} of {{ meta.total }} rows
            </div>
            <div class="flex items-center gap-2">
                <Select
                    :model-value="String(meta.per_page)"
                    @update:model-value="(value) => emit('perPageChange', Number(value))"
                >
                    <SelectTrigger class="w-[110px]">
                        <SelectValue placeholder="Rows" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="option in perPageOptions" :key="option" :value="String(option)">
                            {{ option }} rows
                        </SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="meta.current_page <= 1"
                    @click="emit('pageChange', meta.current_page - 1)"
                >
                    Previous
                </Button>
                <span class="text-sm">Page {{ meta.current_page }} / {{ meta.last_page }}</span>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="meta.current_page >= meta.last_page"
                    @click="emit('pageChange', meta.current_page + 1)"
                >
                    Next
                </Button>
            </div>
        </div>
    </div>
</template>
