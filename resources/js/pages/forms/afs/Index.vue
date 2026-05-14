<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, LoaderCircle, Settings2, Trash2, Upload, X } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AfsIndexTable from '@/components/afs-components/AfsIndexTable.vue';
import AfsEditDialog from '@/components/afs-components/AfsEditDialog.vue';
import AfsSettingsDialog from '@/components/afs-components/AfsSettingsDialog.vue';
import AfsSignDialog from '@/components/afs-components/AfsSignDialog.vue';
import type { CompletedExportState, PaginatedResponse, SignatureSettings, TemplateMappingPayload, UnifiedItem } from '@/components/afs-components/types';
import { useAfsIndexColumns } from '@/components/afs-components/useAfsIndexColumns';
import {
    csrfToken,
    getApi,
    sendPostJson,
} from '@/components/afs-components/utils';
import { resolveTin } from '@/lib/form-field-aliases';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import documentGeneratorRoutes from '@/routes/afs-filing';
import generatedFilesRoutes from '@/routes/afs-filing/completed';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    initialItems: PaginatedResponse<UnifiedItem>;
    initialFilters: {
        search: string;
        sort: 'uploadedAt' | 'generatedAt' | 'pdfStatus' | 'sourceRowNumber' | 'created_at' | 'updated_at' | 'status' | 'row_number';
        direction: 'asc' | 'desc';
        status: string;
        per_page: number;
    };
    initialSignature: {
        signature: SignatureSettings | null;
    };
    initialMapping: TemplateMappingPayload;
    initialImportState: {
        status: 'queued' | 'processing' | 'failed' | null;
        fileName: string | null;
        error: string | null;
    };
    openSettings?: boolean;
    signatureEnabled: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'AFS',
        href: documentGeneratorRoutes.index(),
    },
];

const isUploadDialogOpen = ref(false);
const excelFile = ref<File | null>(null);
const defaultTemplateFile = ref<File | null>(null);
const createErrors = ref<Record<string, string[]>>({});
const createErrorMessage = ref<string | null>(null);
const creatingBatch = ref(false);
const importInProgressNotice = ref<{
    fileName: string;
} | null>(null);
const importFailedNotice = ref<string | null>(null);
const importNoticeUntil = ref<number | null>(null);

const itemsData = ref<PaginatedResponse<UnifiedItem>>(props.initialItems);
const itemsLoading = ref(false);
const itemsNavigating = ref(false);
const itemsSortBy = ref<string>('created_at');
const itemsSortDirection = ref<'asc' | 'desc'>(props.initialFilters.direction ?? 'desc');
const itemStatusFilter = ref(props.initialFilters.status ?? 'all');
const companySearch = ref(props.initialFilters.search ?? '');
const pollingActive = ref(false);
const deletingItems = ref(false);
const selectedItemIds = ref<number[]>([]);
const exportMissingDataBusy = ref(false);
const exportPdfBusy = ref(false);
const exportPdfState = ref<CompletedExportState>({
    status: null,
    error: null,
    itemCount: null,
    downloadUrl: null,
});
let exportPdfPollTimeout: ReturnType<typeof setTimeout> | null = null;
const dismissedExportReadyKey = ref<string | null>(null);

if (typeof window !== 'undefined') {
    dismissedExportReadyKey.value = window.sessionStorage.getItem('afs:dismissed-pdf-export-key');
}

const editDialogOpen = ref(false);
const editingItem = ref<UnifiedItem | null>(null);
const signingItemIds = ref<number[]>([]);
const preparingSignatureItemIds = ref<number[]>([]);
const queuingSignatures = ref(false);
const signDialogOpen = ref(false);
const signDialogTarget = ref<UnifiedItem | null>(null);
const signDialogMode = ref<'single' | 'bulk'>('single');
const signDialogBulkItemIds = ref<number[]>([]);
const settingsDialogOpen = ref(false);
const templateMapping = ref<TemplateMappingPayload>(props.initialMapping);
const editDialogRef = ref<InstanceType<typeof AfsEditDialog> | null>(null);
const deleteConfirmOpen = ref(false);
const deleteConfirmIds = ref<number[]>([]);
const errorDialogOpen = ref(false);
const errorDialogTitle = ref('Row Error');
const errorDialogMissingData = ref<string[]>([]);
const errorDialogErrors = ref<string[]>([]);
const errorDialogMessage = ref<string | null>(null);
const errorDialogFetchedHeaders = ref<string[]>([]);

