<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, toRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import BatchBulkFolderDialog from '@/components/doc-merge-batch-components/BatchBulkFolderDialog.vue';
import BatchBulkZipDialog from '@/components/doc-merge-batch-components/BatchBulkZipDialog.vue';
import BatchDeleteDialog from '@/components/doc-merge-batch-components/BatchDeleteDialog.vue';
import BatchDeleteResultsDialog from '@/components/doc-merge-batch-components/BatchDeleteResultsDialog.vue';
import BatchFailureDialog from '@/components/doc-merge-batch-components/BatchFailureDialog.vue';
import BatchHeader from '@/components/doc-merge-batch-components/BatchHeader.vue';
import BatchPreviewDialog from '@/components/doc-merge-batch-components/BatchPreviewDialog.vue';
import BatchReceiptDialog from '@/components/doc-merge-batch-components/BatchReceiptDialog.vue';
import BatchRemoveReceiptDialog from '@/components/doc-merge-batch-components/BatchRemoveReceiptDialog.vue';
import BatchResultsTable from '@/components/doc-merge-batch-components/BatchResultsTable.vue';
import BatchSendEmailDialog from '@/components/doc-merge-batch-components/BatchSendEmailDialog.vue';
import type {
    BatchDetail,
    BatchMergeHistoryRecord,
    BatchMergedOutput,
    ConfirmationTemplateState,
    DeleteItemPayload,
    FlashState,
    PageFolderPayload,
} from '@/components/doc-merge-batch-components/types';
import { useBatchPageFolders } from '@/components/doc-merge-batch-components/useBatchPageFolders';
import { useBatchResultSelection } from '@/components/doc-merge-batch-components/useBatchResultSelection';
import {
    batchProcessingIsActive,
    bulkOutputPreview,
    defaultConfirmationPlaceholderValue,
    defaultEmailMessage,
    defaultEmailSubject,
    isFailureRecord,
    isMergedRecord,
    mergeHistoryRecordKey,
    receiptJobIsActive,
} from '@/components/doc-merge-batch-components/utils';
import { Card } from '@/components/ui/card';
import {
    birReceiptPlaceholderValue,
    parseBirReceiptEmailText,
} from '@/lib/bir-receipt';
import AppLayout from '@/layouts/AppLayout.vue';
import docMerge from '@/routes/doc-merge';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    flash: FlashState;
    batch: BatchDetail;
    confirmationTemplate: ConfirmationTemplateState;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Doc Merge',
        href: docMerge.index(),
    },
    {
        title: props.batch.name,
        href: props.batch.showUrl,
    },
];

const isBulkZipDialogOpen = ref(false);
const isBulkFolderDialogOpen = ref(false);
const isPreviewDialogOpen = ref(false);
const isFailureDialogOpen = ref(false);
const isRemoveReceiptDialogOpen = ref(false);
const isReceiptDialogOpen = ref(false);
const isSendEmailDialogOpen = ref(false);
const isDeleteResultsDialogOpen = ref(false);
const isDeleteBatchDialogOpen = ref(false);
const previewedMergedPdf = ref<BatchMergedOutput | null>(null);
const mergeFailureForDialog = ref<Extract<BatchMergeHistoryRecord, { recordType: 'merge_failure' }> | null>(
    null,
);
const mergedPdfForReceiptRemoval = ref<BatchMergedOutput | null>(null);
const mergedPdfForReceipt = ref<BatchMergedOutput | null>(null);
const mergedPdfForEmail = ref<BatchMergedOutput | null>(null);
const mergeHistoryForDeletion = ref<BatchMergeHistoryRecord[]>([]);

