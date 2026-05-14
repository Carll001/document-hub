<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ArrowLeft, Download } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AfsCompletedTable from '@/components/afs-components/AfsCompletedTable.vue';
import type { CompletedExportState, PaginatedResponse, SortDirection, UnifiedItem } from '@/components/afs-components/types';
import { useAfsCompletedColumns } from '@/components/afs-components/useAfsCompletedColumns';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import AppLayout from '@/layouts/AppLayout.vue';
import documentGeneratorRoutes from '@/routes/afs-filing';
import { csrfToken } from '@/components/afs-components/utils';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    initialItems: PaginatedResponse<UnifiedItem>;
    initialExportState: CompletedExportState;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Completed Files',
        href: documentGeneratorRoutes.completed.index().url,
    },
];

const itemsData = ref<PaginatedResponse<UnifiedItem>>(props.initialItems);
const itemsLoading = ref(false);
const itemsSortBy = ref('updated_at');
const itemsSortDirection = ref<SortDirection>('desc');
const companySearch = ref('');
const selectedItemIds = ref<number[]>([]);
const deletingItems = ref(false);
const deleteConfirmOpen = ref(false);
const deleteConfirmIds = ref<number[]>([]);
const exportQueueing = ref(false);
const exportState = ref<CompletedExportState>(props.initialExportState);
let companySearchDebounce: ReturnType<typeof setTimeout> | null = null;
let exportPollTimeout: ReturnType<typeof setTimeout> | null = null;

const canDeleteSelected = computed(
    () => selectedItemIds.value.length > 0 && !deletingItems.value,
);
const canDownloadSelected = computed(
    () => selectedItemIds.value.length > 0 && !exportQueueing.value,
);
const exportBusy = computed(
    () => exportState.value.status === 'queued' || exportState.value.status === 'processing' || exportQueueing.value,
);

const buildItemsUrl = (page = itemsData.value.current_page) => {
    const query: Record<string, string> = {
        page: String(page),
        per_page: String(itemsData.value.per_page),
        sort_by: itemsSortBy.value,
        sort_direction: itemsSortDirection.value,
        completed_only: '1',
    };

    if (companySearch.value.trim() !== '') {
        query.company_search = companySearch.value.trim();
    }

    const params = new URLSearchParams(query);

    return `/afs-filing/items?${params.toString()}`;
};

const getApi = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const loadItems = async (page = itemsData.value.current_page) => {
    itemsLoading.value = true;

    try {
        itemsData.value = await getApi<PaginatedResponse<UnifiedItem>>(buildItemsUrl(page));
    } finally {
        itemsLoading.value = false;
    }
};

const toggleItemSelection = (itemId: number, checked: boolean | 'indeterminate') => {
    if (checked === 'indeterminate') {
        return;
    }

    if (checked) {
        selectedItemIds.value = Array.from(new Set([...selectedItemIds.value, itemId]));
        return;
    }

    selectedItemIds.value = selectedItemIds.value.filter((id) => id !== itemId);
};

const toggleAllVisibleRows = (checked: boolean | 'indeterminate') => {
    if (checked === 'indeterminate') {
        return;
    }

    if (checked) {
        selectedItemIds.value = Array.from(new Set([
            ...selectedItemIds.value,
            ...itemsData.value.data.map((item) => item.id),
        ]));
        return;
    }

    const visibleIds = new Set(itemsData.value.data.map((item) => item.id));
    selectedItemIds.value = selectedItemIds.value.filter((id) => !visibleIds.has(id));
};