let pollInterval: ReturnType<typeof setInterval> | null = null;
let companySearchDebounce: ReturnType<typeof setTimeout> | null = null;
const forcePollingUntil = ref<number | null>(null);

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
const allStatusOrder: Record<string, number> = {
    failed: 0,
    signing: 1,
    deleting: 3,
    processing: 4,
    docx_done: 5,
    queued: 6,
    pdf_done: 7,
};
const itemsForTable = computed<UnifiedItem[]>(() => {
    const rows = [...itemsData.value.data];

    if (itemStatusFilter.value !== 'all') {
        return rows;
    }

    const orderFor = (item: UnifiedItem): number => {
        if (isPreparingSignature(item.id)) {
            return 2;
        }

        return allStatusOrder[item.status] ?? Number.MAX_SAFE_INTEGER;
    };

    return rows.sort((a, b) => orderFor(a) - orderFor(b));
});
const canBulkDeleteSelected = computed(
    () => selectedItemIds.value.length > 0 && !deletingItems.value,
);
const tableLoading = computed(() => itemsLoading.value || itemsNavigating.value);
const canBulkSignSelected = computed(() => {
    if (!props.signatureEnabled || selectedItemIds.value.length === 0 || queuingSignatures.value) {
        return false;
    }

    const selectedSet = new Set(selectedItemIds.value);
    return itemsData.value.data.some((item) =>
        selectedSet.has(item.id)
        && item.status === 'pdf_done'
        && item.pdf_available
        && !item.signature_applied
        && item.status !== 'deleting'
        && item.status !== 'signing',
    );
});
const hasPreparingSignatures = computed(() => preparingSignatureItemIds.value.length > 0);
const hasMissingDataItems = computed(() =>
    itemsData.value.data.some((item) => itemHasMissingData(item)),
);
const signingProgressNotice = computed<string | null>(() => {
    if (preparingSignatureItemIds.value.length === 0) {
        return null;
    }

    if (preparingSignatureItemIds.value.length === 1) {
        return 'Preparing to queue signature for 1 row.';
    }

    return `Preparing to queue signatures for ${preparingSignatureItemIds.value.length} rows.`;
});

const sortByFromQuery = (sort: string): string => {
    if (sort === 'uploadedAt') return 'created_at';
    if (sort === 'generatedAt') return 'updated_at';
    if (sort === 'pdfStatus') return 'status';
    if (sort === 'sourceRowNumber') return 'row_number';
    return sort;
};

const querySortFromSortBy = (sortBy: string): string => {
    if (sortBy === 'created_at') return 'uploadedAt';
    if (sortBy === 'updated_at') return 'generatedAt';
    if (sortBy === 'status') return 'pdfStatus';
    if (sortBy === 'row_number') return 'sourceRowNumber';
    return sortBy;
};

itemsSortBy.value = sortByFromQuery(props.initialFilters.sort);
if (props.initialFilters.per_page && props.initialFilters.per_page > 0) {
    itemsData.value.per_page = props.initialFilters.per_page;
}

const buildItemsUrl = (page = itemsData.value.current_page) => {
    const query: Record<string, string> = {
        page: String(page),
        per_page: String(itemsData.value.per_page),
        sort_by: itemsSortBy.value,
        sort_direction: itemsSortDirection.value,
        unsigned_only: '1',
    };

    if (itemStatusFilter.value !== 'all') {
        query.status = itemStatusFilter.value;
    }
    if (companySearch.value.trim() !== '') {
        query.company_search = companySearch.value.trim();
    }

    const params = new URLSearchParams(query);

    return `/afs-filing/items?${params.toString()}`;
};

const visitIndex = (overrides: Partial<{
    page: number;
    perPage: number;
    search: string;
    sortBy: string;
    direction: 'asc' | 'desc';
    status: string;
}>) => {
    itemsNavigating.value = true;
    const nextPage = overrides.page ?? itemsData.value.current_page;
    const nextPerPage = overrides.perPage ?? itemsData.value.per_page;
    const nextSearch = overrides.search ?? companySearch.value.trim();
    const nextSortBy = overrides.sortBy ?? itemsSortBy.value;
    const nextDirection = overrides.direction ?? itemsSortDirection.value;
    const nextStatus = overrides.status ?? itemStatusFilter.value;

    router.get(
        documentGeneratorRoutes.index().url,
        {
            page: nextPage,
            per_page: nextPerPage,
            search: nextSearch !== '' ? nextSearch : undefined,
            sort: querySortFromSortBy(nextSortBy),
            direction: nextDirection,
            status: nextStatus !== 'all' ? nextStatus : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['initialItems', 'initialFilters'],
            onFinish: () => {
                itemsNavigating.value = false;
            },
        },
    );
};

const hasPendingVisibleItems = computed(() =>
    itemsData.value.data.some((item) => ['queued', 'processing', 'docx_done', 'signing', 'deleting'].includes(item.status)),
);

const exportReadyStateKey = computed<string | null>(() => {
    const batchId = typeof exportPdfState.value.batchId === 'string' && exportPdfState.value.batchId !== ''
        ? exportPdfState.value.batchId
        : null;
    if (batchId) {
        return `batch:${batchId}`;
    }

    return exportPdfState.value.downloadUrl ? `url:${exportPdfState.value.downloadUrl}` : null;
});

const shouldShowExportReadyCard = computed<boolean>(() => {
    return exportPdfState.value.status === 'ready'
        && !!exportPdfState.value.downloadUrl
        && exportReadyStateKey.value !== null
        && dismissedExportReadyKey.value !== exportReadyStateKey.value;
});

const stopPolling = () => {
    pollingActive.value = false;
    forcePollingUntil.value = null;

    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
};

const toUnsignedOnly = (payload: PaginatedResponse<UnifiedItem>): PaginatedResponse<UnifiedItem> => ({
    ...payload,
    data: payload.data.filter((item) => !item.signature_applied),
});

