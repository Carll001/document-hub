<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { MoreHorizontal, Search, Settings2, Trash2, Upload } from 'lucide-vue-next';
import { computed, h, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AfsEditDialog from '@/components/afs-components/AfsEditDialog.vue';
import AfsSignatureSettingsDialog from '@/components/afs-components/AfsSignatureSettingsDialog.vue';
import AfsSignDialog from '@/components/afs-components/AfsSignDialog.vue';
import type { PaginatedResponse, SignatureSettings, UnifiedItem } from '@/components/afs-components/types';
import {
    csrfToken,
    getApi,
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
import documentGeneratorRoutes from '@/routes/document-generator';
import generatedFilesRoutes from '@/routes/generated-files';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    initialItems: PaginatedResponse<UnifiedItem>;
    initialSignature: {
        signature: SignatureSettings | null;
    };
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
const itemsSortBy = ref('created_at');
const itemsSortDirection = ref<'asc' | 'desc'>('desc');
const itemStatusFilter = ref('all');
const itemSignatureFilter = ref('all');
const companySearch = ref('');
const selectedItemIds = ref<number[]>([]);
const pollingActive = ref(false);
const deletingItems = ref(false);

const editDialogOpen = ref(false);
const editingItem = ref<UnifiedItem | null>(null);
const signatureDialogOpen = ref(false);
const signingItemIds = ref<number[]>([]);
const signDialogOpen = ref(false);
const signDialogTarget = ref<UnifiedItem | null>(null);
const editDialogRef = ref<InstanceType<typeof AfsEditDialog> | null>(null);

let pollInterval: ReturnType<typeof setInterval> | null = null;
let companySearchDebounce: ReturnType<typeof setTimeout> | null = null;

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
const canBulkDelete = computed(
    () => selectedItemIds.value.length > 0 && !deletingItems.value,
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
        isUploadDialogOpen.value = false;
        excelFile.value = null;
        defaultTemplateFile.value = null;
        createErrors.value = {};
        createErrorMessage.value = null;
        toast.success('Document generation has started for the uploaded file.');
    } catch (error) {
        toast.error(
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
const canDeleteItem = (item: UnifiedItem) => !['queued', 'processing'].includes(item.status);

const extractTin = (item: UnifiedItem): string => {
    if (typeof item.tin === 'string' && item.tin.trim() !== '') {
        return item.tin;
    }

    return resolveTin(item.row_data, 'afs') ?? '-';
};

const toggleItemSelection = (item: UnifiedItem, checked: boolean | 'indeterminate') => {
    if (checked === 'indeterminate') {
        return;
    }

    if (checked) {
        if (!selectedItemIds.value.includes(item.id)) {
            selectedItemIds.value = [...selectedItemIds.value, item.id];
        }
        return;
    }

    selectedItemIds.value = selectedItemIds.value.filter((itemId) => itemId !== item.id);
};

const toggleAllVisibleRows = (checked: boolean | 'indeterminate') => {
    if (checked === 'indeterminate') {
        return;
    }

    if (checked) {
        selectedItemIds.value = Array.from(
            new Set([...selectedItemIds.value, ...itemsData.value.data.map((item) => item.id)]),
        );
        return;
    }

    const visibleIds = new Set(itemsData.value.data.map((item) => item.id));
    selectedItemIds.value = selectedItemIds.value.filter((itemId) => !visibleIds.has(itemId));
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
                documentGeneratorRoutes.batches.items.destroy.url({
                    batch: item.batch_id,
                    item: item.id,
                }),
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

        selectedItemIds.value = selectedItemIds.value.filter((itemId) => !uniqueItemIds.includes(itemId));

        await loadItems(1);
        startPolling();

        if (failedCount === 0) {
            toast.success(
                successCount === 1 ? 'Row deleted.' : `${successCount} rows deleted.`,
            );
            return;
        }

        toast.error(
            successCount > 0
                ? `${successCount} rows deleted, ${failedCount} failed.`
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

const openEditDialog = (item: UnifiedItem) => {
    editingItem.value = item;
    editDialogOpen.value = true;
    editDialogRef.value?.initForm(item);
};

const onEditSaved = async () => {
    await loadItems(itemsData.value.current_page);
    startPolling();
};

const isItemSigning = (itemId: number) => signingItemIds.value.includes(itemId);

const applySignatureToItem = (item: UnifiedItem) => {
    if (!props.signatureEnabled || !item.pdf_available || item.signature_applied || isItemSigning(item.id)) {
        return;
    }

    signDialogTarget.value = item;
    signDialogOpen.value = true;
};

const onSignatureSaved = () => {
    toast.success('Signature settings saved.');
};

const onSignatureRemoved = () => {
    toast.success('Signature removed.');
};

const onItemSigned = async (pdfUrl?: string) => {
    const item = signDialogTarget.value;
    await loadItems(itemsData.value.current_page);
    toast.success(item ? `Row ${item.row_number} was signed.` : 'Signature applied.');
    signDialogTarget.value = null;

    if (pdfUrl) {
        window.open(pdfUrl, '_blank', 'noopener,noreferrer');
    }
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
                'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
                    toggleItemSelection(row.original, value),
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
        id: 'batch_id',
        accessorKey: 'batch_id',
        header: 'Batch',
        enableSorting: false,
        cell: ({ row }) => `#${row.original.batch_id}`,
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
                                    h(
                                        DropdownMenuItem,
                                        {
                                            disabled: !canDeleteItem(row.original) || deletingItems.value,
                                            variant: 'destructive',
                                            onSelect: async (event: Event) => {
                                                event.preventDefault();
                                                await deleteItems([row.original.id]);
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
    () => itemsData.value.data.map((item) => item.id),
    (visibleItemIds) => {
        const availableItemIds = new Set(visibleItemIds);
        selectedItemIds.value = selectedItemIds.value.filter((itemId) =>
            availableItemIds.has(itemId),
        );
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

        <AfsSignatureSettingsDialog
            v-if="props.signatureEnabled"
            v-model:open="signatureDialogOpen"
            :initial-signature="props.initialSignature.signature"
            @saved="onSignatureSaved"
            @removed="onSignatureRemoved"
        />

        <AfsSignDialog
            v-if="props.signatureEnabled"
            v-model:open="signDialogOpen"
            :target="signDialogTarget"
            @signed="onItemSigned"
        />

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
                            Upload one Excel source, one default DOCX template, and optional year-threshold templates.
                            Each year rule applies from its year onward until the next higher rule takes over.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button variant="secondary" as-child>
                            <a :href="generatedFilesRoutes.index().url">Generated Files</a>
                        </Button>
                        <Button variant="outline" as-child>
                            <a href="/document-generator/template-mapping">Template Mapping</a>
                        </Button>
                        <Button v-if="props.signatureEnabled" variant="outline" @click="signatureDialogOpen = true">
                            <Settings2 class="mr-2 size-4" />
                            Signature Settings
                        </Button>
                        <Button @click="isUploadDialogOpen = true">
                            <Upload class="mr-2 size-4" />
                            Upload Spreadsheet
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
                            <Select :model-value="itemStatusFilter" @update:model-value="(value) => onItemStatusChange(String(value))">
                                <SelectTrigger class="h-9 w-[150px] text-sm">
                                    <SelectValue placeholder="All statuses" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All statuses</SelectItem>
                                    <SelectItem value="queued">Queued</SelectItem>
                                    <SelectItem value="processing">Processing</SelectItem>
                                    <SelectItem value="docx_done">Docx Done</SelectItem>
                                    <SelectItem value="pdf_done">Pdf Done</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                </SelectContent>
                            </Select>

                            <Select
                                v-if="props.signatureEnabled"
                                :model-value="itemSignatureFilter"
                                @update:model-value="(value) => onItemSignatureFilterChange(String(value))"
                            >
                                <SelectTrigger class="h-9 w-[150px] text-sm">
                                    <SelectValue placeholder="All signatures" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All signatures</SelectItem>
                                    <SelectItem value="signed">Signed</SelectItem>
                                    <SelectItem value="unsigned">Unsigned</SelectItem>
                                </SelectContent>
                            </Select>

                            <Button
                                type="button"
                                variant="destructive"
                                size="sm"
                                class="gap-2"
                                :disabled="!canBulkDelete"
                                @click="void deleteItems(selectedItemIds)"
                            >
                                <Trash2 class="size-4" />
                                {{
                                    selectedItemIds.length > 0
                                        ? `Delete selected (${selectedItemIds.length})`
                                        : 'Delete selected'
                                }}
                            </Button>
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
        </div>
    </AppLayout>
</template>