const deleteBatchForm = useForm<Record<string, never>>({});
const deleteForm = useForm<{
    items: DeleteItemPayload[];
}>({
    items: [],
});
const sendEmailForm = useForm<{
    recipientEmail: string;
    subject: string;
    message: string;
}>({
    recipientEmail: '',
    subject: '',
    message: '',
});
const bulkZipForm = useForm<{
    zip: File | null;
    outputPrefix: string;
}>({
    zip: null,
    outputPrefix: '',
});
const bulkFolderForm = useForm<{
    outputPrefix: string;
    pageFolders: PageFolderPayload[];
}>({
    outputPrefix: '',
    pageFolders: [],
});
const removeReceiptForm = useForm<Record<string, never>>({});
const receiptForm = useForm<{
    placeholders: Record<string, string>;
}>({
    placeholders: {},
});

const {
    bulkFolderClientError,
    bulkFolderInlineError,
    bulkFolderOutputPreview,
    handlePageFolderContainerSelection,
    handlePageFolderSelection,
    pageFoldersForDisplay,
    removeSelectedPageFolder,
    resetPageFolders,
    selectedPageFolders,
    validSelectedPageFolderCount,
} = useBatchPageFolders(bulkFolderForm, toRef(bulkFolderForm, 'outputPrefix'));
const {
    filteredMergeHistory,
    isMergeHistorySelected,
    mergeHistorySearch,
    selectedMergeHistoryKeys,
    selectedMergeHistorySet,
    selectAllMergeHistoryState,
    toggleAllVisibleMergeHistory,
    toggleMergeHistorySelection,
} = useBatchResultSelection(computed(() => props.batch.results));
const batchPollTimeoutId = ref<number | null>(null);
const busyBatchErrorMessage =
    'This batch is already queued or processing. Wait for it to finish before making more changes.';

const isBatchBusy = computed(() =>
    batchProcessingIsActive(props.batch.processingStatus),
);
const hasActiveReceiptJobs = computed(() =>
    props.batch.results.some(
        (record) =>
            isMergedRecord(record) &&
            receiptJobIsActive(record.receiptJobStatus),
    ),
);
const shouldPollBatch = computed(
    () => isBatchBusy.value || hasActiveReceiptJobs.value,
);

const canBulkDeleteMergeHistory = computed(
    () =>
        selectedMergeHistoryKeys.value.length > 0 &&
        !deleteForm.processing &&
        !isBatchBusy.value,
);
const canConfirmDelete = computed(
    () =>
        mergeHistoryForDeletion.value.length > 0 &&
        !deleteForm.processing &&
        !isBatchBusy.value,
);
const deleteDialogTitle = computed(() =>
    mergeHistoryForDeletion.value.length === 1
        ? 'Delete merge result'
        : 'Delete merge results',
);
const deleteDialogDescription = computed(() =>
    mergeHistoryForDeletion.value.length === 1
        ? `Delete ${mergeHistoryForDeletion.value[0]?.fileName ?? 'this merge result'}? Saved PDFs also remove any attached receipt.`
        : `Delete ${mergeHistoryForDeletion.value.length} selected merge results? Saved PDFs also remove any attached receipt.`,
);
const canSubmitBulkZipMerge = computed(
    () =>
        bulkZipForm.zip instanceof File &&
        !bulkZipForm.processing &&
        !isBatchBusy.value,
);
const canSubmitBulkFolderMerge = computed(
    () =>
        bulkFolderClientError.value === null &&
        validSelectedPageFolderCount.value >= 2 &&
        !bulkFolderForm.processing &&
        !isBatchBusy.value,
);
const bulkZipOutputPreview = computed(() =>
    bulkOutputPreview(bulkZipForm.outputPrefix),
);
const bulkZipFieldError = computed(() => {
    const directError = bulkZipForm.errors.zip;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(bulkZipForm.errors).find(([key]) =>
        key.startsWith('zip.'),
    );

    return nestedEntry?.[1] ?? null;
});
const bulkZipOutputPrefixError = computed(() => bulkZipForm.errors.outputPrefix);
const bulkFolderFieldError = computed(() => {
    const directError = bulkFolderForm.errors.pageFolders;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(bulkFolderForm.errors).find(([key]) =>
        key.startsWith('pageFolders.'),
    );

    return nestedEntry?.[1] ?? null;
});
const bulkFolderOutputPrefixError = computed(
    () => bulkFolderForm.errors.outputPrefix,
);
const canSubmitReceipt = computed(() => {
    if (
        mergedPdfForReceipt.value === null ||
        receiptForm.processing ||
        !props.confirmationTemplate.hasTemplate ||
        isBatchBusy.value ||
        receiptJobIsActive(mergedPdfForReceipt.value.receiptJobStatus)
    ) {
        return false;
    }

    return props.confirmationTemplate.placeholders.every((placeholder) =>
        Object.prototype.hasOwnProperty.call(
            receiptForm.placeholders,
            placeholder,
        ),
    );
});
const receiptFieldError = computed(() => {
    const directError = receiptForm.errors.placeholders;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(receiptForm.errors).find(([key]) =>
        key.startsWith('placeholders.'),
    );

    return nestedEntry?.[1] ?? null;
});
const receiptPlaceholderErrors = computed<Record<string, string | undefined>>(
    () =>
        Object.fromEntries(
            props.confirmationTemplate.placeholders.map((placeholder) => [
                placeholder,
                receiptForm.errors[
                    `placeholders.${placeholder}` as keyof typeof receiptForm.errors
                ],
            ]),
        ),
);
const canSendEmail = computed(
    () =>
        mergedPdfForEmail.value !== null &&
        !sendEmailForm.processing &&
        !isBatchBusy.value,
);

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            resetBulkZipForm();
            resetBulkFolderForm();
            resetDeleteForm();
            resetSendEmailForm();
            isBulkZipDialogOpen.value = false;
            isBulkFolderDialogOpen.value = false;
            isDeleteResultsDialogOpen.value = false;
            isSendEmailDialogOpen.value = false;
            isDeleteBatchDialogOpen.value = false;
            toast.success(success);

            return;
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

