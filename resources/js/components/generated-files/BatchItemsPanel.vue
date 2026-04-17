<script setup lang="ts">
import type { ColumnDef } from '@tanstack/vue-table';
import {
    Eye,
    FileText,
    Loader2,
    MoreHorizontal,
    Pencil,
    PenLine,
    Printer,
} from 'lucide-vue-next';
import { computed, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { createToast, showToast } from '@/lib/toast';
import documentGeneratorRoutes from '@/routes/document-generator';

type SortDirection = 'asc' | 'desc';

type BatchSummary = {
    id: number;
    source_excel_name: string;
    template_name: string;
    status: string;
    total_items: number;
    processed_items: number;
    success_items: number;
    failed_items: number;
    created_at: string | null;
    completed_at: string | null;
};

type BatchProgress = {
    batch_id: number;
    status: string;
    total_items: number;
    processed_items: number;
    success_items: number;
    failed_items: number;
    progress_percent: number;
};

type BatchItem = {
    id: number;
    row_number: number;
    company: string;
    status: string;
    row_data: Record<string, string>;
    docx_available: boolean;
    pdf_available: boolean;
    signature_applied: boolean;
    signature_applied_at: string | null;
    error_message: string | null;
    error_details: {
        missing_data?: string[];
        errors?: string[];
    } | null;
    created_at: string | null;
    updated_at: string | null;
};

type PaginatedResponse<T> = {
    current_page: number;
    data: T[];
    last_page: number;
    per_page: number;
    total: number;
};

const props = defineProps<{
    batch: BatchSummary;
    signatureEnabled: boolean;
}>();

const batchProgress = ref<BatchProgress>({
    batch_id: props.batch.id,
    status: props.batch.status,
    total_items: props.batch.total_items,
    processed_items: props.batch.processed_items,
    success_items: props.batch.success_items,
    failed_items: props.batch.failed_items,
    progress_percent:
        props.batch.total_items === 0
            ? 100
            : Math.min(
                  100,
                  Math.floor(
                      (props.batch.processed_items /
                          Math.max(props.batch.total_items, 1)) *
                          100,
                  ),
              ),
});
const itemsData = ref<PaginatedResponse<BatchItem>>({
    current_page: 1,
    data: [],
    last_page: 1,
    per_page: 10,
    total: 0,
});
const itemsLoading = ref(false);
const itemsSortBy = ref('row_number');
const itemsSortDirection = ref<SortDirection>('asc');
const itemStatusFilter = ref('all');
const itemSignatureFilter = ref('all');
const companySearch = ref('');
const pollingActive = ref(false);

const editDialogOpen = ref(false);
const editSubmitting = ref(false);
const editErrorMessage = ref<string | null>(null);
const editErrors = ref<Record<string, string[]>>({});
const editingItem = ref<BatchItem | null>(null);
const editForm = reactive<Record<string, string>>({});
const deleteItemDialogOpen = ref(false);
const deletingItem = ref(false);
const pendingDeleteItem = ref<BatchItem | null>(null);
const errorDetailsDialogOpen = ref(false);
const selectedErrorItem = ref<BatchItem | null>(null);
const regeneratingItemIds = ref<number[]>([]);
const selectedItemIds = ref<number[]>([]);
const signingItemIds = ref<number[]>([]);
const signingBulk = ref(false);
const signDialogOpen = ref(false);
const signDialogSubmitting = ref(false);
const signDialogError = ref<string | null>(null);
const signDialogMode = ref<'single' | 'bulk'>('single');
const signDialogTarget = ref<BatchItem | null>(null);
const presidentSignatureFile = ref<File | null>(null);

let companySearchDebounce: ReturnType<typeof setTimeout> | null = null;
let pollInterval: ReturnType<typeof setInterval> | null = null;
let printFrame: HTMLIFrameElement | null = null;
let printCleanupTimeout: ReturnType<typeof setTimeout> | null = null;

const csrfToken = () => {
    const xsrfCookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!xsrfCookie) {
        return '';
    }

    return decodeURIComponent(xsrfCookie.split('=')[1] ?? '');
};

