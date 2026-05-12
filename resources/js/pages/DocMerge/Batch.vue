<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, LoaderCircle, Settings2, Trash2, Upload } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, toRef, watch } from 'vue';
import { toast } from 'vue-sonner';
import BatchBulkFolderDialog from '@/components/doc-merge-batch-components/BatchBulkFolderDialog.vue';
import BatchBulkZipDialog from '@/components/doc-merge-batch-components/BatchBulkZipDialog.vue';
import BatchDeleteDialog from '@/components/doc-merge-batch-components/BatchDeleteDialog.vue';
import BatchDeleteResultsDialog from '@/components/doc-merge-batch-components/BatchDeleteResultsDialog.vue';
import BatchFailureDialog from '@/components/doc-merge-batch-components/BatchFailureDialog.vue';
import BatchPreviewDialog from '@/components/doc-merge-batch-components/BatchPreviewDialog.vue';
import BatchResultsTable from '@/components/doc-merge-batch-components/BatchResultsTable.vue';
import BatchSendEmailDialog from '@/components/doc-merge-batch-components/BatchSendEmailDialog.vue';
import type {
    BatchDetail,
    BatchDownloadExportState,
    BatchMergeHistoryRecord,
    BatchMergedOutput,
    ConfirmationTemplateState,
    DeleteItemPayload,
    FlashState,
    PageFolderPayload,
} from '@/components/doc-merge-batch-components/types';
import { useBatchPageFolders } from '@/components/doc-merge-batch-components/useBatchPageFolders';
import { useBatchResultSelection } from '@/components/doc-merge-batch-components/useBatchResultSelection';
import { batchProcessingIsActive, bulkOutputPreview, defaultEmailMessage, defaultEmailSubject, isFailureRecord, isMergedRecord, mergeHistoryRecordKey, receiptJobIsActive } from '@/components/doc-merge-batch-components/utils';
import { Card } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
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
const isSendEmailDialogOpen = ref(false);
const isDeleteResultsDialogOpen = ref(false);
const isDeleteBatchDialogOpen = ref(false);
const previewedMergedPdf = ref<BatchMergedOutput | null>(null);
const mergeFailureForDialog = ref<Extract<BatchMergeHistoryRecord, { recordType: 'merge_failure' }> | null>(
    null,
);
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
const isChunkUploadProcessing = ref(false);
const chunkUploadProgressPercentage = ref<number | null>(null);

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
const downloadExportState = ref<BatchDownloadExportState>(props.batch.downloadExportState);
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
    () => isBatchBusy.value || hasActiveReceiptJobs.value || isDownloadExportBusy.value,
);
const isBatchQueued = computed(
    () => props.batch.processingStatus === 'queued',
);
const isBatchProcessing = computed(
    () => props.batch.processingStatus === 'processing',
);
const isBatchFailed = computed(
    () => props.batch.processingStatus === 'failed',
);
const isDownloadExportQueued = computed(
    () => downloadExportState.value.status === 'queued',
);
const isDownloadExportProcessing = computed(
    () => downloadExportState.value.status === 'processing',
);
const isDownloadExportFailed = computed(
    () => downloadExportState.value.status === 'failed',
);
const isDownloadExportReady = computed(
    () => downloadExportState.value.status === 'ready',
);
const isDownloadExportBusy = computed(
    () => isDownloadExportQueued.value || isDownloadExportProcessing.value,
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
        !isChunkUploadProcessing.value &&
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

    batchPollTimeoutId.value = window.setTimeout(async () => {
        if (isDownloadExportBusy.value) {
            try {
                const response = await fetch(props.batch.downloadStateUrl, {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (response.ok) {
                    downloadExportState.value = (await response.json()) as BatchDownloadExportState;
                }
            } catch {
                // Keep polling.
            }
        }

        router.visit(`${window.location.pathname}${window.location.search}`, {
            only: ['batch'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
            onFinish: () => {
                if (shouldPollBatch.value) {
                    scheduleBatchPoll();
                }
            },
        });
    }, 3000);
}

watch(
    () => props.batch.downloadExportState,
    (state) => {
        if (!isDownloadExportBusy.value) {
            downloadExportState.value = state;
        }
    },
);

function showBusyBatchToast(): void {
    toast.error(busyBatchErrorMessage);
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

async function queueBatchDownloadZip(): Promise<void> {
    if (isDownloadExportBusy.value) {
        toast.error('A batch ZIP export is already processing.');
        return;
    }

    try {
        const response = await fetch(props.batch.downloadQueueUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN':
                    document.querySelector('meta[name=\"csrf-token\"]')?.getAttribute('content') ?? '',
            },
            body: JSON.stringify({}),
        });

        const payload = (await response.json()) as { message?: string; export_state?: BatchDownloadExportState };
        if (!response.ok) {
            if (payload.export_state) {
                downloadExportState.value = payload.export_state;
            }
            throw new Error(payload.message ?? 'Unable to queue batch ZIP download.');
        }

        downloadExportState.value = payload.export_state ?? downloadExportState.value;
        toast.success(payload.message ?? 'Batch ZIP export queued.');
        scheduleBatchPoll();
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to queue batch ZIP download.');
    }
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
    chunkUploadProgressPercentage.value = null;
}

function resetDeleteForm(): void {
    mergeHistoryForDeletion.value = [];
    deleteForm.reset();
    deleteForm.clearErrors();
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

async function submitBulkFolderMerge(): Promise<void> {
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

    if (props.batch.uploadPageFoldersChunkInitUrl) {
        await submitBulkFolderMergeChunked();
        return;
    }

    bulkFolderForm.post(props.batch.uploadPageFoldersUrl, {
        forceFormData: true,
        preserveScroll: true,
    });
}

type UploadManifestFile = {
    fileKey: string;
    displayName: string;
    size: number;
    mimeType: string;
    file: File;
};

type UploadManifestFolder = {
    name: string;
    number: number;
    hasNestedEntries: boolean;
    hasInvalidFiles: boolean;
    files: UploadManifestFile[];
};

const CHUNK_SIZE_BYTES = 5 * 1024 * 1024;
const FILE_UPLOAD_CONCURRENCY = 2;
const CHUNK_UPLOAD_RETRIES = 3;

function chunkUploadUrl(template: string, uploadId: string): string {
    return template.replace('__UPLOAD_ID__', encodeURIComponent(uploadId));
}

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function normalizeErrorMessage(payload: unknown, fallback: string): string {
    if (typeof payload === 'object' && payload !== null) {
        const message = (payload as { message?: unknown }).message;
        if (typeof message === 'string' && message.trim() !== '') {
            return message;
        }

        const errors = (payload as { errors?: Record<string, string[] | string> }).errors;
        if (errors && typeof errors === 'object') {
            for (const value of Object.values(errors)) {
                if (Array.isArray(value) && typeof value[0] === 'string') {
                    return value[0];
                }
                if (typeof value === 'string') {
                    return value;
                }
            }
        }
    }

    return fallback;
}

function buildUploadManifest(): UploadManifestFolder[] {
    return selectedPageFolders.value.map((pageFolder) => ({
        name: pageFolder.name,
        number: pageFolder.number ?? 0,
        hasNestedEntries: pageFolder.hasNestedEntries,
        hasInvalidFiles: pageFolder.hasInvalidFiles,
        files: pageFolder.files.map((file, index) => ({
            fileKey: `${pageFolder.key}-${index}-${file.name}-${file.size}`,
            displayName: file.name,
            size: file.size,
            mimeType: file.type || 'application/pdf',
            file,
        })),
    }));
}

async function requestJson(
    url: string,
    init: RequestInit,
    fallbackError: string,
): Promise<unknown> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...init.headers,
        },
        ...init,
    });
    const payload = (await response.json().catch(() => ({}))) as unknown;

    if (!response.ok) {
        throw new Error(normalizeErrorMessage(payload, fallbackError));
    }

    return payload;
}