const loadItems = async (
    page = itemsData.value.current_page,
    options: { silent?: boolean } = {},
) => {
    const silent = options.silent === true;
    if (!silent) {
        itemsLoading.value = true;
    }

    try {
        const payload = await getApi<PaginatedResponse<UnifiedItem>>(buildItemsUrl(page));
        const unsignedPayload = toUnsignedOnly(payload);
        itemsData.value = unsignedPayload;
        const visibleUnsignedIds = new Set(unsignedPayload.data.map((item) => item.id));

        if (preparingSignatureItemIds.value.length > 0) {
            preparingSignatureItemIds.value = preparingSignatureItemIds.value.filter((id) => visibleUnsignedIds.has(id));
        }

        if (signingItemIds.value.length > 0) {
            const completedSigningIds = signingItemIds.value.filter((id) => !visibleUnsignedIds.has(id));

            if (completedSigningIds.length > 0) {
                toast.success(
                    completedSigningIds.length === 1
                        ? 'Signing done.'
                        : `${completedSigningIds.length} signings done.`,
                );
                signingItemIds.value = signingItemIds.value.filter((id) => visibleUnsignedIds.has(id));
            }
        }

        const stillForcing = forcePollingUntil.value !== null && Date.now() < forcePollingUntil.value;

        if (
            importInProgressNotice.value
            && importNoticeUntil.value !== null
            && Date.now() >= importNoticeUntil.value
            && !stillForcing
        ) {
            importInProgressNotice.value = null;
            importNoticeUntil.value = null;
        }

        if (pollingActive.value && !hasPendingVisibleItems.value && !stillForcing) {
            forcePollingUntil.value = null;
            stopPolling();
        }
    } finally {
        if (!silent) {
            itemsLoading.value = false;
        }
    }
};

const queueAfsPdfExport = async (): Promise<void> => {
    if (exportPdfBusy.value) {
        return;
    }

    exportPdfBusy.value = true;

    try {
        const payload = await sendPostJson<{ message?: string; export_state?: CompletedExportState }>(
            `${generatedFilesRoutes.download.url()}?context=index`,
            {
                company_search: companySearch.value.trim() || undefined,
                sort_by: itemsSortBy.value,
                sort_direction: itemsSortDirection.value,
                status: itemStatusFilter.value,
                include_unsigned: true,
            },
        );

        if (payload.export_state) {
            exportPdfState.value = payload.export_state;
        }

        toast.success(payload.message ?? 'PDF export queued.');
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to export PDF list.');
    } finally {
        exportPdfBusy.value = false;
    }
};

const pollAfsPdfExportState = async (): Promise<void> => {
    try {
        exportPdfState.value = await getApi<CompletedExportState>(`${generatedFilesRoutes.download.state.url()}?context=index`);
    } catch {
        // Ignore transient polling errors.
    }
};

const scheduleAfsPdfExportPoll = (): void => {
    if (exportPdfPollTimeout) {
        return;
    }

    exportPdfPollTimeout = setTimeout(async () => {
        exportPdfPollTimeout = null;
        await pollAfsPdfExportState();

        if (
            exportPdfState.value.status === 'queued'
            || exportPdfState.value.status === 'processing'
            || exportPdfState.value.status === 'cancelling'
        ) {
            scheduleAfsPdfExportPoll();
        }
    }, 3000);
};

const dismissAfsPdfExportReadyCard = (): void => {
    if (!exportReadyStateKey.value) {
        return;
    }

    dismissedExportReadyKey.value = exportReadyStateKey.value;
    if (typeof window !== 'undefined') {
        window.sessionStorage.setItem('afs:dismissed-pdf-export-key', exportReadyStateKey.value);
    }
};

const startPolling = (forceDurationMs = 0) => {
    stopPolling();

    if (forceDurationMs > 0) {
        forcePollingUntil.value = Date.now() + forceDurationMs;
    }

    if (!hasPendingVisibleItems.value && forceDurationMs <= 0) {
        return;
    }

    pollingActive.value = true;

    pollInterval = setInterval(async () => {
        try {
            await loadItems(itemsData.value.current_page, { silent: true });
        } catch {
            stopPolling();
        }
    }, 2000);
};

const postBatch = async () => {
    if (!excelFile.value) {
        createErrorMessage.value = 'Excel file is required.';
        return;
    }

    creatingBatch.value = true;
    createErrors.value = {};
    createErrorMessage.value = null;

    try {
        const formData = new FormData();
        formData.append('excel_file', excelFile.value);

        if (defaultTemplateFile.value) {
            formData.append('default_template_file', defaultTemplateFile.value);
        }

        const response = await fetch(documentGeneratorRoutes.items.upload.url(), {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
        });

        if (response.status === 422) {
            const payload = (await response.json()) as {
                errors?: Record<string, string[]>;
                message?: string;
            };

            createErrors.value = payload.errors ?? {};
            createErrorMessage.value = payload.message ?? 'Validation failed.';
            return;
        }

        if (!response.ok) {
            throw new Error(`Failed to upload file (${response.status}).`);
        }

        const payload = (await response.json()) as {
            message?: string;
            total_items?: number;
            queued_items?: number;
            failed_items?: number;
        };

        await loadItems(1);
        startPolling(120000);
        importInProgressNotice.value = {
            fileName: excelFile.value.name,
        };
        importFailedNotice.value = null;
        importNoticeUntil.value = Date.now() + 120000;
        isUploadDialogOpen.value = false;
        excelFile.value = null;
        defaultTemplateFile.value = null;
        createErrors.value = {};
        createErrorMessage.value = null;

        const queuedItems = Number(payload.queued_items ?? 0);
        const failedItems = Number(payload.failed_items ?? 0);
        const totalItems = Number(payload.total_items ?? queuedItems + failedItems);

        if (failedItems > 0 && queuedItems > 0) {
            toast.success(`Upload processed: ${queuedItems}/${totalItems} queued, ${failedItems} failed validation.`);
        } else if (failedItems > 0 && queuedItems === 0) {
            toast.error(`Upload processed: 0/${totalItems} queued, ${failedItems} failed validation.`);
        } else if (queuedItems > 0) {
            toast.success(`${queuedItems}/${totalItems} rows queued for generation.`);
        } else {
            toast.success(payload.message ?? 'Upload processed.');
        }
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : 'Unable to upload file.',
        );
    } finally {
        creatingBatch.value = false;
    }
};

const onExcelFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    excelFile.value = input.files?.[0] ?? null;
};

const onTemplateFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    defaultTemplateFile.value = input.files?.[0] ?? null;
};

const onItemStatusChange = (value: string) => {
    itemStatusFilter.value = value;
    visitIndex({ page: 1, status: value });
    startPolling();
};

const onCompanySearchInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    companySearch.value = target.value;

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }

    companySearchDebounce = setTimeout(() => {
        visitIndex({ page: 1, search: companySearch.value.trim() });
        startPolling();
    }, 300);
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

const canEditItem = (item: UnifiedItem) => !['queued', 'processing'].includes(item.status);
const canDeleteItem = (item: UnifiedItem) => item.status !== 'deleting';
const isPreparingSignature = (itemId: number) => preparingSignatureItemIds.value.includes(itemId);
const isQueuedForSigning = (item: UnifiedItem): boolean =>
    item.status === 'queued' && signingItemIds.value.includes(item.id) && !isPreparingSignature(item.id);
const displayStatus = (item: UnifiedItem): string =>
    isPreparingSignature(item.id)
        ? 'Preparing for signing'
        : item.status === 'pdf_done' && !item.signature_applied
          ? 'Generated'
        : isQueuedForSigning(item)
          ? 'Queued for signing'
        : item.status === 'deleting'
          ? 'Deleting'
          : item.status === 'signing'
            ? 'Signing'
            : item.status;
