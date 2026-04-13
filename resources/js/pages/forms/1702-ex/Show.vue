<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { FolderClosed, LoaderCircle, Upload } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import BatchRowsTable from '@/components/form-1702-ex-components/BatchRowsTable.vue';
import Form1702ExReceiptDialog from '@/components/form-1702-ex-components/Form1702ExReceiptDialog.vue';
import Form1702ExRemoveReceiptDialog from '@/components/form-1702-ex-components/Form1702ExRemoveReceiptDialog.vue';
import type {
    FlashState,
    Form1702ExBatchDetail,
    Form1702ExBatchRow,
} from '@/components/form-1702-ex-components/types';
import {
    formatFileSize,
    pdfFileNamePreview,
    receiptJobIsActive,
} from '@/components/form-1702-ex-components/utils';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
    birReceiptPlaceholderValue,
    parseBirReceiptEmailText,
} from '@/lib/bir-receipt';
import AppLayout from '@/layouts/AppLayout.vue';
import forms from '@/routes/forms';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    flash: FlashState;
    batch: Form1702ExBatchDetail;
    indexUrl: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: '1702-EX',
        href: forms.form1702Ex.index(),
    },
    {
        title: props.batch.name,
        href: props.batch.showUrl,
    },
];

const importForm = useForm<{
    spreadsheet: File | null;
    receiptAcceptanceStartDate: string;
}>({
    spreadsheet: null,
    receiptAcceptanceStartDate: props.batch.receiptAcceptanceStartDate ?? '',
});
const deleteRowsForm = useForm<{
    rowIds: string[];
}>({
    rowIds: [],
});
const prefixForm = useForm<{
    fileNamePrefix: string;
}>({
    fileNamePrefix: props.batch.fileNamePrefix,
});
const footerForm = useForm<{
    footerSourcePath: string;
    footerPrintedDate: string;
}>({
    footerSourcePath: props.batch.footerSourcePath,
    footerPrintedDate: props.batch.footerPrintedDate,
});
const regenerateForm = useForm<{
    footerSourcePath: string;
    footerPrintedDate: string;
}>({
    footerSourcePath: '',
    footerPrintedDate: '',
});
const receiptForm = useForm<{
    values: Record<string, string>;
}>({
    values: {},
});
const removeReceiptForm = useForm<Record<string, never>>({});
const isUploadDialogOpen = ref(false);
const isPrefixDialogOpen = ref(false);
const isFooterDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);
const isRegenerateDialogOpen = ref(false);
const isReceiptDialogOpen = ref(false);
const isRemoveReceiptDialogOpen = ref(false);
const pendingDeleteRowIds = ref<string[]>([]);
const pendingRegenerateRow = ref<Form1702ExBatchRow | null>(null);
const pendingReceiptRow = ref<Form1702ExBatchRow | null>(null);
const pendingReceiptRemovalRow = ref<Form1702ExBatchRow | null>(null);
const pollTimeoutId = ref<number | null>(null);
const spreadsheetInput = ref<HTMLInputElement | null>(null);
const hasActiveBatchJobs = computed(
    () => props.batch.isProcessing || props.batch.hasActiveReceiptJobs,
);

