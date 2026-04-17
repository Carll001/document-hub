<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { computed, h, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { createToast, showToast } from '@/lib/toast';
import documentGeneratorRoutes from '@/routes/document-generator';
import generatedFilesRoutes from '@/routes/generated-files';
import type { BreadcrumbItem } from '@/types';

type SortDirection = 'asc' | 'desc';

type GeneratedFileItem = {
    id: number;
    batch_id: number;
    row_number: number;
    company: string;
    status: string;
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

const props = defineProps<{
    initialItems: PaginatedResponse<GeneratedFileItem>;
    signatureEnabled: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Generated Files',
        href: '/generated-files',
    },
];

const itemsData = ref<PaginatedResponse<GeneratedFileItem>>(props.initialItems);
const itemsLoading = ref(false);
const itemsSortBy = ref('created_at');
const itemsSortDirection = ref<SortDirection>('desc');
const itemStatusFilter = ref('all');
const itemSignatureFilter = ref('all');
const companySearch = ref('');
const selectedKeys = ref<string[]>([]);
const signingItemIds = ref<number[]>([]);
const signingBulk = ref(false);
const signDialogOpen = ref(false);
const signDialogSubmitting = ref(false);
const signDialogError = ref<string | null>(null);
const signDialogMode = ref<'single' | 'bulk'>('single');
const signDialogTarget = ref<GeneratedFileItem | null>(null);
const presidentSignatureFile = ref<File | null>(null);
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
        throw new Error(errorPayload.message ?? 'Validation failed.');
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
        throw new Error(errorPayload.message ?? 'Validation failed.');
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

const rowKey = (item: GeneratedFileItem) => `${item.batch_id}:${item.id}`;
const isItemSelected = (item: GeneratedFileItem) => selectedKeys.value.includes(rowKey(item));
const visibleSelectableItems = computed(() => itemsData.value.data.filter((item) => item.pdf_available && !item.signature_applied));
const allVisibleSelected = computed(
    () => visibleSelectableItems.value.length > 0 && visibleSelectableItems.value.every((item) => isItemSelected(item)),
);
const isItemSigning = (itemId: number) => signingItemIds.value.includes(itemId);

const toggleItemSelection = (item: GeneratedFileItem, checked: boolean) => {
    if (!props.signatureEnabled) {
        return;
    }

    const key = rowKey(item);
    selectedKeys.value = checked
        ? Array.from(new Set([...selectedKeys.value, key]))
        : selectedKeys.value.filter((value) => value !== key);
};

const toggleAllVisibleSelection = (checked: boolean) => {
    if (!props.signatureEnabled) {
        return;
    }

    if (!checked) {
        selectedKeys.value = selectedKeys.value.filter(
            (value) => !visibleSelectableItems.value.some((item) => rowKey(item) === value),
        );
        return;
    }

    selectedKeys.value = Array.from(
        new Set([...selectedKeys.value, ...visibleSelectableItems.value.map((item) => rowKey(item))]),
    );
};

const selectedTargets = computed(() =>
    itemsData.value.data
        .filter((item) => isItemSelected(item) && item.pdf_available && !item.signature_applied)
        .map((item) => ({ batch_id: item.batch_id, item_id: item.id, row_number: item.row_number })),
);

const canBulkSign = computed(() => selectedTargets.value.length > 0 && !signingBulk.value);
const bulkSignButtonLabel = computed(() => {
    if (signingBulk.value) {
        return 'Applying...';
    }

    const countLabel = selectedTargets.value.length > 0 ? ` (${selectedTargets.value.length})` : '';
    return `Add Signature (Bulk)${countLabel}`;
});

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

const buildItemsUrl = (page = itemsData.value.current_page) => {
    const query: Record<string, string> = {
        page: String(page),
        per_page: String(itemsData.value.per_page),
        sort_by: itemsSortBy.value,
        sort_direction: itemsSortDirection.value,
        files_only: '1',
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
        itemsData.value = await getApi<PaginatedResponse<GeneratedFileItem>>(buildItemsUrl(page));
        selectedKeys.value = selectedKeys.value.filter((value) =>
            itemsData.value.data.some((item) => rowKey(item) === value),
        );
    } finally {
        itemsLoading.value = false;
    }
};

const applySignatureSingle = async (item: GeneratedFileItem) => {
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
                item: GeneratedFileItem;
                pdf_url: string;
            }>(
                documentGeneratorRoutes.batches.items.signature.url({
                    batch: item.batch_id,
                    item: item.id,
                }),
                formData,
            );

            showNotice('success', 'Signature applied', `Row ${item.row_number} was signed.`);
            await loadItems(itemsData.value.current_page);
            signDialogOpen.value = false;

            if (payload.pdf_url) {
                window.open(payload.pdf_url, '_blank', 'noopener,noreferrer');
            }
        }

        if (signDialogMode.value === 'bulk') {
            signingBulk.value = true;
            const formData = new FormData();
            formData.append('president_signature_file', presidentSignatureFile.value);
            selectedTargets.value.forEach((target, index) => {
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

            selectedKeys.value = [];
            await loadItems(itemsData.value.current_page);
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

const onItemStatusChange = async (value: string) => {
    itemStatusFilter.value = value;
    await loadItems(1);
};

const onItemSignatureFilterChange = async (value: string) => {
    if (!props.signatureEnabled) {
        return;
    }

    itemSignatureFilter.value = value;
    await loadItems(1);
};

const onCompanySearchInput = (event: Event) => {
    const target = event.target as HTMLInputElement;
    companySearch.value = target.value;

    if (companySearchDebounce) {
        clearTimeout(companySearchDebounce);
    }

    companySearchDebounce = setTimeout(() => {
        void loadItems(1);
    }, 300);
};

const itemColumns = computed<ColumnDef<GeneratedFileItem>[]>(() => [
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
                      checked: isItemSelected(row.original),
                      onChange: (event: Event) => {
                          const target = event.target as HTMLInputElement;
                          toggleItemSelection(row.original, target.checked);
                      },
                  }),
          } satisfies ColumnDef<GeneratedFileItem>]
        : []),
    {
        id: 'batch_id',
        accessorKey: 'batch_id',
        header: 'Batch',
        enableSorting: false,
        cell: ({ row }) =>
            h(
                Link,
                {
                    href: generatedFilesRoutes.show.url({ batch: row.original.batch_id }),
                    class: 'text-primary underline',
                },
                () => `#${row.original.batch_id}`,
            ),
    },
    {
        id: 'source_excel_name',
        accessorKey: 'source_excel_name',
        header: 'Excel',
        enableSorting: false,
    },
    {
        id: 'template_name',
        accessorKey: 'template_name',
        header: 'Template',
        enableSorting: false,
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
        id: 'actions',
        header: 'Files',
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'flex items-center gap-2' }, [
                row.original.docx_available
                    ? h(
                          'a',
                          {
                              href: documentGeneratorRoutes.batches.items.download.url({
                                  batch: row.original.batch_id,
                                  item: row.original.id,
                                  type: 'docx',
                              }),
                              class: 'text-primary text-sm underline',
                          },
                          'DOCX',
                      )
                    : h('span', { class: 'text-muted-foreground text-sm' }, 'DOCX'),
                row.original.pdf_available
                    ? h(
                          'a',
                          {
                              href: documentGeneratorRoutes.batches.items.download.url({
                                  batch: row.original.batch_id,
                                  item: row.original.id,
                                  type: 'pdf',
                              }),
                              class: 'text-primary text-sm underline',
                              target: '_blank',
                              rel: 'noopener noreferrer',
                          },
                          'Preview PDF',
                      )
                    : h('span', { class: 'text-muted-foreground text-sm' }, 'PDF'),
                props.signatureEnabled
                    ? h(
                          Button,
                          {
                              variant: 'outline',
                              size: 'sm',
                              disabled: !row.original.pdf_available || row.original.signature_applied || isItemSigning(row.original.id),
                              onClick: () => applySignatureSingle(row.original),
                          },
                          () => (row.original.signature_applied ? 'Signed' : isItemSigning(row.original.id) ? 'Signing...' : 'Add Signature'),
                      )
                    : null,
            ]),
    },
]);
</script>

