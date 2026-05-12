<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Copy, Download, LoaderCircle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Form1702ExCancelCompletedBulkDialog from '@/components/form-1702-ex-components/Form1702ExCancelCompletedBulkDialog.vue';
import Form1702ExCancelCompletedDialog from '@/components/form-1702-ex-components/Form1702ExCancelCompletedDialog.vue';
import CompletedRowsTable from '@/components/form-1702-ex-components/CompletedRowsTable.vue';
import Form1702ExRecipientDialog from '@/components/form-1702-ex-components/Form1702ExRecipientDialog.vue';
import Form1702ExCompletedSendDialog from '@/components/form-1702-ex-components/Form1702ExCompletedSendDialog.vue';
import type {
    Form1702ExBatchRow,
    Form1702ExCompletedPageProps,
} from '@/components/form-1702-ex-components/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
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
import AppLayout from '@/layouts/AppLayout.vue';
import forms from '@/routes/forms';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<Form1702ExCompletedPageProps>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: '1702-EX',
        href: forms.form1702ex.index(),
    },
    {
        title: 'Completed Files',
        href: props.completedFilesUrl,
    },
];

const sendEmailForm = useForm<{
    subject: string;
    message: string;
    extraAttachment: File | null;
}>({
    subject: '',
    message: '',
    extraAttachment: null,
});
const bulkSendForm = useForm<{
    rowIds: string[];
}>({
    rowIds: [],
});
const recipientForm = useForm<{
    recipientEmail: string;
}>({
    recipientEmail: '',
});
const cancelForm = useForm({});
const bulkCancelForm = useForm<{
    rowIds: string[];
}>({
    rowIds: [],
});
const pendingSendRow = ref<Form1702ExBatchRow | null>(null);
const pendingRecipientRow = ref<Form1702ExBatchRow | null>(null);
const pendingCancelRow = ref<Form1702ExBatchRow | null>(null);
const pendingBulkCancelRowIds = ref<string[]>([]);
const isSendDialogOpen = ref(false);
const isRecipientDialogOpen = ref(false);
const isCancelDialogOpen = ref(false);
const isBulkCancelDialogOpen = ref(false);
const isSignatureDialogOpen = ref(false);
const isSignaturePreviewDialogOpen = ref(false);
const pollTimeoutId = ref<number | null>(null);
const signatureUploadInput = ref<HTMLInputElement | null>(null);
const signatureUploadFile = ref<File | null>(null);
const signatureUploadProcessing = ref(false);
const signatureUploadError = ref<string | null>(null);
const pendingSignatureRow = ref<Form1702ExBatchRow | null>(null);
const pendingSignaturePreviewRow = ref<Form1702ExBatchRow | null>(null);
const uploadedSignaturePath = ref<string | null>(null);
const uploadedSignatureUrl = ref<string | null>(null);

const canSubmitSend = computed(
    () =>
        pendingSendRow.value !== null
        && !!pendingSendRow.value.recipientEmail
        && !sendEmailForm.processing,
);
const canSubmitRecipient = computed(
    () => pendingRecipientRow.value !== null && !recipientForm.processing,
);
const isExportBusy = computed(
    () => props.exportState.status === 'queued' || props.exportState.status === 'processing',
);
const readyExportDownloadUrl = computed(
    () => props.exportState.downloadUrl ?? forms.form1702ex.completed.download.file().url,
);

watch(
    () => [props.flash.success, props.flash.error, props.exportState.error] as const,
    ([success, error, exportError]) => {
        if (success) {
            isSendDialogOpen.value = false;
            pendingSendRow.value = null;
            isRecipientDialogOpen.value = false;
            pendingRecipientRow.value = null;
            isCancelDialogOpen.value = false;
            pendingCancelRow.value = null;
            isBulkCancelDialogOpen.value = false;
            pendingBulkCancelRowIds.value = [];
            sendEmailForm.reset();
            sendEmailForm.clearErrors();
            recipientForm.reset();
            recipientForm.clearErrors();
            bulkSendForm.reset();
            bulkSendForm.clearErrors();
            cancelForm.reset();
            cancelForm.clearErrors();
            bulkCancelForm.reset();
            bulkCancelForm.clearErrors();
            toast.success(success);
        }

        if (error) {
            toast.error(error);
        }

        if (exportError && props.exportState.status === 'failed') {
            toast.error(exportError);
        }
    },
    { immediate: true },
);