const postJson = async <T>(url: string, payload: unknown): Promise<T> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (response.status === 422 || response.status === 409) {
        const errorPayload = (await response.json()) as {
            message?: string;
            export_state?: CompletedExportState;
        };

        if (errorPayload.export_state) {
            exportState.value = errorPayload.export_state;
        }

        throw new Error(errorPayload.message ?? 'Request failed.');
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const deleteJson = async <T>(url: string, payload: unknown): Promise<T> => {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (response.status === 422) {
        const errorPayload = (await response.json()) as { message?: string };
        throw new Error(errorPayload.message ?? 'Validation failed.');
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const queueCompletedExport = async (itemIds?: number[]) => {
    if (exportBusy.value) {
        return;
    }

    exportQueueing.value = true;

    try {
        const payload = await postJson<{
            message: string;
            export_state: CompletedExportState;
        }>(`${documentGeneratorRoutes.completed.download.url()}?context=completed`, {
            company_search: companySearch.value.trim() || undefined,
            sort_by: itemsSortBy.value,
            sort_direction: itemsSortDirection.value,
            item_ids: itemIds && itemIds.length > 0 ? itemIds : undefined,
        });

        exportState.value = payload.export_state;
        toast.success(payload.message);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to queue completed files export.');
    } finally {
        exportQueueing.value = false;
    }
};

const pollExportState = async () => {
    try {
        const state = await getApi<CompletedExportState>(`${documentGeneratorRoutes.completed.download.state.url()}?context=completed`);
        const previousStatus = exportState.value.status;
        exportState.value = state;

        if (state.status === 'ready' && previousStatus !== 'ready') {
            toast.success('Completed files ZIP is ready to download.');
        }

        if (state.status === 'failed' && state.error) {
            toast.error(state.error);
        }
    } catch {
        // Ignore transient polling errors.
    }
};

const deleteCompletedItems = async (itemIds: number[]) => {
    const uniqueIds = Array.from(new Set(itemIds));
    if (uniqueIds.length === 0 || deletingItems.value) {
        return;
    }

    deletingItems.value = true;

    try {
        const payload = await deleteJson<{
            message: string;
            queued_count: number;
        }>(documentGeneratorRoutes.completed.items.destroy.bulk.url(), {
            item_ids: uniqueIds,
        });

        selectedItemIds.value = selectedItemIds.value.filter((id) => !uniqueIds.includes(id));
        await loadItems(itemsData.value.current_page);
        toast.success(payload.message);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to delete completed rows.');
    } finally {
        deletingItems.value = false;
    }
};

const confirmDelete = (ids: number[]) => {
    deleteConfirmIds.value = ids;
    deleteConfirmOpen.value = true;
};

const handleConfirmedDelete = async () => {
    deleteConfirmOpen.value = false;
    await deleteCompletedItems(deleteConfirmIds.value);
    deleteConfirmIds.value = [];
};

const onCompanySearchInput = (value: string) => {
    companySearch.value = value;
    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }

    companySearchDebounce = setTimeout(() => {
        void loadItems(1);
    }, 300);
};

const selectedVisibleCount = computed(
    () => itemsData.value.data.filter((item) => selectedItemIds.value.includes(item.id)).length,
);
const selectAllState = computed<boolean | 'indeterminate'>(() => {
    if (itemsData.value.data.length === 0 || selectedVisibleCount.value === 0) {
        return false;
    }

    if (selectedVisibleCount.value === itemsData.value.data.length) {
        return true;
    }

    return 'indeterminate';
});

const itemColumns = useAfsCompletedColumns({
    currentPage: computed(() => itemsData.value.current_page),
    perPage: computed(() => itemsData.value.per_page),
    itemsCount: computed(() => itemsData.value.data.length),
    deletingItems,
    selectedItemIds,
    selectAllState,
    toggleAllVisibleRows,
    toggleItemSelection,
    requestDeleteRow: (itemId) => confirmDelete([itemId]),
});

watch(
    () => itemsData.value.data.map((item) => item.id),
    (visibleIds) => {
        const visibleSet = new Set(visibleIds);
        selectedItemIds.value = selectedItemIds.value.filter((id) => visibleSet.has(id));
    },
    { immediate: true },
);

watch(
    () => exportState.value.status,
    (status) => {
        if (exportPollTimeout) {
            clearTimeout(exportPollTimeout);
            exportPollTimeout = null;
        }

        if (status !== 'queued' && status !== 'processing' && status !== 'cancelling') {
            return;
        }

        exportPollTimeout = setTimeout(async () => {
            await pollExportState();
        }, 3000);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }

    if (exportPollTimeout) {
        clearTimeout(exportPollTimeout);
    }
});
</script>

<template>
    <Head title="Completed Files" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card >
                <CardContent class="flex flex-col gap-5 p-6 md:flex-row md:items-center md:justify-between md:p-8">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.3em] text-teal-700 uppercase">
                            AFS Workspace
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">
                            Completed Files
                        </h1>
                        <p class="max-w-3xl text-sm leading-7 text-muted-foreground">
                            Review signed AFS PDFs, then preview, download, or clean up completed outputs.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="exportBusy"
                            @click="void queueCompletedExport()"
                        >
                            <Download class="mr-2 size-4" />
                            {{ exportBusy ? 'Preparing ZIP...' : 'Download all ZIP' }}
                        </Button>
                        <Button
                            v-if="exportState.status === 'ready' && exportState.downloadUrl"
                            type="button"
                            as-child
                        >
                            <a :href="exportState.downloadUrl">
                                <Download class="mr-2 size-4" />
                                Download ZIP{{ exportState.itemCount ? ` (${exportState.itemCount})` : '' }}
                            </a>
                        </Button>
                        <Button type="button" variant="outline" as-child>
                            <a :href="documentGeneratorRoutes.index().url">
                                <ArrowLeft class="mr-2 size-4" />
                                Back to workspace
                            </a>
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardContent class="space-y-4">
                    <AfsCompletedTable
                        :items-data="itemsData"
                        :items-loading="itemsLoading"
                        :items-sort-by="itemsSortBy"
                        :items-sort-direction="itemsSortDirection"
                        :item-columns="itemColumns"
                        :items-for-table="itemsData.data"
                        :company-search="companySearch"
                        :selected-item-ids="selectedItemIds"
                        :deleting-items="deletingItems"
                        :export-busy="exportBusy"
                        :can-download-selected="canDownloadSelected"
                        :can-delete-selected="canDeleteSelected"
                        :export-status="exportState.status"
                        :export-error="exportState.error"
                        @update:company-search="onCompanySearchInput"
                        @request-download-selected="void queueCompletedExport(selectedItemIds)"
                        @request-delete-selected="confirmDelete(selectedItemIds)"
                        @page-change="loadItems"
                        @per-page-change="async (perPage) => { itemsData.per_page = perPage; await loadItems(1); }"
                        @sort-change="async (column, direction) => { itemsSortBy = column; itemsSortDirection = direction; await loadItems(1); }"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>

    <AlertDialog v-model:open="deleteConfirmOpen">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Delete {{ deleteConfirmIds.length === 1 ? 'file' : 'files' }}?</AlertDialogTitle>
                <AlertDialogDescription>
                    This will permanently delete {{ deleteConfirmIds.length === 1 ? 'this file' : `${deleteConfirmIds.length} files` }}. This action cannot be undone.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                    class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    @click="handleConfirmedDelete"
                >
                    Delete
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

</template>