<template>
    <Head title="Generated Files" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Card>
                <CardHeader>
                    <CardTitle>Generated Files</CardTitle>
                    <CardDescription>
                        One table view of generated outputs across all batches.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
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
                            <Label for="company-search" class="mb-2 block">Search company</Label>
                            <Input
                                id="company-search"
                                :model-value="companySearch"
                                placeholder="Type company name..."
                                @input="onCompanySearchInput"
                            />
                        </div>

                        <div class="flex w-full flex-wrap justify-start gap-3 lg:w-auto lg:justify-end">
                            <div class="w-full min-w-[180px] sm:w-[220px]">
                                <Label class="mb-2 block">Status</Label>
                                <Select :model-value="itemStatusFilter" @update:model-value="(value) => onItemStatusChange(String(value))">
                                    <SelectTrigger>
                                        <SelectValue placeholder="All statuses" />
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

                            <div v-if="props.signatureEnabled" class="w-full min-w-[180px] sm:w-[220px]">
                                <Label class="mb-2 block">Signature</Label>
                                <Select :model-value="itemSignatureFilter" @update:model-value="(value) => onItemSignatureFilterChange(String(value))">
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
                        empty-message="No generated files available."
                        @page-change="loadItems"
                        @per-page-change="async (perPage) => { itemsData.per_page = perPage; await loadItems(1); }"
                        @sort-change="async (column, direction) => { itemsSortBy = column; itemsSortDirection = direction; await loadItems(1); }"
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
                        <Label for="generated-files-president-signature">President Signature Image</Label>
                        <Input
                            id="generated-files-president-signature"
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
        </div>
    </AppLayout>
</template>
