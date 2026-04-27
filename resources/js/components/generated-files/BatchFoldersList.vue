<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
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
import { Spinner } from '@/components/ui/spinner';
import documentGeneratorRoutes from '@/routes/afs-filing';
import generatedFilesRoutes from '@/routes/afs-filing/completed';
import {
    FolderOpen,
    FileSpreadsheet,
    LayoutTemplate,
    Calendar,
    ChevronRight,
    Loader2,
} from 'lucide-vue-next';
import { createToast, showToast } from '@/lib/toast';

type HistoryBatch = {
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

type PaginatedResponse<T> = {
    current_page: number;
    data: T[];
    last_page: number;
    per_page: number;
    total: number;
};

const props = defineProps<{
    initialHistory: PaginatedResponse<HistoryBatch>;
}>();

const historyData = ref<PaginatedResponse<HistoryBatch>>(props.initialHistory);
const historyLoading = ref(false);
const deleteDialogOpen = ref(false);
const deletingBatch = ref(false);
const pendingDeleteBatch = ref<HistoryBatch | null>(null);

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

const getApi = async <T,>(url: string): Promise<T> => {
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok)
        throw new Error(`Request failed with status ${response.status}`);
    return (await response.json()) as T;
};

const sendDelete = async (url: string): Promise<void> => {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }
};

const statusConfig = (status: string) => {
    const configs: Record<string, { variant: any; label: string }> = {
        failed: { variant: 'destructive', label: 'Failed' },
        completed: { variant: 'default', label: 'Success' },
        processing: { variant: 'secondary', label: 'In Progress' },
    };
    return configs[status] || { variant: 'outline', label: status };
};

const loadHistory = async (page = historyData.value.current_page) => {
    historyLoading.value = true;
    try {
        historyData.value = await getApi<PaginatedResponse<HistoryBatch>>(
            `/document-generator/batches/history?page=${page}&history_per_page=${historyData.value.per_page}&sort_by=created_at&sort_direction=desc`,
        );
    } finally {
        historyLoading.value = false;
    }
};

const formatDate = (dateStr: string | null) => {
    if (!dateStr) return '-';
    return new Date(dateStr).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const openDeleteDialog = (batch: HistoryBatch) => {
    pendingDeleteBatch.value = batch;
    deleteDialogOpen.value = true;
};

const closeDeleteDialog = () => {
    deleteDialogOpen.value = false;
    pendingDeleteBatch.value = null;
};

const confirmDeleteBatch = async () => {
    if (!pendingDeleteBatch.value) {
        return;
    }

    deletingBatch.value = true;

    try {
        await sendDelete(
            documentGeneratorRoutes.batches.destroy.url({
                batch: pendingDeleteBatch.value.id,
            }),
        );

        await loadHistory(
            historyData.value.current_page > 1 && historyData.value.data.length === 1
                ? historyData.value.current_page - 1
                : historyData.value.current_page,
        );
        showNotice(
            'success',
            `Batch #${pendingDeleteBatch.value.id} deleted`,
            'The batch has been removed from generated files.',
        );
        closeDeleteDialog();
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
</script>

<template>
    <Card
        class="overflow-hidden border-none bg-background/50 shadow-lg backdrop-blur"
    >
        <CardHeader class="pb-4">
            <div class="flex items-center justify-between">
                <div>
                    <CardTitle class="text-xl font-bold tracking-tight"
                        >Batch Management</CardTitle
                    >
                    <CardDescription
                        >Track and manage your generated document
                        batches.</CardDescription
                    >
                </div>
                <div v-if="historyLoading">
                    <Loader2 class="animate-spin text-muted-foreground" />
                </div>
            </div>
        </CardHeader>

        <CardContent>
            <div
                v-if="historyData.data.length === 0"
                class="flex flex-col items-center justify-center rounded-xl border border-dashed py-12 text-center"
            >
                <div class="mb-4 rounded-full bg-muted p-4">
                    <FolderOpen class="size-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium">No batches found</h3>
                <p class="text-sm text-muted-foreground">
                    Start by generating your first document batch.
                </p>
            </div>

            <div v-else class="space-y-3">
                <div
                    v-for="batch in historyData.data"
                    :key="batch.id"
                    class="group relative overflow-hidden rounded-xl border bg-card p-5 transition-all hover:border-primary/50 hover:ring-2 hover:ring-primary/20"
                >
                    <div
                        class="flex flex-col gap-6 md:flex-row md:items-center"
                    >
                        <div class="flex min-w-[200px] items-center gap-4">
                            <div
                                class="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary transition-colors group-hover:bg-primary group-hover:text-primary-foreground"
                            >
                                <FolderOpen class="size-6" />
                            </div>
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span
                                        class="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                        >Batch</span
                                    >
                                    <span class="font-mono font-bold"
                                        >#{{ batch.id }}</span
                                    >
                                </div>
                                <h4
                                    class="flex items-center gap-1.5 truncate text-base font-semibold"
                                >
                                    <FileSpreadsheet
                                        class="size-3.5 text-muted-foreground"
                                    />
                                    {{ batch.source_excel_name }}
                                </h4>
                            </div>
                        </div>

                        <div class="flex-1 space-y-2">
                            <div
                                class="flex items-center justify-between text-xs"
                            >
                                <span
                                    class="flex items-center gap-1.5 font-medium"
                                >
                                    <LayoutTemplate class="size-3.5" />
                                    {{ batch.template_name }}
                                </span>
                            </div>
                        </div>

                        <div
                            class="flex min-w-[140px] items-center justify-between gap-2 md:flex-col md:items-end"
                        >
                            <Badge
                                :variant="statusConfig(batch.status).variant"
                                class="capitalize shadow-sm"
                            >
                                {{ statusConfig(batch.status).label }}
                            </Badge>
                            <div
                                class="flex items-center gap-1 text-[11px] text-muted-foreground"
                            >
                                <Calendar class="size-3" />
                                {{ formatDate(batch.created_at) }}
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <Button as-child variant="outline" size="sm">
                                <Link :href="generatedFilesRoutes.show({ batch: batch.id })">
                                    Open
                                </Link>
                            </Button>
                            <Button variant="destructive" size="sm" @click="openDeleteDialog(batch)">
                                Delete
                            </Button>
                            <div class="hidden md:block">
                                <ChevronRight
                                    class="size-5 text-muted-foreground transition-transform group-hover:translate-x-1"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex items-center justify-between border-t pt-6">
                <p class="text-xs font-medium text-muted-foreground">
                    Showing page {{ historyData.current_page }} of
                    {{ historyData.last_page }}
                </p>
                <div class="flex gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            historyLoading || historyData.current_page <= 1
                        "
                        @click="loadHistory(historyData.current_page - 1)"
                    >
                        Previous
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            historyLoading ||
                            historyData.current_page >= historyData.last_page
                        "
                        @click="loadHistory(historyData.current_page + 1)"
                    >
                        Next
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>

    <Dialog :open="deleteDialogOpen" @update:open="(open) => { if (!open) closeDeleteDialog(); }">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Delete Batch #{{ pendingDeleteBatch?.id ?? '-' }}?</DialogTitle>
                <DialogDescription>
                    This batch will be hidden from generated files and history. Stored files will remain on disk for now.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button variant="outline" @click="closeDeleteDialog">Cancel</Button>
                <Button variant="destructive" :disabled="deletingBatch" @click="confirmDeleteBatch">
                    <Spinner v-if="deletingBatch" class="size-4" />
                    Delete batch
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