function delay(ms: number): Promise<void> {
    return new Promise((resolve) => {
        window.setTimeout(resolve, ms);
    });
}

async function uploadOneFileInChunks(
    uploadId: string,
    uploadFile: UploadManifestFile,
    onChunkUploaded: () => void,
): Promise<void> {
    const totalChunks = Math.max(1, Math.ceil(uploadFile.size / CHUNK_SIZE_BYTES));

    for (let chunkIndex = 0; chunkIndex < totalChunks; chunkIndex++) {
        const start = chunkIndex * CHUNK_SIZE_BYTES;
        const end = Math.min(start + CHUNK_SIZE_BYTES, uploadFile.size);
        const blob = uploadFile.file.slice(start, end);

        let attempt = 0;
        while (attempt < CHUNK_UPLOAD_RETRIES) {
            try {
                const formData = new FormData();
                formData.append('fileKey', uploadFile.fileKey);
                formData.append('chunkIndex', String(chunkIndex));
                formData.append('chunk', blob, `${uploadFile.displayName}.part-${chunkIndex}`);

                await requestJson(
                    chunkUploadUrl(props.batch.uploadPageFoldersChunkChunkUrlTemplate, uploadId),
                    {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken(),
                        },
                        body: formData,
                    },
                    'Unable to upload one of the file chunks.',
                );
                onChunkUploaded();
                break;
            } catch (error) {
                attempt++;
                if (attempt >= CHUNK_UPLOAD_RETRIES) {
                    throw error;
                }
                await delay(300 * (2 ** (attempt - 1)));
            }
        }
    }

    await requestJson(
        chunkUploadUrl(props.batch.uploadPageFoldersChunkCompleteUrlTemplate, uploadId),
        {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                fileKey: uploadFile.fileKey,
                totalChunks,
                expectedSize: uploadFile.size,
            }),
        },
        `Unable to complete upload for ${uploadFile.displayName}.`,
    );
}

