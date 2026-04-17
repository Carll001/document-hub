<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { MoreVertical } from 'lucide-vue-next';
import { computed, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { createToast, showToast } from '@/lib/toast';
import documentGeneratorRoutes from '@/routes/document-generator';
import generatedFilesRoutes from '@/routes/generated-files';
import type { BreadcrumbItem } from '@/types';

type SortDirection = 'asc' | 'desc';

type UnifiedItem = {
    id: number;
    batch_id: number;
    row_number: number;
    company: string;
    status: string;
    row_data: Record<string, string>;
    docx_available: boolean;
    pdf_available: boolean;
    signature_applied: boolean;
    signature_applied_at: string | null;
    error_message: string | null;
    source_excel_name: string;
    template_name: string;
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

type SignatureAnchor = 'top_left' | 'top_right' | 'bottom_left' | 'bottom_right' | 'center';

type SignatureLayout = {
    anchor: SignatureAnchor;
    offset_x: number;
    offset_y: number;
    width: number;
    height: number;
};

type SignatureSettings = {
    president: {
        page2: SignatureLayout;
        page3: SignatureLayout;
    };
    getor: {
        page4: SignatureLayout;
        page8: SignatureLayout;
        preview_url: string;
    };
};

const props = defineProps<{
    initialItems: PaginatedResponse<UnifiedItem>;
    initialSignature: {
        signature: SignatureSettings | null;
    };
    signatureEnabled: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Document Generator',
        href: documentGeneratorRoutes.index(),
    },
];

const excelFile = ref<File | null>(null);
const defaultTemplateFile = ref<File | null>(null);
const createErrors = ref<Record<string, string[]>>({});
const createErrorMessage = ref<string | null>(null);
const creatingBatch = ref(false);

const itemsData = ref<PaginatedResponse<UnifiedItem>>(props.initialItems);
const itemsLoading = ref(false);
const itemsSortBy = ref('created_at');
const itemsSortDirection = ref<SortDirection>('desc');
const itemStatusFilter = ref('all');
const itemSignatureFilter = ref('all');
const companySearch = ref('');
const pollingActive = ref(false);

const editDialogOpen = ref(false);
const editSubmitting = ref(false);
const editErrorMessage = ref<string | null>(null);
const editErrors = ref<Record<string, string[]>>({});
const editingItem = ref<UnifiedItem | null>(null);
const editForm = reactive<Record<string, string>>({});
const signatureDialogOpen = ref(false);
const signatureSaving = ref(false);
const signatureDeleting = ref(false);
const signatureErrorMessage = ref<string | null>(null);
const signatureErrors = ref<Record<string, string[]>>({});
const signatureFile = ref<File | null>(null);
const signatureData = ref<SignatureSettings | null>(props.initialSignature.signature);
const signatureForm = reactive({
    president: {
        page2: {
            anchor: (props.initialSignature.signature?.president.page2.anchor ?? 'bottom_right') as SignatureAnchor,
            offset_x: props.initialSignature.signature?.president.page2.offset_x ?? 0,
            offset_y: props.initialSignature.signature?.president.page2.offset_y ?? 0,
            width: props.initialSignature.signature?.president.page2.width ?? 40,
            height: props.initialSignature.signature?.president.page2.height ?? 16,
        },
        page3: {
            anchor: (props.initialSignature.signature?.president.page3.anchor ?? 'bottom_right') as SignatureAnchor,
            offset_x: props.initialSignature.signature?.president.page3.offset_x ?? 0,
            offset_y: props.initialSignature.signature?.president.page3.offset_y ?? 0,
            width: props.initialSignature.signature?.president.page3.width ?? 40,
            height: props.initialSignature.signature?.president.page3.height ?? 16,
        },
    },
    getor: {
        page4: {
            anchor: (props.initialSignature.signature?.getor.page4.anchor ?? 'bottom_right') as SignatureAnchor,
            offset_x: props.initialSignature.signature?.getor.page4.offset_x ?? 0,
            offset_y: props.initialSignature.signature?.getor.page4.offset_y ?? 0,
            width: props.initialSignature.signature?.getor.page4.width ?? 40,
            height: props.initialSignature.signature?.getor.page4.height ?? 16,
        },
        page8: {
            anchor: (props.initialSignature.signature?.getor.page8.anchor ?? 'bottom_right') as SignatureAnchor,
            offset_x: props.initialSignature.signature?.getor.page8.offset_x ?? 0,
            offset_y: props.initialSignature.signature?.getor.page8.offset_y ?? 0,
            width: props.initialSignature.signature?.getor.page8.width ?? 40,
            height: props.initialSignature.signature?.getor.page8.height ?? 16,
        },
    },
});
const signingItemIds = ref<number[]>([]);
const signDialogOpen = ref(false);
const signDialogSubmitting = ref(false);
const signDialogError = ref<string | null>(null);
const signDialogTarget = ref<UnifiedItem | null>(null);
const presidentSignatureFile = ref<File | null>(null);

let pollInterval: ReturnType<typeof setInterval> | null = null;
let companySearchDebounce: ReturnType<typeof setTimeout> | null = null;

const showNotice = (type: 'success' | 'error', title: string, message: string) => {
    showToast(createToast(type, title, message));
};

const csrfToken = () => {
    const xsrfCookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!xsrfCookie) {
        return '';
    }

    return decodeURIComponent(xsrfCookie.split('=')[1] ?? '');
};