const getApi = async <T,>(url: string): Promise<T> => {
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

const sendJson = async <T,>(
    url: string,
    method: 'PUT',
    payload: unknown,
): Promise<T> => {
    const response = await fetch(url, {
        method,
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
        const errorPayload = (await response.json()) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        const validationError = new Error(
            errorPayload.message ?? 'Validation failed.',
        );
        Object.assign(validationError, {
            validationErrors: errorPayload.errors ?? {},
        });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const sendDelete = async (url: string): Promise<void> => {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }
};

const sendPostJson = async <T,>(url: string, payload: unknown): Promise<T> => {
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

    if (response.status === 422) {
        const errorPayload = (await response.json()) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        throw new Error(errorPayload.message ?? 'Validation failed.');
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const sendPostFormData = async <T,>(url: string, payload: FormData): Promise<T> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: payload,
    });

    if (response.status === 422) {
        const errorPayload = (await response.json()) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        throw new Error(errorPayload.message ?? 'Validation failed.');
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const loadBatchProgress = async () => {
    batchProgress.value = await getApi<BatchProgress>(
        documentGeneratorRoutes.batches.progress.url({
            batch: props.batch.id,
        }),
    );
};

const loadBatchItems = async (page = itemsData.value.current_page) => {
    itemsLoading.value = true;

    try {
        const query: Record<string, string | number> = {
            page,
            per_page: itemsData.value.per_page,
            sort_by: itemsSortBy.value,
            sort_direction: itemsSortDirection.value,
        };

        if (itemStatusFilter.value !== 'all') {
            query.status = itemStatusFilter.value;
        }
        if (props.signatureEnabled && itemSignatureFilter.value !== 'all') {
            query.signature_filter = itemSignatureFilter.value;
        }
        if (companySearch.value.trim() !== '') {
            query.company_search = companySearch.value.trim();
        }

        itemsData.value = await getApi<PaginatedResponse<BatchItem>>(
            documentGeneratorRoutes.batches.items.url(
                { batch: props.batch.id },
                {
                    query,
                },
            ),
        );
        selectedItemIds.value = selectedItemIds.value.filter((itemId) =>
            itemsData.value.data.some((item) => item.id === itemId),
        );

        reconcileRegeneratingItems(itemsData.value.data);
        syncPolling();
    } finally {
        itemsLoading.value = false;
    }
};

const statusBadgeVariant = (
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === 'failed') {
        return 'destructive';
    }

    if (status === 'pdf_done' || status === 'completed') {
        return 'default';
    }

    if (status === 'processing' || status === 'docx_done') {
        return 'secondary';
    }

    return 'outline';
};

const isItemRegenerating = (itemId: number) =>
    regeneratingItemIds.value.includes(itemId);
const isItemSelected = (itemId: number) => selectedItemIds.value.includes(itemId);
const isItemSigning = (itemId: number) => signingItemIds.value.includes(itemId);

const selectableItems = computed(() => itemsData.value.data.filter((item) => item.pdf_available && !item.signature_applied));
const allVisibleSelected = computed(
    () => selectableItems.value.length > 0 && selectableItems.value.every((item) => isItemSelected(item.id)),
);
const selectedBulkTargets = computed(() =>
    itemsData.value.data
        .filter((item) => isItemSelected(item.id) && item.pdf_available && !item.signature_applied)
        .map((item) => ({ batch_id: props.batch.id, item_id: item.id, row_number: item.row_number })),
);
const canBulkSign = computed(() => selectedBulkTargets.value.length > 0 && !signingBulk.value);
const bulkSignButtonLabel = computed(() => {
    if (signingBulk.value) {
        return 'Applying...';
    }

    const countLabel = selectedBulkTargets.value.length > 0 ? ` (${selectedBulkTargets.value.length})` : '';
    return `Add Signature (Bulk)${countLabel}`;
});

const canEditItem = (item: BatchItem) =>
    !isItemRegenerating(item.id) &&
    !['queued', 'processing'].includes(item.status);

const resetEditForm = () => {
    for (const key of Object.keys(editForm)) {
        delete editForm[key];
    }
};

const openEditDialog = (item: BatchItem) => {
    editingItem.value = item;
    editDialogOpen.value = true;
    editErrorMessage.value = null;
    editErrors.value = {};
    resetEditForm();

    for (const [key, value] of Object.entries(item.row_data)) {
        editForm[key] = value;
    }
};

const closeEditDialog = () => {
    editDialogOpen.value = false;
    editingItem.value = null;
    editErrorMessage.value = null;
    editErrors.value = {};
    resetEditForm();
};

const openDeleteItemDialog = (item: BatchItem) => {
    pendingDeleteItem.value = item;
    deleteItemDialogOpen.value = true;
};

const closeDeleteItemDialog = () => {
    deleteItemDialogOpen.value = false;
    pendingDeleteItem.value = null;
};

const openErrorDetailsDialog = (item: BatchItem) => {
    selectedErrorItem.value = item;
    errorDetailsDialogOpen.value = true;
};

const closeErrorDetailsDialog = () => {
    errorDetailsDialogOpen.value = false;
    selectedErrorItem.value = null;
};

const stopPolling = () => {
    pollingActive.value = false;

    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
};

const showNotice = (type: 'success' | 'error', title: string, message: string) => {
    showToast(createToast(type, title, message));
};

const reconcileRegeneratingItems = (items: BatchItem[]) => {
    regeneratingItemIds.value = regeneratingItemIds.value.filter((id) => {
        const item = items.find((entry) => entry.id === id);

        if (!item) {
            return false;
        }

        if (item.status === 'failed') {
            showNotice(
                'error',
                `Row ${item.row_number} regeneration failed`,
                item.error_message ?? 'Please review the row and try again.',
            );
            return false;
        }

        if (item.status === 'pdf_done') {
            showNotice(
                'success',
                `Row ${item.row_number} regeneration completed`,
                'The updated files are ready to use.',
            );
            return false;
        }

        return ['queued', 'processing', 'docx_done'].includes(item.status);
    });
};

const syncPolling = () => {
    const shouldPoll =
        regeneratingItemIds.value.length > 0 ||
        itemsData.value.data.some((item) =>
            ['queued', 'processing', 'docx_done'].includes(item.status),
        );

    if (!shouldPoll) {
        stopPolling();
        return;
    }

    if (pollInterval) {
        pollingActive.value = true;
        return;
    }

    pollingActive.value = true;
    pollInterval = setInterval(() => {
        void Promise.all([loadBatchItems(), loadBatchProgress()]);
    }, 2000);
};

const onItemStatusChange = async (value: string) => {
    itemStatusFilter.value = value;
    await loadBatchItems(1);
};

const onItemSignatureFilterChange = async (value: string) => {
    if (!props.signatureEnabled) {
        return;
    }

    itemSignatureFilter.value = value;
    await loadBatchItems(1);
};

const onCompanySearchInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    companySearch.value = target.value;

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }

    companySearchDebounce = setTimeout(() => {
        void loadBatchItems(1);
    }, 300);
};

const optimisticQueueItem = (itemId: number, rowData: Record<string, string>) => {
    itemsData.value = {
        ...itemsData.value,
        data: itemsData.value.data.map((item) =>
            item.id === itemId
                ? {
                      ...item,
                      row_data: { ...rowData },
                      status: 'queued',
                      docx_available: false,
                      pdf_available: false,
                      signature_applied: false,
                      signature_applied_at: null,
                      error_message: null,
                      error_details: null,
                      updated_at: new Date().toISOString(),
                  }
                : item,
        ),
    };
};

const toggleItemSelection = (itemId: number, checked: boolean) => {
    if (!props.signatureEnabled) {
        return;
    }

    selectedItemIds.value = checked
        ? Array.from(new Set([...selectedItemIds.value, itemId]))
        : selectedItemIds.value.filter((id) => id !== itemId);
};

const toggleAllVisibleSelection = (checked: boolean) => {
    if (!props.signatureEnabled) {
        return;
    }

    if (!checked) {
        selectedItemIds.value = selectedItemIds.value.filter(
            (id) => !selectableItems.value.some((item) => item.id === id),
        );
        return;
    }

    selectedItemIds.value = Array.from(
        new Set([...selectedItemIds.value, ...selectableItems.value.map((item) => item.id)]),
    );
};

const applySignatureToItem = async (item: BatchItem) => {
    if (!props.signatureEnabled) {
        return;
    }

    if (!item.pdf_available || item.signature_applied || isItemSigning(item.id)) {
        return;
    }
    signDialogMode.value = 'single';
    signDialogTarget.value = item;
    signDialogOpen.value = true;
    signDialogError.value = null;
    presidentSignatureFile.value = null;
};

const applySignatureBulk = async () => {
    if (!props.signatureEnabled) {
        return;
    }

    if (!canBulkSign.value) {
        return;
    }
    signDialogMode.value = 'bulk';
    signDialogTarget.value = null;
    signDialogOpen.value = true;
    signDialogError.value = null;
    presidentSignatureFile.value = null;
};

const onPresidentSignatureFileChange = (event: Event) => {
    if (!props.signatureEnabled) {
        return;
    }

    const input = event.target as HTMLInputElement;
    presidentSignatureFile.value = input.files?.[0] ?? null;
    signDialogError.value = null;
};

const submitSignatureDialog = async () => {
    if (!props.signatureEnabled) {
        return;
    }

    if (!presidentSignatureFile.value) {
        signDialogError.value = 'President signature image is required.';
        return;
    }

    signDialogSubmitting.value = true;
    signDialogError.value = null;

    try {
        if (signDialogMode.value === 'single' && signDialogTarget.value) {
            const item = signDialogTarget.value;
            signingItemIds.value = [...signingItemIds.value, item.id];

            const formData = new FormData();
            formData.append('president_signature_file', presidentSignatureFile.value);

            const payload = await sendPostFormData<{
                message: string;
                item: BatchItem;
                pdf_url: string;
            }>(
                documentGeneratorRoutes.batches.items.signature.url({
                    batch: props.batch.id,
                    item: item.id,
                }),
                formData,
            );

            showNotice('success', 'Signature applied', `Row ${item.row_number} was signed.`);
            await loadBatchItems(itemsData.value.current_page);
            signDialogOpen.value = false;

            if (payload.pdf_url) {
                window.open(payload.pdf_url, '_blank', 'noopener,noreferrer');
            }
        }

        if (signDialogMode.value === 'bulk') {
            signingBulk.value = true;

            const formData = new FormData();
            formData.append('president_signature_file', presidentSignatureFile.value);
            selectedBulkTargets.value.forEach((target, index) => {
                formData.append(`targets[${index}][batch_id]`, String(target.batch_id));
                formData.append(`targets[${index}][item_id]`, String(target.item_id));
            });

            const payload = await sendPostFormData<{
                results: Array<{ batch_id: number; item_id: number; success: boolean; message?: string }>;
            }>(documentGeneratorRoutes.items.signature.bulk.url(), formData);

            const successCount = payload.results.filter((result) => result.success).length;
            const failedCount = payload.results.length - successCount;

            if (failedCount === 0) {
                showNotice('success', 'Bulk signature complete', `${successCount} file(s) signed.`);
            } else {
                showNotice(
                    'error',
                    'Bulk signature completed with errors',
                    `${successCount} signed, ${failedCount} failed. Please retry failed files one by one.`,
                );
            }

            selectedItemIds.value = [];
            await loadBatchItems(itemsData.value.current_page);
            signDialogOpen.value = false;
        }
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Unable to apply signatures.';
        signDialogError.value = message;
        showNotice('error', 'Signature failed', message);
    } finally {
        signDialogSubmitting.value = false;
        signingBulk.value = false;
        if (signDialogTarget.value) {
            signingItemIds.value = signingItemIds.value.filter((id) => id !== signDialogTarget.value?.id);
        }
    }
};

const summaryStats = computed(() => {
    const total = batchProgress.value.total_items;
    const done = batchProgress.value.success_items;
    const failed = batchProgress.value.failed_items;
    const withPdf = batchProgress.value.success_items;
    const withoutPdf = Math.max(0, total - withPdf);
    const inProgress = Math.max(0, total - done - failed);

    return [
        { label: 'Total', value: total },
        { label: 'Done', value: done },
        { label: 'Failed', value: failed },
        { label: 'In Progress', value: inProgress },
        { label: 'With PDF', value: withPdf },
        { label: 'Without PDF', value: withoutPdf },
    ];
});

const editFormEntries = computed(() => Object.entries(editForm));
const selectedMissingData = computed(
    () => selectedErrorItem.value?.error_details?.missing_data ?? [],
);
const selectedValidationErrors = computed(
    () => selectedErrorItem.value?.error_details?.errors ?? [],
);

const saveEditedItem = async () => {
    if (!editingItem.value) {
        return;
    }

    const itemId = editingItem.value.id;
    const rowNumber = editingItem.value.row_number;
    const queuedRowData = { ...editForm };

    editSubmitting.value = true;
    editErrorMessage.value = null;
    editErrors.value = {};

    try {
        await sendJson<BatchItem>(
            documentGeneratorRoutes.batches.items.update.url({
                batch: props.batch.id,
                item: editingItem.value.id,
            }),
            'PUT',
            {
                row_data: editForm,
            },
        );

        if (!regeneratingItemIds.value.includes(itemId)) {
            regeneratingItemIds.value = [...regeneratingItemIds.value, itemId];
        }

        optimisticQueueItem(itemId, queuedRowData);
        showNotice(
            'success',
            `Row ${rowNumber} saved`,
            'Regeneration started. Updated files will appear automatically.',
        );

        await Promise.all([
            loadBatchItems(itemsData.value.current_page),
            loadBatchProgress(),
        ]);
        syncPolling();
        closeEditDialog();
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            editErrors.value =
                (
                    error as Error & {
                        validationErrors?: Record<string, string[]>;
                    }
                ).validationErrors ?? {};
        }

        editErrorMessage.value =
            error instanceof Error ? error.message : 'Unable to update row.';

        showNotice(
            'error',
            `Row ${rowNumber} was not updated`,
            error instanceof Error ? error.message : 'Unable to update row.',
        );
    } finally {
        editSubmitting.value = false;
    }
};

