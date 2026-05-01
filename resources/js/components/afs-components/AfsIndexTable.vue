<script setup lang="ts">
import type { ColumnDef } from '@tanstack/vue-table';
import { PenLine, Search, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { PaginatedResponse, UnifiedItem } from '@/components/afs-components/types';

const props = defineProps<{
    status: string;
    companySearch: string;
    canBulkSignSelected: boolean;
    showBulkSign: boolean;
    bulkSignLabel: string;
    canBulkDeleteSelected: boolean;
    tableLoading: boolean;
    itemsForTable: UnifiedItem[];
    itemsData: PaginatedResponse<UnifiedItem>;
    itemsSortBy: string;
    itemsSortDirection: 'asc' | 'desc';
    itemColumns: ColumnDef<UnifiedItem>[];
}>();

const emit = defineEmits<{
    statusChange: [value: string];
    companySearchInput: [event: Event];
    bulkSign: [];
    bulkDelete: [];
    pageChange: [page: number];
    perPageChange: [perPage: number];
    sortChange: [column: string, direction: 'asc' | 'desc'];
}>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="flex w-full flex-col gap-3 md:flex-row md:items-center">
                <div class="relative w-full md:flex-1">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        :model-value="props.companySearch"
                        class="pl-10"
                        placeholder="Search company or TIN"
                        @input="emit('companySearchInput', $event)"
                    />
                </div>
                <div class="w-full md:w-[180px]">
                    <!-- <Label class="mb-2 block" for="item-status-filter">Status</Label> -->
                    <Select :model-value="props.status" @update:model-value="emit('statusChange', String($event))">
                        <SelectTrigger id="item-status-filter" class="w-full">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All</SelectItem>
                            <SelectItem value="failed">Failed</SelectItem>
                            <SelectItem value="queued">Queued</SelectItem>
                            <SelectItem value="processing">Processing</SelectItem>
                            <SelectItem value="docx_done">DOCX Done</SelectItem>
                            <SelectItem value="pdf_done">Generated</SelectItem>
                            <SelectItem value="signing">Signing</SelectItem>
                            <SelectItem value="deleting">Deleting</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- <div class="flex flex-wrap gap-2"> -->
                <Button
                    v-if="props.showBulkSign"
                    type="button"
                    variant="outline"
                    :disabled="!props.canBulkSignSelected"
                    @click="emit('bulkSign')"
                >
                    <PenLine class="mr-2 size-4" />
                    {{ props.bulkSignLabel }}
                </Button>
                <Button type="button" variant="destructive" :disabled="!props.canBulkDeleteSelected" @click="emit('bulkDelete')">
                    <Trash2 class="mr-2 size-4" />
                    Delete selected
                </Button>
            <!-- </div> -->
        </div>

        <DataTable
            :columns="props.itemColumns"
            :data="props.itemsForTable"
            :meta="props.itemsData"
            :loading="props.tableLoading"
            :sort-by="props.itemsSortBy"
            :sort-direction="props.itemsSortDirection"
            empty-message="No rows available."
            @page-change="emit('pageChange', $event)"
            @per-page-change="emit('perPageChange', $event)"
            @sort-change="(column, direction) => emit('sortChange', column, direction)"
        />
    </div>
</template>