const statusBadgeVariant = (status: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
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

const sendJson = async <T>(url: string, method: 'PUT', payload: unknown): Promise<T> => {
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
        const validationError = new Error(errorPayload.message ?? 'Validation failed.');
        Object.assign(validationError, { validationErrors: errorPayload.errors ?? {} });
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

const sendPostJson = async <T>(url: string, payload: unknown): Promise<T> => {
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
        const validationError = new Error(errorPayload.message ?? 'Validation failed.');
        Object.assign(validationError, { validationErrors: errorPayload.errors ?? {} });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const sendPostFormData = async <T>(url: string, payload: FormData): Promise<T> => {
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
        const validationError = new Error(errorPayload.message ?? 'Validation failed.');
        Object.assign(validationError, { validationErrors: errorPayload.errors ?? {} });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const buildItemsUrl = (page = itemsData.value.current_page) => {
    const query: Record<string, string> = {
        page: String(page),
        per_page: String(itemsData.value.per_page),
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

    const params = new URLSearchParams(query);

    return `/document-generator/items?${params.toString()}`;
};

const hasPendingVisibleItems = computed(() =>
    itemsData.value.data.some((item) => ['queued', 'processing'].includes(item.status)),
);

const stopPolling = () => {
    pollingActive.value = false;

    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
};

const loadItems = async (page = itemsData.value.current_page) => {
    itemsLoading.value = true;

    try {
        itemsData.value = await getApi<PaginatedResponse<UnifiedItem>>(buildItemsUrl(page));

        if (pollingActive.value && !hasPendingVisibleItems.value) {
            stopPolling();
        }
    } finally {
        itemsLoading.value = false;
    }
};

const startPolling = () => {
    stopPolling();

    if (!hasPendingVisibleItems.value) {
        return;
    }

    pollingActive.value = true;

    pollInterval = setInterval(async () => {
        try {
            await loadItems();
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

        const response = await fetch(documentGeneratorRoutes.batches.store.url(), {
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
            throw new Error(`Failed to create batch (${response.status}).`);
        }

        await loadItems(1);
        startPolling();
        showNotice('success', 'Batch started', 'Document generation has started for the uploaded file.');
    } catch (error) {
        showNotice(
            'error',
            'Batch was not started',
            error instanceof Error ? error.message : 'Unable to create batch.',
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

const onSignatureFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    signatureFile.value = input.files?.[0] ?? null;
};

const onPresidentSignatureFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    presidentSignatureFile.value = input.files?.[0] ?? null;
    signDialogError.value = null;
};

const onItemStatusChange = async (value: string) => {
    itemStatusFilter.value = value;
    await loadItems(1);
    startPolling();
};

const onItemSignatureFilterChange = async (value: string) => {
    itemSignatureFilter.value = value;
    await loadItems(1);
    startPolling();
};

const onCompanySearchInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    companySearch.value = target.value;

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }

    companySearchDebounce = setTimeout(async () => {
        await loadItems(1);
        startPolling();
    }, 300);
};

const canEditItem = (item: UnifiedItem) => !['queued', 'processing'].includes(item.status);
const editFormEntries = computed(() => Object.entries(editForm));

const resetEditForm = () => {
    for (const key of Object.keys(editForm)) {
        delete editForm[key];
    }
};

const openEditDialog = (item: UnifiedItem) => {
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

const isItemSigning = (itemId: number) => signingItemIds.value.includes(itemId);

const applySignatureToItem = async (item: UnifiedItem) => {
    if (!props.signatureEnabled) {
        return;
    }

    if (!item.pdf_available || item.signature_applied || isItemSigning(item.id)) {
        return;
    }

    signDialogTarget.value = item;
    presidentSignatureFile.value = null;
    signDialogError.value = null;
    signDialogOpen.value = true;
};

const submitSignatureForItem = async () => {
    if (!props.signatureEnabled) {
        return;
    }

    const item = signDialogTarget.value;
    if (!item) {
        return;
    }

    if (!presidentSignatureFile.value) {
        signDialogError.value = 'President signature image is required.';
        return;
    }

    signDialogSubmitting.value = true;
    signingItemIds.value = [...signingItemIds.value, item.id];
    signDialogError.value = null;

    try {
        const formData = new FormData();
        formData.append('president_signature_file', presidentSignatureFile.value);

        const payload = await sendPostFormData<{
            message: string;
            item: Record<string, unknown>;
            pdf_url: string;
        }>(
            documentGeneratorRoutes.batches.items.signature.url({
                batch: item.batch_id,
                item: item.id,
            }),
            formData,
        );

        await loadItems(itemsData.value.current_page);
        showNotice('success', 'Signature applied', `Row ${item.row_number} was signed.`);
        signDialogOpen.value = false;
        signDialogTarget.value = null;
        presidentSignatureFile.value = null;

        if (payload.pdf_url) {
            window.open(payload.pdf_url, '_blank', 'noopener,noreferrer');
        }
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Unable to apply signature.';
        signDialogError.value = message;
        showNotice('error', 'Signature was not applied', message);
    } finally {
        signDialogSubmitting.value = false;
        signingItemIds.value = signingItemIds.value.filter((id) => id !== item.id);
    }
};

const saveEditedItem = async () => {
    if (!editingItem.value) {
        return;
    }

    editSubmitting.value = true;
    editErrorMessage.value = null;
    editErrors.value = {};

    try {
        await sendJson<UnifiedItem>(
            documentGeneratorRoutes.batches.items.update.url({
                batch: editingItem.value.batch_id,
                item: editingItem.value.id,
            }),
            'PUT',
            {
                row_data: editForm,
            },
        );

        await loadItems(itemsData.value.current_page);
        startPolling();
        closeEditDialog();
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            editErrors.value = (error as Error & { validationErrors?: Record<string, string[]> }).validationErrors ?? {};
        }

        editErrorMessage.value = error instanceof Error ? error.message : 'Unable to update row.';
    } finally {
        editSubmitting.value = false;
    }
};

const openSignatureDialog = () => {
    if (!props.signatureEnabled) {
        return;
    }

    signatureDialogOpen.value = true;
    signatureErrorMessage.value = null;
    signatureErrors.value = {};
    signatureFile.value = null;
};

const saveSignature = async () => {
    if (!props.signatureEnabled) {
        return;
    }

    signatureSaving.value = true;
    signatureErrorMessage.value = null;
    signatureErrors.value = {};

    try {
        const formData = new FormData();
        formData.append('page2_anchor', signatureForm.president.page2.anchor);
        formData.append('page2_offset_x', String(signatureForm.president.page2.offset_x));
        formData.append('page2_offset_y', String(signatureForm.president.page2.offset_y));
        formData.append('page2_width', String(signatureForm.president.page2.width));
        formData.append('page2_height', String(signatureForm.president.page2.height));
        formData.append('page3_anchor', signatureForm.president.page3.anchor);
        formData.append('page3_offset_x', String(signatureForm.president.page3.offset_x));
        formData.append('page3_offset_y', String(signatureForm.president.page3.offset_y));
        formData.append('page3_width', String(signatureForm.president.page3.width));
        formData.append('page3_height', String(signatureForm.president.page3.height));
        formData.append('page4_anchor', signatureForm.getor.page4.anchor);
        formData.append('page4_offset_x', String(signatureForm.getor.page4.offset_x));
        formData.append('page4_offset_y', String(signatureForm.getor.page4.offset_y));
        formData.append('page4_width', String(signatureForm.getor.page4.width));
        formData.append('page4_height', String(signatureForm.getor.page4.height));
        formData.append('page8_anchor', signatureForm.getor.page8.anchor);
        formData.append('page8_offset_x', String(signatureForm.getor.page8.offset_x));
        formData.append('page8_offset_y', String(signatureForm.getor.page8.offset_y));
        formData.append('page8_width', String(signatureForm.getor.page8.width));
        formData.append('page8_height', String(signatureForm.getor.page8.height));
        if (signatureFile.value) {
            formData.append('signature_file', signatureFile.value);
        }

        const response = await fetch('/document-generator/signature', {
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
            signatureErrors.value = payload.errors ?? {};
            signatureErrorMessage.value = payload.message ?? 'Validation failed.';
            return;
        }

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        const payload = (await response.json()) as {
            signature: SignatureSettings | null;
        };
        signatureData.value = payload.signature;
        if (payload.signature) {
            signatureForm.president.page2.anchor = payload.signature.president.page2.anchor;
            signatureForm.president.page2.offset_x = payload.signature.president.page2.offset_x;
            signatureForm.president.page2.offset_y = payload.signature.president.page2.offset_y;
            signatureForm.president.page2.width = payload.signature.president.page2.width;
            signatureForm.president.page2.height = payload.signature.president.page2.height;
            signatureForm.president.page3.anchor = payload.signature.president.page3.anchor;
            signatureForm.president.page3.offset_x = payload.signature.president.page3.offset_x;
            signatureForm.president.page3.offset_y = payload.signature.president.page3.offset_y;
            signatureForm.president.page3.width = payload.signature.president.page3.width;
            signatureForm.president.page3.height = payload.signature.president.page3.height;
            signatureForm.getor.page4.anchor = payload.signature.getor.page4.anchor;
            signatureForm.getor.page4.offset_x = payload.signature.getor.page4.offset_x;
            signatureForm.getor.page4.offset_y = payload.signature.getor.page4.offset_y;
            signatureForm.getor.page4.width = payload.signature.getor.page4.width;
            signatureForm.getor.page4.height = payload.signature.getor.page4.height;
            signatureForm.getor.page8.anchor = payload.signature.getor.page8.anchor;
            signatureForm.getor.page8.offset_x = payload.signature.getor.page8.offset_x;
            signatureForm.getor.page8.offset_y = payload.signature.getor.page8.offset_y;
            signatureForm.getor.page8.width = payload.signature.getor.page8.width;
            signatureForm.getor.page8.height = payload.signature.getor.page8.height;
        }
        signatureFile.value = null;
        showNotice('success', 'Signature saved', 'You can now apply it manually from each file row.');
    } catch (error) {
        signatureErrorMessage.value = error instanceof Error ? error.message : 'Unable to save signature settings.';
    } finally {
        signatureSaving.value = false;
    }
};

const removeSignature = async () => {
    if (!props.signatureEnabled) {
        return;
    }

    signatureDeleting.value = true;
    signatureErrorMessage.value = null;
    signatureErrors.value = {};

    try {
        await sendDelete('/document-generator/signature');
        signatureData.value = null;
        signatureFile.value = null;
        showNotice('success', 'Signature removed', 'Manual sign actions will require uploading a signature again.');
    } catch (error) {
        signatureErrorMessage.value = error instanceof Error ? error.message : 'Unable to remove signature.';
    } finally {
        signatureDeleting.value = false;
    }
};

const itemColumns = computed<ColumnDef<UnifiedItem>[]>(() => [
    {
        id: 'batch_id',
        accessorKey: 'batch_id',
        header: 'Batch',
        enableSorting: false,
        cell: ({ row }) => `#${row.original.batch_id}`,
    },
    {
        id: 'source_excel_name',
        accessorKey: 'source_excel_name',
        header: 'Excel',
        enableSorting: false,
        cell: ({ row }) => row.original.source_excel_name || '-',
    },
    {
        id: 'template_name',
        accessorKey: 'template_name',
        header: 'Template',
        enableSorting: false,
        cell: ({ row }) => row.original.template_name || '-',
    },
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
        id: 'status',
        accessorKey: 'status',
        header: 'Status',
        enableSorting: true,
        cell: ({ row }) =>
            h(
                'div',
                { class: 'flex items-center gap-2' },
                [
                    h(
                        Badge,
                        {
                            variant: statusBadgeVariant(row.original.status),
                        },
                        () => row.original.status,
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
        cell: ({ row }) =>
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
                                            variant: 'outline',
                                            size: 'icon',
                                            class: 'size-8',
                                            'aria-label': 'Row actions',
                                        },
                                        {
                                            default: () =>
                                                h(MoreVertical, {
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
                                            disabled: !canEditItem(row.original),
                                            onSelect: (event: Event) => {
                                                event.preventDefault();
                                                if (!canEditItem(row.original)) {
                                                    return;
                                                }
                                                openEditDialog(row.original);
                                            },
                                        },
                                        {
                                            default: () => 'Edit',
                                        },
                                    ),
                                    row.original.docx_available
                                        ? h(
                                              DropdownMenuItem,
                                              { asChild: true },
                                              {
                                                  default: () =>
                                                      h(
                                                          'a',
                                                          {
                                                              href: documentGeneratorRoutes.batches.items.download.url({
                                                                  batch: row.original.batch_id,
                                                                  item: row.original.id,
                                                                  type: 'docx',
                                                              }),
                                                          },
                                                          'DOCX',
                                                      ),
                                              },
                                          )
                                        : h(
                                              DropdownMenuItem,
                                              { disabled: true },
                                              {
                                                  default: () => 'DOCX',
                                              },
                                          ),
                                    row.original.pdf_available
                                        ? h(
                                              DropdownMenuItem,
                                              { asChild: true },
                                              {
                                                  default: () =>
                                                      h(
                                                          'a',
                                                          {
                                                              href: documentGeneratorRoutes.batches.items.download.url({
                                                                  batch: row.original.batch_id,
                                                                  item: row.original.id,
                                                                  type: 'pdf',
                                                              }),
                                                              target: '_blank',
                                                              rel: 'noopener noreferrer',
                                                          },
                                                          'Preview PDF',
                                                      ),
                                              },
                                          )
                                        : h(
                                              DropdownMenuItem,
                                              { disabled: true },
                                              {
                                                  default: () => 'Preview PDF',
                                              },
                                          ),
                                    props.signatureEnabled
                                        ? h(
                                              DropdownMenuItem,
                                              {
                                                  disabled: !row.original.pdf_available || row.original.signature_applied || isItemSigning(row.original.id),
                                                  onSelect: (event: Event) => {
                                                      event.preventDefault();
                                                      void applySignatureToItem(row.original);
                                                  },
                                              },
                                              {
                                                  default: () => (row.original.signature_applied ? 'Signed' : isItemSigning(row.original.id) ? 'Signing...' : 'Add Signature'),
                                              },
                                          )
                                        : null,
                                ],
                            },
                        ),
                    ],
                },
            ),
    },
]);

onBeforeUnmount(() => {
    stopPolling();

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }
});

onMounted(() => {
    startPolling();
});
</script>

<template>
    <Head title="Document Generator" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Card>
                <CardHeader>
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle>Bulk Document Generator</CardTitle>
                            <CardDescription>
                                Upload one Excel source, one default DOCX template, and optional year-threshold templates.
                                Each year rule applies from its year onward until the next higher rule takes over.
                            </CardDescription>
                        </div>

                        <div class="flex items-center gap-2">
                            <Button variant="outline" as-child>
                                <a href="/document-generator/template-mapping">Template Mapping</a>
                            </Button>
                            <Button v-if="props.signatureEnabled" variant="outline" @click="openSignatureDialog">Signature Settings</Button>
                            <Button variant="outline" as-child>
                                <a :href="generatedFilesRoutes.index().url">Generated Files</a>
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="postBatch">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="grid gap-2">
                                <Label for="excel">Excel File</Label>
                                <Input id="excel" type="file" accept=".xls,.xlsx" @change="onExcelFileChange" />
                                <p v-if="createErrors.excel_file" class="text-sm text-destructive">
                                    {{ createErrors.excel_file[0] }}
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label for="template">Default DOCX Template</Label>
                                <Input id="template" type="file" accept=".docx" @change="onTemplateFileChange" />
                                <p v-if="createErrors.default_template_file" class="text-sm text-destructive">
                                    {{ createErrors.default_template_file[0] }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Optional if a global default is already set in Template Mapping.
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
                        </div>

                        <Button type="submit" :disabled="creatingBatch">
                            <Spinner v-if="creatingBatch" class="size-4" />
                            Start Batch
                        </Button>
                    </form>
                    <p v-if="createErrorMessage" class="mt-3 text-sm text-destructive">
                        {{ createErrorMessage }}
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        Generated Rows
                        <Spinner v-if="pollingActive" class="size-4" />
                    </CardTitle>
                    <CardDescription>
                        One table across all batches with row status, editing, and file downloads.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                        <div class="w-full max-w-[360px]">
                            <Label for="company-search" class="mb-2 block">Search company</Label>
                            <Input
                                id="company-search"
                                :model-value="companySearch"
                                placeholder="Type company name..."
                                @input="onCompanySearchInput"
                            />
                        </div>

                        <div class="flex w-full justify-start gap-3 lg:w-auto lg:justify-end">
                            <div class="w-full ">
                                <Label class="mb-2 block">Status</Label>
                                <Select :model-value="itemStatusFilter" @update:model-value="(value) => onItemStatusChange(String(value))">
                                    <SelectTrigger>
                                        <SelectValue placeholder="All statuses" class="w-20"/>
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All</SelectItem>
                                        <SelectItem value="queued">Queued</SelectItem>
                                        <SelectItem value="processing">Processing</SelectItem>
                                        <SelectItem value="docx_done">Docx Done</SelectItem>
                                        <SelectItem value="pdf_done">Pdf Done</SelectItem>
                                        <SelectItem value="failed">Failed</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div v-if="props.signatureEnabled" class="w-full ">
                                <Label class="mb-2 block">Signature</Label>
                                <Select :model-value="itemSignatureFilter" @update:model-value="(value) => onItemSignatureFilterChange(String(value))">
                                    <SelectTrigger>
                                        <SelectValue placeholder="All signatures" class="w-20"/>
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
                        empty-message="No rows available."
                        @page-change="async (page) => { await loadItems(page); startPolling(); }"
                        @per-page-change="async (perPage) => { itemsData.per_page = perPage; await loadItems(1); startPolling(); }"
                        @sort-change="async (column, direction) => { itemsSortBy = column; itemsSortDirection = direction; await loadItems(1); startPolling(); }"
                    />
                </CardContent>
            </Card>

            <Dialog :open="editDialogOpen" @update:open="(open) => { if (!open) closeEditDialog(); }">
                <DialogContent class="sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Edit Row {{ editingItem?.row_number ?? '-' }} (Batch #{{ editingItem?.batch_id ?? '-' }})</DialogTitle>
                        <DialogDescription>
                            Update the row data and regenerate documents. Old outputs will be deleted first.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid max-h-[60vh] gap-4 overflow-y-auto py-2">
                        <div v-for="[key] in editFormEntries" :key="key" class="grid gap-2">
                            <Label :for="`edit-${key}`">{{ key }}</Label>
                            <Input :id="`edit-${key}`" v-model="editForm[key]" type="text" />
                            <p v-if="editErrors[`row_data.${key}`]" class="text-sm text-destructive">
                                {{ editErrors[`row_data.${key}`][0] }}
                            </p>
                        </div>
                    </div>

                    <p v-if="editErrorMessage" class="text-sm text-destructive">
                        {{ editErrorMessage }}
                    </p>

                    <DialogFooter>
                        <Button variant="outline" @click="closeEditDialog">Cancel</Button>
                        <Button :disabled="editSubmitting" @click="saveEditedItem">
                            <Spinner v-if="editSubmitting" class="size-4" />
                            Save and Regenerate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog v-if="props.signatureEnabled" :open="signatureDialogOpen" @update:open="(open) => { signatureDialogOpen = open; }">
                <DialogContent class="sm:max-w-xl max-h-[85vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>Signature Settings</DialogTitle>
                        <DialogDescription>
                            Upload the default Getor signature image and configure layouts for President (pages 2/3) and Getor (pages 4/8).
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 py-2">
                        <div class="grid gap-2">
                            <Label for="signature-file">Signature Image</Label>
                            <Input id="signature-file" type="file" accept=".png,.jpg,.jpeg,.webp" @change="onSignatureFileChange" />
                            <p v-if="signatureErrors.signature_file" class="text-sm text-destructive">
                                {{ signatureErrors.signature_file[0] }}
                            </p>
                        </div>

                        <div v-if="signatureData?.getor.preview_url" class="grid gap-2">
                            <Label>Current Signature Preview</Label>
                            <div class="rounded-md border bg-muted p-3">
                                <img :src="signatureData.getor.preview_url" alt="Signature preview" class="max-h-24 object-contain" />
                            </div>
                        </div>

                        <div class="grid gap-4 rounded-md border p-3">
                            <h4 class="text-sm font-semibold">President Signature: Page 2</h4>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label>Anchor</Label>
                                    <Select :model-value="signatureForm.president.page2.anchor" @update:model-value="(value) => signatureForm.president.page2.anchor = String(value) as SignatureAnchor">
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="top_left">Top Left</SelectItem>
                                            <SelectItem value="top_right">Top Right</SelectItem>
                                            <SelectItem value="bottom_left">Bottom Left</SelectItem>
                                            <SelectItem value="bottom_right">Bottom Right</SelectItem>
                                            <SelectItem value="center">Center</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page2-width">Width (mm)</Label>
                                    <Input id="sig-page2-width" v-model.number="signatureForm.president.page2.width" type="number" min="1" max="300" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page2-height">Height (mm)</Label>
                                    <Input id="sig-page2-height" v-model.number="signatureForm.president.page2.height" type="number" min="1" max="300" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page2-offset-x">Offset X (mm)</Label>
                                    <Input id="sig-page2-offset-x" v-model.number="signatureForm.president.page2.offset_x" type="number" min="-500" max="500" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page2-offset-y">Offset Y (mm)</Label>
                                    <Input id="sig-page2-offset-y" v-model.number="signatureForm.president.page2.offset_y" type="number" min="-500" max="500" step="0.1" />
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 rounded-md border p-3">
                            <h4 class="text-sm font-semibold">President Signature: Page 3</h4>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label>Anchor</Label>
                                    <Select :model-value="signatureForm.president.page3.anchor" @update:model-value="(value) => signatureForm.president.page3.anchor = String(value) as SignatureAnchor">
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="top_left">Top Left</SelectItem>
                                            <SelectItem value="top_right">Top Right</SelectItem>
                                            <SelectItem value="bottom_left">Bottom Left</SelectItem>
                                            <SelectItem value="bottom_right">Bottom Right</SelectItem>
                                            <SelectItem value="center">Center</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page3-width">Width (mm)</Label>
                                    <Input id="sig-page3-width" v-model.number="signatureForm.president.page3.width" type="number" min="1" max="300" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page3-height">Height (mm)</Label>
                                    <Input id="sig-page3-height" v-model.number="signatureForm.president.page3.height" type="number" min="1" max="300" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page3-offset-x">Offset X (mm)</Label>
                                    <Input id="sig-page3-offset-x" v-model.number="signatureForm.president.page3.offset_x" type="number" min="-500" max="500" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page3-offset-y">Offset Y (mm)</Label>
                                    <Input id="sig-page3-offset-y" v-model.number="signatureForm.president.page3.offset_y" type="number" min="-500" max="500" step="0.1" />
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 rounded-md border p-3">
                            <h4 class="text-sm font-semibold">Getor Signature: Page 4</h4>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label>Anchor</Label>
                                    <Select :model-value="signatureForm.getor.page4.anchor" @update:model-value="(value) => signatureForm.getor.page4.anchor = String(value) as SignatureAnchor">
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="top_left">Top Left</SelectItem>
                                            <SelectItem value="top_right">Top Right</SelectItem>
                                            <SelectItem value="bottom_left">Bottom Left</SelectItem>
                                            <SelectItem value="bottom_right">Bottom Right</SelectItem>
                                            <SelectItem value="center">Center</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page4-width">Width (mm)</Label>
                                    <Input id="sig-page4-width" v-model.number="signatureForm.getor.page4.width" type="number" min="1" max="300" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page4-height">Height (mm)</Label>
                                    <Input id="sig-page4-height" v-model.number="signatureForm.getor.page4.height" type="number" min="1" max="300" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page4-offset-x">Offset X (mm)</Label>
                                    <Input id="sig-page4-offset-x" v-model.number="signatureForm.getor.page4.offset_x" type="number" min="-500" max="500" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page4-offset-y">Offset Y (mm)</Label>
                                    <Input id="sig-page4-offset-y" v-model.number="signatureForm.getor.page4.offset_y" type="number" min="-500" max="500" step="0.1" />
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 rounded-md border p-3">
                            <h4 class="text-sm font-semibold">Getor Signature: Page 8</h4>
                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label>Anchor</Label>
                                    <Select :model-value="signatureForm.getor.page8.anchor" @update:model-value="(value) => signatureForm.getor.page8.anchor = String(value) as SignatureAnchor">
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="top_left">Top Left</SelectItem>
                                            <SelectItem value="top_right">Top Right</SelectItem>
                                            <SelectItem value="bottom_left">Bottom Left</SelectItem>
                                            <SelectItem value="bottom_right">Bottom Right</SelectItem>
                                            <SelectItem value="center">Center</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page8-width">Width (mm)</Label>
                                    <Input id="sig-page8-width" v-model.number="signatureForm.getor.page8.width" type="number" min="1" max="300" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page8-height">Height (mm)</Label>
                                    <Input id="sig-page8-height" v-model.number="signatureForm.getor.page8.height" type="number" min="1" max="300" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page8-offset-x">Offset X (mm)</Label>
                                    <Input id="sig-page8-offset-x" v-model.number="signatureForm.getor.page8.offset_x" type="number" min="-500" max="500" step="0.1" />
                                </div>
                                <div class="grid gap-2">
                                    <Label for="sig-page8-offset-y">Offset Y (mm)</Label>
                                    <Input id="sig-page8-offset-y" v-model.number="signatureForm.getor.page8.offset_y" type="number" min="-500" max="500" step="0.1" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <p v-if="signatureErrorMessage" class="text-sm text-destructive">
                        {{ signatureErrorMessage }}
                    </p>

                    <DialogFooter>
                        <Button v-if="signatureData" variant="destructive" :disabled="signatureDeleting" @click="removeSignature">
                            <Spinner v-if="signatureDeleting" class="size-4" />
                            Remove Signature
                        </Button>
                        <Button :disabled="signatureSaving" @click="saveSignature">
                            <Spinner v-if="signatureSaving" class="size-4" />
                            Save Signature
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog v-if="props.signatureEnabled" :open="signDialogOpen" @update:open="(open) => { signDialogOpen = open; }">
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Apply Signature</DialogTitle>
                        <DialogDescription>
                            Upload President signature image for this signing action. Getor default signature will be applied automatically.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2 py-2">
                        <Label for="president-signature-file">President Signature Image</Label>
                        <Input
                            id="president-signature-file"
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
                        <Button :disabled="signDialogSubmitting" @click="submitSignatureForItem">
                            <Spinner v-if="signDialogSubmitting" class="size-4" />
                            Apply
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
