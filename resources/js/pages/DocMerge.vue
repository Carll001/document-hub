<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Download,
    Eye,
    FileText,
    LoaderCircle,
    Lock,
    Mail,
    MoreHorizontal,
    Plus,
    Printer,
    Search,
    Trash2,
    Upload,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import docMerge from '@/routes/doc-merge';
import type { BreadcrumbItem } from '@/types';

type FlashState = {
    success?: string | null;
    error?: string | null;
};

type MergedPdfRecord = {
    id: number;
    fileName: string;
    fileSize: number;
    sourceCount: number;
    sourceFileNames: string[];
    hasReceipt: boolean;
    receiptFileName: string | null;
    receiptFileSize: number | null;
    createdAt: string | null;
    downloadUrl: string;
    previewUrl: string;
    receiptUploadUrl: string;
    receiptRemoveUrl: string | null;
    receiptDownloadUrl: string | null;
    sendEmailUrl: string;
};

type MergeSourceType = 'upload' | 'merged_pdf';

type MergeSourcePayload = {
    type: MergeSourceType;
    id?: number;
};

type MergeQueueItem = {
    key: string;
    type: MergeSourceType;
    title: string;
    subtitle: string;
    size: number | null;
    id?: number;
    locked: boolean;
    file?: File;
};

const props = defineProps<{
    flash: FlashState;
    mergedPdfs: MergedPdfRecord[];
}>();
const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Doc Merge',
        href: docMerge.index(),
    },
];

const fileInput = ref<HTMLInputElement | null>(null);
const mergeReceiptInput = ref<HTMLInputElement | null>(null);
const printFrame = ref<HTMLIFrameElement | null>(null);
const receiptInput = ref<HTMLInputElement | null>(null);
const isMergeDialogOpen = ref(false);
const isPreviewDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);
const isRemoveReceiptDialogOpen = ref(false);
const isReceiptDialogOpen = ref(false);
const isSendEmailDialogOpen = ref(false);
const mergedFileSearch = ref('');
const mergeQueue = ref<MergeQueueItem[]>([]);
const printFrameUrl = ref<string | null>(null);
const previewedMergedPdf = ref<MergedPdfRecord | null>(null);
const mergedPdfsForDeletion = ref<MergedPdfRecord[]>([]);
const mergedPdfForReceiptRemoval = ref<MergedPdfRecord | null>(null);
const mergedPdfForReceipt = ref<MergedPdfRecord | null>(null);
const mergedPdfForEmail = ref<MergedPdfRecord | null>(null);
const appendBaseMergedPdfId = ref<number | null>(null);
const selectedMergedPdfIds = ref<number[]>([]);

let mergeSourceSequence = 0;
let isPrintPending = false;
let printCleanupTimeoutId: number | null = null;