watch(
    shouldPollBatch,
    (active) => {
        if (!active) {
            stopBatchPolling();

            return;
        }

        scheduleBatchPoll();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    stopBatchPolling();
});

function stopBatchPolling(): void {
    if (batchPollTimeoutId.value === null || typeof window === 'undefined') {
        return;
    }

    window.clearTimeout(batchPollTimeoutId.value);
    batchPollTimeoutId.value = null;
}

function scheduleBatchPoll(): void {
    stopBatchPolling();

    if (!shouldPollBatch.value || typeof window === 'undefined') {
        return;
    }

    batchPollTimeoutId.value = window.setTimeout(() => {
        router.visit(
            `${window.location.pathname}${window.location.search}`,
            {
                only: ['batch'],
                preserveScroll: true,
                preserveState: true,
                replace: true,
                onFinish: () => {
                    if (shouldPollBatch.value) {
                        scheduleBatchPoll();
                    }
                },
            },
        );
    }, 3000);
}

function showBusyBatchToast(): void {
    toast.error(busyBatchErrorMessage);
}

function receiptJobBusyMessage(fileName: string): string {
    return `A receipt update is already queued for ${fileName}.`;
}

function handleBulkZipDialogOpenChange(open: boolean): void {
    if (bulkZipForm.processing) {
        return;
    }

    if (!open) {
        bulkZipForm.clearErrors();
    }

    isBulkZipDialogOpen.value = open;
}

function handleBulkFolderDialogOpenChange(open: boolean): void {
    if (bulkFolderForm.processing) {
        return;
    }

    if (!open) {
        bulkFolderForm.clearErrors();
    }

    isBulkFolderDialogOpen.value = open;
}

function handlePreviewDialogOpenChange(open: boolean): void {
    isPreviewDialogOpen.value = open;

    if (!open) {
        previewedMergedPdf.value = null;
    }
}

function handleFailureDialogOpenChange(open: boolean): void {
    isFailureDialogOpen.value = open;

    if (!open) {
        mergeFailureForDialog.value = null;
    }
}