const formatDateTime = (value: string | null): string => {
    if (!value) {
        return '-';
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(parsed);
};

const extractTin = (item: UnifiedItem): string => {
    if (typeof item.tin === 'string' && item.tin.trim() !== '') {
        return item.tin;
    }

    return resolveTin(item.row_data, 'afs') ?? '-';
};

const itemHasMissingData = (item: UnifiedItem): boolean => {
    if (item.status !== 'failed') {
        return false;
    }

    const details = item.error_details && typeof item.error_details === 'object'
        ? item.error_details
        : {};
    const missingRaw = (details as Record<string, unknown>).missing_data;

    return Array.isArray(missingRaw) && missingRaw.some((value) => typeof value === 'string' && value.trim() !== '');
};

const exportMissingData = async () => {
    if (exportMissingDataBusy.value) {
        return;
    }

    exportMissingDataBusy.value = true;

    try {
        const response = await fetch(documentGeneratorRoutes.items.exportMissingData.url(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        const contentType = response.headers.get('Content-Type') ?? '';

        if (!response.ok) {
            let message = 'Unable to export missing-data rows.';

            if (contentType.includes('application/json')) {
                const payload = (await response.json()) as { message?: string };
                message = payload.message ?? message;
            }

            throw new Error(message);
        }

        if (!contentType.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
            throw new Error('Missing-data export is unavailable right now.');
        }

        const blob = await response.blob();
        const blobUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = 'afs-filing-missing-data.xlsx';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(blobUrl);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to export missing-data rows.');
    } finally {
        exportMissingDataBusy.value = false;
    }
};

const confirmDelete = (ids: number[]) => {
    deleteConfirmIds.value = ids;
    deleteConfirmOpen.value = true;
};

const handleConfirmedDelete = async () => {
    deleteConfirmOpen.value = false;
    await deleteItems(deleteConfirmIds.value);
    deleteConfirmIds.value = [];
};

const deleteSelectedItems = () => {
    if (!canBulkDeleteSelected.value) {
        return;
    }

    confirmDelete(selectedItemIds.value);
};

const deleteItems = async (itemIds: number[]) => {
    const uniqueItemIds = Array.from(new Set(itemIds));

    if (uniqueItemIds.length === 0 || deletingItems.value) {
        return;
    }

    deletingItems.value = true;

    try {
        const itemMap = new Map(itemsData.value.data.map((item) => [item.id, item]));
        const requests = uniqueItemIds.map(async (itemId) => {
            const item = itemMap.get(itemId);

            if (!item || !canDeleteItem(item)) {
                return false;
            }

            const response = await fetch(
                documentGeneratorRoutes.items.destroy.url({ item: item.id }),
                {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': csrfToken(),
                    },
                },
            );

            if (!response.ok) {
                throw new Error(`Failed to delete row ${item.row_number} (${response.status}).`);
            }

            return true;
        });

        const results = await Promise.allSettled(requests);
        const successCount = results.filter(
            (result) => result.status === 'fulfilled' && result.value === true,
        ).length;
        const failedCount = results.length - successCount;
        const deletedIds = results
            .flatMap((result, index) => (result.status === 'fulfilled' && result.value === true ? [uniqueItemIds[index]] : []));
        if (deletedIds.length > 0) {
            selectedItemIds.value = selectedItemIds.value.filter((id) => !deletedIds.includes(id));
        }

        const currentPageBeforeDelete = itemsData.value.current_page;
        await loadItems(currentPageBeforeDelete);
        if (itemsData.value.data.length === 0 && currentPageBeforeDelete > 1) {
            await loadItems(currentPageBeforeDelete - 1);
        }
        startPolling();

        if (failedCount === 0) {
            toast.success(
                successCount === 1 ? 'Row deletion queued.' : `${successCount} row deletions queued.`,
            );
            return;
        }

        toast.error(
            successCount > 0
                ? `${successCount} deletions queued, ${failedCount} failed.`
                : 'Unable to delete selected rows.',
        );
    } catch (error) {
        toast.error(
            error instanceof Error ? error.message : 'Unable to delete selected rows.',
        );
    } finally {
        deletingItems.value = false;
    }
};

const retryItem = async (item: UnifiedItem) => {
    try {
        await fetch(documentGeneratorRoutes.items.retry.url({ item: item.id }), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
        });

        await loadItems(itemsData.value.current_page, { silent: true });
        startPolling();
        toast.success(`Row ${item.row_number} re-queued.`);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to retry item.');
    }
};

const openEditDialog = (item: UnifiedItem) => {
    editingItem.value = item;
    editDialogOpen.value = true;
    editDialogRef.value?.initForm(item);
};

const onEditSaved = async () => {
    await loadItems(itemsData.value.current_page, { silent: true });
    startPolling();
};

const isItemSigning = (itemId: number) => signingItemIds.value.includes(itemId);

const applySignatureToItem = (item: UnifiedItem) => {
    if (!props.signatureEnabled || !item.pdf_available || item.signature_applied || isItemSigning(item.id) || isPreparingSignature(item.id)) {
        return;
    }

    signDialogMode.value = 'single';
    signDialogBulkItemIds.value = [];
    signDialogTarget.value = item;
    signDialogOpen.value = true;
};

const applySignatureToSelectedItems = () => {
    if (!canBulkSignSelected.value) {
        return;
    }

    const selectedSet = new Set(selectedItemIds.value);
    const eligibleIds = itemsData.value.data
        .filter((item) =>
            selectedSet.has(item.id)
            && item.status === 'pdf_done'
            && item.pdf_available
            && !item.signature_applied
            && item.status !== 'deleting'
            && item.status !== 'signing',
        )
        .map((item) => item.id);

    if (eligibleIds.length === 0) {
        return;
    }

    signDialogMode.value = 'bulk';
    signDialogBulkItemIds.value = eligibleIds;
    signDialogTarget.value = null;
    signDialogOpen.value = true;
};

const onItemSignPrepare = (payload: { mode: 'single' | 'bulk'; itemIds: number[] }) => {
    const targetIds = Array.from(new Set(payload.itemIds));
    if (targetIds.length === 0) {
        return;
    }

    preparingSignatureItemIds.value = Array.from(new Set([...preparingSignatureItemIds.value, ...targetIds]));
    queuingSignatures.value = true;
    toast.message(
        payload.mode === 'bulk'
            ? 'Preparing for signing selected rows...'
            : 'Preparing for signing row...',
    );
};

const onItemSigned = async (payload: { mode: 'single' | 'bulk'; itemIds: number[]; queuedCount: number }) => {
    const targetIds = Array.from(new Set(payload.itemIds));
    if (targetIds.length > 0) {
        preparingSignatureItemIds.value = preparingSignatureItemIds.value.filter((id) => !targetIds.includes(id));
        signingItemIds.value = Array.from(new Set([...signingItemIds.value, ...targetIds]));
    }

    toast.message(payload.mode === 'bulk'
        ? 'Queuing selected rows for signing...'
        : 'Queuing row for signing...');
    try {
        await loadItems(itemsData.value.current_page, { silent: true });
        startPolling();
        if (payload.mode === 'bulk') {
            toast.success(`${payload.queuedCount} row signatures queued.`);
        } else {
            const signedItem = targetIds.length === 1
                ? itemsData.value.data.find((item) => item.id === targetIds[0])
                : null;
            toast.success(signedItem ? `Row ${signedItem.row_number} signature queued.` : 'Signature queued.');
        }
    } finally {
        queuingSignatures.value = false;
    }

    signDialogMode.value = 'single';
    signDialogBulkItemIds.value = [];
    signDialogTarget.value = null;
};

const onItemSignFailed = (payload: { mode: 'single' | 'bulk'; itemIds: number[]; message: string }) => {
    const targetIds = Array.from(new Set(payload.itemIds));
    preparingSignatureItemIds.value = preparingSignatureItemIds.value.filter((id) => !targetIds.includes(id));
    queuingSignatures.value = false;
    toast.error(payload.message);
};

const onTemplateMappingUpdated = (nextMapping: TemplateMappingPayload) => {
    templateMapping.value = nextMapping;
};

const parseErrorDetails = (item: UnifiedItem) => {
    const details = item.error_details && typeof item.error_details === 'object'
        ? item.error_details
        : {};
    const missingRaw = (details as Record<string, unknown>).missing_data;
    const errorsRaw = (details as Record<string, unknown>).errors;
    const messageRaw = (details as Record<string, unknown>).message;
    const missingData = Array.isArray(missingRaw) ? missingRaw.filter((x): x is string => typeof x === 'string' && x.trim() !== '') : [];
    const errors = Array.isArray(errorsRaw) ? errorsRaw.filter((x): x is string => typeof x === 'string' && x.trim() !== '') : [];
    const detailMessage = typeof messageRaw === 'string' && messageRaw.trim() !== '' ? messageRaw : null;
    const rawMessage = item.error_message?.trim() || detailMessage;
    const message = rawMessage ? toUserFriendlyErrorMessage(rawMessage) : null;

    return { missingData, errors, message };
};

const toUserFriendlyErrorMessage = (message: string): string => {
    const normalized = message.trim().toLowerCase();

    if (normalized.includes('attempted too many times')) {
        return 'Generation failed after multiple retry attempts. Please retry this row.';
    }

    if (normalized.includes('timed out')) {
        return 'Generation timed out while processing this row. Please retry.';
    }

    return message;
};

const openErrorDialog = (item: UnifiedItem) => {
    const parsed = parseErrorDetails(item);
    const companyName = item.company?.trim();
    errorDialogTitle.value = companyName && companyName.length > 0
        ? `${companyName} Error`
        : `Row ${item.row_number} Error`;
    errorDialogMissingData.value = parsed.missingData;
    errorDialogErrors.value = parsed.errors;
    errorDialogMessage.value = parsed.message;
    errorDialogFetchedHeaders.value = parsed.missingData.length > 0
        ? Object.keys(item.row_data ?? {})
        : [];
    errorDialogOpen.value = true;
};

const itemColumns = useAfsIndexColumns({
    selectAllState,
    itemsCount: computed(() => itemsData.value.data.length),
    deletingItems,
    selectedItemIds,
    currentPage: computed(() => itemsData.value.current_page),
    perPage: computed(() => itemsData.value.per_page),
    signatureEnabled: props.signatureEnabled,
    queuingSignatures,
    toggleAllVisibleRows,
    toggleItemSelection,
    extractTin,
    formatDateTime,
    displayStatus,
    parseErrorDetails,
    openErrorDialog,
    canEditItem,
    openEditDialog,
    isItemSigning,
    isPreparingSignature,
    applySignatureToItem,
    retryItem,
    canDeleteItem,
    confirmDelete,
});

watch(
    () => props.initialItems,
    (nextItems) => {
        const unsignedItems = toUnsignedOnly(nextItems);
        itemsData.value = unsignedItems;
        itemsNavigating.value = false;
        const visibleIds = new Set(unsignedItems.data.map((item) => item.id));
        selectedItemIds.value = selectedItemIds.value.filter((id) => visibleIds.has(id));
    },
    { immediate: true, deep: true },
);

watch(
    () => props.initialFilters,
    (nextFilters) => {
        companySearch.value = nextFilters.search ?? '';
        itemStatusFilter.value = nextFilters.status ?? 'all';
        itemsSortDirection.value = nextFilters.direction ?? 'desc';
        itemsSortBy.value = sortByFromQuery(nextFilters.sort ?? 'uploadedAt');
        if (nextFilters.per_page > 0) {
            itemsData.value.per_page = nextFilters.per_page;
        }
    },
    { deep: true },
);

watch(
    () => props.initialMapping,
    (nextMapping) => {
        templateMapping.value = nextMapping;
    },
    { deep: true },
);

watch(
    () => itemsData.value.data.map((item) => item.id),
    (visibleIds) => {
        const visibleSet = new Set(visibleIds);
        selectedItemIds.value = selectedItemIds.value.filter((id) => visibleSet.has(id));
    },
    { immediate: true },
);

watch(
    () => exportPdfState.value.status,
    (status) => {
        if (exportPdfPollTimeout) {
            clearTimeout(exportPdfPollTimeout);
            exportPdfPollTimeout = null;
        }

        if (status !== 'queued' && status !== 'processing' && status !== 'cancelling') {
            return;
        }

        scheduleAfsPdfExportPoll();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopPolling();

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }
    if (exportPdfPollTimeout) {
        clearTimeout(exportPdfPollTimeout);
    }
});