async function runWithConcurrency<T>(
    items: T[],
    concurrency: number,
    worker: (item: T) => Promise<void>,
): Promise<void> {
    let currentIndex = 0;

    const runners = Array.from({ length: Math.max(1, concurrency) }, async () => {
        while (currentIndex < items.length) {
            const index = currentIndex;
            currentIndex++;
            await worker(items[index] as T);
        }
    });

    await Promise.all(runners);
}

async function submitBulkFolderMergeChunked(): Promise<void> {
    isChunkUploadProcessing.value = true;
    chunkUploadProgressPercentage.value = 0;
    bulkFolderForm.clearErrors();
    let uploadId: string | null = null;

    try {
        const manifestFolders = buildUploadManifest();
        const allFiles = manifestFolders.flatMap((folder) => folder.files);

        const initPayload = await requestJson(
            props.batch.uploadPageFoldersChunkInitUrl,
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    outputPrefix: bulkFolderForm.outputPrefix || null,
                    pageFolders: manifestFolders.map((folder) => ({
                        name: folder.name,
                        number: folder.number,
                        hasNestedEntries: folder.hasNestedEntries,
                        hasInvalidFiles: folder.hasInvalidFiles,
                        files: folder.files.map((file) => ({
                            fileKey: file.fileKey,
                            displayName: file.displayName,
                            size: file.size,
                            mimeType: file.mimeType,
                        })),
                    })),
                }),
            },
            'Unable to initialize chunk upload.',
        ) as { uploadId: string; chunkSize?: number };

        uploadId = initPayload.uploadId;

        const totalChunks = allFiles.reduce(
            (sum, file) => sum + Math.max(1, Math.ceil(file.size / CHUNK_SIZE_BYTES)),
            0,
        );
        let completedChunks = 0;
        const updateProgress = (): void => {
            completedChunks++;
            chunkUploadProgressPercentage.value = Math.min(
                100,
                Math.round((completedChunks / totalChunks) * 100),
            );
        };

        await runWithConcurrency(allFiles, FILE_UPLOAD_CONCURRENCY, async (file) => {
            await uploadOneFileInChunks(uploadId as string, file, updateProgress);
        });

        await requestJson(
            chunkUploadUrl(props.batch.uploadPageFoldersChunkFinalizeUrlTemplate, uploadId),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    outputPrefix: bulkFolderForm.outputPrefix || null,
                }),
            },
            'Unable to finalize chunk upload.',
        );

        toast.success('Batch processing queued. Results will refresh automatically.');
        resetBulkFolderForm();
        isBulkFolderDialogOpen.value = false;
        router.visit(`${window.location.pathname}${window.location.search}`, {
            only: ['batch', 'flash'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    } catch (error) {
        const message = error instanceof Error ? error.message : 'Unable to upload page folders right now.';
        bulkFolderForm.setError('pageFolders', message);
        toast.error(message);

        if (uploadId !== null) {
            await fetch(chunkUploadUrl(props.batch.uploadPageFoldersChunkDestroyUrlTemplate, uploadId), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
            }).catch(() => undefined);
        }
    } finally {
        isChunkUploadProcessing.value = false;
        if (!bulkFolderForm.processing) {
            chunkUploadProgressPercentage.value = null;
        }
    }
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

function selectedMergedRecords(): BatchMergedOutput[] {
    return filteredMergeHistory.value.filter((record): record is BatchMergedOutput =>
        isMergedRecord(record) && selectedMergeHistorySet.value.has(mergeHistoryRecordKey(record)),
    );
}

function bulkDownloadSelectedMerged(): void {
    const selected = selectedMergedRecords();

    if (selected.length === 0) {
        toast.error('Select at least one merged file to download.');
        return;
    }

    selected.forEach((record) => {
        if (!record.downloadUrl) {
            return;
        }

        const anchor = document.createElement('a');
        anchor.href = record.downloadUrl;
        anchor.target = '_blank';
        anchor.rel = 'noopener noreferrer';
        document.body.appendChild(anchor);
        anchor.click();
        anchor.remove();
    });
}

async function bulkSendEmailSelectedMerged(): Promise<void> {
    const selected = selectedMergedRecords();

    if (selected.length === 0) {
        toast.error('Select at least one merged file to send.');
        return;
    }

    const recipientEmail = window.prompt('Recipient email for selected files:')?.trim() ?? '';

    if (recipientEmail === '') {
        return;
    }

    const subject = window.prompt('Email subject (optional):')?.trim() ?? '';
    const message = window.prompt('Email message (optional):')?.trim() ?? '';
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    let successCount = 0;

    for (const record of selected) {
        try {
            const response = await fetch(record.sendEmailUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json, text/html',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    recipientEmail,
                    subject: subject !== '' ? subject : defaultEmailSubject(record),
                    message: message !== '' ? message : defaultEmailMessage(record),
                }),
            });

            if (response.ok) {
                successCount++;
            }
        } catch {
            // Continue sending the rest.
        }
    }

    if (successCount === 0) {
        toast.error('Unable to queue emails for selected files.');
        return;
    }

    toast.success(
        successCount === selected.length
            ? `Queued ${successCount} email${successCount === 1 ? '' : 's'}.`
            : `Queued ${successCount} of ${selected.length} emails.`,
    );
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
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <div class="flex flex-col gap-5 p-6 md:flex-row md:items-center md:justify-between md:p-8">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.3em] text-teal-700 uppercase">
                            Doc Merge Batch
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">
                            {{ props.batch.name }}
                        </h1>
                        <p class="max-w-3xl text-sm leading-7 text-muted-foreground">
                            Upload page folders or ZIP sources, review merge results, and manage receipts and email sending for this batch.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 justify-end">
                        <Button type="button" variant="outline" :disabled="isBatchBusy" @click="openBulkFolderDialog">
                            <Upload class="mr-2 size-4" />
                            Bulk Merge Folders
                        </Button>
                        <Button type="button" variant="outline" :disabled="isBatchBusy" @click="openBulkZipDialog">
                            <Upload class="mr-2 size-4" />
                            Bulk Merge ZIP
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button type="button" variant="outline">
                                    <Settings2 class="mr-2 size-4" />
                                    Settings
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-52">
                                <DropdownMenuItem @select.prevent="queueBatchDownloadZip">
                                    <span class="flex w-full items-center gap-2">
                                        <Download class="size-4" />
                                        Download Batch ZIP
                                    </span>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    variant="destructive"
                                    :disabled="deleteBatchForm.processing || isBatchBusy"
                                    @select="openDeleteBatchDialog"
                                >
                                    <Trash2 class="size-4" />
                                    Delete Batch
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </Card>

            <Alert v-if="isBatchQueued || isBatchProcessing">
                <LoaderCircle class="size-4 animate-spin" />
                <AlertTitle>Batch Processing In Progress</AlertTitle>
                <AlertDescription>
                    {{
                        isBatchQueued
                            ? 'Your batch is queued and will start shortly.'
                            : 'Your batch is currently processing. Results will refresh automatically when ready.'
                    }}
                </AlertDescription>
            </Alert>

            <Alert v-if="isBatchFailed" variant="destructive">
                <AlertTitle>Batch Processing Failed</AlertTitle>
                <AlertDescription>
                    {{ props.batch.processingError || 'The latest batch run failed. Please try again.' }}
                </AlertDescription>
            </Alert>

            <Alert v-if="isDownloadExportQueued || isDownloadExportProcessing">
                <LoaderCircle class="size-4 animate-spin" />
                <AlertTitle>Batch ZIP Export In Progress</AlertTitle>
                <AlertDescription>
                    {{
                        isDownloadExportQueued
                            ? 'Your batch ZIP export is queued and will start shortly.'
                            : 'Your batch ZIP export is processing. We will keep checking until it is ready.'
                    }}
                </AlertDescription>
            </Alert>

            <Alert v-if="isDownloadExportFailed" variant="destructive">
                <AlertTitle>Batch ZIP Export Failed</AlertTitle>
                <AlertDescription>
                    {{ downloadExportState.error || 'The batch ZIP could not be prepared right now.' }}
                </AlertDescription>
            </Alert>

            <Alert v-if="isDownloadExportReady">
                <AlertTitle>Batch ZIP Ready</AlertTitle>
                <AlertDescription class="flex flex-wrap items-center gap-3">
                    <span>
                        {{
                            downloadExportState.itemCount
                                ? `${downloadExportState.itemCount} merged file${downloadExportState.itemCount === 1 ? '' : 's'} ready.`
                                : 'Your batch ZIP is ready to download.'
                        }}
                    </span>
                    <Button
                        v-if="downloadExportState.downloadUrl"
                        as-child
                        size="sm"
                        variant="secondary"
                    >
                        <a :href="downloadExportState.downloadUrl">
                            <Download class="mr-2 size-4" />
                            Download ZIP
                        </a>
                    </Button>
                </AlertDescription>
            </Alert>

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
                    @bulk-download-selected="bulkDownloadSelectedMerged"
                    @bulk-send-email-selected="bulkSendEmailSelectedMerged"
                    @open-failure="openFailureDialog"
                    @open-preview="openPreviewDialog"
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
            :processing="bulkFolderForm.processing || isChunkUploadProcessing"
            :progress-percentage="chunkUploadProgressPercentage ?? bulkFolderForm.progress?.percentage ?? null"
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
