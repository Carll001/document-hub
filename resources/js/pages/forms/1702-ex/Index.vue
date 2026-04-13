<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Settings2, Upload } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import Form1702ExReceiptDialog from '@/components/form-1702-ex-components/Form1702ExReceiptDialog.vue';
import Form1702ExRemoveReceiptDialog from '@/components/form-1702-ex-components/Form1702ExRemoveReceiptDialog.vue';
import PaginatedRowsTable from '@/components/form-1702-ex-components/PaginatedRowsTable.vue';
import type {
    FlashState,
    Form1702ExBatchRow,
    Form1702ExReceiptTemplateState,
    Form1702ExRowFilters,
    Form1702ExRowPagination,
    Form1702ExSettings,
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
    indexUrl: string;
    completedFilesUrl: string;
    completedCount: number;
    importUrl: string;
    bulkDeleteUrl: string;
    settingsUpdateUrl: string;
    templateSpreadsheetUrl: string;
    receiptTemplateUrl: string;
    receiptTemplate: Form1702ExReceiptTemplateState;
    settings: Form1702ExSettings;
    rows: Form1702ExBatchRow[];
    pagination: Form1702ExRowPagination;
    filters: Form1702ExRowFilters;
    hasActiveJobs: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: '1702-EX',
        href: forms.form1702Ex.index(),
    },
];