const confirmDeleteItem = async () => {
    if (!pendingDeleteItem.value) {
        return;
    }

    deletingItem.value = true;

    try {
        await sendDelete(
            documentGeneratorRoutes.batches.items.destroy.url({
                batch: props.batch.id,
                item: pendingDeleteItem.value.id,
            }),
        );

        regeneratingItemIds.value = regeneratingItemIds.value.filter((id) => id !== pendingDeleteItem.value?.id);

        await Promise.all([
            loadBatchItems(
                itemsData.value.current_page > 1 && itemsData.value.data.length === 1
                    ? itemsData.value.current_page - 1
                    : itemsData.value.current_page,
            ),
            loadBatchProgress(),
        ]);

        showNotice(
            'success',
            `Row ${pendingDeleteItem.value.row_number} deleted`,
            'The row has been hidden from this batch.',
        );
        closeDeleteItemDialog();
    } catch (error) {
        showNotice(
            'error',
            'Row was not deleted',
            error instanceof Error ? error.message : 'Unable to delete row.',
        );
    } finally {
        deletingItem.value = false;
    }
};

const pdfUrlForItem = (item: BatchItem) =>
    documentGeneratorRoutes.batches.items.download.url({
        batch: props.batch.id,
        item: item.id,
        type: 'pdf',
    });