function handleRemoveReceiptDialogOpenChange(open: boolean): void {
    if (removeReceiptForm.processing) {
        return;
    }

    isRemoveReceiptDialogOpen.value = open;

    if (!open) {
        resetRemoveReceiptForm();
    }
}

function handleReceiptDialogOpenChange(open: boolean): void {
    if (receiptForm.processing) {
        return;
    }

    isReceiptDialogOpen.value = open;

    if (!open) {
        resetReceiptForm();
    }
}

function handleSendEmailDialogOpenChange(open: boolean): void {
    if (sendEmailForm.processing) {
        return;
    }

    isSendEmailDialogOpen.value = open;

    if (!open) {
        resetSendEmailForm();
    }
}

function handleDeleteDialogOpenChange(open: boolean): void {
    if (deleteForm.processing) {
        return;
    }

    isDeleteResultsDialogOpen.value = open;

    if (!open) {
        resetDeleteForm();
    }
}

function handleDeleteBatchDialogOpenChange(open: boolean): void {
    if (deleteBatchForm.processing) {
        return;
    }

    isDeleteBatchDialogOpen.value = open;
}

function openBulkZipDialog(): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    resetBulkZipForm();
    isBulkZipDialogOpen.value = true;
}

function openBulkFolderDialog(): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    resetBulkFolderForm();
    isBulkFolderDialogOpen.value = true;
}

function openDeleteBatchDialog(): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    isDeleteBatchDialogOpen.value = true;
}

function resetBulkZipForm(): void {
    bulkZipForm.reset();
    bulkZipForm.clearErrors();
}

function resetBulkFolderForm(): void {
    resetPageFolders();
    bulkFolderForm.reset();
    bulkFolderForm.clearErrors();
    bulkFolderForm.pageFolders = [];
}

function resetRemoveReceiptForm(): void {
    mergedPdfForReceiptRemoval.value = null;
    removeReceiptForm.clearErrors();
}

function resetDeleteForm(): void {
    mergeHistoryForDeletion.value = [];
    deleteForm.reset();
    deleteForm.clearErrors();
}

function resetReceiptForm(): void {
    mergedPdfForReceipt.value = null;
    receiptForm.reset();
    receiptForm.clearErrors();
}

function resetSendEmailForm(): void {
    mergedPdfForEmail.value = null;
    sendEmailForm.reset();
    sendEmailForm.clearErrors();
}

function handleBulkZipSelected(file: File | null): void {
    bulkZipForm.zip = file;
    bulkZipForm.clearErrors('zip');
}

function openPreviewDialog(record: BatchMergeHistoryRecord): void {
    if (!isMergedRecord(record)) {
        return;
    }

    previewedMergedPdf.value = record;
    isPreviewDialogOpen.value = true;
}

function openFailureDialog(record: BatchMergeHistoryRecord): void {
    if (!isFailureRecord(record)) {
        return;
    }

    mergeFailureForDialog.value = record;
    isFailureDialogOpen.value = true;
}

function openDeleteDialogForRecord(record: BatchMergeHistoryRecord): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    mergeHistoryForDeletion.value = [record];
    deleteForm.clearErrors();
    isDeleteResultsDialogOpen.value = true;
}

function openDeleteDialogForSelection(): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    const selectedMergeHistory = filteredMergeHistory.value.filter((record) =>
        selectedMergeHistorySet.value.has(mergeHistoryRecordKey(record)),
    );

    if (selectedMergeHistory.length === 0) {
        return;
    }

    mergeHistoryForDeletion.value = selectedMergeHistory;
    deleteForm.clearErrors();
    isDeleteResultsDialogOpen.value = true;
}

function openRemoveReceiptDialog(record: BatchMergeHistoryRecord): void {
    if (!isMergedRecord(record)) {
        return;
    }

    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    if (receiptJobIsActive(record.receiptJobStatus)) {
        toast.error(receiptJobBusyMessage(record.fileName));

        return;
    }

    mergedPdfForReceiptRemoval.value = record;
    removeReceiptForm.clearErrors();
    isRemoveReceiptDialogOpen.value = true;
}