watch(
    () => props.exportState.status,
    (status) => {
        if (status !== 'queued' && status !== 'processing') {
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

const DEFAULT_FORM_TYPE = '1702-EX';
const DEFAULT_EMAIL_FOOTER =
    'Please do not reply to this message. If you have any concerns, please contact:';

function defaultSubject(row: Form1702ExBatchRow): string {
    return `1702EX - ${row.taxpayerName}`;
}

function defaultMessage(row: Form1702ExBatchRow): string {
    return [
        `Good day! Attached is the ${DEFAULT_FORM_TYPE} with the confirmation for ${row.taxpayerName}. Thank you!`,
        '',
        DEFAULT_EMAIL_FOOTER,
    ].join('\n');
}

function openSendEmail(row: Form1702ExBatchRow): void {
    if (!row.recipientEmail || !row.sendEmailUrl) {
        toast.error('This completed row has no recipients.');

        return;
    }

    pendingSendRow.value = row;
    sendEmailForm.subject = defaultSubject(row);
    sendEmailForm.message = defaultMessage(row);
    sendEmailForm.extraAttachment = null;
    sendEmailForm.clearErrors();
    isSendDialogOpen.value = true;
}

function openRecipientEditor(row: Form1702ExBatchRow): void {
    pendingRecipientRow.value = row;
    recipientForm.recipientEmail = row.recipientEmail ?? '';
    recipientForm.clearErrors();
    isRecipientDialogOpen.value = true;
}

function submitSendEmail(): void {
    if (!pendingSendRow.value?.sendEmailUrl) {
        return;
    }

    sendEmailForm.post(pendingSendRow.value.sendEmailUrl, {
        forceFormData: true,
        preserveScroll: true,
    });
}

function submitRecipient(): void {
    if (!pendingRecipientRow.value?.updateRecipientUrl) {
        return;
    }

    recipientForm.patch(pendingRecipientRow.value.updateRecipientUrl, {
        preserveScroll: true,
    });
}

function selectExtraAttachment(file: File | null): void {
    sendEmailForm.extraAttachment = file;
    sendEmailForm.clearErrors('extraAttachment');
}

function requestBulkSend(rowIds: string[]): void {
    bulkSendForm.rowIds = rowIds;
    bulkSendForm.post(props.completedBulkSendUrl, {
        preserveScroll: true,
    });
}

function requestBulkDownload(rowIds: string[]): void {
    router.get(
        forms.form1702ex.completed.download().url,
        {
            page: props.pagination.currentPage,
            search: props.filters.search || undefined,
            sort: props.filters.sort,
            direction: props.filters.direction,
            rowIds: rowIds.length > 0 ? rowIds : undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}

function openCancelDialog(row: Form1702ExBatchRow): void {
    if (!row.cancelUrl) {
        return;
    }

    pendingCancelRow.value = row;
    isCancelDialogOpen.value = true;
}

function openSignatureDialogForRow(row: Form1702ExBatchRow): void {
    pendingSignatureRow.value = row;
    signatureUploadFile.value = null;
    signatureUploadError.value = null;
    isSignatureDialogOpen.value = true;
}

function openSignaturePreviewDialogForRow(row: Form1702ExBatchRow): void {
    if (!row.signatureApplied || !row.signaturePreviewUrl) {
        return;
    }

    pendingSignaturePreviewRow.value = row;
    isSignaturePreviewDialogOpen.value = true;
}

function triggerSignatureFilePicker(): void {
    signatureUploadInput.value?.click();
}

function onSignatureFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement | null;

    signatureUploadFile.value = input?.files?.[0] ?? null;
    signatureUploadError.value = null;
}

function resetSignatureDialogState(): void {
    signatureUploadFile.value = null;
    signatureUploadError.value = null;
    pendingSignatureRow.value = null;
    isSignatureDialogOpen.value = false;

    if (signatureUploadInput.value) {
        signatureUploadInput.value.value = '';
    }
}

async function submitSignatureUpload(): Promise<void> {
    if (!pendingSignatureRow.value?.signatureUploadUrl) {
        signatureUploadError.value = 'This row has no signature upload URL.';

        return;
    }

    if (!(signatureUploadFile.value instanceof File)) {
        signatureUploadError.value = 'Choose a PNG, JPG, or WEBP signature image first.';

        return;
    }

    const formData = new FormData();
    formData.append('signature_file', signatureUploadFile.value);
    signatureUploadProcessing.value = true;
    signatureUploadError.value = null;

    try {
        const response = await fetch(pendingSignatureRow.value.signatureUploadUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN':
                    document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
            body: formData,
        });

        const payload = (await response.json()) as {
            message?: string;
            errors?: {
                signature_file?: string[];
            };
            signaturePath?: string;
            signatureUrl?: string;
        };

        if (!response.ok) {
            signatureUploadError.value =
                payload.errors?.signature_file?.[0]
                ?? payload.message
                ?? 'Unable to upload signature.';

            return;
        }

        uploadedSignaturePath.value = payload.signaturePath ?? null;
        uploadedSignatureUrl.value = payload.signatureUrl ?? null;
        toast.success(payload.message ?? 'Signature uploaded and row regeneration queued.');
        resetSignatureDialogState();
        router.reload({
            only: ['rows', 'pagination', 'filters', 'flash'],
            preserveScroll: true,
            preserveState: true,
        });
    } catch {
        signatureUploadError.value = 'Unable to upload signature right now.';
    } finally {
        signatureUploadProcessing.value = false;
    }
}

async function copySignaturePath(): Promise<void> {
    const value = uploadedSignaturePath.value ?? uploadedSignatureUrl.value;

    if (!value) {
        toast.error('No signature key is available to copy yet.');

        return;
    }

    try {
        await navigator.clipboard.writeText(value);
        toast.success('Signature key copied. Paste it into Excel signature column.');
    } catch {
        toast.error('Clipboard copy failed. Copy the signature key manually.');
    }
}

function openBulkCancelDialog(rowIds: string[]): void {
    pendingBulkCancelRowIds.value = Array.from(new Set(rowIds));
    bulkCancelForm.rowIds = pendingBulkCancelRowIds.value;
    bulkCancelForm.clearErrors();
    isBulkCancelDialogOpen.value = true;
}

function submitCancel(): void {
    if (!pendingCancelRow.value?.cancelUrl) {
        return;
    }

    cancelForm.delete(pendingCancelRow.value.cancelUrl, {
        preserveScroll: true,
        data: {
            page: props.pagination.currentPage,
            search: props.filters.search,
            sort: props.filters.sort,
            direction: props.filters.direction,
        },
    });
}

function submitBulkCancel(): void {
    bulkCancelForm.rowIds = pendingBulkCancelRowIds.value;
    bulkCancelForm.delete(props.completedBulkCancelUrl, {
        preserveScroll: true,
        data: {
            rowIds: bulkCancelForm.rowIds,
            page: props.pagination.currentPage,
            search: props.filters.search,
            sort: props.filters.sort,
            direction: props.filters.direction,
        },
    });
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
            only: ['flash', 'exportState'],
            preserveState: true,
            onFinish: () => {
                pollTimeoutId.value = null;

                if (props.exportState.status === 'queued' || props.exportState.status === 'processing') {
                    schedulePoll();
                }
            },
        });
    }, 3000);
}
</script>