const cleanupPrintFrame = () => {
    if (printCleanupTimeout) {
        clearTimeout(printCleanupTimeout);
        printCleanupTimeout = null;
    }

    printFrame?.remove();
    printFrame = null;
};

const printItemPdf = (item: BatchItem) => {
    if (!item.pdf_available) {
        return;
    }

    cleanupPrintFrame();

    const iframe = document.createElement('iframe');
    iframe.src = pdfUrlForItem(item);
    iframe.setAttribute('aria-hidden', 'true');
    iframe.style.position = 'fixed';
    iframe.style.right = '0';
    iframe.style.bottom = '0';
    iframe.style.width = '1px';
    iframe.style.height = '1px';
    iframe.style.opacity = '0';
    iframe.style.pointerEvents = 'none';
    iframe.style.border = '0';

    iframe.addEventListener('load', () => {
        window.setTimeout(() => {
            const frameWindow = iframe.contentWindow;
            frameWindow?.focus();
            frameWindow?.print();
            frameWindow?.addEventListener('afterprint', cleanupPrintFrame, {
                once: true,
            });
        }, 400);
    });

    printCleanupTimeout = setTimeout(() => {
        cleanupPrintFrame();
    }, 60000);

    printFrame = iframe;
    document.body.append(iframe);
};

