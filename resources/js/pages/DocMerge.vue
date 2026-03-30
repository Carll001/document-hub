<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import BatchFoldersSection from '@/components/doc-merge-components/BatchFoldersSection.vue';
import CreateBatchDialog from '@/components/doc-merge-components/CreateBatchDialog.vue';
import DocMergeToolbar from '@/components/doc-merge-components/DocMergeToolbar.vue';
import type {
    BatchPaginationState,
    BatchSummary,
    ConfirmationTemplateState,
    FlashState,
} from '@/components/doc-merge-components/types';
import SharedTemplateDialog from '@/components/doc-merge-components/SharedTemplateDialog.vue';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import docMerge from '@/routes/doc-merge';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    flash: FlashState;
    batchCreateUrl: string;
    batches: BatchSummary[];
    batchPagination: BatchPaginationState;
    confirmationTemplate: ConfirmationTemplateState;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Doc Merge',
        href: docMerge.index(),
    },
];

const isCreateBatchDialogOpen = ref(false);
const isTemplateDialogOpen = ref(false);

const createBatchForm = useForm<{
    name: string;
}>({
    name: '',
});
const confirmationTemplateForm = useForm<{
    template: File | null;
}>({
    template: null,
});

const canSubmitConfirmationTemplate = computed(
    () =>
        confirmationTemplateForm.template instanceof File &&
        !confirmationTemplateForm.processing,
);
const canSubmitCreateBatch = computed(
    () => createBatchForm.name.trim() !== '' && !createBatchForm.processing,
);
const confirmationTemplateFieldError = computed(
    () => confirmationTemplateForm.errors.template,
);

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            isCreateBatchDialogOpen.value = false;
            isTemplateDialogOpen.value = false;
            resetCreateBatchForm();
            resetConfirmationTemplateForm();
            toast.success(success);
        }

        if (error) {
            toast.error(error);
        }
    },
);

function resetCreateBatchForm(): void {
    createBatchForm.reset();
    createBatchForm.clearErrors();
}

function resetConfirmationTemplateForm(): void {
    confirmationTemplateForm.reset();
    confirmationTemplateForm.clearErrors();
}

function handleCreateBatchDialogOpenChange(open: boolean): void {
    if (createBatchForm.processing) {
        return;
    }

    isCreateBatchDialogOpen.value = open;

    if (!open) {
        resetCreateBatchForm();
    }
}

function handleTemplateDialogOpenChange(open: boolean): void {
    if (confirmationTemplateForm.processing) {
        return;
    }

    isTemplateDialogOpen.value = open;

    if (!open) {
        resetConfirmationTemplateForm();
    }
}

function handleConfirmationTemplateSelected(file: File | null): void {
    confirmationTemplateForm.template = file;
    confirmationTemplateForm.clearErrors('template');
}

function openCreateBatchDialog(): void {
    isCreateBatchDialogOpen.value = true;
}

function openTemplateDialog(): void {
    isTemplateDialogOpen.value = true;
}

function submitCreateBatch(): void {
    createBatchForm
        .transform((data) => ({
            name: data.name.trim(),
        }))
        .post(props.batchCreateUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const success = (page.props as { flash?: FlashState }).flash?.success;

                if (success) {
                    isCreateBatchDialogOpen.value = false;
                    resetCreateBatchForm();
                }
            },
        });
}

function submitConfirmationTemplate(): void {
    if (!(confirmationTemplateForm.template instanceof File)) {
        return;
    }

    confirmationTemplateForm.post(docMerge.confirmationTemplate.store().url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            const success = (page.props as { flash?: FlashState }).flash?.success;

            if (success) {
                isTemplateDialogOpen.value = false;
                resetConfirmationTemplateForm();
            }
        },
    });
}
</script>

<template>
    <Head title="Doc Merge" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <DocMergeToolbar
                @open-create-batch="openCreateBatchDialog"
                @open-template="openTemplateDialog"
            />
        </template>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <CreateBatchDialog
                :open="isCreateBatchDialogOpen"
                :can-submit="canSubmitCreateBatch"
                :errors="createBatchForm.errors"
                :name="createBatchForm.name"
                :processing="createBatchForm.processing"
                @submit="submitCreateBatch"
                @update:name="createBatchForm.name = $event"
                @update:open="handleCreateBatchDialogOpenChange"
            />

            <SharedTemplateDialog
                :open="isTemplateDialogOpen"
                :can-submit="canSubmitConfirmationTemplate"
                :field-error="confirmationTemplateFieldError"
                :processing="confirmationTemplateForm.processing"
                :selected-template="confirmationTemplateForm.template"
                :template="props.confirmationTemplate"
                @select-file="handleConfirmationTemplateSelected"
                @submit="submitConfirmationTemplate"
                @update:open="handleTemplateDialogOpenChange"
            />

            <Card class="rounded-3xl">
                <BatchFoldersSection
                    :batches="props.batches"
                    :pagination="props.batchPagination"
                />
            </Card>
        </div>
    </AppLayout>
</template>