<template>
    <Head title="1702-EX Completed Files" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <Form1702ExRecipientDialog
            :can-submit="canSubmitRecipient"
            :errors="recipientForm.errors"
            :open="isRecipientDialogOpen"
            :processing="recipientForm.processing"
            :recipient-email="recipientForm.recipientEmail"
            :row="pendingRecipientRow"
            @submit="submitRecipient"
            @update:open="isRecipientDialogOpen = $event"
            @update:recipient-email="recipientForm.recipientEmail = $event"
        />
        <Form1702ExCompletedSendDialog
            :can-submit="canSubmitSend"
            :extra-attachment="sendEmailForm.extraAttachment"
            :message="sendEmailForm.message"
            :open="isSendDialogOpen"
            :processing="sendEmailForm.processing"
            :row="pendingSendRow"
            :subject="sendEmailForm.subject"
            :errors="sendEmailForm.errors"
            @select-extra-attachment="selectExtraAttachment"
            @submit="submitSendEmail"
            @update:message="sendEmailForm.message = $event"
            @update:open="isSendDialogOpen = $event"
            @update:subject="sendEmailForm.subject = $event"
        />
        <Form1702ExCancelCompletedDialog
            :open="isCancelDialogOpen"
            :processing="cancelForm.processing"
            :row="pendingCancelRow"
            @submit="submitCancel"
            @update:open="isCancelDialogOpen = $event"
        />
        <Form1702ExCancelCompletedBulkDialog
            :open="isBulkCancelDialogOpen"
            :processing="bulkCancelForm.processing"
            :row-count="pendingBulkCancelRowIds.length"
            @submit="submitBulkCancel"
            @update:open="isBulkCancelDialogOpen = $event"
        />

        <Dialog
            :open="isSignatureDialogOpen"
            @update:open="(open) => { if (!signatureUploadProcessing) { isSignatureDialogOpen = open; if (!open) resetSignatureDialogState(); } }"
        >
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add signature</DialogTitle>
                    <DialogDescription>
                        Upload a signature image for
                        <span class="font-medium text-foreground">
                            {{ pendingSignatureRow?.taxpayerName ?? 'the selected row' }}
                        </span>.
                    </DialogDescription>
                </DialogHeader>

                <div class="space-y-3">
                    <input
                        ref="signatureUploadInput"
                        type="file"
                        class="hidden"
                        accept=".png,.jpg,.jpeg,.webp,image/png,image/jpeg,image/webp"
                        @change="onSignatureFileSelected"
                    />
                    <Button type="button" variant="outline" class="w-full" @click="triggerSignatureFilePicker">
                        Choose signature image
                    </Button>
                    <p v-if="signatureUploadFile" class="text-sm text-muted-foreground">
                        {{ signatureUploadFile.name }}
                    </p>
                    <p v-if="signatureUploadError" class="text-sm text-destructive">
                        {{ signatureUploadError }}
                    </p>

                    <div v-if="uploadedSignaturePath || uploadedSignatureUrl" class="rounded-lg border p-3">
                        <Label class="text-xs uppercase text-muted-foreground">Latest signature key</Label>
                        <div class="mt-2 flex items-center gap-2">
                            <Input
                                :model-value="uploadedSignaturePath ?? uploadedSignatureUrl ?? ''"
                                readonly
                            />
                            <Button type="button" variant="outline" size="icon" @click="copySignaturePath">
                                <Copy class="size-4" />
                                <span class="sr-only">Copy signature key</span>
                            </Button>
                        </div>
                    </div>
                </div>

                <DialogFooter>
                    <Button type="button" variant="secondary" :disabled="signatureUploadProcessing" @click="resetSignatureDialogState">
                        Cancel
                    </Button>
                    <Button type="button" :disabled="signatureUploadProcessing || signatureUploadFile === null" @click="submitSignatureUpload">
                        <LoaderCircle v-if="signatureUploadProcessing" class="mr-2 size-4 animate-spin" />
                        {{ signatureUploadProcessing ? 'Uploading...' : 'Upload signature' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="isSignaturePreviewDialogOpen" @update:open="isSignaturePreviewDialogOpen = $event">
            <DialogContent class="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Signature Preview</DialogTitle>
                    <DialogDescription>
                        {{ pendingSignaturePreviewRow?.taxpayerName ?? '' }}
                    </DialogDescription>
                </DialogHeader>

                <div class="flex min-h-[200px] items-center justify-center rounded-lg border bg-muted/20 p-4">
                    <img
                        v-if="pendingSignaturePreviewRow?.signaturePreviewUrl"
                        :src="pendingSignaturePreviewRow.signaturePreviewUrl"
                        alt="Row signature preview"
                        class="max-h-[360px] max-w-full object-contain"
                    />
                </div>
            </DialogContent>
        </Dialog>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardContent class="flex flex-col gap-5 p-6 md:flex-row md:items-center md:justify-between md:p-8">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.3em] text-teal-700 uppercase">
                            1702-EX Workspace
                        </p>
                        <h1 class="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">
                            Completed Files
                        </h1>
                        <p class="max-w-3xl text-sm leading-7 text-muted-foreground">
                            Review final PDFs that already include their receipt,
                            then preview, download, or send them by email.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-if="props.rows.length > 0"
                            type="button"
                            variant="secondary"
                            :disabled="isExportBusy"
                            @click="requestBulkDownload([])"
                        >
                            <Download class="mr-2 size-4" />
                            {{
                                isExportBusy
                                    ? 'Preparing ZIP...'
                                    : 'Download all ZIP'
                            }}
                        </Button>
                        <Button
                            v-if="props.exportState.status === 'ready' && readyExportDownloadUrl"
                            as-child
                            type="button"
                        >
                            <a :href="readyExportDownloadUrl">
                                <Download class="mr-2 size-4" />
                                Download ZIP{{ props.exportState.rowCount ? ` (${props.exportState.rowCount})` : '' }}
                            </a>
                        </Button>
                        <Button type="button" variant="outline" @click="router.get(props.indexUrl)">
                            <ArrowLeft class="mr-2 size-4" />
                            Back to workspace
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-3xl">
                <CardContent>
                    <CompletedRowsTable
                        :bulk-send-processing="bulkSendForm.processing"
                        :filters="props.filters"
                        :page-url="props.completedFilesUrl"
                        :pagination="props.pagination"
                        :rows="props.rows"
                        :cancel-processing="cancelForm.processing || bulkCancelForm.processing"
                        :download-processing="isExportBusy"
                        :single-send-processing="sendEmailForm.processing"
                        @cancel-row="openCancelDialog"
                        @open-recipient-editor="openRecipientEditor"
                        @request-bulk-cancel="openBulkCancelDialog"
                        @request-bulk-download="requestBulkDownload"
                        @open-send-email="openSendEmail"
                        @request-bulk-send="requestBulkSend"
                        @open-signature="openSignatureDialogForRow"
                        @open-signature-preview="openSignaturePreviewDialogForRow"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