const docxUrlForItem = (item: BatchItem) =>
    documentGeneratorRoutes.batches.items.download.url({
        batch: props.batch.id,
        item: item.id,
        type: 'docx',
    });

const normalizeHeader = (header: string) =>
    header.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

const extractSecRegistrationYear = (rowData: Record<string, string>) => {
    for (const [header, value] of Object.entries(rowData)) {
        if (normalizeHeader(header) !== 'sec_registration_date') {
            continue;
        }

        const match = value.match(/\b(\d{4})\b/);
        if (match) {
            return match[1];
        }
    }

    return null;
};

const itemColumns = computed<ColumnDef<BatchItem>[]>(() => [
    ...(props.signatureEnabled
        ? [{
              id: 'select',
              header: () =>
                  h('input', {
                      type: 'checkbox',
                      checked: allVisibleSelected.value,
                      onChange: (event: Event) => {
                          const target = event.target as HTMLInputElement;
                          toggleAllVisibleSelection(target.checked);
                      },
                  }),
              enableSorting: false,
              cell: ({ row }) =>
                  h('input', {
                      type: 'checkbox',
                      disabled: !row.original.pdf_available || row.original.signature_applied,
                      checked: isItemSelected(row.original.id),
                      onChange: (event: Event) => {
                          const target = event.target as HTMLInputElement;
                          toggleItemSelection(row.original.id, target.checked);
                      },
                  }),
          } satisfies ColumnDef<BatchItem>]
        : []),
    {
        id: 'row_number',
        accessorKey: 'row_number',
        header: 'Row',
        enableSorting: true,
    },
    {
        id: 'company',
        accessorKey: 'company',
        header: 'Company',
        enableSorting: false,
        cell: ({ row }) => row.original.company || '-',
    },
    {
        id: 'sec_registration_year',
        header: 'Year',
        enableSorting: false,
        cell: ({ row }) => extractSecRegistrationYear(row.original.row_data) ?? '-',
    },
    {
        id: 'status',
        accessorKey: 'status',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'div',
                { class: 'flex items-center gap-2' },
                [
                    isItemRegenerating(row.original.id)
                        ? h(Loader2, {
                              class: 'size-3.5 animate-spin text-muted-foreground',
                          })
                        : null,
                    h(
                        Badge,
                        {
                            variant: statusBadgeVariant(row.original.status),
                        },
                        () =>
                            isItemRegenerating(row.original.id) &&
                            row.original.status === 'queued'
                                ? 'regenerating'
                                : row.original.status,
                    ),
                    row.original.signature_applied
                        ? h(
                              Badge,
                              {
                                  variant: 'secondary',
                              },
                              () => 'Signed',
                          )
                        : null,
                ],
            ),
    },
    {
        id: 'error_message',
        accessorKey: 'error_message',
        header: 'Error',
        enableSorting: false,
        cell: ({ row }) => row.original.error_message ?? '-',
    },
    {
        id: 'actions',
        header: 'Actions',
        enableSorting: false,
        cell: ({ row }) => {
            const item = row.original;

            return h('div', { class: 'flex items-center gap-1' }, [
                item.status === 'failed' && item.error_details
                    ? h(
                          Button,
                          {
                              variant: 'ghost',
                              size: 'sm',
                              class: 'h-8 px-2 text-xs',
                              onClick: () => openErrorDetailsDialog(item),
                          },
                          {
                              default: () => 'Error details',
                          },
                      )
                    : null,
                h(
                    Button,
                    {
                        variant: 'ghost',
                        size: 'icon',
                        class: 'size-8',
                        disabled:
                            !item.pdf_available || isItemRegenerating(item.id),
                        'aria-label': 'Print PDF',
                        title: item.pdf_available ? 'Print PDF' : 'Print unavailable',
                        onClick: () => printItemPdf(item),
                    },
                    {
                        default: () =>
                            h(Printer, {
                                class: 'size-4',
                            }),
                    },
                ),
                h(
                    DropdownMenu,
                    {},
                    {
                        default: () => [
                            h(
                                DropdownMenuTrigger,
                                { asChild: true },
                                {
                                    default: () =>
                                        h(
                                            Button,
                                            {
                                                variant: 'ghost',
                                                size: 'icon-sm',
                                                'aria-label': 'More actions',
                                                title: 'More actions',
                                            },
                                            {
                                                default: () =>
                                                    h(MoreHorizontal, {
                                                        class: 'size-4',
                                                    }),
                                            },
                                        ),
                                },
                            ),
                            h(
                                DropdownMenuContent,
                                { align: 'end', class: 'w-44' },
                                {
                                    default: () => [
                                        h(
                                            DropdownMenuItem,
                                            {
                                                disabled: !canEditItem(item),
                                                onSelect: (
                                                    event: Event,
                                                ) => {
                                                    event.preventDefault();

                                                    if (!canEditItem(item)) {
                                                        return;
                                                    }

                                                    openEditDialog(item);
                                                },
                                            },
                                            {
                                                default: () => [
                                                    h(Pencil, {
                                                        class: 'size-4',
                                                    }),
                                                    h('span', 'Edit'),
                                                ],
                                            },
                                        ),
                                        item.docx_available
                                            ? h(
                                                  DropdownMenuItem,
                                                  {
                                                      asChild: true,
                                                      disabled: isItemRegenerating(
                                                          item.id,
                                                      ),
                                                  },
                                                  {
                                                      default: () =>
                                                          h(
                                                              'a',
                                                              {
                                                                  class: 'flex items-center gap-2',
                                                                  href: docxUrlForItem(
                                                                      item,
                                                                  ),
                                                              },
                                                              [
                                                                  h(FileText, {
                                                                      class: 'size-4',
                                                                  }),
                                                                  h(
                                                                      'span',
                                                                      'DOCX',
                                                                  ),
                                                              ],
                                                          ),
                                                  },
                                              )
                                            : h(
                                                  DropdownMenuItem,
                                                  { disabled: true },
                                                  {
                                                      default: () => [
                                                          h(FileText, {
                                                              class: 'size-4',
                                                          }),
                                                          h('span', 'DOCX'),
                                                      ],
                                                  },
                                              ),
                                        item.pdf_available
                                            ? h(
                                                  DropdownMenuItem,
                                                  {
                                                      asChild: true,
                                                      disabled: isItemRegenerating(
                                                          item.id,
                                                      ),
                                                  },
                                                  {
                                                      default: () =>
                                                          h(
                                                              'a',
                                                              {
                                                                  class: 'flex items-center gap-2',
                                                                  href: pdfUrlForItem(
                                                                      item,
                                                                  ),
                                                                  target: '_blank',
                                                                  rel: 'noopener noreferrer',
                                                              },
                                                              [
                                                                  h(Eye, {
                                                                      class: 'size-4',
                                                                  }),
                                                                  h(
                                                                      'span',
                                                                      'Preview PDF',
                                                                  ),
                                                              ],
                                                          ),
                                                  },
                                              )
                                            : h(
                                                  DropdownMenuItem,
                                                  { disabled: true },
                                                  {
                                                      default: () => [
                                                          h(Eye, {
                                                              class: 'size-4',
                                                          }),
                                                          h(
                                                              'span',
                                                              'Preview PDF',
                                                          ),
                                                      ],
                                                  },
                                              ),
                                        props.signatureEnabled
                                            ? h(
                                                  DropdownMenuItem,
                                                  {
                                                      disabled: !item.pdf_available || item.signature_applied || isItemSigning(item.id),
                                                      onSelect: (event: Event) => {
                                                          event.preventDefault();
                                                          void applySignatureToItem(item);
                                                      },
                                                  },
                                                  {
                                                      default: () => [
                                                          h(PenLine, {
                                                              class: 'size-4',
                                                          }),
                                                          h('span', item.signature_applied ? 'Signed' : isItemSigning(item.id) ? 'Signing...' : 'Add Signature'),
                                                      ],
                                                  },
                                              )
                                            : null,
                                        h(
                                            DropdownMenuItem,
                                            {
                                                disabled: isItemRegenerating(item.id),
                                                onSelect: (event: Event) => {
                                                    event.preventDefault();

                                                    if (isItemRegenerating(item.id)) {
                                                        return;
                                                    }

                                                    openDeleteItemDialog(item);
                                                },
                                            },
                                            {
                                                default: () => [
                                                    h('span', { class: 'text-destructive' }, 'Delete row'),
                                                ],
                                            },
                                        ),
                                    ],
                                },
                            ),
                        ],
                    },
                ),
            ]);
        },
    },
]);