const canSubmitImport = computed(
    () =>
        importForm.spreadsheet instanceof File &&
        !importForm.processing &&
        !props.batch.isProcessing,
);
const canSubmitPrefix = computed(
    () => !prefixForm.processing && !props.batch.isProcessing,
);
const canSubmitFooter = computed(
    () => !footerForm.processing && !props.batch.isProcessing,
);
const canSubmitRegenerate = computed(
    () =>
        pendingRegenerateRow.value !== null &&
        !regenerateForm.processing &&
        !props.batch.isProcessing,
);
const canSubmitReceipt = computed(() => {
    const row = pendingReceiptRow.value;

    if (
        row === null ||
        receiptForm.processing ||
        props.batch.isProcessing ||
        row.pdfStatus !== 'generated' ||
        receiptJobIsActive(row.receiptJobStatus)
    ) {
        return false;
    }

    return props.batch.receiptTemplate.fields.every((field) =>
        Object.prototype.hasOwnProperty.call(receiptForm.values, field.key),
    );
});
const pendingDeleteCount = computed(() => pendingDeleteRowIds.value.length);
const selectedSpreadsheetSummary = computed(() => {
    if (!(importForm.spreadsheet instanceof File)) {
        return null;
    }

    return `${importForm.spreadsheet.name} (${formatFileSize(importForm.spreadsheet.size)})`;
});
const batchPrefixPreview = computed(() =>
    pdfFileNamePreview(prefixForm.fileNamePrefix, 'taxpayer_name'),
);
const regenerateFileNamePreview = computed(() =>
    pdfFileNamePreview(
        prefixForm.fileNamePrefix,
        pendingRegenerateRow.value?.taxpayerName ?? 'taxpayer_name',
    ),
);
const receiptFieldError = computed(() => {
    const directError = receiptForm.errors.values;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(receiptForm.errors).find(([key]) =>
        key.startsWith('values.'),
    );

    return nestedEntry?.[1] ?? null;
});
const receiptValueErrors = computed<Record<string, string | undefined>>(() =>
    Object.fromEntries(
        props.batch.receiptTemplate.fields.map((field) => [
            field.key,
            receiptForm.errors[
                `values.${field.key}` as keyof typeof receiptForm.errors
            ],
        ]),
    ),
);

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            isUploadDialogOpen.value = false;
            isPrefixDialogOpen.value = false;
            isFooterDialogOpen.value = false;
            isDeleteDialogOpen.value = false;
            isRegenerateDialogOpen.value = false;
            isReceiptDialogOpen.value = false;
            isRemoveReceiptDialogOpen.value = false;
            pendingDeleteRowIds.value = [];
            pendingRegenerateRow.value = null;
            pendingReceiptRow.value = null;
            pendingReceiptRemovalRow.value = null;
            deleteRowsForm.reset();
            deleteRowsForm.clearErrors();
            prefixForm.defaults({
                fileNamePrefix: prefixForm.fileNamePrefix,
            });
            prefixForm.clearErrors();
            footerForm.clearErrors();
            regenerateForm.reset();
            regenerateForm.clearErrors();
            receiptForm.reset();
            receiptForm.clearErrors();
            removeReceiptForm.clearErrors();
            resetImportForm();
            toast.success(success);
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

watch(
    hasActiveBatchJobs,
    (hasActiveJobs) => {
        if (!hasActiveJobs) {
            clearPollTimeout();

            return;
        }

        schedulePoll();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearPollTimeout();
});

watch(
    () => props.batch.rows.map((row) => row.id),
    (rowIds) => {
        const availableRowIds = new Set(rowIds);

        pendingDeleteRowIds.value = pendingDeleteRowIds.value.filter((rowId) =>
            availableRowIds.has(rowId),
        );
    },
    { immediate: true },
);

function resetImportForm(): void {
    importForm.reset();
    importForm.clearErrors();
    importForm.receiptAcceptanceStartDate = props.batch.receiptAcceptanceStartDate ?? '';

    if (spreadsheetInput.value) {
        spreadsheetInput.value.value = '';
    }
}

function clearPollTimeout(): void {
    if (pollTimeoutId.value !== null) {
        window.clearTimeout(pollTimeoutId.value);
        pollTimeoutId.value = null;
    }
}

function schedulePoll(): void {
    if (pollTimeoutId.value !== null) {
        return;
    }

    pollTimeoutId.value = window.setTimeout(() => {
        const reloadOptions = {
            only: ['batch', 'flash'],
            preserveState: true,
            onFinish: () => {
                pollTimeoutId.value = null;

                if (hasActiveBatchJobs.value) {
                    schedulePoll();
                }
            },
        } as Parameters<typeof router.reload>[0];

        router.reload(reloadOptions);
    }, 3000);
}

function handleSpreadsheetSelected(event: Event): void {
    const input = event.target as HTMLInputElement | null;

    importForm.spreadsheet = input?.files?.[0] ?? null;
    importForm.clearErrors('spreadsheet');

    if (input) {
        input.value = '';
    }
}

function submitImport(): void {
    if (!(importForm.spreadsheet instanceof File)) {
        importForm.setError('spreadsheet', 'Choose a CSV or XLSX file first.');

        return;
    }

    if (importForm.receiptAcceptanceStartDate.trim() === '') {
        importForm.setError(
            'receiptAcceptanceStartDate',
            'Choose the receipt acceptance start date first.',
        );

        return;
    }

    importForm.post(props.batch.importUrl, {
        forceFormData: true,
        preserveScroll: true,
    });
}

