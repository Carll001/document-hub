<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CompletedRowsTable from '@/components/form-1702-ex-components/CompletedRowsTable.vue';
import Form1702ExCompletedSendDialog from '@/components/form-1702-ex-components/Form1702ExCompletedSendDialog.vue';
import type {
    Form1702ExBatchRow,
    Form1702ExCompletedPageProps,
} from '@/components/form-1702-ex-components/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import forms from '@/routes/forms';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<Form1702ExCompletedPageProps>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: '1702-EX',
        href: forms['1702Ex'].index(),
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
const pendingSendRow = ref<Form1702ExBatchRow | null>(null);
const isSendDialogOpen = ref(false);

const canSubmitSend = computed(
    () =>
        pendingSendRow.value !== null
        && !!pendingSendRow.value.recipientEmail
        && !sendEmailForm.processing,
);

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            isSendDialogOpen.value = false;
            pendingSendRow.value = null;
            sendEmailForm.reset();
            sendEmailForm.clearErrors();
            bulkSendForm.reset();
            bulkSendForm.clearErrors();
            toast.success(success);
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

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

function submitSendEmail(): void {
    if (!pendingSendRow.value?.sendEmailUrl) {
        return;
    }

    sendEmailForm.post(pendingSendRow.value.sendEmailUrl, {
        forceFormData: true,
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
</script>

<template>
    <Head title="1702-EX Completed Files" />

    <AppLayout :breadcrumbs="breadcrumbs">
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
                        <Button type="button" variant="outline" @click="router.get(props.indexUrl)">
                            <ArrowLeft class="mr-2 size-4" />
                            Back to workspace
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle class="text-xl">Completed file table</CardTitle>
                    <CardDescription>
                        Only rows with a generated PDF and attached receipt appear here.
                    </CardDescription>
                </CardHeader>

                <CardContent>
                    <CompletedRowsTable
                        :bulk-send-processing="bulkSendForm.processing"
                        :filters="props.filters"
                        :page-url="props.completedFilesUrl"
                        :pagination="props.pagination"
                        :rows="props.rows"
                        :single-send-processing="sendEmailForm.processing"
                        @open-send-email="openSendEmail"
                        @request-bulk-send="requestBulkSend"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