function openReceiptDialog(record: BatchMergeHistoryRecord): void {
    if (!isMergedRecord(record)) {
        return;
    }

    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    if (receiptJobIsActive(record.receiptJobStatus)) {
        toast.error(receiptJobBusyMessage(record.fileName));

        return;
    }

    if (!props.confirmationTemplate.hasTemplate) {
        toast.error(
            'Upload the shared receipt template on the main Doc Merge page first.',
        );

        return;
    }

    mergedPdfForReceipt.value = record;
    receiptForm.placeholders = Object.fromEntries(
        props.confirmationTemplate.placeholders.map((placeholder) => [
            placeholder,
            defaultConfirmationPlaceholderValue(record, placeholder),
        ]),
    );
    receiptForm.clearErrors();
    isReceiptDialogOpen.value = true;
}

function updateReceiptPlaceholder(payload: {
    placeholder: string;
    value: string;
}): void {
    receiptForm.placeholders = {
        ...receiptForm.placeholders,
        [payload.placeholder]: payload.value,
    };
}

function openSendEmailDialog(record: BatchMergeHistoryRecord): void {
    if (!isMergedRecord(record)) {
        return;
    }

    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    mergedPdfForEmail.value = record;
    sendEmailForm.recipientEmail = '';
    sendEmailForm.subject = defaultEmailSubject(record);
    sendEmailForm.message = defaultEmailMessage(record);
    sendEmailForm.clearErrors();
    isSendEmailDialogOpen.value = true;
}

function submitBulkZipMerge(): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    if (!(bulkZipForm.zip instanceof File)) {
        return;
    }

    bulkZipForm.post(props.batch.uploadZipUrl, {
        forceFormData: true,
        preserveScroll: true,
    });
}

function submitBulkFolderMerge(): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    if (!canSubmitBulkFolderMerge.value) {
        return;
    }

    bulkFolderForm.pageFolders = selectedPageFolders.value.map((pageFolder) => ({
        name: pageFolder.name,
        number: pageFolder.number ?? 0,
        hasNestedEntries: pageFolder.hasNestedEntries,
        hasInvalidFiles: pageFolder.hasInvalidFiles,
        files: pageFolder.files,
    }));

    bulkFolderForm.post(props.batch.uploadPageFoldersUrl, {
        forceFormData: true,
        preserveScroll: true,
    });
}

function submitReceipt(): void {
    const mergedPdf = mergedPdfForReceipt.value;

    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    if (!mergedPdf) {
        return;
    }

    if (receiptJobIsActive(mergedPdf.receiptJobStatus)) {
        toast.error(receiptJobBusyMessage(mergedPdf.fileName));

        return;
    }

    receiptForm
        .transform((data) => ({
            placeholders: data.placeholders,
        }))
        .post(mergedPdf.receiptStoreUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const success = (page.props as { flash?: FlashState }).flash
                    ?.success;

                if (success) {
                    isReceiptDialogOpen.value = false;
                    resetReceiptForm();
                }
            },
        });
}

function removeReceipt(): void {
    const mergedPdf = mergedPdfForReceiptRemoval.value;

    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    if (
        mergedPdf &&
        receiptJobIsActive(mergedPdf.receiptJobStatus)
    ) {
        toast.error(receiptJobBusyMessage(mergedPdf.fileName));

        return;
    }

    if (!mergedPdf?.receiptRemoveUrl) {
        return;
    }

    removeReceiptForm.delete(mergedPdf.receiptRemoveUrl, {
        preserveScroll: true,
        onSuccess: (page) => {
            const success = (page.props as { flash?: FlashState }).flash
                ?.success;

            if (success) {
                isRemoveReceiptDialogOpen.value = false;
                resetRemoveReceiptForm();
            }
        },
    });
}