const importForm = useForm<{
    spreadsheet: File | null;
    receiptAcceptanceStartDate: string;
}>({
    spreadsheet: null,
    receiptAcceptanceStartDate: '',
});
const settingsForm = useForm<Form1702ExSettings>({
    fileNamePrefix: props.settings.fileNamePrefix,
    footerSourcePath: props.settings.footerSourcePath,
    footerPrintedDate: props.settings.footerPrintedDate,
});
const deleteRowsForm = useForm<{
    rowIds: string[];
}>({
    rowIds: [],
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
const isSettingsDialogOpen = ref(false);
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

const canSubmitImport = computed(
    () => importForm.spreadsheet instanceof File && !importForm.processing,
);
const canSubmitSettings = computed(() => !settingsForm.processing);
const canSubmitRegenerate = computed(
    () => pendingRegenerateRow.value !== null && !regenerateForm.processing,
);
const canSubmitReceipt = computed(() => {
    const row = pendingReceiptRow.value;

    if (
        row === null
        || receiptForm.processing
        || row.pdfStatus !== 'generated'
        || receiptJobIsActive(row.receiptJobStatus)
    ) {
        return false;
    }

    return props.receiptTemplate.fields.every((field) =>
        Object.prototype.hasOwnProperty.call(receiptForm.values, field.key),
    );
});
const selectedSpreadsheetSummary = computed(() => {
    if (!(importForm.spreadsheet instanceof File)) {
        return null;
    }

    return `${importForm.spreadsheet.name} (${formatFileSize(importForm.spreadsheet.size)})`;
});
const batchPrefixPreview = computed(() =>
    pdfFileNamePreview(settingsForm.fileNamePrefix, 'taxpayer_name'),
);
const regenerateFileNamePreview = computed(() =>
    pdfFileNamePreview(
        settingsForm.fileNamePrefix,
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
        props.receiptTemplate.fields.map((field) => [
            field.key,
            receiptForm.errors[
                `values.${field.key}` as keyof typeof receiptForm.errors
            ],
        ]),
    ),
);

watch(
    () => props.settings,
    (settings) => {
        settingsForm.defaults({
            fileNamePrefix: settings.fileNamePrefix,
            footerSourcePath: settings.footerSourcePath,
            footerPrintedDate: settings.footerPrintedDate,
        });

        if (!isSettingsDialogOpen.value) {
            settingsForm.fileNamePrefix = settings.fileNamePrefix;
            settingsForm.footerSourcePath = settings.footerSourcePath;
            settingsForm.footerPrintedDate = settings.footerPrintedDate;
        }
    },
    { deep: true },
);

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            isUploadDialogOpen.value = false;
            isSettingsDialogOpen.value = false;
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
    () => props.hasActiveJobs,
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

function resetImportForm(): void {
    importForm.reset();
    importForm.clearErrors();

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
        router.reload({
            only: ['rows', 'pagination', 'filters', 'hasActiveJobs', 'settings', 'flash'],
            preserveState: true,
            onFinish: () => {
                pollTimeoutId.value = null;

                if (props.hasActiveJobs) {
                    schedulePoll();
                }
            },
        });
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

function openSpreadsheetPicker(): void {
    spreadsheetInput.value?.click();
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

    importForm.post(props.importUrl, {
        forceFormData: true,
        preserveScroll: true,
    });
}

function openSettingsDialog(): void {
    settingsForm.fileNamePrefix = props.settings.fileNamePrefix;
    settingsForm.footerSourcePath = props.settings.footerSourcePath;
    settingsForm.footerPrintedDate = props.settings.footerPrintedDate;
    settingsForm.clearErrors();
    isSettingsDialogOpen.value = true;
}

function submitSettings(): void {
    settingsForm.patch(props.settingsUpdateUrl, {
        preserveScroll: true,
    });
}

function requestDelete(rowIds: string[]): void {
    pendingDeleteRowIds.value = rowIds;
    deleteRowsForm.rowIds = rowIds;
    deleteRowsForm.clearErrors();
    isDeleteDialogOpen.value = true;
}

function submitDeleteRows(): void {
    deleteRowsForm.delete(props.bulkDeleteUrl, {
        preserveScroll: true,
        data: {
            rowIds: deleteRowsForm.rowIds,
        },
    });
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

function resetReceiptValues(row: Form1702ExBatchRow | null): void {
    receiptForm.values = Object.fromEntries(
        props.receiptTemplate.fields.map((field) => [
            field.key,
            birReceiptPlaceholderValue(field.key, row?.fileName ?? ''),
        ]),
    );
}

function openReceiptDialog(row: Form1702ExBatchRow): void {
    pendingReceiptRow.value = row;
    receiptForm.reset();
    receiptForm.clearErrors();
    resetReceiptValues(row);
    isReceiptDialogOpen.value = true;
}

function handleReceiptDialogOpenChange(open: boolean): void {
    isReceiptDialogOpen.value = open;

    if (!open) {
        pendingReceiptRow.value = null;
        receiptForm.reset();
        receiptForm.clearErrors();
    }
}

function submitReceipt(): void {
    if (pendingReceiptRow.value === null) {
        return;
    }

    receiptForm.post(pendingReceiptRow.value.receiptStoreUrl, {
        preserveScroll: true,
    });
}

async function pasteReceiptValuesFromEmail(): Promise<void> {
    try {
        const clipboardText = await navigator.clipboard.readText();
        const parsedValues = parseBirReceiptEmailText(clipboardText);

        for (const field of props.receiptTemplate.fields) {
            const parsedValue = parsedValues[field.key];

            if (parsedValue) {
                receiptForm.values[field.key] = parsedValue;
            }
        }
    } catch {
        toast.error('Clipboard access failed. Paste the values manually.');
    }
}

function updateReceiptValue(payload: { key: string; value: string }): void {
    receiptForm.values = {
        ...receiptForm.values,
        [payload.key]: payload.value,
    };
}

function openRemoveReceiptDialog(row: Form1702ExBatchRow): void {
    pendingReceiptRemovalRow.value = row;
    removeReceiptForm.clearErrors();
    isRemoveReceiptDialogOpen.value = true;
}

function handleRemoveReceiptDialogOpenChange(open: boolean): void {
    isRemoveReceiptDialogOpen.value = open;

    if (!open) {
        pendingReceiptRemovalRow.value = null;
        removeReceiptForm.clearErrors();
    }
}

function submitRemoveReceipt(): void {
    if (pendingReceiptRemovalRow.value?.receiptRemoveUrl === null || pendingReceiptRemovalRow.value?.receiptRemoveUrl === undefined) {
        return;
    }

    removeReceiptForm.delete(pendingReceiptRemovalRow.value.receiptRemoveUrl, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Company Name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <Dialog
            :open="isUploadDialogOpen"
            @update:open="isUploadDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-xl">
                <DialogHeader class="space-y-1">
                    <DialogTitle>Upload spreadsheet</DialogTitle>
                    <DialogDescription>
                        Import a CSV or XLSX file and queue one generated PDF per row.
                        Use the <code>client_name</code> header to group companies under a client page.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-6" @submit.prevent="submitImport">
                    <div class="space-y-3">
                        <input
                            ref="spreadsheetInput"
                            type="file"
                            class="hidden"
                            accept=".csv,.xlsx,.xls"
                            @change="handleSpreadsheetSelected"
                        />

                        <Button
                            type="button"
                            variant="outline"
                            class="w-full justify-start gap-2"
                            @click="openSpreadsheetPicker"
                        >
                            <Upload class="size-4" />
                            Choose spreadsheet
                        </Button>

                        <p
                            v-if="selectedSpreadsheetSummary"
                            class="text-sm text-muted-foreground"
                        >
                            {{ selectedSpreadsheetSummary }}
                        </p>

                        <p class="text-xs text-muted-foreground">
                            Use the provided template to match the import columns exactly.
                        </p>

                        <InputError :message="importForm.errors.spreadsheet" />
                    </div>

                    <div class="space-y-2">
                        <Label for="receiptAcceptanceStartDate">
                            Receipt acceptance start date
                        </Label>
                        <Input
                            id="receiptAcceptanceStartDate"
                            v-model="importForm.receiptAcceptanceStartDate"
                            type="date"
                        />
                        <p class="text-xs text-muted-foreground">
                            Only BIR receipts with a Date received by BIR on or after this date will auto-apply to this uploaded spreadsheet.
                        </p>
                        <InputError :message="importForm.errors.receiptAcceptanceStartDate" />
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
                        <Button type="submit" :disabled="!canSubmitImport">
                            <LoaderCircle
                                v-if="importForm.processing"
                                class="mr-2 size-4 animate-spin"
                            />
                            <Upload v-else class="mr-2 size-4" />
                            {{ importForm.processing ? 'Uploading...' : 'Upload and generate' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog
            :open="isSettingsDialogOpen"
            @update:open="isSettingsDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader class="space-y-1">
                    <DialogTitle>PDF defaults</DialogTitle>
                    <DialogDescription>
                        These defaults apply to new uploads and row regeneration from this screen.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-6" @submit.prevent="submitSettings">
                    <div class="space-y-2">
                        <Label for="fileNamePrefix">File name prefix</Label>
                        <Input
                            id="fileNamePrefix"
                            v-model="settingsForm.fileNamePrefix"
                            type="text"
                            maxlength="120"
                            placeholder="Optional prefix"
                        />
                        <p class="text-xs text-muted-foreground">
                            Preview: <span class="font-medium text-foreground">{{ batchPrefixPreview }}</span>
                        </p>
                        <InputError :message="settingsForm.errors.fileNamePrefix" />
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="settingsForm.processing"
                            @click="isSettingsDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="!canSubmitSettings">
                            Save defaults
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <AlertDialog :open="isDeleteDialogOpen" @update:open="isDeleteDialogOpen = $event">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete imported rows</AlertDialogTitle>
                    <AlertDialogDescription>
                        Delete {{ pendingDeleteRowIds.length }} selected row{{
                            pendingDeleteRowIds.length === 1 ? '' : 's'
                        }} and any generated PDF or attached receipt files.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter class="gap-2">
                    <AlertDialogCancel :disabled="deleteRowsForm.processing">
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        :disabled="deleteRowsForm.processing"
                        @click="submitDeleteRows"
                    >
                        Delete rows
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <Dialog
            :open="isRegenerateDialogOpen"
            @update:open="isRegenerateDialogOpen = $event"
        >
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader class="space-y-1">
                    <DialogTitle>Regenerate PDF</DialogTitle>
                    <DialogDescription>
                        Queue a fresh PDF build for this row using the saved internal defaults.
                    </DialogDescription>
                </DialogHeader>

                <form class="space-y-6" @submit.prevent="submitRegenerate">
                    <div class="rounded-2xl border bg-muted/30 p-4 text-sm">
                        <p class="font-medium text-foreground">
                            {{ pendingRegenerateRow?.taxpayerName ?? 'Selected row' }}
                        </p>
                        <p class="mt-1 text-muted-foreground">
                            Generated file preview:
                            <span class="font-medium text-foreground">
                                {{ regenerateFileNamePreview }}
                            </span>
                        </p>
                    </div>

                    <div class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground">
                        This keeps the saved footer configuration hidden and only queues a fresh PDF build for the selected row.
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
                            Queue regeneration
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Form1702ExRemoveReceiptDialog
            :open="isRemoveReceiptDialogOpen"
            :processing="removeReceiptForm.processing"
            :row="pendingReceiptRemovalRow"
            @confirm="submitRemoveReceipt"
            @update:open="handleRemoveReceiptDialogOpenChange"
        />

        <Form1702ExReceiptDialog
            :can-submit="canSubmitReceipt"
            :field-error="receiptFieldError"
            :fields="props.receiptTemplate.fields"
            :open="isReceiptDialogOpen"
            :processing="receiptForm.processing"
            :row="pendingReceiptRow"
            :value-errors="receiptValueErrors"
            :values="receiptForm.values"
            @paste-from-email="pasteReceiptValuesFromEmail"
            @submit="submitReceipt"
            @update:open="handleReceiptDialogOpenChange"
            @update:value="updateReceiptValue"
        />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardContent class="flex flex-col gap-5 p-6 md:flex-row md:items-center md:justify-between md:p-8">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.3em] text-teal-700 uppercase">
                            1702-EX Workspace
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">
                            Company Name Rows
                        </h1>
                        <p class="max-w-3xl text-sm leading-7 text-muted-foreground">
                            Upload spreadsheets directly, keep one shared PDF default configuration,
                            and manage every imported row in a single paginated table.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="router.get(props.completedFilesUrl)"
                        >
                            Completed Files ({{ props.completedCount }})
                        </Button>
                        <Button type="button" variant="outline" @click="openSettingsDialog">
                            <Settings2 class="mr-2 size-4" />
                            PDF Defaults
                        </Button>
                        <Button type="button" @click="isUploadDialogOpen = true">
                            <Upload class="mr-2 size-4" />
                            Upload Spreadsheet
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle class="text-xl">Import and output table</CardTitle>
                    <CardDescription>
                        The latest shared defaults are used for new uploads. Template:
                        <a
                            :href="templateSpreadsheetUrl"
                            class="font-medium text-foreground underline underline-offset-4"
                        >
                            download spreadsheet
                        </a>
                        . Receipt alignment:
                        <a
                            :href="receiptTemplateUrl"
                            class="font-medium text-foreground underline underline-offset-4"
                        >
                            open receipt template
                        </a>
                        .
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-4">
                    <div class="grid gap-4 rounded-2xl border bg-muted/20 p-4 md:grid-cols-3">
                        <div>
                            <p class="text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                File Prefix
                            </p>
                            <p class="mt-1 text-sm font-medium text-foreground">
                                {{ props.settings.fileNamePrefix || 'None' }}
                            </p>
                        </div>
                        <div class="md:col-span-2">
                            <p class="text-xs font-semibold tracking-[0.2em] text-muted-foreground uppercase">
                                Receipt Acceptance Start
                            </p>
                            <p class="mt-1 text-sm font-medium text-foreground">
                                {{ props.settings.receiptAcceptanceStartDate || 'Not set' }}
                            </p>
                        </div>
                    </div>

                    <PaginatedRowsTable
                        :filters="props.filters"
                        :is-busy="props.hasActiveJobs"
                        :is-delete-processing="deleteRowsForm.processing"
                        :page-url="props.indexUrl"
                        :pagination="props.pagination"
                        :rows="props.rows"
                        @open-receipt="openReceiptDialog"
                        @open-remove-receipt="openRemoveReceiptDialog"
                        @regenerate="regenerateRow"
                        @request-delete="requestDelete"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