function openSpreadsheetPicker(): void {
    spreadsheetInput.value?.click();
}

function openPrefixDialog(): void {
    prefixForm.fileNamePrefix = props.batch.fileNamePrefix;
    prefixForm.clearErrors();
    isPrefixDialogOpen.value = true;
}

function submitPrefix(): void {
    prefixForm.patch(props.batch.prefixUpdateUrl, {
        preserveScroll: true,
    });
}

function openFooterDialog(): void {
    footerForm.footerSourcePath = props.batch.footerSourcePath;
    footerForm.footerPrintedDate = props.batch.footerPrintedDate;
    footerForm.clearErrors();
    isFooterDialogOpen.value = true;
}

function submitFooter(): void {
    footerForm.patch(props.batch.footerUpdateUrl, {
        preserveScroll: true,
    });
}

function handleReceiptDialogOpenChange(open: boolean): void {
    if (receiptForm.processing) {
        return;
    }

    isReceiptDialogOpen.value = open;

    if (!open) {
        pendingReceiptRow.value = null;
        receiptForm.reset();
        receiptForm.clearErrors();
    }
}

function handleRemoveReceiptDialogOpenChange(open: boolean): void {
    if (removeReceiptForm.processing) {
        return;
    }

    isRemoveReceiptDialogOpen.value = open;

    if (!open) {
        pendingReceiptRemovalRow.value = null;
        removeReceiptForm.clearErrors();
    }
}

function regenerateRow(row: Form1702ExBatchRow): void {
    pendingRegenerateRow.value = row;
    regenerateForm.footerSourcePath = row.footerSourcePath;
    regenerateForm.footerPrintedDate = row.footerPrintedDate;
    regenerateForm.clearErrors();
    isRegenerateDialogOpen.value = true;
}

function submitRegenerate(): void {
    if (pendingRegenerateRow.value === null) {
        return;
    }

    regenerateForm.post(pendingRegenerateRow.value.regenerateUrl, {
        preserveScroll: true,
    });
}

function openReceiptDialog(row: Form1702ExBatchRow): void {
    if (props.batch.isProcessing) {
        toast.error('Wait for the current PDF generation to finish first.');

        return;
    }

    if (row.pdfStatus !== 'generated') {
        toast.error('Generate this row PDF before adding a receipt.');

        return;
    }

    if (receiptJobIsActive(row.receiptJobStatus)) {
        toast.error('A receipt update is already queued for this row.');

        return;
    }

    pendingReceiptRow.value = row;
    receiptForm.values = Object.fromEntries(
        props.batch.receiptTemplate.fields.map((field) => [
            field.key,
            field.key === 'file_name' ? row.fileName : '',
        ]),
    );
    receiptForm.clearErrors();
    isReceiptDialogOpen.value = true;
}

function updateReceiptValue(payload: { key: string; value: string }): void {
    receiptForm.values = {
        ...receiptForm.values,
        [payload.key]: payload.value,
    };
}

async function pasteReceiptDetailsFromClipboard(): Promise<void> {
    if (typeof navigator === 'undefined' || !navigator.clipboard?.readText) {
        toast.error('Clipboard paste is not available in this browser.');

        return;
    }

    try {
        const clipboardText = await navigator.clipboard.readText();
        const details = parseBirReceiptEmailText(clipboardText);

        if (!details) {
            toast.error('Clipboard does not contain BIR receipt details yet.');

            return;
        }

        const nextValues = { ...receiptForm.values };
        let appliedCount = 0;

        for (const field of props.batch.receiptTemplate.fields) {
            const value = birReceiptPlaceholderValue(field.key, details);

            if (!value) {
                continue;
            }

            nextValues[field.key] = value;
            appliedCount++;
        }

        if (appliedCount === 0) {
            toast.error(
                'This receipt template does not have matching file/date/time fields.',
            );

            return;
        }

        receiptForm.values = nextValues;
        receiptForm.clearErrors();
        toast.success(
            `Filled ${appliedCount} field${appliedCount === 1 ? '' : 's'} from the copied email details.`,
        );
    } catch {
        toast.error('Unable to read the clipboard right now.');
    }
}

