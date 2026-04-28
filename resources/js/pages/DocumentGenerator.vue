<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { ArrowUpDown, Download, Eye, FileText, MoreHorizontal, Pencil, PenLine, Search, Settings2, Trash2, Upload } from 'lucide-vue-next';
import { computed, h, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AfsEditDialog from '@/components/afs-components/AfsEditDialog.vue';
import AfsSettingsDialog from '@/components/afs-components/AfsSettingsDialog.vue';
import AfsSignDialog from '@/components/afs-components/AfsSignDialog.vue';
import type { PaginatedResponse, SignatureSettings, TemplateMappingPayload, UnifiedItem } from '@/components/afs-components/types';
import {
    csrfToken,
    getApi,
    statusBadgeClass,
    statusBadgeVariant,
} from '@/components/afs-components/utils';
import { resolveTin } from '@/lib/form-field-aliases';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
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

const itemsData = ref<PaginatedResponse<UnifiedItem>>(props.initialItems);
const itemsLoading = ref(false);
const itemsSortBy = ref<string>('created_at');
const itemsSortDirection = ref<'asc' | 'desc'>(props.initialFilters.direction ?? 'desc');
const itemStatusFilter = ref(props.initialFilters.status ?? 'all');
const companySearch = ref(props.initialFilters.search ?? '');
const pollingActive = ref(false);
const deletingItems = ref(false);
const selectedItemIds = ref<number[]>([]);

const editDialogOpen = ref(false);
const editingItem = ref<UnifiedItem | null>(null);
const signingItemIds = ref<number[]>([]);
const signDialogOpen = ref(false);
const signDialogTarget = ref<UnifiedItem | null>(null);
const signDialogMode = ref<'single' | 'bulk'>('single');
const signDialogBulkItemIds = ref<number[]>([]);
const settingsDialogOpen = ref(false);
const templateMapping = ref<TemplateMappingPayload>(props.initialMapping);
const editDialogRef = ref<InstanceType<typeof AfsEditDialog> | null>(null);
const deleteConfirmOpen = ref(false);
const deleteConfirmIds = ref<number[]>([]);

let pollInterval: ReturnType<typeof setInterval> | null = null;
let companySearchDebounce: ReturnType<typeof setTimeout> | null = null;

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
const canBulkDeleteSelected = computed(
    () => selectedItemIds.value.length > 0 && !deletingItems.value,
);
const canBulkSignSelected = computed(() => {
    if (!props.signatureEnabled || selectedItemIds.value.length === 0) {
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
        },
    );
};

const hasPendingVisibleItems = computed(() =>
    itemsData.value.data.some((item) => ['queued', 'processing', 'docx_done', 'signing', 'deleting'].includes(item.status)),
);

const stopPolling = () => {
    pollingActive.value = false;

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
        itemsData.value = toUnsignedOnly(payload);

        if (pollingActive.value && !hasPendingVisibleItems.value) {
            stopPolling();
        }
    } finally {
        if (!silent) {
            itemsLoading.value = false;
        }
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

        await loadItems(1);
        startPolling();
        isUploadDialogOpen.value = false;
        excelFile.value = null;
        defaultTemplateFile.value = null;
        createErrors.value = {};
        createErrorMessage.value = null;
        toast.success('Document generation has started for the uploaded file.');
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
const displayStatus = (item: UnifiedItem): string =>
    item.status === 'pdf_done' && !item.signature_applied
        ? 'Generated'
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

        await loadItems(1);
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
    if (!props.signatureEnabled || !item.pdf_available || item.signature_applied || isItemSigning(item.id)) {
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

const onItemSigned = async () => {
    const item = signDialogTarget.value;
    const queuedBulkCount = signDialogBulkItemIds.value.length;
    await loadItems(itemsData.value.current_page, { silent: true });
    startPolling();
    if (signDialogMode.value === 'bulk') {
        toast.success(`${queuedBulkCount} row signatures queued.`);
    } else {
        toast.success(item ? `Row ${item.row_number} signature queued.` : 'Signature queued.');
    }
    signDialogMode.value = 'single';
    signDialogBulkItemIds.value = [];
    signDialogTarget.value = null;
};

const onTemplateMappingUpdated = (nextMapping: TemplateMappingPayload) => {
    templateMapping.value = nextMapping;
};

const itemColumns = computed<ColumnDef<UnifiedItem>[]>(() => [
    {
        id: 'selection',
        header: () =>
            h(Checkbox, {
                modelValue: selectAllState.value,
                disabled: itemsData.value.data.length === 0 || deletingItems.value,
                'aria-label': 'Select visible rows',
                'onUpdate:modelValue': (value: boolean | 'indeterminate') => toggleAllVisibleRows(value),
            }),
        enableSorting: false,
        cell: ({ row }) =>
            h(Checkbox, {
                modelValue: selectedItemIds.value.includes(row.original.id),
                disabled: deletingItems.value,
                'aria-label': `Select row ${row.original.row_number}`,
                'onUpdate:modelValue': (value: boolean | 'indeterminate') => toggleItemSelection(row.original.id, value),
            }),
    },
    {
        id: 'index',
        header: '#',
        enableSorting: false,
        cell: ({ row }) =>
            String(
                (itemsData.value.current_page - 1) * itemsData.value.per_page
                + row.index
                + 1,
            ),
    },
    {
        id: 'tin',
        header: 'TIN',
        enableSorting: false,
        cell: ({ row }) => extractTin(row.original),
    },
    {
        id: 'company',
        accessorKey: 'company',
        header: 'Company',
        enableSorting: false,
        cell: ({ row }) => row.original.company || '-',
    },
    {
        id: 'created_at',
        accessorKey: 'created_at',
        header: () =>
            h('span', { class: 'inline-flex items-center gap-1' }, [
                'Uploaded',
                h(ArrowUpDown, { class: 'size-4 text-muted-foreground' }),
            ]),
        enableSorting: true,
        cell: ({ row }) => formatDateTime(row.original.created_at),
    },
    {
        id: 'status',
        accessorKey: 'status',
        header: () =>
            h('span', { class: 'inline-flex items-center gap-1' }, [
                'Status',
                h(ArrowUpDown, { class: 'size-4 text-muted-foreground' }),
            ]),
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
                            class: statusBadgeClass(row.original.status),
                        },
                        () => displayStatus(row.original),
                    ),
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
                                            variant: 'ghost',
                                            size: 'icon-sm',
                                            'aria-label': 'Row actions',
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
                                            disabled: !canEditItem(row.original),
                                            onSelect: () => {
                                                if (!canEditItem(row.original)) {
                                                    return;
                                                }
                                                openEditDialog(row.original);
                                            },
                                        },
                                        {
                                            default: () => [h(Pencil, { class: 'size-4' }), 'Edit'],
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
                                                              href: documentGeneratorRoutes.items.download.url({ item: row.original.id, type: 'docx' }),
                                                              class: 'flex w-full items-center gap-2',
                                                          },
                                                          [h(FileText, { class: 'size-4' }), 'DOCX'],
                                                      ),
                                              },
                                          )
                                        : h(
                                              DropdownMenuItem,
                                              { disabled: true },
                                              {
                                                  default: () => [h(FileText, { class: 'size-4' }), 'DOCX'],
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
                                                              href: `${documentGeneratorRoutes.items.download.url({ item: row.original.id, type: 'pdf' })}?inline=1`,
                                                              target: '_blank',
                                                              rel: 'noopener noreferrer',
                                                              class: 'flex w-full items-center gap-2',
                                                          },
                                                          [h(Eye, { class: 'size-4' }), 'Preview PDF'],
                                                      ),
                                              },
                                          )
                                        : h(
                                              DropdownMenuItem,
                                              { disabled: true },
                                              {
                                                  default: () => [h(Eye, { class: 'size-4' }), 'Preview PDF'],
                                              },
                                          ),
                                    props.signatureEnabled
                                        ? h(
                                              DropdownMenuItem,
                                              {
                                                  disabled: !row.original.pdf_available || row.original.signature_applied || isItemSigning(row.original.id),
                                                  onSelect: () => {
                                                      void applySignatureToItem(row.original);
                                                  },
                                              },
                                              {
                                                  default: () => [
                                                      h(PenLine, { class: 'size-4' }),
                                                      row.original.signature_applied ? 'Signed' : isItemSigning(row.original.id) ? 'Signing...' : 'Add Signature',
                                                  ],
                                              },
                                          )
                                        : null,
                                    row.original.status === 'failed'
                                        ? h(
                                              DropdownMenuItem,
                                              {
                                                  onSelect: () => {
                                                      void retryItem(row.original);
                                                  },
                                              },
                                              {
                                                  default: () => [
                                                      h(Upload, { class: 'size-4' }),
                                                      'Retry',
                                                  ],
                                              },
                                          )
                                        : null,
                                    h(
                                        DropdownMenuItem,
                                        {
                                            disabled: !canDeleteItem(row.original) || deletingItems.value,
                                            variant: 'destructive',
                                            onSelect: () => {
                                                confirmDelete([row.original.id]);
                                            },
                                        },
                                        {
                                            default: () => [
                                                h(Trash2, { class: 'size-4' }),
                                                'Delete',
                                            ],
                                        },
                                    ),
                                ],
                            },
                        ),
                    ],
                },
            ),
    },
]);