function submitDelete(): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    const items = mergeHistoryForDeletion.value.map((record) => ({
        type: record.recordType,
        id: record.id,
    }));
    const deletedKeys = mergeHistoryForDeletion.value.map((record) =>
        mergeHistoryRecordKey(record),
    );

    if (items.length === 0) {
        return;
    }

    deleteForm.items = items;
    deleteForm.delete(docMerge.destroyMany.url(), {
        preserveScroll: true,
        onSuccess: () => {
            selectedMergeHistoryKeys.value = selectedMergeHistoryKeys.value.filter(
                (key) => !deletedKeys.includes(key),
            );
        },
    });
}

function submitSendEmail(): void {
    const mergedPdf = mergedPdfForEmail.value;

    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    if (!mergedPdf) {
        return;
    }

    sendEmailForm.post(mergedPdf.sendEmailUrl, {
        preserveScroll: true,
    });
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

        const nextPlaceholders = { ...receiptForm.placeholders };
        let appliedCount = 0;

        for (const placeholder of props.confirmationTemplate.placeholders) {
            const value = birReceiptPlaceholderValue(placeholder, details);

            if (!value) {
                continue;
            }

            nextPlaceholders[placeholder] = value;
            appliedCount++;
        }

        if (appliedCount === 0) {
            toast.error(
                'This template does not have matching file/date/time placeholders.',
            );

            return;
        }

        receiptForm.placeholders = nextPlaceholders;
        receiptForm.clearErrors();
        toast.success(
            `Filled ${appliedCount} field${appliedCount === 1 ? '' : 's'} from the copied email details.`,
        );
    } catch {
        toast.error('Unable to read the clipboard right now.');
    }
}

function deleteBatch(): void {
    if (isBatchBusy.value) {
        showBusyBatchToast();

        return;
    }

    deleteBatchForm.delete(props.batch.deleteUrl);
}
</script>