function submitReceipt(): void {
    const row = pendingReceiptRow.value;

    if (row === null) {
        return;
    }

    if (props.batch.isProcessing) {
        toast.error('Wait for the current PDF generation to finish first.');

        return;
    }

    if (receiptJobIsActive(row.receiptJobStatus)) {
        toast.error('A receipt update is already queued for this row.');

        return;
    }

    receiptForm.post(row.receiptStoreUrl, {
        preserveScroll: true,
    });
}

function openRemoveReceiptDialog(row: Form1702ExBatchRow): void {
    if (!row.receiptRemoveUrl) {
        return;
    }

    if (props.batch.isProcessing) {
        toast.error('Wait for the current PDF generation to finish first.');

        return;
    }

    if (receiptJobIsActive(row.receiptJobStatus)) {
        toast.error('A receipt update is already queued for this row.');

        return;
    }

    pendingReceiptRemovalRow.value = row;
    removeReceiptForm.clearErrors();
    isRemoveReceiptDialogOpen.value = true;
}

function removeReceipt(): void {
    const row = pendingReceiptRemovalRow.value;

    if (!row?.receiptRemoveUrl) {
        return;
    }

    removeReceiptForm.delete(row.receiptRemoveUrl, {
        preserveScroll: true,
    });
}

function requestDeleteRows(rowIds: string[]): void {
    pendingDeleteRowIds.value = Array.from(new Set(rowIds));

    if (pendingDeleteRowIds.value.length === 0) {
        return;
    }

    isDeleteDialogOpen.value = true;
}

