<script setup lang="ts">
import type { ColumnDef } from '@tanstack/vue-table';
import { Download, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import type { CompletedExportState, PaginatedResponse, SortDirection, UnifiedItem } from '@/components/afs-components/types';

const props = defineProps<{
    itemsData: PaginatedResponse<UnifiedItem>;
    itemsLoading: boolean;
    itemsSortBy: string;
    itemsSortDirection: SortDirection;
    itemColumns: ColumnDef<UnifiedItem>[];
    itemsForTable: UnifiedItem[];
    companySearch: string;
    selectedItemIds: number[];
    deletingItems: boolean;
    exportBusy: boolean;
    canDownloadSelected: boolean;
    canDeleteSelected: boolean;
    exportStatus: CompletedExportState['status'];
    exportError: CompletedExportState['error'];
}>();

const emit = defineEmits<{
    'update:companySearch': [value: string];
    requestDownloadSelected: [];
    requestDeleteSelected: [];
    pageChange: [page: number];
    perPageChange: [perPage: number];
    sortChange: [column: string, direction: SortDirection];
}>();
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="w-full max-w-[360px]">
                <Input
                    id="company-search"
                    :model-value="props.companySearch"
                    placeholder="Type company name or TIN..."
                    @update:model-value="emit('update:companySearch', String($event ?? ''))"
                />
            </div>
            <div class="flex flex-wrap gap-2">
                <Button type="button" variant="outline" class="gap-2" :disabled="!props.canDownloadSelected || props.exportBusy" @click="emit('requestDownloadSelected')">
                    <Download class="size-4" />
                    {{ props.selectedItemIds.length > 0 ? `Download selected (${props.selectedItemIds.length})` : 'Download selected' }}
                </Button>
                <Button type="button" variant="destructive" class="gap-2" :disabled="!props.canDeleteSelected" @click="emit('requestDeleteSelected')">
                    <Trash2 class="size-4" />
                    {{ props.selectedItemIds.length > 0 ? `Delete selected (${props.selectedItemIds.length})` : 'Delete selected' }}
                </Button>
            </div>
        </div>

        <p v-if="props.exportStatus === 'queued' || props.exportStatus === 'processing'" class="text-sm text-muted-foreground">
            Preparing completed ZIP export...
        </p>
        <p v-else-if="props.exportStatus === 'failed' && props.exportError" class="text-sm text-destructive">
            {{ props.exportError }}
        </p>

        <DataTable
            :columns="props.itemColumns"
            :data="props.itemsForTable"
            :meta="props.itemsData"
            :loading="props.itemsLoading"
            :sort-by="props.itemsSortBy"
            :sort-direction="props.itemsSortDirection"
            empty-message="No completed files available."
            @page-change="emit('pageChange', $event)"
            @per-page-change="emit('perPageChange', $event)"
            @sort-change="(column, direction) => emit('sortChange', column, direction)"
        />
    </div>
</template>