onMounted(() => {
    let startedWithImportState = false;

    if (
        (props.initialImportState.status === 'queued' || props.initialImportState.status === 'processing')
        && props.initialImportState.fileName
    ) {
        importInProgressNotice.value = {
            fileName: props.initialImportState.fileName,
        };
        importNoticeUntil.value = Date.now() + 120000;
        startPolling(120000);
        startedWithImportState = true;
    }
    if (props.initialImportState.status === 'failed' && props.initialImportState.error) {
        importFailedNotice.value = props.initialImportState.error;
    }

    if (!startedWithImportState) {
        startPolling();
    }
    if (props.openSettings) {
        settingsDialogOpen.value = true;
    }
    void pollAfsPdfExportState();
});
</script>

<template>
    <Head title="AFS" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <AfsEditDialog
            ref="editDialogRef"
            v-model:open="editDialogOpen"
            :item="editingItem"
            @saved="onEditSaved"
        />

        <AfsSignDialog
            v-if="props.signatureEnabled"
            v-model:open="signDialogOpen"
            :target="signDialogTarget"
            :mode="signDialogMode"
            :bulk-item-ids="signDialogBulkItemIds"
            @prepare="onItemSignPrepare"
            @signed="onItemSigned"
            @failed="onItemSignFailed"
        />
        <AfsSettingsDialog
            v-model:open="settingsDialogOpen"
            :mapping="templateMapping"
            :initial-signature="props.initialSignature.signature"
            :signature-enabled="props.signatureEnabled"
            @mapping-updated="onTemplateMappingUpdated"
        />

        <AlertDialog v-model:open="deleteConfirmOpen">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete {{ deleteConfirmIds.length === 1 ? 'row' : 'rows' }}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This will permanently delete {{ deleteConfirmIds.length === 1 ? 'this row' : `${deleteConfirmIds.length} rows` }} and any generated files. This action cannot be undone.
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

        <Dialog :open="errorDialogOpen" @update:open="errorDialogOpen = $event">
            <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>{{ errorDialogTitle }}</DialogTitle>
                </DialogHeader>

                <div
                    :class="[
                        'grid grid-cols-1 gap-4',
                        errorDialogMissingData.length > 0 ? 'md:grid-cols-2' : '',
                    ]"
                >
                    <div v-if="errorDialogMissingData.length > 0" class="space-y-2">
                        <p class="font-medium">Missing data ({{ errorDialogMissingData.length }})</p>
                        <div class="rounded-md border p-3">
                            <ul class="list-disc space-y-1 pl-5 text-sm break-words">
                                <li v-for="field in errorDialogMissingData" :key="field">{{ field }}</li>
                            </ul>
                        </div>
                    </div>

                    <div v-if="errorDialogMissingData.length > 0" class="space-y-2">
                        <p class="font-medium">Fetched headers ({{ errorDialogFetchedHeaders.length }})</p>
                        <div class="rounded-md border p-3">
                            <ul class="list-disc space-y-1 pl-5 text-sm break-words">
                                <li v-for="header in errorDialogFetchedHeaders" :key="header">
                                    {{ header }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div v-if="errorDialogMessage" class="space-y-2 text-sm">
                    <p class="font-medium">Error message</p>
                    <div class="rounded-md border p-3 break-words">
                        {{ errorDialogMessage }}
                    </div>
                </div>

                <div v-if="errorDialogErrors.length > 0" class="space-y-2 text-sm">
                    <p class="font-medium">Validation errors</p>
                    <ul class="list-disc space-y-1 pl-5">
                        <li v-for="entry in errorDialogErrors" :key="entry">{{ entry }}</li>
                    </ul>
                </div>

                <DialogFooter>
                    <Button type="button" variant="secondary" @click="errorDialogOpen = false">Close</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardContent class="flex flex-col gap-5 p-6 md:flex-row md:items-center md:justify-between md:p-8">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.3em] text-teal-700 uppercase">
                            AFS Workspace
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">
                            AFS Generator
                        </h1>
                        <p class="max-w-3xl text-sm leading-7 text-muted-foreground">
                            Upload one Excel source and one default DOCX template, then generate and review output rows
                            in one workspace.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 justify-end">
                        <Button variant="secondary" as-child>
                            <a :href="generatedFilesRoutes.index().url">Completed Files</a>
                        </Button>
                        <Button
                            v-if="hasMissingDataItems"
                            variant="outline"
                            :disabled="exportMissingDataBusy"
                            @click="void exportMissingData()"
                        >
                            <LoaderCircle v-if="exportMissingDataBusy" class="mr-2 size-4 animate-spin" />
                            <Download v-else class="mr-2 size-4" />
                            {{ exportMissingDataBusy ? 'Exporting...' : 'Export Missing Data (Excel)' }}
                        </Button>
                        <Button variant="outline" @click="settingsDialogOpen = true">
                            <Settings2 class="mr-2 size-4" />
                            Settings
                        </Button>
                        <Button @click="isUploadDialogOpen = true">
                            <Upload class="mr-2 size-4" />
                            Upload XLS | XLSX
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Dialog :open="isUploadDialogOpen" @update:open="isUploadDialogOpen = $event">
                <DialogContent class="sm:max-w-xl">
                    <DialogHeader class="space-y-1">
                        <DialogTitle>Upload Spreadsheet</DialogTitle>
                        <DialogDescription>
                            Select an Excel file and optionally override the default DOCX template for this batch.
                        </DialogDescription>
                    </DialogHeader>

                    <form class="space-y-5" @submit.prevent="postBatch">
                        <div class="space-y-3">
                            <Label for="excel">Excel File</Label>
                            <Input id="excel" type="file" accept=".xls,.xlsx" @change="onExcelFileChange" />
                            <p v-if="createErrors.excel_file" class="text-sm text-destructive">
                                {{ createErrors.excel_file[0] }}
                            </p>
                        </div>

                        <div class="space-y-3">
                            <Label for="template">Default DOCX Template</Label>
                            <Input id="template" type="file" accept=".docx" @change="onTemplateFileChange" />
                            <p v-if="createErrors.default_template_file" class="text-sm text-destructive">
                                {{ createErrors.default_template_file[0] }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Optional if a global default is already set in Settings.
                            </p>
                            <p class="text-xs text-muted-foreground">
                                The first worksheet is always used, and the latest earlier workbook you uploaded is
                                checked automatically for matching company rows.
                            </p>
                            <p class="text-xs text-muted-foreground">
                                In the 2025 template, placeholders like
                                <code>{NET INCOME}</code> treat the current file value as 2025 and add the matched
                                old-file base value, and subtraction stays explicit, such as
                                <code>{TRADE RECEIVABLES 2025-TRADE RECEIVABLES}</code>.
                            </p>
                        </div>

                        <p v-if="createErrorMessage" class="text-sm text-destructive">
                            {{ createErrorMessage }}
                        </p>

                        <DialogFooter class="gap-2">
                            <Button type="button" variant="secondary" :disabled="creatingBatch" @click="isUploadDialogOpen = false">
                                Cancel
                            </Button>
                            <Button type="submit" :disabled="creatingBatch">
                                <LoaderCircle v-if="creatingBatch" class="size-4 animate-spin" />
                                <Upload v-else class="mr-2 size-4" />
                                {{ creatingBatch ? 'Uploading...' : 'Start Batch' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Alert v-if="importInProgressNotice">
                <LoaderCircle class="size-4 animate-spin" />
                <AlertTitle>Spreadsheet Import In Progress</AlertTitle>
                <AlertDescription>
                    Importing {{ importInProgressNotice.fileName }}. Rows will appear here once the background import finishes.
                </AlertDescription>
            </Alert>

            <Alert v-if="importFailedNotice" variant="destructive">
                <AlertTitle>Spreadsheet Import Failed</AlertTitle>
                <AlertDescription>
                    {{ importFailedNotice }}
                </AlertDescription>
            </Alert>
            <Alert v-if="signingProgressNotice">
                <LoaderCircle class="size-4 animate-spin" />
                <AlertTitle>Signature Queue In Progress</AlertTitle>
                <AlertDescription>
                    {{ signingProgressNotice }}
                </AlertDescription>
            </Alert>
            <Alert v-if="exportPdfState.status === 'queued' || exportPdfState.status === 'processing' || exportPdfState.status === 'cancelling'">
                <LoaderCircle class="size-4 animate-spin" />
                <AlertTitle>PDF Export In Progress</AlertTitle>
                <AlertDescription>
                    <span>
                        {{
                            exportPdfState.status === 'queued'
                                ? 'Your PDF ZIP export is queued and will start shortly.'
                                : exportPdfState.status === 'cancelling'
                                    ? 'Cancelling your PDF ZIP export...'
                                    : 'Your PDF ZIP export is being prepared in the background.'
                        }}
                    </span>
                </AlertDescription>
            </Alert>
            <Alert v-if="shouldShowExportReadyCard" class="relative pr-14">
                <Button
                    size="icon"
                    variant="ghost"
                    class="absolute top-2 right-2 z-20 h-7 w-7"
                    @click="dismissAfsPdfExportReadyCard"
                >
                    <X class="size-4" />
                </Button>
                <AlertTitle>PDF ZIP Export Ready</AlertTitle>
                <AlertDescription class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <span class="flex flex-col gap-1">
                        <span>
                        {{
                            exportPdfState.itemCount !== null
                                ? `Your PDF ZIP export is ready with ${exportPdfState.itemCount} item${exportPdfState.itemCount === 1 ? '' : 's'}.`
                                : 'Your PDF ZIP export is ready to download.'
                        }}
                        </span>
                    </span>
                    <Button as-child size="sm" class="self-start sm:self-auto">
                        <a :href="exportPdfState.downloadUrl">
                            Download ready
                        </a>
                    </Button>
                </AlertDescription>
            </Alert>

            <Card>
                <CardContent class="space-y-4">
                    <AfsIndexTable
                        :status="itemStatusFilter"
                        :company-search="companySearch"
                        :show-bulk-sign="props.signatureEnabled"
                        :bulk-sign-label="
                            hasPreparingSignatures || queuingSignatures
                                ? 'Queuing for signing...'
                                : (selectedItemIds.length > 0 ? `Sign selected (${selectedItemIds.length})` : 'Sign selected')
                        "
                        :can-bulk-sign-selected="canBulkSignSelected"
                        :can-bulk-delete-selected="canBulkDeleteSelected"
                        :table-loading="tableLoading"
                        :items-for-table="itemsForTable"
                        :items-data="itemsData"
                        :items-sort-by="itemsSortBy"
                        :items-sort-direction="itemsSortDirection"
                        :item-columns="itemColumns"
                        :export-pdf-busy="exportPdfBusy"
                        @status-change="onItemStatusChange"
                        @company-search-input="onCompanySearchInput"
                        @export-pdf-list="void queueAfsPdfExport()"
                        @bulk-sign="applySignatureToSelectedItems"
                        @bulk-delete="deleteSelectedItems"
                        @page-change="(page) => { visitIndex({ page }); startPolling(); }"
                        @per-page-change="(perPage) => { itemsData.per_page = perPage; visitIndex({ page: 1, perPage }); startPolling(); }"
                        @sort-change="(column, direction) => { itemsSortBy = column; itemsSortDirection = direction; visitIndex({ page: 1, sortBy: column, direction }); startPolling(); }"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