onMounted(() => {
    void Promise.all([loadBatchItems(1), loadBatchProgress()]);
});

onBeforeUnmount(() => {
    stopPolling();
    cleanupPrintFrame();

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>Batch #{{ batch.id }} Files</CardTitle>
            <CardDescription>
                {{ batch.source_excel_name }} using {{ batch.template_name }}
            </CardDescription>
        </CardHeader>
        <CardContent class="space-y-4">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
                <div
                    v-for="stat in summaryStats"
                    :key="stat.label"
                    class="rounded-xl border bg-muted/30 p-4"
                >
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ stat.label }}
                    </p>
                    <p class="mt-2 text-2xl font-semibold tracking-tight">
                        {{ stat.value }}
                    </p>
                </div>
            </div>

            <div v-if="props.signatureEnabled" class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-muted-foreground">
                    Select PDF rows to apply signature in bulk.
                </p>
                <Button :disabled="!canBulkSign" @click="applySignatureBulk">
                    {{ bulkSignButtonLabel }}
                </Button>
            </div>

            <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="w-full max-w-[360px]">
                    <Label for="generated-company-search" class="mb-2 block"
                        >Search company or TIN</Label
                    >
                    <Input
                        id="generated-company-search"
                        :model-value="companySearch"
                        placeholder="Type company name or TIN..."
                        @input="onCompanySearchInput"
                    />
                </div>

                <div class="flex w-full flex-wrap justify-start gap-3 lg:w-auto lg:justify-end">
                    <div class="w-full min-w-[180px] sm:w-[220px]">
                        <Label class="mb-2 block">Status</Label>
                        <Select
                            :model-value="itemStatusFilter"
                            @update:model-value="
                                (value) => onItemStatusChange(String(value))
                            "
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="All statuses" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="queued">Queued</SelectItem>
                                <SelectItem value="processing"
                                    >Processing</SelectItem
                                >
                                <SelectItem value="docx_done">Docx Done</SelectItem>
                                <SelectItem value="pdf_done">Pdf Done</SelectItem>
                                <SelectItem value="failed">Failed</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div v-if="props.signatureEnabled" class="w-full min-w-[180px] sm:w-[220px]">
                        <Label class="mb-2 block">Signature</Label>
                        <Select
                            :model-value="itemSignatureFilter"
                            @update:model-value="
                                (value) => onItemSignatureFilterChange(String(value))
                            "
                        >
                            <SelectTrigger>
                                <SelectValue placeholder="All signatures" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="signed">Signed</SelectItem>
                                <SelectItem value="unsigned">Unsigned</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            </div>

            <DataTable
                :columns="itemColumns"
                :data="itemsData.data"
                :meta="itemsData"
                :loading="itemsLoading"
                :sort-by="itemsSortBy"
                :sort-direction="itemsSortDirection"
                empty-message="No batch items yet."
                @page-change="loadBatchItems"
                @per-page-change="
                    async (perPage) => {
                        itemsData.per_page = perPage;
                        await loadBatchItems(1);
                    }
                "
                @sort-change="
                    async (column, direction) => {
                        itemsSortBy = column;
                        itemsSortDirection = direction;
                        await loadBatchItems(1);
                    }
                "
            />
        </CardContent>
    </Card>

    <Dialog v-if="props.signatureEnabled" :open="signDialogOpen" @update:open="(open) => { signDialogOpen = open; }">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>
                    {{ signDialogMode === 'bulk' ? 'Bulk Apply Signature' : 'Apply Signature' }}
                </DialogTitle>
                <DialogDescription>
                    Upload President signature image. Getor default signature will be applied automatically.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2 py-2">
                <Label for="batch-items-president-signature">President Signature Image</Label>
                <Input
                    id="batch-items-president-signature"
                    type="file"
                    accept=".png,.jpg,.jpeg,.webp"
                    @change="onPresidentSignatureFileChange"
                />
            </div>

            <p v-if="signDialogError" class="text-sm text-destructive">
                {{ signDialogError }}
            </p>

            <DialogFooter>
                <Button variant="outline" @click="signDialogOpen = false">Cancel</Button>
                <Button :disabled="signDialogSubmitting" @click="submitSignatureDialog">
                    {{ signDialogMode === 'bulk' ? 'Apply Bulk' : 'Apply' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="errorDetailsDialogOpen"
        @update:open="
            (open) => {
                if (!open) closeErrorDetailsDialog();
            }
        "
    >
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>
                    Error Details for Row {{ selectedErrorItem?.row_number ?? '-' }}
                </DialogTitle>
                <DialogDescription>
                    Review the missing fields and validation issues before editing this row.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 py-2">
                <div>
                    <p class="text-sm font-medium">Summary</p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ selectedErrorItem?.error_message ?? 'No error message recorded.' }}
                    </p>
                </div>

                <div>
                    <p class="text-sm font-medium">Missing Data</p>
                    <p v-if="selectedMissingData.length === 0" class="mt-1 text-sm text-muted-foreground">
                        No missing fields were recorded.
                    </p>
                    <ul v-else class="mt-2 space-y-1 text-sm text-muted-foreground">
                        <li v-for="field in selectedMissingData" :key="field">
                            {{ field }}
                        </li>
                    </ul>
                </div>

                <div>
                    <p class="text-sm font-medium">Validation Errors</p>
                    <p v-if="selectedValidationErrors.length === 0" class="mt-1 text-sm text-muted-foreground">
                        No additional validation errors were recorded.
                    </p>
                    <ul v-else class="mt-2 space-y-1 text-sm text-muted-foreground">
                        <li v-for="message in selectedValidationErrors" :key="message">
                            {{ message }}
                        </li>
                    </ul>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="closeErrorDetailsDialog">Close</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="editDialogOpen"
        @update:open="
            (open) => {
                if (!open) closeEditDialog();
            }
        "
    >
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle
                    >Edit Row {{ editingItem?.row_number ?? '-' }}</DialogTitle
                >
                <DialogDescription>
                    Update the row data and regenerate documents. Old outputs
                    will be deleted first.
                </DialogDescription>
            </DialogHeader>

            <div class="grid max-h-[60vh] gap-4 overflow-y-auto py-2">
                <div
                    v-for="[key] in editFormEntries"
                    :key="key"
                    class="grid gap-2"
                >
                    <Label :for="`edit-${key}`">{{ key }}</Label>
                    <Input
                        :id="`edit-${key}`"
                        v-model="editForm[key]"
                        type="text"
                    />
                    <p
                        v-if="editErrors[`row_data.${key}`]"
                        class="text-sm text-destructive"
                    >
                        {{ editErrors[`row_data.${key}`][0] }}
                    </p>
                </div>
            </div>

            <p v-if="editErrorMessage" class="text-sm text-destructive">
                {{ editErrorMessage }}
            </p>

            <DialogFooter>
                <Button variant="outline" @click="closeEditDialog"
                    >Cancel</Button
                >
                <Button :disabled="editSubmitting" @click="saveEditedItem">
                    <Spinner v-if="editSubmitting" class="size-4" />
                    Save and Regenerate
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="deleteItemDialogOpen"
        @update:open="
            (open) => {
                if (!open) closeDeleteItemDialog();
            }
        "
    >
        <DialogContent>
            <DialogHeader>
                <DialogTitle>
                    Delete Row {{ pendingDeleteItem?.row_number ?? '-' }}?
                </DialogTitle>
                <DialogDescription>
                    This row and its outputs will be hidden from the batch. Stored files will remain on disk for now.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button variant="outline" @click="closeDeleteItemDialog">
                    Cancel
                </Button>
                <Button variant="destructive" :disabled="deletingItem" @click="confirmDeleteItem">
                    <Spinner v-if="deletingItem" class="size-4" />
                    Delete row
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