<template>
    <Head :title="`Batch: ${props.batch.name}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <BatchHeader
                variant="toolbar"
                :batch="props.batch"
                :delete-batch-processing="deleteBatchForm.processing"
                @open-bulk-folder="openBulkFolderDialog"
                @open-bulk-zip="openBulkZipDialog"
                @open-delete-batch="openDeleteBatchDialog"
            />
        </template>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <BatchHeader
                variant="summary"
                :batch="props.batch"
                :delete-batch-processing="deleteBatchForm.processing"
            />

            <Card class="rounded-3xl">
                <BatchResultsTable
                    :can-bulk-delete="canBulkDeleteMergeHistory"
                    :is-batch-busy="isBatchBusy"
                    :is-record-selected="isMergeHistorySelected"
                    :page-url="props.batch.showUrl"
                    :pagination="props.batch.resultsPagination"
                    :results="filteredMergeHistory"
                    :search="mergeHistorySearch"
                    :selected-count="selectedMergeHistoryKeys.length"
                    :select-all-state="selectAllMergeHistoryState"
                    :total-results="props.batch.results.length"
                    @delete-record="openDeleteDialogForRecord"
                    @open-bulk-delete="openDeleteDialogForSelection"
                    @open-failure="openFailureDialog"
                    @open-preview="openPreviewDialog"
                    @open-receipt="openReceiptDialog"
                    @open-remove-receipt="openRemoveReceiptDialog"
                    @open-send-email="openSendEmailDialog"
                    @toggle-all="toggleAllVisibleMergeHistory"
                    @toggle-record="toggleMergeHistorySelection($event.record, $event.checked)"
                    @update:search="mergeHistorySearch = $event"
                />
            </Card>
        </div>

        <BatchFailureDialog
            :open="isFailureDialogOpen"
            :failure="mergeFailureForDialog"
            @update:open="handleFailureDialogOpenChange"
        />

        <BatchRemoveReceiptDialog
            :open="isRemoveReceiptDialogOpen"
            :merged-pdf="mergedPdfForReceiptRemoval"
            :processing="removeReceiptForm.processing"
            @submit="removeReceipt"
            @update:open="handleRemoveReceiptDialogOpenChange"
        />

        <BatchReceiptDialog
            :open="isReceiptDialogOpen"
            :can-submit="canSubmitReceipt"
            :field-error="receiptFieldError"
            :merged-pdf="mergedPdfForReceipt"
            :placeholder-errors="receiptPlaceholderErrors"
            :placeholders="receiptForm.placeholders"
            :processing="receiptForm.processing"
            :template="props.confirmationTemplate"
            @paste-from-email="pasteReceiptDetailsFromClipboard"
            @submit="submitReceipt"
            @update:open="handleReceiptDialogOpenChange"
            @update:placeholder="updateReceiptPlaceholder"
        />

        <BatchPreviewDialog
            :open="isPreviewDialogOpen"
            :merged-pdf="previewedMergedPdf"
            @update:open="handlePreviewDialogOpenChange"
        />

        <BatchSendEmailDialog
            :open="isSendEmailDialogOpen"
            :can-submit="canSendEmail"
            :file-name="mergedPdfForEmail?.fileName ?? null"
            :message="sendEmailForm.message"
            :processing="sendEmailForm.processing"
            :recipient-email="sendEmailForm.recipientEmail"
            :subject="sendEmailForm.subject"
            :errors="sendEmailForm.errors"
            @submit="submitSendEmail"
            @update:message="sendEmailForm.message = $event"
            @update:open="handleSendEmailDialogOpenChange"
            @update:recipient-email="sendEmailForm.recipientEmail = $event"
            @update:subject="sendEmailForm.subject = $event"
        />

        <BatchDeleteResultsDialog
            :open="isDeleteResultsDialogOpen"
            :can-confirm="canConfirmDelete"
            :description="deleteDialogDescription"
            :processing="deleteForm.processing"
            :title="deleteDialogTitle"
            @submit="submitDelete"
            @update:open="handleDeleteDialogOpenChange"
        />

        <BatchDeleteDialog
            :open="isDeleteBatchDialogOpen"
            :batch-name="props.batch.name"
            :processing="deleteBatchForm.processing"
            @submit="deleteBatch"
            @update:open="handleDeleteBatchDialogOpenChange"
        />

        <BatchBulkFolderDialog
            :open="isBulkFolderDialogOpen"
            :can-submit="canSubmitBulkFolderMerge"
            :field-error="bulkFolderFieldError"
            :inline-error="bulkFolderInlineError"
            :output-prefix="bulkFolderForm.outputPrefix"
            :output-prefix-error="bulkFolderOutputPrefixError"
            :output-preview="bulkFolderOutputPreview"
            :page-folders="pageFoldersForDisplay"
            :processing="bulkFolderForm.processing"
            :progress-percentage="bulkFolderForm.progress?.percentage ?? null"
            :valid-selected-page-folder-count="validSelectedPageFolderCount"
            @remove-page-folder="removeSelectedPageFolder"
            @select-container-folder="handlePageFolderContainerSelection"
            @select-page-folders="handlePageFolderSelection"
            @submit="submitBulkFolderMerge"
            @update:open="handleBulkFolderDialogOpenChange"
            @update:output-prefix="bulkFolderForm.outputPrefix = $event"
        />

        <BatchBulkZipDialog
            :open="isBulkZipDialogOpen"
            :can-submit="canSubmitBulkZipMerge"
            :field-error="bulkZipFieldError"
            :output-prefix="bulkZipForm.outputPrefix"
            :output-prefix-error="bulkZipOutputPrefixError"
            :output-preview="bulkZipOutputPreview"
            :processing="bulkZipForm.processing"
            :progress-percentage="bulkZipForm.progress?.percentage ?? null"
            :zip-file="bulkZipForm.zip"
            @select-file="handleBulkZipSelected"
            @submit="submitBulkZipMerge"
            @update:open="handleBulkZipDialogOpenChange"
            @update:output-prefix="bulkZipForm.outputPrefix = $event"
        />
    </AppLayout>
</template>