const form = useForm<{
    outputName: string;
    sources: MergeSourcePayload[];
    files: File[];
    receipt: File | null;
}>({
    outputName: defaultOutputName(),
    sources: [],
    files: [],
    receipt: null,
});
const removeReceiptForm = useForm<Record<string, never>>({});
const deleteForm = useForm<{
    ids: number[];
}>({
    ids: [],
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
const receiptForm = useForm<{
    receipt: File | null;
}>({
    receipt: null,
});

const isAppendMode = computed(() => appendBaseMergedPdfId.value !== null);
const appName = computed(() => {
    const name = page.props.name;

    return typeof name === 'string' && name.trim() !== '' ? name : 'Laravel';
});

const canSubmit = computed(
    () => mergeQueue.value.length >= 2 && !form.processing,
);
const selectedMergedPdfSet = computed(
    () => new Set(selectedMergedPdfIds.value),
);
const visibleMergedPdfIds = computed(() =>
    filteredMergedPdfs.value.map((mergedPdf) => mergedPdf.id),
);
const visibleSelectedMergedPdfCount = computed(
    () =>
        visibleMergedPdfIds.value.filter((id) =>
            selectedMergedPdfSet.value.has(id),
        ).length,
);
const selectAllMergedPdfsState = computed<boolean | 'indeterminate'>(() => {
    if (visibleSelectedMergedPdfCount.value === 0) {
        return false;
    }

    if (
        visibleSelectedMergedPdfCount.value === visibleMergedPdfIds.value.length
    ) {
        return true;
    }

    return 'indeterminate';
});
const canBulkDeleteMergedPdfs = computed(
    () => selectedMergedPdfIds.value.length > 0 && !deleteForm.processing,
);
const canConfirmDelete = computed(
    () => mergedPdfsForDeletion.value.length > 0 && !deleteForm.processing,
);
const deleteDialogTitle = computed(() =>
    mergedPdfsForDeletion.value.length === 1
        ? 'Delete merged PDF'
        : 'Delete merged PDFs',
);
const canSendEmail = computed(
    () => mergedPdfForEmail.value !== null && !sendEmailForm.processing,
);
const canSubmitReceipt = computed(
    () =>
        mergedPdfForReceipt.value !== null &&
        receiptForm.receipt instanceof File &&
        !receiptForm.processing,
);

const filteredMergedPdfs = computed(() => {
    const query = mergedFileSearch.value.trim().toLowerCase();

    if (query === '') {
        return props.mergedPdfs;
    }

    return props.mergedPdfs.filter((mergedPdf) =>
        [
            mergedPdf.fileName,
            ...mergedPdf.sourceFileNames,
            mergedPdf.receiptFileName ?? '',
            formatDateTime(mergedPdf.createdAt),
        ]
            .join(' ')
            .toLowerCase()
            .includes(query),
    );
});

const fileFieldError = computed(() => {
    const directError = form.errors.files;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(form.errors).find(([key]) =>
        key.startsWith('files.'),
    );

    return nestedEntry?.[1] ?? null;
});

const sourceFieldError = computed(() => {
    const directError = form.errors.sources;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(form.errors).find(([key]) =>
        key.startsWith('sources.'),
    );

    return nestedEntry?.[1] ?? null;
});
const mergeReceiptFieldError = computed(() => {
    const directError = form.errors.receipt;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(form.errors).find(([key]) =>
        key.startsWith('receipt.'),
    );

    return nestedEntry?.[1] ?? null;
});
const receiptFieldError = computed(() => {
    const directError = receiptForm.errors.receipt;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(receiptForm.errors).find(([key]) =>
        key.startsWith('receipt.'),
    );

    return nestedEntry?.[1] ?? null;
});

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            resetMergeForm();
            resetDeleteForm();
            selectedMergedPdfIds.value = [];
            isMergeDialogOpen.value = false;
            toast.success(success);

            return;
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

watch(filteredMergedPdfs, (mergedPdfs) => {
    const visibleIds = new Set(mergedPdfs.map((mergedPdf) => mergedPdf.id));

    selectedMergedPdfIds.value = selectedMergedPdfIds.value.filter((id) =>
        visibleIds.has(id),
    );
});

function nextMergeSourceKey(): string {
    mergeSourceSequence += 1;

    return `merge-source-${mergeSourceSequence}`;
}

function defaultOutputName(): string {
    const date = new Date();
    const parts = [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('');
    const time = [
        String(date.getHours()).padStart(2, '0'),
        String(date.getMinutes()).padStart(2, '0'),
        String(date.getSeconds()).padStart(2, '0'),
    ].join('');

    return `merged-document-${parts}-${time}.pdf`;
}

function handleMergeDialogOpenChange(open: boolean): void {
    if (form.processing) {
        return;
    }

    if (!open) {
        form.clearErrors();
    }

    isMergeDialogOpen.value = open;
}

function handlePreviewDialogOpenChange(open: boolean): void {
    isPreviewDialogOpen.value = open;

    if (!open) {
        previewedMergedPdf.value = null;
    }
}

function handleDeleteDialogOpenChange(open: boolean): void {
    if (deleteForm.processing) {
        return;
    }

    isDeleteDialogOpen.value = open;

    if (!open) {
        resetDeleteForm();
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

function openNewMergeDialog(): void {
    resetMergeForm();
    isMergeDialogOpen.value = true;
}

function openAppendDialog(mergedPdf: MergedPdfRecord): void {
    resetMergeForm();
    appendBaseMergedPdfId.value = mergedPdf.id;
    form.outputName = mergedPdf.fileName;
    mergeQueue.value = [createMergedPdfQueueItem(mergedPdf, true)];
    isMergeDialogOpen.value = true;
}

function openPreviewDialog(mergedPdf: MergedPdfRecord): void {
    previewedMergedPdf.value = mergedPdf;
    isPreviewDialogOpen.value = true;
}

function openDeleteDialogForMergedPdf(mergedPdf: MergedPdfRecord): void {
    mergedPdfsForDeletion.value = [mergedPdf];
    deleteForm.clearErrors();
    isDeleteDialogOpen.value = true;
}

function openDeleteDialogForSelection(): void {
    const selectedMergedPdfs = filteredMergedPdfs.value.filter((mergedPdf) =>
        selectedMergedPdfSet.value.has(mergedPdf.id),
    );

    if (selectedMergedPdfs.length === 0) {
        return;
    }

    mergedPdfsForDeletion.value = selectedMergedPdfs;
    deleteForm.clearErrors();
    isDeleteDialogOpen.value = true;
}

function openRemoveReceiptDialog(mergedPdf: MergedPdfRecord): void {
    mergedPdfForReceiptRemoval.value = mergedPdf;
    removeReceiptForm.clearErrors();
    isRemoveReceiptDialogOpen.value = true;
}

function openReceiptDialog(mergedPdf: MergedPdfRecord): void {
    mergedPdfForReceipt.value = mergedPdf;
    receiptForm.receipt = null;
    receiptForm.clearErrors();

    if (receiptInput.value) {
        receiptInput.value.value = '';
    }

    isReceiptDialogOpen.value = true;
}

function openSendEmailDialog(mergedPdf: MergedPdfRecord): void {
    mergedPdfForEmail.value = mergedPdf;
    sendEmailForm.recipientEmail = '';
    sendEmailForm.subject = defaultEmailSubject(mergedPdf);
    sendEmailForm.message = defaultEmailMessage(mergedPdf);
    sendEmailForm.clearErrors();
    isSendEmailDialogOpen.value = true;
}

function resetMergeForm(): void {
    mergeQueue.value = [];
    appendBaseMergedPdfId.value = null;
    form.outputName = defaultOutputName();
    form.sources = [];
    form.files = [];
    form.receipt = null;
    form.clearErrors();

    if (fileInput.value) {
        fileInput.value.value = '';
    }

    if (mergeReceiptInput.value) {
        mergeReceiptInput.value.value = '';
    }
}

function resetSendEmailForm(): void {
    mergedPdfForEmail.value = null;
    sendEmailForm.reset();
    sendEmailForm.clearErrors();
}

function resetDeleteForm(): void {
    mergedPdfsForDeletion.value = [];
    deleteForm.reset();
    deleteForm.clearErrors();
}

function resetRemoveReceiptForm(): void {
    mergedPdfForReceiptRemoval.value = null;
    removeReceiptForm.clearErrors();
}

function resetReceiptForm(): void {
    mergedPdfForReceipt.value = null;
    receiptForm.reset();
    receiptForm.clearErrors();

    if (receiptInput.value) {
        receiptInput.value.value = '';
    }
}

function handleFileSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const incomingFiles = Array.from(input.files ?? []).filter((file) =>
        isPdfFile(file),
    );

    if (incomingFiles.length === 0) {
        input.value = '';

        return;
    }

    mergeQueue.value = [
        ...mergeQueue.value,
        ...incomingFiles.map(createUploadQueueItem),
    ];
    form.clearErrors('files', 'sources');
    input.value = '';
}

function handleReceiptSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const [file] = Array.from(input.files ?? []);

    receiptForm.receipt = file ?? null;
    receiptForm.clearErrors('receipt');
}

function handleMergeReceiptSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const [file] = Array.from(input.files ?? []);

    form.receipt = file ?? null;
    form.clearErrors('receipt');
}

function isPdfFile(file: File): boolean {
    return (
        file.type === 'application/pdf' ||
        file.name.toLowerCase().endsWith('.pdf')
    );
}

function createUploadQueueItem(file: File): MergeQueueItem {
    return {
        key: nextMergeSourceKey(),
        type: 'upload',
        title: file.name,
        subtitle: 'From your device',
        size: file.size,
        locked: false,
        file,
    };
}

function createMergedPdfQueueItem(
    mergedPdf: MergedPdfRecord,
    locked: boolean,
): MergeQueueItem {
    return {
        key: nextMergeSourceKey(),
        type: 'merged_pdf',
        title: mergedPdf.fileName,
        subtitle: locked
            ? 'Base saved merged PDF'
            : `Saved merged PDF with ${mergedPdf.sourceCount} ${mergedPdf.sourceCount === 1 ? 'source' : 'sources'}`,
        size: mergedPdf.fileSize,
        id: mergedPdf.id,
        locked,
    };
}

function canMoveSource(index: number, direction: -1 | 1): boolean {
    const source = mergeQueue.value[index];
    const targetIndex = index + direction;
    const target = mergeQueue.value[targetIndex];

    return (
        source !== undefined &&
        target !== undefined &&
        !source.locked &&
        !target.locked
    );
}

function moveSource(index: number, direction: -1 | 1): void {
    if (!canMoveSource(index, direction)) {
        return;
    }

    const targetIndex = index + direction;
    const reordered = [...mergeQueue.value];
    const [source] = reordered.splice(index, 1);

    reordered.splice(targetIndex, 0, source);
    mergeQueue.value = reordered;
}

function removeSource(index: number): void {
    if (mergeQueue.value[index]?.locked) {
        return;
    }

    mergeQueue.value = mergeQueue.value.filter(
        (_, sourceIndex) => sourceIndex !== index,
    );
}

function openPicker(): void {
    fileInput.value?.click();
}

function openMergeReceiptPicker(): void {
    mergeReceiptInput.value?.click();
}

function clearMergeReceipt(): void {
    form.receipt = null;
    form.clearErrors('receipt');

    if (mergeReceiptInput.value) {
        mergeReceiptInput.value.value = '';
    }
}

function isMergedPdfSelected(mergedPdfId: number): boolean {
    return selectedMergedPdfSet.value.has(mergedPdfId);
}

function toggleMergedPdfSelection(
    mergedPdfId: number,
    checked: boolean | 'indeterminate',
): void {
    if (checked === true) {
        selectedMergedPdfIds.value = Array.from(
            new Set([...selectedMergedPdfIds.value, mergedPdfId]),
        );

        return;
    }

    selectedMergedPdfIds.value = selectedMergedPdfIds.value.filter(
        (id) => id !== mergedPdfId,
    );
}

function toggleAllVisibleMergedPdfs(checked: boolean | 'indeterminate'): void {
    if (checked === true) {
        selectedMergedPdfIds.value = Array.from(
            new Set([
                ...selectedMergedPdfIds.value,
                ...visibleMergedPdfIds.value,
            ]),
        );

        return;
    }

    selectedMergedPdfIds.value = selectedMergedPdfIds.value.filter(
        (id) => !visibleMergedPdfIds.value.includes(id),
    );
}

function mergeSourceTypeLabel(type: MergeSourceType): string {
    if (type === 'upload') {
        return 'Upload';
    }

    return 'Saved merge';
}

function mergeSourceTypeVariant(
    type: MergeSourceType,
): 'default' | 'secondary' | 'outline' {
    if (type === 'upload') {
        return 'secondary';
    }

    return 'default';
}

function syncFormPayload(): void {
    form.sources = mergeQueue.value.map((source) => {
        if (source.type === 'upload') {
            return { type: source.type };
        }

        return {
            type: source.type,
            id: source.id,
        };
    });
    form.files = mergeQueue.value
        .filter(
            (source): source is MergeQueueItem & { file: File } =>
                source.type === 'upload' && source.file instanceof File,
        )
        .map((source) => source.file);
}

function submit(): void {
    syncFormPayload();

    form.post(docMerge.store.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
}

function submitDelete(): void {
    const ids = mergedPdfsForDeletion.value.map((mergedPdf) => mergedPdf.id);

    if (ids.length === 0) {
        return;
    }

    deleteForm.ids = ids;
    deleteForm.delete(docMerge.index.url(), {
        preserveScroll: true,
        onSuccess: (page) => {
            const success = (page.props as { flash?: FlashState }).flash
                ?.success;

            if (success) {
                selectedMergedPdfIds.value = selectedMergedPdfIds.value.filter(
                    (id) => !ids.includes(id),
                );
                isDeleteDialogOpen.value = false;
                resetDeleteForm();
            }
        },
    });
}

function submitReceipt(): void {
    const mergedPdf = mergedPdfForReceipt.value;

    if (!mergedPdf || !(receiptForm.receipt instanceof File)) {
        return;
    }

    receiptForm.post(mergedPdf.receiptUploadUrl, {
        forceFormData: true,
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

function clearPrintCleanupTimeout(): void {
    if (printCleanupTimeoutId !== null) {
        window.clearTimeout(printCleanupTimeoutId);
        printCleanupTimeoutId = null;
    }
}

function cleanupPrintFrame(): void {
    clearPrintCleanupTimeout();
    printFrameUrl.value = null;
    isPrintPending = false;
}

function handlePrintFrameLoad(): void {
    if (!printFrameUrl.value || !isPrintPending) {
        return;
    }

    const frameWindow = printFrame.value?.contentWindow;

    if (!frameWindow) {
        toast.error('The PDF could not be prepared for printing.');
        cleanupPrintFrame();

        return;
    }

    isPrintPending = false;

    window.setTimeout(() => {
        try {
            const handleAfterPrint = () => {
                frameWindow.removeEventListener('afterprint', handleAfterPrint);
                window.removeEventListener('afterprint', handleAfterPrint);
                cleanupPrintFrame();
            };

            frameWindow.addEventListener('afterprint', handleAfterPrint, {
                once: true,
            });
            window.addEventListener('afterprint', handleAfterPrint, {
                once: true,
            });
            clearPrintCleanupTimeout();
            printCleanupTimeoutId = window.setTimeout(handleAfterPrint, 15000);
            frameWindow.focus();
            frameWindow.print();
        } catch {
            toast.error(
                'Use the preview panel or download the PDF if printing does not open.',
            );
            cleanupPrintFrame();
        }
    }, 700);
}

function printMergedPdf(mergedPdf: MergedPdfRecord): void {
    const url = new URL(mergedPdf.previewUrl, window.location.origin);

    url.searchParams.set('print', Date.now().toString());
    clearPrintCleanupTimeout();
    isPrintPending = true;
    printFrameUrl.value = url.toString();
}

function submitSendEmail(): void {
    const mergedPdf = mergedPdfForEmail.value;

    if (!mergedPdf) {
        return;
    }

    sendEmailForm
        .transform((data) => ({
            recipientEmail: data.recipientEmail.trim(),
            subject: data.subject.trim() === '' ? null : data.subject.trim(),
            message: data.message.trim() === '' ? null : data.message.trim(),
        }))
        .post(mergedPdf.sendEmailUrl, {
            preserveScroll: true,
            onSuccess: (page) => {
                const success = (page.props as { flash?: FlashState }).flash
                    ?.success;

                if (success) {
                    isSendEmailDialogOpen.value = false;
                    resetSendEmailForm();
                }
            },
        });
}

function defaultEmailSubject(mergedPdf: MergedPdfRecord): string {
    return `Merged PDF: ${mergedPdf.fileName}`;
}

function defaultEmailMessage(mergedPdf: MergedPdfRecord): string {
    return [
        'Hello,',
        '',
        `Please find attached "${mergedPdf.fileName}".`,
        '',
        `Sent from ${appName.value}.`,
    ].join('\n');
}

function formatFileSize(bytes: number | null): string {
    if (bytes === null || !Number.isFinite(bytes) || bytes <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value >= 10 || unitIndex === 0 ? value.toFixed(0) : value.toFixed(1)} ${units[unitIndex]}`;
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Unknown date';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Unknown date';
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}
</script>

<template>
    <Head title="Doc Merge" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <div class="flex items-center justify-end">
                <Button
                    type="button"
                    size="sm"
                    class="gap-2 text-xs"
                    @click="openNewMergeDialog"
                >
                    <FileText class="size-4" />
                    Merge PDFs
                </Button>
            </div>
        </template>

        <iframe
            v-if="printFrameUrl"
            ref="printFrame"
            :src="printFrameUrl"
            title="Merged PDF print frame"
            class="absolute top-0 -left-[9999px] h-px w-px opacity-0"
            @load="handlePrintFrameLoad"
        />

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardHeader class="space-y-1">
                    <CardTitle class="text-xl">Saved merged PDFs</CardTitle>
                    <CardDescription>
                        Preview, download, email, or track receipts for any
                        saved merged file.
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-4">
                    <div
                        class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                    >
                        <div class="relative flex-1">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <Input
                                v-model="mergedFileSearch"
                                type="search"
                                placeholder="Search merged files"
                                class="pl-10"
                            />
                        </div>

                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            class="gap-2 self-end md:self-auto"
                            :disabled="!canBulkDeleteMergedPdfs"
                            @click="openDeleteDialogForSelection"
                        >
                            <Trash2 class="size-4" />
                            {{
                                selectedMergedPdfIds.length > 0
                                    ? `Delete selected (${selectedMergedPdfIds.length})`
                                    : 'Delete selected'
                            }}
                        </Button>
                    </div>

                    <div
                        class="overflow-hidden rounded-2xl border bg-background"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-[1%]">
                                        <Checkbox
                                            :key="`select-all-${selectAllMergedPdfsState}`"
                                            :model-value="
                                                selectAllMergedPdfsState
                                            "
                                            :disabled="
                                                filteredMergedPdfs.length === 0
                                            "
                                            aria-label="Select all merged PDFs"
                                            @update:model-value="
                                                toggleAllVisibleMergedPdfs
                                            "
                                        />
                                    </TableHead>
                                    <TableHead class="w-[1%]">#</TableHead>
                                    <TableHead>Merged file</TableHead>
                                    <TableHead>Sources</TableHead>
                                    <TableHead>Receipt</TableHead>
                                    <TableHead>Size</TableHead>
                                    <TableHead>Saved</TableHead>
                                    <TableHead class="w-[1%] text-right">
                                        Actions
                                    </TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                <template v-if="filteredMergedPdfs.length > 0">
                                    <TableRow
                                        v-for="(
                                            mergedPdf, index
                                        ) in filteredMergedPdfs"
                                        :key="mergedPdf.id"
                                    >
                                        <TableCell>
                                            <Checkbox
                                                :key="`select-${mergedPdf.id}-${isMergedPdfSelected(mergedPdf.id)}`"
                                                :model-value="
                                                    isMergedPdfSelected(
                                                        mergedPdf.id,
                                                    )
                                                "
                                                :aria-label="`Select ${mergedPdf.fileName}`"
                                                @update:model-value="
                                                    toggleMergedPdfSelection(
                                                        mergedPdf.id,
                                                        $event,
                                                    )
                                                "
                                            />
                                        </TableCell>
                                        <TableCell
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{ index + 1 }}
                                        </TableCell>
                                        <TableCell>
                                            <div class="min-w-0 space-y-1">
                                                <p
                                                    class="max-w-[16rem] truncate font-medium text-foreground"
                                                >
                                                    {{ mergedPdf.fileName }}
                                                </p>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    {{ mergedPdf.sourceCount }}
                                                    {{
                                                        mergedPdf.sourceCount ===
                                                        1
                                                            ? 'source file'
                                                            : 'source files'
                                                    }}
                                                </p>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div
                                                class="flex max-w-[18rem] flex-wrap gap-1.5"
                                            >
                                                <Badge
                                                    v-for="sourceFileName in mergedPdf.sourceFileNames.slice(
                                                        0,
                                                        2,
                                                    )"
                                                    :key="sourceFileName"
                                                    variant="secondary"
                                                    class="max-w-full truncate"
                                                >
                                                    {{ sourceFileName }}
                                                </Badge>
                                                <Badge
                                                    v-if="
                                                        mergedPdf
                                                            .sourceFileNames
                                                            .length > 2
                                                    "
                                                    variant="outline"
                                                >
                                                    +{{
                                                        mergedPdf
                                                            .sourceFileNames
                                                            .length - 2
                                                    }}
                                                    more
                                                </Badge>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div
                                                class="flex min-w-[7.5rem] items-center"
                                            >
                                                <Badge
                                                    v-if="mergedPdf.hasReceipt"
                                                    variant="outline"
                                                    class="border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800/70 dark:bg-emerald-950/40 dark:text-emerald-200"
                                                >
                                                    Receipt attached
                                                </Badge>
                                                <span
                                                    v-else
                                                    class="text-sm text-muted-foreground"
                                                >
                                                    -
                                                </span>
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                formatFileSize(
                                                    mergedPdf.fileSize,
                                                )
                                            }}
                                        </TableCell>
                                        <TableCell
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                formatDateTime(
                                                    mergedPdf.createdAt,
                                                )
                                            }}
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex justify-end">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                        as-child
                                                    >
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon-sm"
                                                        >
                                                            <MoreHorizontal
                                                                class="size-4"
                                                            />
                                                            <span
                                                                class="sr-only"
                                                            >
                                                                Open actions
                                                            </span>
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent
                                                        align="end"
                                                        class="w-48 rounded-lg"
                                                    >
                                                        <DropdownMenuItem
                                                            @select="
                                                                openPreviewDialog(
                                                                    mergedPdf,
                                                                )
                                                            "
                                                        >
                                                            <Eye
                                                                class="size-4"
                                                            />
                                                            Preview
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            @select="
                                                                printMergedPdf(
                                                                    mergedPdf,
                                                                )
                                                            "
                                                        >
                                                            <Printer
                                                                class="size-4"
                                                            />
                                                            Print
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            @select="
                                                                openAppendDialog(
                                                                    mergedPdf,
                                                                )
                                                            "
                                                        >
                                                            <Plus
                                                                class="size-4"
                                                            />
                                                            Add Files
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            @select="
                                                                openSendEmailDialog(
                                                                    mergedPdf,
                                                                )
                                                            "
                                                        >
                                                            <Mail
                                                                class="size-4"
                                                            />
                                                            Send Email
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            @select="
                                                                openReceiptDialog(
                                                                    mergedPdf,
                                                                )
                                                            "
                                                        >
                                                            <Upload
                                                                class="size-4"
                                                            />
                                                            {{
                                                                mergedPdf.hasReceipt
                                                                    ? 'Replace Receipt'
                                                                    : 'Add Receipt'
                                                            }}
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem
                                                            v-if="
                                                                mergedPdf.hasReceipt &&
                                                                mergedPdf.receiptRemoveUrl
                                                            "
                                                            variant="destructive"
                                                            @select="
                                                                openRemoveReceiptDialog(
                                                                    mergedPdf,
                                                                )
                                                            "
                                                        >
                                                            <Trash2
                                                                class="size-4"
                                                            />
                                                            Remove Receipt
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            variant="destructive"
                                                            @select="
                                                                openDeleteDialogForMergedPdf(
                                                                    mergedPdf,
                                                                )
                                                            "
                                                        >
                                                            <Trash2
                                                                class="size-4"
                                                            />
                                                            Delete
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            :as-child="true"
                                                        >
                                                            <a
                                                                :href="
                                                                    mergedPdf.downloadUrl
                                                                "
                                                                class="flex w-full items-center gap-2"
                                                            >
                                                                <Download
                                                                    class="size-4"
                                                                />
                                                                Download
                                                            </a>
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </template>

                                <TableEmpty v-else :colspan="8">
                                    {{
                                        props.mergedPdfs.length === 0
                                            ? 'No merged PDFs saved yet.'
                                            : 'No merged PDFs match your search.'
                                    }}
                                </TableEmpty>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            <Dialog
                :open="isMergeDialogOpen"
                @update:open="handleMergeDialogOpenChange"
            >
                <DialogContent class="sm:max-w-4xl">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            {{
                                isAppendMode
                                    ? 'Append PDF files'
                                    : 'Merge PDF files'
                            }}
                        </DialogTitle>
                        <DialogDescription>
                            <template v-if="isAppendMode">
                                Keep the saved merged PDF first, then add more
                                PDFs from your device. A new merged file will be
                                saved without replacing the original.
                            </template>
                            <template v-else>
                                Add at least two PDFs, arrange them in order,
                                and save the merged result to your account.
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <div class="max-h-[70vh] space-y-6 overflow-y-auto pr-1">
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".pdf,application/pdf"
                            multiple
                            class="hidden"
                            @change="handleFileSelection"
                        />
                        <input
                            ref="mergeReceiptInput"
                            type="file"
                            accept=".pdf,image/png,image/jpeg,image/webp"
                            class="hidden"
                            @change="handleMergeReceiptSelection"
                        />

                        <div class="flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="gap-2"
                                @click="openPicker"
                            >
                                <Upload class="size-4" />
                                Add from device
                            </Button>
                        </div>

                        <div class="space-y-2">
                            <Label for="outputName">Output filename</Label>
                            <Input
                                id="outputName"
                                v-model="form.outputName"
                                type="text"
                                placeholder="merged-document.pdf"
                            />
                            <InputError :message="form.errors.outputName" />
                        </div>

                        <div class="space-y-3">
                            <div
                                class="flex flex-wrap items-start justify-between gap-3 rounded-2xl border bg-muted/20 p-4"
                            >
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <Label class="text-sm font-medium">
                                            Receipt
                                        </Label>
                                        <Badge variant="outline">
                                            Optional
                                        </Badge>
                                    </div>
                                    <p class="text-xs text-muted-foreground">
                                        Add one receipt now and it will be
                                        appended as the last page after the
                                        merged PDF is saved.
                                    </p>
                                    <p
                                        v-if="form.receipt"
                                        class="text-sm text-foreground"
                                    >
                                        <span class="font-medium">
                                            {{ form.receipt.name }}
                                        </span>
                                        <span class="text-muted-foreground">
                                            ({{
                                                formatFileSize(
                                                    form.receipt.size,
                                                )
                                            }})
                                        </span>
                                    </p>
                                </div>

                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="gap-2"
                                        @click="openMergeReceiptPicker"
                                    >
                                        <Plus class="size-4" />
                                        {{
                                            form.receipt
                                                ? 'Change receipt'
                                                : 'Add receipt'
                                        }}
                                    </Button>
                                    <Button
                                        v-if="form.receipt"
                                        type="button"
                                        variant="ghost"
                                        size="sm"
                                        class="gap-2"
                                        @click="clearMergeReceipt"
                                    >
                                        <Trash2 class="size-4" />
                                        Remove
                                    </Button>
                                </div>
                            </div>

                            <InputError
                                :message="mergeReceiptFieldError ?? undefined"
                            />
                        </div>

                        <div class="space-y-3">
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <p class="text-sm font-medium">
                                        Merge queue
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Top to bottom is the final merge order.
                                    </p>
                                </div>

                                <Badge variant="outline">
                                    {{ mergeQueue.length }} sources
                                </Badge>
                            </div>

                            <div
                                v-if="mergeQueue.length === 0"
                                class="rounded-2xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                                No PDF sources selected yet.
                            </div>

                            <div v-else class="space-y-3">
                                <Card
                                    v-for="(source, index) in mergeQueue"
                                    :key="source.key"
                                    class="rounded-2xl"
                                >
                                    <CardHeader
                                        class="flex flex-col gap-3 sm:flex-row sm:items-start"
                                    >
                                        <div class="min-w-0 flex-1 space-y-2">
                                            <div
                                                class="flex flex-wrap items-center gap-2"
                                            >
                                                <Badge
                                                    :variant="
                                                        mergeSourceTypeVariant(
                                                            source.type,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        mergeSourceTypeLabel(
                                                            source.type,
                                                        )
                                                    }}
                                                </Badge>
                                                <Badge
                                                    v-if="source.locked"
                                                    variant="outline"
                                                    class="gap-1"
                                                >
                                                    <Lock class="size-3" />
                                                    Locked
                                                </Badge>
                                            </div>
                                            <CardTitle
                                                class="truncate text-base"
                                            >
                                                {{ source.title }}
                                            </CardTitle>
                                            <CardDescription
                                                class="flex flex-wrap items-center gap-2"
                                            >
                                                <span>{{
                                                    source.subtitle
                                                }}</span>
                                                <span>&bull;</span>
                                                <span>
                                                    {{
                                                        formatFileSize(
                                                            source.size,
                                                        )
                                                    }}
                                                </span>
                                            </CardDescription>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon-sm"
                                                :disabled="
                                                    !canMoveSource(index, -1)
                                                "
                                                @click="moveSource(index, -1)"
                                            >
                                                <ArrowUp class="size-4" />
                                                <span class="sr-only">
                                                    Move up
                                                </span>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon-sm"
                                                :disabled="
                                                    !canMoveSource(index, 1)
                                                "
                                                @click="moveSource(index, 1)"
                                            >
                                                <ArrowDown class="size-4" />
                                                <span class="sr-only">
                                                    Move down
                                                </span>
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                :disabled="source.locked"
                                                @click="removeSource(index)"
                                            >
                                                <Trash2 class="size-4" />
                                                <span class="sr-only">
                                                    Remove source
                                                </span>
                                            </Button>
                                        </div>
                                    </CardHeader>
                                </Card>
                            </div>

                            <InputError
                                :message="sourceFieldError ?? undefined"
                            />
                            <InputError
                                :message="fileFieldError ?? undefined"
                            />
                        </div>
                    </div>

                    <DialogFooter class="gap-2">
                        <div class="mr-auto max-w-md space-y-2 text-xs">
                            <div
                                class="rounded-2xl border border-amber-200 bg-amber-50/80 px-3 py-2 text-amber-950"
                            >
                                <p class="font-medium">Warning</p>
                                <p class="mt-1">
                                    Double-check the file order before saving.
                                    If a saved merge does not have editable
                                    source files, you will not be able to edit
                                    it later.
                                </p>
                            </div>
                            <div class="text-muted-foreground">
                                <p>
                                    PDF-only for now. Unsupported or locked PDFs
                                    will be rejected during merge.
                                </p>
                                <p v-if="form.progress" class="mt-1">
                                    Uploading {{ form.progress.percentage }}%
                                </p>
                            </div>
                        </div>

                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="form.processing"
                            @click="handleMergeDialogOpenChange(false)"
                        >
                            Cancel
                        </Button>

                        <Button
                            type="button"
                            class="gap-2"
                            :disabled="!canSubmit"
                            @click="submit"
                        >
                            <LoaderCircle
                                v-if="form.processing"
                                class="size-4 animate-spin"
                            />
                            <FileText v-else class="size-4" />
                            {{
                                form.processing
                                    ? 'Merging PDFs...'
                                    : 'Merge and save'
                            }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                :open="isDeleteDialogOpen"
                @update:open="handleDeleteDialogOpenChange"
            >
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>{{ deleteDialogTitle }}</DialogTitle>
                        <DialogDescription>
                            <template v-if="mergedPdfsForDeletion.length === 1">
                                Delete
                                <span class="font-medium text-foreground">
                                    {{ mergedPdfsForDeletion[0]?.fileName }}
                                </span>
                                ? This removes the saved merged PDF and any
                                attached receipt file.
                            </template>
                            <template
                                v-else-if="mergedPdfsForDeletion.length > 1"
                            >
                                Delete
                                <span class="font-medium text-foreground">
                                    {{ mergedPdfsForDeletion.length }}
                                </span>
                                selected merged PDFs? This also removes any
                                attached receipts for those files.
                            </template>
                            <template v-else>
                                Delete the selected merged PDFs.
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="deleteForm.processing"
                            @click="handleDeleteDialogOpenChange(false)"
                        >
                            Cancel
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            class="gap-2"
                            :disabled="!canConfirmDelete"
                            @click="submitDelete"
                        >
                            <LoaderCircle
                                v-if="deleteForm.processing"
                                class="size-4 animate-spin"
                            />
                            <Trash2 v-else class="size-4" />
                            {{
                                deleteForm.processing
                                    ? 'Deleting...'
                                    : mergedPdfsForDeletion.length === 1
                                      ? 'Delete file'
                                      : `Delete ${mergedPdfsForDeletion.length} files`
                            }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                :open="isRemoveReceiptDialogOpen"
                @update:open="handleRemoveReceiptDialogOpenChange"
            >
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Remove receipt</DialogTitle>
                        <DialogDescription>
                            <template v-if="mergedPdfForReceiptRemoval">
                                Remove the receipt from
                                <span class="font-medium text-foreground">
                                    {{ mergedPdfForReceiptRemoval.fileName }}
                                </span>
                                ? This will remove the appended receipt pages
                                from the saved merged PDF.
                            </template>
                            <template v-else>
                                Remove the attached receipt from this saved
                                merged PDF.
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="removeReceiptForm.processing"
                            @click="handleRemoveReceiptDialogOpenChange(false)"
                        >
                            Cancel
                        </Button>

                        <Button
                            type="button"
                            variant="destructive"
                            class="gap-2"
                            :disabled="removeReceiptForm.processing"
                            @click="removeReceipt"
                        >
                            <LoaderCircle
                                v-if="removeReceiptForm.processing"
                                class="size-4 animate-spin"
                            />
                            <Trash2 v-else class="size-4" />
                            {{
                                removeReceiptForm.processing
                                    ? 'Removing...'
                                    : 'Remove Receipt'
                            }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                :open="isReceiptDialogOpen"
                @update:open="handleReceiptDialogOpenChange"
            >
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            {{
                                mergedPdfForReceipt?.hasReceipt
                                    ? 'Replace receipt'
                                    : 'Add receipt'
                            }}
                        </DialogTitle>
                        <DialogDescription>
                            <template v-if="mergedPdfForReceipt">
                                Attach a receipt file to
                                <span class="font-medium text-foreground">
                                    {{ mergedPdfForReceipt.fileName }}
                                </span>
                                so you can track which merged PDFs already have
                                one. The receipt will be appended as the final
                                page of the merged PDF.
                            </template>
                            <template v-else>
                                Attach a receipt file to the selected merged
                                PDF. The receipt will be appended as the final
                                page.
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <form class="space-y-6" @submit.prevent="submitReceipt">
                        <div
                            v-if="mergedPdfForReceipt?.hasReceipt"
                            class="rounded-2xl border bg-muted/30 p-4 text-sm"
                        >
                            <p class="font-medium text-foreground">
                                Current receipt
                            </p>
                            <div
                                class="mt-2 flex flex-wrap items-center gap-2 text-muted-foreground"
                            >
                                <a
                                    v-if="
                                        mergedPdfForReceipt.receiptDownloadUrl
                                    "
                                    :href="
                                        mergedPdfForReceipt.receiptDownloadUrl
                                    "
                                    class="underline underline-offset-4"
                                >
                                    {{
                                        mergedPdfForReceipt.receiptFileName ??
                                        'Download current receipt'
                                    }}
                                </a>
                                <span v-else>
                                    {{ mergedPdfForReceipt.receiptFileName }}
                                </span>
                                <span>
                                    {{
                                        formatFileSize(
                                            mergedPdfForReceipt.receiptFileSize,
                                        )
                                    }}
                                </span>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="receiptFile">
                                Receipt file
                                <span class="text-muted-foreground">
                                    (PDF or image)
                                </span>
                            </Label>
                            <input
                                id="receiptFile"
                                ref="receiptInput"
                                type="file"
                                accept=".pdf,image/png,image/jpeg,image/webp"
                                class="block w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                                @change="handleReceiptSelection"
                            />
                            <p class="text-xs text-muted-foreground">
                                PDF, JPG, PNG, or WEBP up to 10 MB.
                            </p>
                            <p
                                v-if="receiptForm.receipt"
                                class="text-sm text-muted-foreground"
                            >
                                Selected:
                                <span class="font-medium text-foreground">
                                    {{ receiptForm.receipt.name }}
                                </span>
                                ({{ formatFileSize(receiptForm.receipt.size) }})
                            </p>
                            <InputError
                                :message="receiptFieldError ?? undefined"
                            />
                        </div>

                        <DialogFooter class="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                :disabled="receiptForm.processing"
                                @click="handleReceiptDialogOpenChange(false)"
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                class="gap-2"
                                :disabled="!canSubmitReceipt"
                            >
                                <LoaderCircle
                                    v-if="receiptForm.processing"
                                    class="size-4 animate-spin"
                                />
                                <Upload v-else class="size-4" />
                                {{
                                    receiptForm.processing
                                        ? 'Saving receipt...'
                                        : mergedPdfForReceipt?.hasReceipt
                                          ? 'Replace receipt'
                                          : 'Save receipt'
                                }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                :open="isSendEmailDialogOpen"
                @update:open="handleSendEmailDialogOpenChange"
            >
                <DialogContent class="sm:max-w-xl">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Send merged PDF</DialogTitle>
                        <DialogDescription>
                            <template v-if="mergedPdfForEmail">
                                Send
                                <span class="font-medium text-foreground">
                                    {{ mergedPdfForEmail.fileName }}
                                </span>
                                as an email attachment.
                            </template>
                            <template v-else>
                                Send the selected merged PDF as an email
                                attachment.
                            </template>
                        </DialogDescription>
                    </DialogHeader>

                    <form class="space-y-6" @submit.prevent="submitSendEmail">
                        <div class="space-y-2">
                            <Label for="recipientEmail">Recipient email</Label>
                            <Input
                                id="recipientEmail"
                                v-model="sendEmailForm.recipientEmail"
                                type="email"
                                placeholder="name@example.com"
                                required
                            />
                            <InputError
                                :message="sendEmailForm.errors.recipientEmail"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="sendEmailSubject">
                                Subject
                                <span class="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="sendEmailSubject"
                                v-model="sendEmailForm.subject"
                                type="text"
                                placeholder="Merged PDF"
                            />
                            <InputError
                                :message="sendEmailForm.errors.subject"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="sendEmailMessage">
                                Message
                                <span class="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <textarea
                                id="sendEmailMessage"
                                v-model="sendEmailForm.message"
                                rows="8"
                                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none selection:bg-primary selection:text-primary-foreground placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                                placeholder="Write a message for the recipient"
                            />
                            <InputError
                                :message="sendEmailForm.errors.message"
                            />
                        </div>

                        <DialogFooter class="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                :disabled="sendEmailForm.processing"
                                @click="handleSendEmailDialogOpenChange(false)"
                            >
                                Cancel
                            </Button>

                            <Button
                                type="submit"
                                class="gap-2"
                                :disabled="!canSendEmail"
                            >
                                <LoaderCircle
                                    v-if="sendEmailForm.processing"
                                    class="size-4 animate-spin"
                                />
                                <Mail v-else class="size-4" />
                                {{
                                    sendEmailForm.processing
                                        ? 'Sending...'
                                        : 'Send Email'
                                }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                :open="isPreviewDialogOpen"
                @update:open="handlePreviewDialogOpenChange"
            >
                <DialogContent class="sm:max-w-5xl">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>
                            {{ previewedMergedPdf?.fileName }}
                        </DialogTitle>
                        <DialogDescription>
                            Preview the saved merged PDF before downloading or
                            appending more files.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-4">
                        <div
                            class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Badge variant="secondary">
                                {{
                                    previewedMergedPdf
                                        ? formatFileSize(
                                              previewedMergedPdf.fileSize,
                                          )
                                        : '0 B'
                                }}
                            </Badge>
                            <Badge variant="outline">
                                {{
                                    previewedMergedPdf
                                        ? `${previewedMergedPdf.sourceCount} ${
                                              previewedMergedPdf.sourceCount ===
                                              1
                                                  ? 'source'
                                                  : 'sources'
                                          }`
                                        : '0 sources'
                                }}
                            </Badge>
                        </div>

                        <iframe
                            v-if="previewedMergedPdf"
                            :key="previewedMergedPdf.id"
                            :src="previewedMergedPdf.previewUrl"
                            title="Merged PDF preview"
                            class="h-[70vh] w-full rounded-2xl border bg-white"
                        />
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            v-if="previewedMergedPdf"
                            type="button"
                            variant="outline"
                            class="gap-2"
                            @click="printMergedPdf(previewedMergedPdf)"
                        >
                            <Printer class="size-4" />
                            Print
                        </Button>
                        <Button
                            v-if="previewedMergedPdf"
                            as-child
                            class="gap-2"
                        >
                            <a :href="previewedMergedPdf.downloadUrl">
                                <Download class="size-4" />
                                Download
                            </a>
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
