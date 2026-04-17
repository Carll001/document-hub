<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import BatchItemsPanel from '@/components/generated-files/BatchItemsPanel.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { createToast, showToast } from '@/lib/toast';
import documentGeneratorRoutes from '@/routes/document-generator';
import type { BreadcrumbItem } from '@/types';

type BatchSummary = {
    id: number;
    source_excel_name: string;
    template_name: string;
    status: string;
    total_items: number;
    processed_items: number;
    success_items: number;
    failed_items: number;
    created_at: string | null;
    completed_at: string | null;
};

const props = defineProps<{
    batch: BatchSummary;
    signatureEnabled: boolean;
}>();

const deleteDialogOpen = ref(false);
const deletingBatch = ref(false);

const showNotice = (
    type: 'success' | 'error',
    title: string,
    message: string,
) => {
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

const confirmDeleteBatch = async () => {
    deletingBatch.value = true;

    try {
        const response = await fetch(
            documentGeneratorRoutes.batches.destroy.url({
                batch: props.batch.id,
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
            throw new Error(`Request failed with status ${response.status}`);
        }

        showNotice(
            'success',
            `Batch #${props.batch.id} deleted`,
            'The batch has been removed from generated files.',
        );
        router.visit('/generated-files');
    } catch (error) {
        showNotice(
            'error',
            'Batch was not deleted',
            error instanceof Error ? error.message : 'Unable to delete batch.',
        );
    } finally {
        deletingBatch.value = false;
    }
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Generated Files',
        href: '/generated-files',
    },
    {
        title: `Batch #${props.batch.id}`,
        href: `/generated-files/${props.batch.id}`,
    },
];
</script>

<template>
    <Head :title="`Batch #${batch.id} Files`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-center gap-3">
                <Button variant="outline" as-child>
                    <Link href="/generated-files">Back to Batch Folders</Link>
                </Button>
                <Button variant="destructive" @click="deleteDialogOpen = true">
                    Delete Batch
                </Button>
            </div>

            <BatchItemsPanel :batch="batch" :signature-enabled="props.signatureEnabled" />
        </div>
    </AppLayout>

    <Dialog :open="deleteDialogOpen" @update:open="(open) => { deleteDialogOpen = open; }">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete Batch #{{ batch.id }}?</DialogTitle>
                <DialogDescription>
                    This batch will be hidden from generated files and history. Stored files will remain on disk for now.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button variant="outline" @click="deleteDialogOpen = false">Cancel</Button>
                <Button variant="destructive" :disabled="deletingBatch" @click="confirmDeleteBatch">
                    <Spinner v-if="deletingBatch" class="size-4" />
                    Delete batch
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