function submitDeleteRows(): void {
    if (pendingDeleteRowIds.value.length === 0) {
        return;
    }

    deleteRowsForm.rowIds = pendingDeleteRowIds.value;

    deleteRowsForm.delete(props.batch.bulkDeleteUrl, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="props.batch.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <AlertDialog
            :open="isDeleteDialogOpen"
            @update:open="isDeleteDialogOpen = $event"
        >
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete imported rows</AlertDialogTitle>
                    <AlertDialogDescription>
                        This will permanently remove
                        <span class="font-medium text-foreground">
                            {{ pendingDeleteCount }}
                        </span>
                        selected row{{ pendingDeleteCount === 1 ? '' : 's' }}
                        from this batch, including any generated PDFs.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel :disabled="deleteRowsForm.processing">
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        :disabled="
                            pendingDeleteCount === 0 ||
                            deleteRowsForm.processing
                        "
                        class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                        @click="submitDeleteRows"
                    >
                        <LoaderCircle
                            v-if="deleteRowsForm.processing"
                            class="mr-2 size-4 animate-spin"
                        />
                        Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <template #subheader>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Button
                    as-child
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2 text-xs"
                >
                    <a :href="props.batch.templateSpreadsheetUrl" download>
                        Download template
                    </a>
                </Button>

                <Button
                    as-child
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2 text-xs"
                >
                    <a :href="props.batch.receiptTemplateUrl">
                        Receipt Template
                    </a>
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2 text-xs"
                    :disabled="props.batch.isProcessing"
                    @click="openPrefixDialog"
                >
                    Prefix
                </Button>

                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2 text-xs"
                    :disabled="props.batch.isProcessing"
                    @click="isUploadDialogOpen = true"
                >
                    <Upload class="size-4" />
                    Upload Excel File
                </Button>
            </div>
        </template>

        <Dialog
            :open="isPrefixDialogOpen"
            @update:open="isPrefixDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader class="space-y-1">
                    <DialogTitle>Batch file name prefix</DialogTitle>
                    <DialogDescription>
                        Set one optional prefix for this batch. New uploads and
                        regenerated PDFs in this folder will use it.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-5" @submit.prevent="submitPrefix">
                    <div class="space-y-2">
                        <Label for="batch-file-name-prefix">
                            File name prefix
                            <span class="text-muted-foreground"
                                >(optional)</span
                            >
                        </Label>
                        <Input
                            id="batch-file-name-prefix"
                            :model-value="prefixForm.fileNamePrefix"
                            type="text"
                            placeholder="prefix"
                            @update:model-value="
                                prefixForm.fileNamePrefix = String($event)
                            "
                        />
                        <p class="text-xs text-muted-foreground">
                            Each generated PDF becomes
                            <span class="font-medium text-foreground">
                                {{ batchPrefixPreview }}
                            </span>
                            .
                        </p>
                        <InputError
                            :message="prefixForm.errors.fileNamePrefix"
                        />
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="prefixForm.processing"
                            @click="isPrefixDialogOpen = false"
                        >
                            Cancel
                        </Button>

                        <Button type="submit" :disabled="!canSubmitPrefix">
                            <LoaderCircle
                                v-if="prefixForm.processing"
                                class="mr-2 size-4 animate-spin"
                            />
                            Save Prefix
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="isUploadDialogOpen"
            @update:open="isUploadDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader class="space-y-1">
                    <DialogTitle>Upload Excel File</DialogTitle>
                    <DialogDescription>
                        Upload one merged CSV or XLSX row per taxpayer for
                        1702-EX pages 1 to 3. New uploads append rows to this
                        batch.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-5" @submit.prevent="submitImport">
                    <input
                        ref="spreadsheetInput"
                        type="file"
                        accept=".csv,.xlsx,.txt"
                        class="hidden"
                        @change="handleSpreadsheetSelected"
                    />

                    <div class="space-y-2">
                        <Label>Excel file</Label>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                class="gap-2"
                                :disabled="importForm.processing"
                                @click="openSpreadsheetPicker"
                            >
                                <Upload class="size-4" />
                                Choose file
                            </Button>
                            <span
                                v-if="selectedSpreadsheetSummary"
                                class="self-center text-sm text-muted-foreground"
                            >
                                <span class="font-medium text-foreground">
                                    {{ selectedSpreadsheetSummary }}
                                </span>
                            </span>
                        </div>
                        <InputError :message="importForm.errors.spreadsheet" />
                    </div>

                    <div class="space-y-2">
                        <Label for="batch-receipt-acceptance-start-date">
                            Receipt acceptance start date
                        </Label>
                        <Input
                            id="batch-receipt-acceptance-start-date"
                            v-model="importForm.receiptAcceptanceStartDate"
                            type="date"
                        />
                        <p class="text-xs text-muted-foreground">
                            Only BIR receipts with a Date received by BIR on or after this date will auto-apply to rows from this upload.
                        </p>
                        <InputError :message="importForm.errors.receiptAcceptanceStartDate" />
                    </div>

                    <div
                        class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground"
                    >
                        This batch currently has
                        <span class="font-medium text-foreground">
                            {{ props.batch.rows.length }}
                        </span>
                        imported row{{
                            props.batch.rows.length === 1 ? '' : 's'
                        }}. Merged PDFs are generated automatically after the
                        import finishes, and the batch prefix can be updated from the top buttons.
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="importForm.processing"
                            @click="isUploadDialogOpen = false"
                        >
                            Cancel
                        </Button>

                        <Button as-child type="button" variant="outline">
                            <a
                                :href="props.batch.templateSpreadsheetUrl"
                                download
                            >
                                Download Template
                            </a>
                        </Button>

                        <Button type="submit" :disabled="!canSubmitImport">
                            <LoaderCircle
                                v-if="importForm.processing"
                                class="mr-2 size-4 animate-spin"
                            />
                            <Upload v-else class="mr-2 size-4" />
                            {{
                                importForm.processing
                                    ? 'Uploading...'
                                    : 'Upload Excel File'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="isRegenerateDialogOpen"
            @update:open="isRegenerateDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-lg">
                <DialogHeader class="space-y-1">
                    <DialogTitle>Regenerate PDF</DialogTitle>
                    <DialogDescription>
                        Queue a fresh PDF build for this row using the saved internal defaults.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-5" @submit.prevent="submitRegenerate">
                    <div
                        class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground"
                    >
                        This row will regenerate using the current batch prefix:
                        <span class="font-medium text-foreground">
                            {{ regenerateFileNamePreview }}
                        </span>
                    </div>

                    <div
                        class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground"
                    >
                        The footer configuration stays hidden here and the selected row will regenerate with its saved values.
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="regenerateForm.processing"
                            @click="isRegenerateDialogOpen = false"
                        >
                            Cancel
                        </Button>

                        <Button type="submit" :disabled="!canSubmitRegenerate">
                            <LoaderCircle
                                v-if="regenerateForm.processing"
                                class="mr-2 size-4 animate-spin"
                            />
                            Regenerate PDF
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div
                class="flex flex-col gap-4 rounded-3xl border bg-background p-6 shadow-sm md:flex-row md:items-start md:justify-between"
            >
                <div class="space-y-3">
                    <div class="flex items-center gap-3">
                        <div class="rounded-2xl bg-muted p-3">
                            <FolderClosed class="size-6 text-foreground" />
                        </div>
                        <div>
                            <p
                                class="text-xs font-semibold tracking-[0.3em] text-teal-700 uppercase"
                            >
                                1702-EX Batch
                            </p>
                            <h1
                                class="text-3xl font-semibold tracking-tight text-foreground"
                            >
                                {{ props.batch.name }}
                            </h1>
                        </div>
                    </div>

                    <p
                        class="max-w-3xl text-sm leading-7 text-muted-foreground"
                    >
                        Save one merged Excel row per taxpayer in this batch and
                        generate one merged 1702-EX page 1 to page 3 PDF per row
                        in the background.
                    </p>

                    <p class="text-sm text-muted-foreground">
                        {{ props.batch.rows.length }} imported row{{
                            props.batch.rows.length === 1 ? '' : 's'
                        }}.
                        <template v-if="hasActiveBatchJobs">
                            <template v-if="props.batch.isProcessing">
                                PDF generation is still running, so this page
                                will refresh automatically.
                            </template>
                            <template v-else>
                                Receipt updates are still running, so this page
                                will refresh automatically.
                            </template>
                        </template>
                    </p>

                </div>

                <div class="flex flex-wrap gap-2">
                    <Button as-child type="button" variant="outline">
                        <Link :href="props.indexUrl"> Back to Batches </Link>
                    </Button>
                </div>
            </div>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle class="text-xl">Imported Rows</CardTitle>
                    <CardDescription>
                        Search and sort the saved rows in this batch, then
                        preview, download, or regenerate each merged page 1 to
                        page 3 PDF from the data table.
                    </CardDescription>
                </CardHeader>

                <CardContent>
                    <div class="mb-4 grid gap-4 rounded-2xl border bg-muted/20 p-4 md:grid-cols-3">
                        <div>
                            <p class="text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                File Prefix
                            </p>
                            <p class="mt-1 text-sm font-medium text-foreground">
                                {{ props.batch.fileNamePrefix || 'None' }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                Accepted Receipts From
                            </p>
                            <p class="mt-1 text-sm font-medium text-foreground">
                                {{ props.batch.receiptAcceptanceStartDate || 'Not set' }}
                            </p>
                        </div>
                    </div>

                    <BatchRowsTable
                        :rows="props.batch.rows"
                        :is-batch-busy="props.batch.isProcessing"
                        :is-delete-processing="deleteRowsForm.processing"
                        @open-receipt="openReceiptDialog"
                        @open-remove-receipt="openRemoveReceiptDialog"
                        @regenerate="regenerateRow"
                        @request-delete="requestDeleteRows"
                    />
                </CardContent>
            </Card>
        </div>

        <Form1702ExRemoveReceiptDialog
            :open="isRemoveReceiptDialogOpen"
            :processing="removeReceiptForm.processing"
            :row="pendingReceiptRemovalRow"
            @submit="removeReceipt"
            @update:open="handleRemoveReceiptDialogOpenChange"
        />

        <Form1702ExReceiptDialog
            :open="isReceiptDialogOpen"
            :can-submit="canSubmitReceipt"
            :field-error="receiptFieldError"
            :fields="props.batch.receiptTemplate.fields"
            :processing="receiptForm.processing"
            :row="pendingReceiptRow"
            :value-errors="receiptValueErrors"
            :values="receiptForm.values"
            @paste-from-email="pasteReceiptDetailsFromClipboard"
            @submit="submitReceipt"
            @update:open="handleReceiptDialogOpenChange"
            @update:value="updateReceiptValue"
        />
    </AppLayout>
</template>