watch(
    () => props.initialItems,
    (nextItems) => {
        const unsignedItems = toUnsignedOnly(nextItems);
        itemsData.value = unsignedItems;
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

onBeforeUnmount(() => {
    stopPolling();

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }
});

onMounted(() => {
    startPolling();
    if (props.openSettings) {
        settingsDialogOpen.value = true;
    }
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
            @signed="onItemSigned"
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

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardContent class="flex flex-col gap-5 p-6 md:flex-row md:items-center md:justify-between md:p-8">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.3em] text-teal-700 uppercase">
                            AFS Workspace
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">
                            Bulk Document Generator
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
                                <Spinner v-if="creatingBatch" class="size-4" />
                                <Upload v-else class="mr-2 size-4" />
                                {{ creatingBatch ? 'Uploading...' : 'Start Batch' }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

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
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div class="relative flex-1">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                id="company-search"
                                :model-value="companySearch"
                                type="search"
                                placeholder="Search company, TIN, or status"
                                class="pl-10"
                                @input="onCompanySearchInput"
                            />
                        </div>

                        <div class="flex flex-wrap gap-2 self-end md:self-auto">
                            <Button
                                type="button"
                                variant="destructive"
                                :disabled="!canBulkDeleteSelected"
                                @click="deleteSelectedItems"
                            >
                                <Trash2 class="mr-2 size-4" />
                                {{ selectedItemIds.length > 0 ? `Delete selected (${selectedItemIds.length})` : 'Delete selected' }}
                            </Button>
                            <Button
                                v-if="props.signatureEnabled"
                                type="button"
                                variant="secondary"
                                :disabled="!canBulkSignSelected"
                                @click="applySignatureToSelectedItems"
                            >
                                <PenLine class="mr-2 size-4" />
                                {{ selectedItemIds.length > 0 ? `Sign selected (${selectedItemIds.length})` : 'Sign selected' }}
                            </Button>
                            <Select :model-value="itemStatusFilter" @update:model-value="(value) => onItemStatusChange(String(value))">
                                <SelectTrigger class="h-9 w-[150px] text-sm">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="queued">Queued</SelectItem>
                                    <SelectItem value="processing">Processing</SelectItem>
                                    <SelectItem value="signing">Signing</SelectItem>
                                    <SelectItem value="deleting">Deleting</SelectItem>
                                    <SelectItem value="docx_done">Docx Done</SelectItem>
                                    <SelectItem value="pdf_done">Generated</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                </SelectContent>
                            </Select>

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
                        @page-change="(page) => { visitIndex({ page }); startPolling(); }"
                        @per-page-change="(perPage) => { itemsData.per_page = perPage; visitIndex({ page: 1, perPage }); startPolling(); }"
                        @sort-change="(column, direction) => { itemsSortBy = column; itemsSortDirection = direction; visitIndex({ page: 1, sortBy: column, direction }); startPolling(); }"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
