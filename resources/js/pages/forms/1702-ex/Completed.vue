<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Download } from 'lucide-vue-next';
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
const pollTimeoutId = ref<number | null>(null);

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
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
