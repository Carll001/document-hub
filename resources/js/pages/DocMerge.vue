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

type MergeHistoryRecordType = 'merged_pdf' | 'merge_failure';
type BulkInputMode = 'zip' | 'folder';

type MergedPdfRecord = {
    recordType: 'merged_pdf';
    id: number;
    fileName: string;
    fileSize: number;
    sourceCount: number;
    sourceFileNames: string[];
    tinNumber: string | null;
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
    inputMode: null;
    inputLabel: null;
    groupLabel: null;
    errorMessage: null;
};

type MergeFailureRecord = {
    recordType: 'merge_failure';
    id: number;
    fileName: string;
    fileSize: null;
    sourceCount: null;
    sourceFileNames: string[];
    tinNumber: null;
    hasReceipt: false;
    receiptFileName: null;
    receiptFileSize: null;
    createdAt: string | null;
    downloadUrl: null;
    previewUrl: null;
    receiptUploadUrl: null;
    receiptRemoveUrl: null;
    receiptDownloadUrl: null;
    sendEmailUrl: null;
    inputMode: BulkInputMode;
    inputLabel: string;
    groupLabel: string;
    errorMessage: string;
};

type MergeHistoryRecord = MergedPdfRecord | MergeFailureRecord;

type MergeSourceType = 'upload' | 'merged_pdf';
type DeleteItemPayload = {
    type: MergeHistoryRecordType;
    id: number;
};

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

type DirectoryCapableFile = File & {
    webkitRelativePath?: string;
};

type PageFolderUploadItem = {
    key: string;
    name: string;
    number: number | null;
    files: File[];
    hasNestedEntries: boolean;
    hasInvalidFiles: boolean;
};

type PageFolderPayload = {
    name: string;
    number: number;
    hasNestedEntries: boolean;
    hasInvalidFiles: boolean;
    files: File[];
};

const props = defineProps<{
    flash: FlashState;
    mergeHistory: MergeHistoryRecord[];
}>();
const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Doc Merge',
        href: docMerge.index(),
    },
];

const fileInput = ref<HTMLInputElement | null>(null);
const bulkZipInput = ref<HTMLInputElement | null>(null);
const bulkPageFolderInput = ref<HTMLInputElement | null>(null);
const bulkPageFolderContainerInput = ref<HTMLInputElement | null>(null);
const mergeReceiptInput = ref<HTMLInputElement | null>(null);
const printFrame = ref<HTMLIFrameElement | null>(null);
const receiptInput = ref<HTMLInputElement | null>(null);
const isMergeDialogOpen = ref(false);
const isBulkZipDialogOpen = ref(false);
const isBulkFolderDialogOpen = ref(false);
const isPreviewDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);
const isRemoveReceiptDialogOpen = ref(false);
const isReceiptDialogOpen = ref(false);
const isSendEmailDialogOpen = ref(false);
const isFailureDialogOpen = ref(false);
const mergeHistorySearch = ref('');
const mergeQueue = ref<MergeQueueItem[]>([]);
const printFrameUrl = ref<string | null>(null);
const previewedMergedPdf = ref<MergedPdfRecord | null>(null);
const mergeHistoryForDeletion = ref<MergeHistoryRecord[]>([]);
const mergedPdfForReceiptRemoval = ref<MergedPdfRecord | null>(null);
const mergedPdfForReceipt = ref<MergedPdfRecord | null>(null);
const mergedPdfForEmail = ref<MergedPdfRecord | null>(null);
const mergeFailureForDialog = ref<MergeFailureRecord | null>(null);
const selectedPageFolders = ref<PageFolderUploadItem[]>([]);
const appendBaseMergedPdfId = ref<number | null>(null);
const selectedMergeHistoryKeys = ref<string[]>([]);

let mergeSourceSequence = 0;
let pageFolderSequence = 0;
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
const canSubmitBulkZipMerge = computed(
    () => bulkZipForm.zip instanceof File && !bulkZipForm.processing,
);
const selectedMergeHistorySet = computed(
    () => new Set(selectedMergeHistoryKeys.value),
);
const duplicatePageFolderNumbers = computed(() => {
    const duplicateNumbers = new Set<number>();
    const counts = new Map<number, number>();

    for (const pageFolder of selectedPageFolders.value) {
        if (pageFolder.number === null) {
            continue;
        }

        const nextCount = (counts.get(pageFolder.number) ?? 0) + 1;

        counts.set(pageFolder.number, nextCount);

        if (nextCount > 1) {
            duplicateNumbers.add(pageFolder.number);
        }
    }

    return duplicateNumbers;
});
const pageFoldersForDisplay = computed(() =>
    [...selectedPageFolders.value]
        .map((pageFolder) => ({
            ...pageFolder,
            issueMessage: pageFolderIssueMessage(pageFolder),
        }))
        .sort(comparePageFolderItems),
);
const validSelectedPageFolderCount = computed(
    () =>
        pageFoldersForDisplay.value.filter(
            (pageFolder) => pageFolder.issueMessage === null,
        ).length,
);
const bulkFolderOutputPreview = computed(() =>
    bulkOutputPreview(bulkFolderForm.outputPrefix),
);
const bulkZipOutputPreview = computed(() =>
    bulkOutputPreview(bulkZipForm.outputPrefix),
);
const bulkFolderInlineError = computed(
    () =>
        pageFoldersForDisplay.value.find(
            (pageFolder) => pageFolder.issueMessage !== null,
        )?.issueMessage ?? null,
);
const bulkFolderClientError = computed(() => {
    if (bulkFolderInlineError.value) {
        return bulkFolderInlineError.value;
    }

    if (selectedPageFolders.value.length < 2) {
        return 'Add at least two page folders like PAGE 1 and PAGE 2.';
    }

    if (validSelectedPageFolderCount.value < 2) {
        return 'Add at least two valid page folders like PAGE 1 and PAGE 2.';
    }

    return null;
});
const canSubmitBulkFolderMerge = computed(
    () =>
        bulkFolderClientError.value === null &&
        validSelectedPageFolderCount.value >= 2 &&
        !bulkFolderForm.processing,
);
const visibleMergeHistoryKeys = computed(() =>
    filteredMergeHistory.value.map((record) => mergeHistoryRecordKey(record)),
);
const visibleSelectedMergeHistoryCount = computed(
    () =>
        visibleMergeHistoryKeys.value.filter((key) =>
            selectedMergeHistorySet.value.has(key),
        ).length,
);
const selectAllMergeHistoryState = computed<boolean | 'indeterminate'>(() => {
    if (visibleSelectedMergeHistoryCount.value === 0) {
        return false;
    }

    if (
        visibleSelectedMergeHistoryCount.value ===
        visibleMergeHistoryKeys.value.length
    ) {
        return true;
    }

    return 'indeterminate';
});
const canBulkDeleteMergeHistory = computed(
    () => selectedMergeHistoryKeys.value.length > 0 && !deleteForm.processing,
);
const canConfirmDelete = computed(
    () => mergeHistoryForDeletion.value.length > 0 && !deleteForm.processing,
);
const deleteDialogTitle = computed(() =>
    mergeHistoryForDeletion.value.length === 1
        ? 'Delete merge result'
        : 'Delete merge results',
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

const filteredMergeHistory = computed(() => {
    const query = mergeHistorySearch.value.trim().toLowerCase();

    if (query === '') {
        return props.mergeHistory;
    }

    return props.mergeHistory.filter((record) =>
        mergeHistorySearchText(record).includes(query),
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
            resetBulkZipForm();
            resetBulkFolderForm();
            resetDeleteForm();
            selectedMergeHistoryKeys.value = [];
            isMergeDialogOpen.value = false;
            isBulkZipDialogOpen.value = false;
            isBulkFolderDialogOpen.value = false;
            toast.success(success);

            return;
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

watch(filteredMergeHistory, (mergeHistory) => {
    const visibleKeys = new Set(
        mergeHistory.map((record) => mergeHistoryRecordKey(record)),
    );

    selectedMergeHistoryKeys.value = selectedMergeHistoryKeys.value.filter(
        (key) => visibleKeys.has(key),
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

function handleFailureDialogOpenChange(open: boolean): void {
    isFailureDialogOpen.value = open;

    if (!open) {
        mergeFailureForDialog.value = null;
    }
}

function openNewMergeDialog(): void {
    resetMergeForm();
    isMergeDialogOpen.value = true;
}

function openBulkZipDialog(): void {
    resetBulkZipForm();
    isBulkZipDialogOpen.value = true;
}

function openBulkFolderDialog(): void {
    resetBulkFolderForm();
    isBulkFolderDialogOpen.value = true;
}

function openAppendDialog(record: MergeHistoryRecord): void {
    if (!isMergedPdfRecord(record)) {
        return;
    }

    resetMergeForm();
    appendBaseMergedPdfId.value = record.id;
    form.outputName = record.fileName;
    mergeQueue.value = [createMergedPdfQueueItem(record, true)];
    isMergeDialogOpen.value = true;
}

function openPreviewDialog(record: MergeHistoryRecord): void {
    if (!isMergedPdfRecord(record)) {
        return;
    }

    previewedMergedPdf.value = record;
    isPreviewDialogOpen.value = true;
}

function openFailureDialog(record: MergeHistoryRecord): void {
    if (!isMergeFailureRecord(record)) {
        return;
    }

    mergeFailureForDialog.value = record;
    isFailureDialogOpen.value = true;
}

function openDeleteDialogForRecord(record: MergeHistoryRecord): void {
    mergeHistoryForDeletion.value = [record];
    deleteForm.clearErrors();
    isDeleteDialogOpen.value = true;
}

function openDeleteDialogForSelection(): void {
    const selectedMergeHistory = filteredMergeHistory.value.filter((record) =>
        selectedMergeHistorySet.value.has(mergeHistoryRecordKey(record)),
    );

    if (selectedMergeHistory.length === 0) {
        return;
    }

    mergeHistoryForDeletion.value = selectedMergeHistory;
    deleteForm.clearErrors();
    isDeleteDialogOpen.value = true;
}

function openRemoveReceiptDialog(record: MergeHistoryRecord): void {
    if (!isMergedPdfRecord(record)) {
        return;
    }

    mergedPdfForReceiptRemoval.value = record;
    removeReceiptForm.clearErrors();
    isRemoveReceiptDialogOpen.value = true;
}

function openReceiptDialog(record: MergeHistoryRecord): void {
    if (!isMergedPdfRecord(record)) {
        return;
    }

    mergedPdfForReceipt.value = record;
    receiptForm.receipt = null;
    receiptForm.clearErrors();

    if (receiptInput.value) {
        receiptInput.value.value = '';
    }

    isReceiptDialogOpen.value = true;
}

function openSendEmailDialog(record: MergeHistoryRecord): void {
    if (!isMergedPdfRecord(record)) {
        return;
    }

    mergedPdfForEmail.value = record;
    sendEmailForm.recipientEmail = '';
    sendEmailForm.subject = defaultEmailSubject(record);
    sendEmailForm.message = defaultEmailMessage(record);
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

function resetBulkZipForm(): void {
    bulkZipForm.reset();
    bulkZipForm.clearErrors();

    if (bulkZipInput.value) {
        bulkZipInput.value.value = '';
    }
}

function resetBulkFolderForm(): void {
    selectedPageFolders.value = [];
    bulkFolderForm.reset();
    bulkFolderForm.clearErrors();
    bulkFolderForm.pageFolders = [];

    if (bulkPageFolderInput.value) {
        bulkPageFolderInput.value.value = '';
    }

    if (bulkPageFolderContainerInput.value) {
        bulkPageFolderContainerInput.value.value = '';
    }
}

function resetSendEmailForm(): void {
    mergedPdfForEmail.value = null;
    sendEmailForm.reset();
    sendEmailForm.clearErrors();
}

function resetDeleteForm(): void {
    mergeHistoryForDeletion.value = [];
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

function handleBulkZipSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const [file] = Array.from(input.files ?? []);

    bulkZipForm.zip = file ?? null;
    bulkZipForm.clearErrors('zip');
}

function handlePageFolderSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    input.value = '';

    if (files.length === 0) {
        return;
    }

    const parsedPageFolders = parsePageFolderSelection(files);

    if (parsedPageFolders.length === 0) {
        return;
    }

    selectedPageFolders.value = [
        ...selectedPageFolders.value,
        ...parsedPageFolders,
    ];
    bulkFolderForm.clearErrors('pageFolders');
}

function handlePageFolderContainerSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    input.value = '';

    if (files.length === 0) {
        return;
    }

    const parsedPageFolders = parseContainerFolderSelection(files);

    if (parsedPageFolders.length === 0) {
        return;
    }

    selectedPageFolders.value = [
        ...selectedPageFolders.value,
        ...parsedPageFolders,
    ];
    bulkFolderForm.clearErrors('pageFolders');
}

function isPdfFile(file: File): boolean {
    return (
        file.type === 'application/pdf' ||
        file.name.toLowerCase().endsWith('.pdf')
    );
}

function nextPageFolderKey(): string {
    pageFolderSequence += 1;

    return `page-folder-${pageFolderSequence}`;
}

function pageFolderNumberFromName(name: string): number | null {
    const match = name.trim().match(/(\d+)$/);

    if (!match) {
        return null;
    }

    const pageNumber = Number.parseInt(match[1] ?? '', 10);

    return Number.isFinite(pageNumber) && pageNumber > 0 ? pageNumber : null;
}

function pageFolderIssueMessage(pageFolder: PageFolderUploadItem): string | null {
    if (pageFolder.number === null) {
        return `Folder ${pageFolder.name} must end with a positive number like PAGE 1.`;
    }

    if (duplicatePageFolderNumbers.value.has(pageFolder.number)) {
        return `Page number ${pageFolder.number} is already selected by another folder.`;
    }

    if (pageFolder.hasNestedEntries) {
        return `Folder ${pageFolder.name} contains nested folders. Only direct PDF files are allowed.`;
    }

    if (pageFolder.hasInvalidFiles) {
        return `Folder ${pageFolder.name} contains non-PDF files. Only direct PDF files are allowed.`;
    }

    if (pageFolder.files.length === 0) {
        return `Folder ${pageFolder.name} must contain at least one direct PDF file.`;
    }

    return null;
}

function bulkOutputPreview(prefix: string): string {
    return `${prefix}PDF_NAME`;
}

function comparePageFolderItems(
    left: Pick<PageFolderUploadItem, 'number' | 'name'>,
    right: Pick<PageFolderUploadItem, 'number' | 'name'>,
): number {
    const leftNumber = left.number ?? Number.POSITIVE_INFINITY;
    const rightNumber = right.number ?? Number.POSITIVE_INFINITY;

    if (leftNumber !== rightNumber) {
        return leftNumber - rightNumber;
    }

    const leftName = left.name.toLowerCase();
    const rightName = right.name.toLowerCase();

    if (leftName !== rightName) {
        return leftName.localeCompare(rightName);
    }

    return left.name.localeCompare(right.name);
}

function fileRelativePath(file: File): string {
    const relativePath = (file as DirectoryCapableFile).webkitRelativePath;

    if (typeof relativePath === 'string' && relativePath.trim() !== '') {
        return relativePath.replaceAll('\\', '/');
    }

    return file.name;
}

function fileRelativePathSegments(file: File): string[] {
    return fileRelativePath(file)
        .split('/')
        .filter((segment) => segment !== '');
}

function looksLikeContainerFolderSelection(files: File[]): boolean {
    const segmentedPaths = files
        .map(fileRelativePathSegments)
        .filter((segments) => segments.length > 0);

    if (segmentedPaths.length === 0) {
        return false;
    }

    const rootSegments = Array.from(
        new Set(segmentedPaths.map(([rootSegment = '']) => rootSegment)),
    ).filter((segment) => segment !== '');

    if (rootSegments.length !== 1) {
        return false;
    }

    const hasDirectRootFiles = segmentedPaths.some(
        (segments) => segments.length === 2,
    );

    if (hasDirectRootFiles) {
        return false;
    }

    const childFolderNames = Array.from(
        new Set(
            segmentedPaths
                .filter((segments) => segments.length >= 3)
                .map((segments) => segments[1] ?? ''),
        ),
    ).filter((segment) => segment !== '');

    return (
        childFolderNames.length > 0 &&
        childFolderNames.every(
            (folderName) => pageFolderNumberFromName(folderName) !== null,
        )
    );
}

function parsePageFolderSelection(files: File[]): PageFolderUploadItem[] {
    if (looksLikeContainerFolderSelection(files)) {
        return parseContainerFolderSelection(files);
    }

    const pageFolderMap = new Map<string, PageFolderUploadItem>();

    for (const file of files) {
        const segments = fileRelativePathSegments(file);
        const [pageFolderName = ''] = segments;

        if (pageFolderName === '') {
            continue;
        }

        if (!pageFolderMap.has(pageFolderName)) {
            pageFolderMap.set(pageFolderName, {
                key: nextPageFolderKey(),
                name: pageFolderName,
                number: pageFolderNumberFromName(pageFolderName),
                files: [],
                hasNestedEntries: false,
                hasInvalidFiles: false,
            });
        }

        const pageFolder = pageFolderMap.get(pageFolderName);

        if (!pageFolder) {
            continue;
        }

        if (segments.length > 2) {
            pageFolder.hasNestedEntries = true;

            continue;
        }

        if (!isPdfFile(file)) {
            pageFolder.hasInvalidFiles = true;

            continue;
        }

        pageFolder.files.push(file);
    }

    const pageFolders = Array.from(pageFolderMap.values());

    if (pageFolders.length === 0) {
        bulkFolderForm.setError(
            'pageFolders',
            'Choose one or more page folders to add.',
        );
    }

    return pageFolders;
}

function parseContainerFolderSelection(files: File[]): PageFolderUploadItem[] {
    const rootSegments = Array.from(
        new Set(
            files.map((file) => {
                const [rootSegment = ''] = fileRelativePath(file).split('/');

                return rootSegment;
            }),
        ),
    ).filter((segment) => segment !== '');

    if (rootSegments.length !== 1) {
        bulkFolderForm.setError(
            'pageFolders',
            'Choose one container folder at a time.',
        );

        return [];
    }

    const pageFolderMap = new Map<string, PageFolderUploadItem>();

    for (const file of files) {
        const segments = fileRelativePathSegments(file);

        if (segments.length < 3) {
            bulkFolderForm.setError(
                'pageFolders',
                'The container folder must contain only page folders.',
            );

            return [];
        }

        const pageFolderName = segments[1] ?? '';

        if (!pageFolderMap.has(pageFolderName)) {
            pageFolderMap.set(pageFolderName, {
                key: nextPageFolderKey(),
                name: pageFolderName,
                number: pageFolderNumberFromName(pageFolderName),
                files: [],
                hasNestedEntries: false,
                hasInvalidFiles: false,
            });
        }

        const pageFolder = pageFolderMap.get(pageFolderName);

        if (!pageFolder) {
            continue;
        }

        if (segments.length > 3) {
            pageFolder.hasNestedEntries = true;

            continue;
        }

        if (!isPdfFile(file)) {
            pageFolder.hasInvalidFiles = true;

            continue;
        }

        pageFolder.files.push(file);
    }

    return Array.from(pageFolderMap.values());
}

function mergeFailureInputModeLabel(inputMode: BulkInputMode): string {
    return inputMode === 'zip' ? 'ZIP upload' : 'Folder upload';
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

function openBulkZipPicker(): void {
    bulkZipInput.value?.click();
}

function openPageFolderPicker(): void {
    bulkPageFolderInput.value?.click();
}

function openPageFolderContainerPicker(): void {
    bulkPageFolderContainerInput.value?.click();
}

function removePageFolder(pageFolderKey: string): void {
    selectedPageFolders.value = selectedPageFolders.value.filter(
        (pageFolder) => pageFolder.key !== pageFolderKey,
    );
    bulkFolderForm.clearErrors('pageFolders');
}

function isMergeHistorySelected(record: MergeHistoryRecord): boolean {
    return selectedMergeHistorySet.value.has(mergeHistoryRecordKey(record));
}

function toggleMergeHistorySelection(
    record: MergeHistoryRecord,
    checked: boolean | 'indeterminate',
): void {
    const recordKey = mergeHistoryRecordKey(record);

    if (checked === true) {
        selectedMergeHistoryKeys.value = Array.from(
            new Set([...selectedMergeHistoryKeys.value, recordKey]),
        );

        return;
    }

    selectedMergeHistoryKeys.value = selectedMergeHistoryKeys.value.filter(
        (key) => key !== recordKey,
    );
}

function toggleAllVisibleMergeHistory(
    checked: boolean | 'indeterminate',
): void {
    if (checked === true) {
        selectedMergeHistoryKeys.value = Array.from(
            new Set([
                ...selectedMergeHistoryKeys.value,
                ...visibleMergeHistoryKeys.value,
            ]),
        );

        return;
    }

    selectedMergeHistoryKeys.value = selectedMergeHistoryKeys.value.filter(
        (key) => !visibleMergeHistoryKeys.value.includes(key),
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

function submitBulkZipMerge(): void {
    if (!(bulkZipForm.zip instanceof File)) {
        return;
    }

    bulkZipForm.post(docMerge.bulk.store.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
}

function submitBulkFolderMerge(): void {
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

    bulkFolderForm.post(docMerge.bulkFolders.store.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
}

function submitDelete(): void {
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
    deleteForm.delete(docMerge.index.url(), {
        preserveScroll: true,
        onSuccess: (page) => {
            const success = (page.props as { flash?: FlashState }).flash
                ?.success;

            if (success) {
                selectedMergeHistoryKeys.value =
                    selectedMergeHistoryKeys.value.filter(
                        (key) => !deletedKeys.includes(key),
                    );

                if (
                    mergeFailureForDialog.value &&
                    deletedKeys.includes(
                        mergeHistoryRecordKey(mergeFailureForDialog.value),
                    )
                ) {
                    handleFailureDialogOpenChange(false);
                }

                isDeleteDialogOpen.value = false;
                resetDeleteForm();
            }
        },
    });
}

function mergeHistoryRecordKey(record: MergeHistoryRecord): string {
    return `${record.recordType}:${record.id}`;
}

function isMergedPdfRecord(record: MergeHistoryRecord): record is MergedPdfRecord {
    return record.recordType === 'merged_pdf';
}

function isMergeFailureRecord(
    record: MergeHistoryRecord,
): record is MergeFailureRecord {
    return record.recordType === 'merge_failure';
}

function mergeHistoryDeleteLabel(record: MergeHistoryRecord): string {
    return record.fileName;
}

function mergeHistorySearchText(record: MergeHistoryRecord): string {
    return [
        record.fileName,
        ...record.sourceFileNames,
        record.tinNumber ?? '',
        record.receiptFileName ?? '',
        record.inputMode ?? '',
        record.inputLabel ?? '',
        record.groupLabel ?? '',
        record.errorMessage ?? '',
        formatDateTime(record.createdAt),
    ]
        .join(' ')
        .toLowerCase();
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

function printMergedPdf(record: MergeHistoryRecord): void {
    if (!isMergedPdfRecord(record)) {
        return;
    }

    const url = new URL(record.previewUrl, window.location.origin);

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
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2 text-xs"
                    @click="openBulkFolderDialog"
                >
                    <Upload class="size-4" />
                    Bulk merge folders
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2 text-xs"
                    @click="openBulkZipDialog"
                >
                    <Upload class="size-4" />
                    Bulk merge ZIP
                </Button>
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
                    <CardTitle class="text-xl">Merge history</CardTitle>
                    <CardDescription>
                        Review saved merges and failed bulk merge results in one
                        place.
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
                                v-model="mergeHistorySearch"
                                type="search"
                                placeholder="Search merge history"
                                class="pl-10"
                            />
                        </div>

                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            class="gap-2 self-end md:self-auto"
                            :disabled="!canBulkDeleteMergeHistory"
                            @click="openDeleteDialogForSelection"
                        >
                            <Trash2 class="size-4" />
                            {{
                                selectedMergeHistoryKeys.length > 0
                                    ? `Delete selected (${selectedMergeHistoryKeys.length})`
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
                                            :key="`select-all-${selectAllMergeHistoryState}`"
                                            :model-value="
                                                selectAllMergeHistoryState
                                            "
                                            :disabled="
                                                filteredMergeHistory.length === 0
                                            "
                                            aria-label="Select all merge results"
                                            @update:model-value="
                                                toggleAllVisibleMergeHistory
                                            "
                                        />
                                    </TableHead>
                                    <TableHead class="w-[1%]">#</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Result</TableHead>
                                    <TableHead>TIN</TableHead>
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
                                <template v-if="filteredMergeHistory.length > 0">
                                    <TableRow
                                        v-for="(record, index) in filteredMergeHistory"
                                        :key="mergeHistoryRecordKey(record)"
                                    >
                                        <TableCell>
                                            <Checkbox
                                                :key="`select-${mergeHistoryRecordKey(record)}-${isMergeHistorySelected(record)}`"
                                                :model-value="
                                                    isMergeHistorySelected(
                                                        record,
                                                    )
                                                "
                                                :aria-label="`Select ${record.fileName}`"
                                                @update:model-value="
                                                    toggleMergeHistorySelection(
                                                        record,
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
                                            <Badge
                                                v-if="isMergedPdfRecord(record)"
                                                variant="outline"
                                                class="border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-50 dark:border-emerald-800/70 dark:bg-emerald-950/40 dark:text-emerald-200"
                                            >
                                                Saved
                                            </Badge>
                                            <Button
                                                v-else
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                class="h-auto rounded-full border-destructive/30 px-3 py-1 text-destructive hover:text-destructive"
                                                @click="openFailureDialog(record)"
                                            >
                                                Error
                                            </Button>
                                        </TableCell>
                                        <TableCell>
                                            <div class="min-w-0 space-y-1">
                                                <p
                                                    class="max-w-[16rem] truncate font-medium text-foreground"
                                                >
                                                    {{ record.fileName }}
                                                </p>
                                                <p
                                                    class="text-xs text-muted-foreground"
                                                >
                                                    <template
                                                        v-if="
                                                            isMergedPdfRecord(
                                                                record,
                                                            )
                                                        "
                                                    >
                                                        {{ record.sourceCount }}
                                                        {{
                                                            record.sourceCount ===
                                                            1
                                                                ? 'source file'
                                                                : 'source files'
                                                        }}
                                                    </template>
                                                    <template v-else>
                                                        Matched PDF:
                                                        {{ record.groupLabel }}
                                                    </template>
                                                </p>
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                record.tinNumber && record.tinNumber !== ''
                                                    ? record.tinNumber
                                                    : '—'
                                            }}
                                        </TableCell>
                                        <TableCell>
                                            <template
                                                v-if="isMergedPdfRecord(record)"
                                            >
                                                <div
                                                    class="flex max-w-[18rem] flex-wrap gap-1.5"
                                                >
                                                    <Badge
                                                        v-for="sourceFileName in record.sourceFileNames.slice(
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
                                                            record
                                                                .sourceFileNames
                                                                .length > 2
                                                        "
                                                        variant="outline"
                                                    >
                                                        +{{
                                                            record
                                                                .sourceFileNames
                                                                .length - 2
                                                        }}
                                                        more
                                                    </Badge>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <div class="space-y-1 text-sm">
                                                    <p
                                                        class="font-medium text-foreground"
                                                    >
                                                        {{
                                                            mergeFailureInputModeLabel(
                                                                record.inputMode,
                                                            )
                                                        }}
                                                    </p>
                                                    <p
                                                        class="max-w-[18rem] truncate text-xs text-muted-foreground"
                                                    >
                                                        {{ record.inputLabel }}
                                                    </p>
                                                </div>
                                            </template>
                                        </TableCell>
                                        <TableCell>
                                            <div
                                                class="flex min-w-[7.5rem] items-center"
                                            >
                                                <Badge
                                                    v-if="
                                                        isMergedPdfRecord(
                                                            record,
                                                        ) && record.hasReceipt
                                                    "
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
                                                record.fileSize === null
                                                    ? '-'
                                                    : formatFileSize(
                                                          record.fileSize,
                                                      )
                                            }}
                                        </TableCell>
                                        <TableCell
                                            class="text-sm text-muted-foreground"
                                        >
                                            {{
                                                formatDateTime(
                                                    record.createdAt,
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
                                                        <template
                                                            v-if="
                                                                isMergedPdfRecord(
                                                                    record,
                                                                )
                                                            "
                                                        >
                                                            <DropdownMenuItem
                                                                @select="
                                                                    openPreviewDialog(
                                                                        record,
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
                                                                        record,
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
                                                                        record,
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
                                                                        record,
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
                                                                        record,
                                                                    )
                                                                "
                                                            >
                                                                <Upload
                                                                    class="size-4"
                                                                />
                                                                {{
                                                                    record.hasReceipt
                                                                        ? 'Replace Receipt'
                                                                        : 'Add Receipt'
                                                                }}
                                                            </DropdownMenuItem>
                                                            <DropdownMenuItem
                                                                v-if="
                                                                    record.hasReceipt &&
                                                                    record.receiptRemoveUrl
                                                                "
                                                                variant="destructive"
                                                                @select="
                                                                    openRemoveReceiptDialog(
                                                                        record,
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
                                                                    openDeleteDialogForRecord(
                                                                        record,
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
                                                                        record.downloadUrl
                                                                    "
                                                                    class="flex w-full items-center gap-2"
                                                                >
                                                                    <Download
                                                                        class="size-4"
                                                                    />
                                                                    Download
                                                                </a>
                                                            </DropdownMenuItem>
                                                        </template>
                                                        <template v-else>
                                                            <DropdownMenuItem
                                                                @select="
                                                                    openFailureDialog(
                                                                        record,
                                                                    )
                                                                "
                                                            >
                                                                <Eye
                                                                    class="size-4"
                                                                />
                                                                View Error
                                                            </DropdownMenuItem>
                                                            <DropdownMenuSeparator />
                                                            <DropdownMenuItem
                                                                variant="destructive"
                                                                @select="
                                                                    openDeleteDialogForRecord(
                                                                        record,
                                                                    )
                                                                "
                                                            >
                                                                <Trash2
                                                                    class="size-4"
                                                                />
                                                                Delete
                                                            </DropdownMenuItem>
                                                        </template>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </template>

                                <TableEmpty v-else :colspan="10">
                                    {{
                                        props.mergeHistory.length === 0
                                            ? 'No merge history yet.'
                                            : 'No merge results match your search.'
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
                :open="isBulkFolderDialogOpen"
                @update:open="handleBulkFolderDialogOpenChange"
            >
                <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Bulk merge folders</DialogTitle>
                        <DialogDescription>
                            Add one or more page folders like PAGE 1 and PAGE 2,
                            or import one container folder with page folders
                            inside it. PDFs with the same base name across every
                            selected page folder will be merged together.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        class="space-y-6"
                        @submit.prevent="submitBulkFolderMerge"
                    >
                        <input
                            ref="bulkPageFolderInput"
                            type="file"
                            accept=".pdf,application/pdf"
                            webkitdirectory
                            directory
                            multiple
                            class="hidden"
                            @change="handlePageFolderSelection"
                        />
                        <input
                            ref="bulkPageFolderContainerInput"
                            type="file"
                            accept=".pdf,application/pdf"
                            webkitdirectory
                            directory
                            multiple
                            class="hidden"
                            @change="handlePageFolderContainerSelection"
                        />

                        <div
                            class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground"
                        >
                            <p class="font-medium text-foreground">
                                Folder rules
                            </p>
                            <p class="mt-2">
                                Each page folder name must end with a positive
                                number like
                                <span class="font-medium text-foreground">
                                    PAGE 1
                                </span>
                                ,
                                <span class="font-medium text-foreground">
                                    PAGE 2
                                </span>
                                , or
                                <span class="font-medium text-foreground">
                                    PAGE 10
                                </span>
                                .
                            </p>
                            <p class="mt-2">
                                Only direct PDF files are allowed inside each
                                page folder. Matching uses the PDF name without
                                the ending page number, so
                                <span class="font-medium text-foreground">
                                    invoice 1.pdf
                                </span>
                                and
                                <span class="font-medium text-foreground">
                                    invoice 2.pdf
                                </span>
                                become one output.
                            </p>
                            <p class="mt-2">
                                If a PDF ends with a page number, that number
                                must match the folder name, like
                                <span class="font-medium text-foreground">
                                    PAGE 2 / invoice 2.pdf
                                </span>
                                .
                            </p>
                            <p class="mt-2">
                                To add multiple page folders in one pick, choose
                                their parent folder so it contains PAGE 1, PAGE
                                2, and the other page folders inside.
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="bulkFolderOutputPrefix">
                                Output prefix
                                <span class="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="bulkFolderOutputPrefix"
                                v-model="bulkFolderForm.outputPrefix"
                                type="text"
                                placeholder="ClientA_"
                            />
                            <p class="text-xs text-muted-foreground">
                                Each output becomes
                                <span class="font-medium text-foreground">
                                    {{ bulkFolderOutputPreview }}
                                </span>
                                .
                            </p>
                            <InputError
                                :message="
                                    bulkFolderOutputPrefixError ?? undefined
                                "
                            />
                        </div>

                        <div class="space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="gap-2"
                                    :disabled="bulkFolderForm.processing"
                                    @click="openPageFolderPicker"
                                >
                                    <Upload class="size-4" />
                                    Add page folders
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="gap-2"
                                    :disabled="bulkFolderForm.processing"
                                    @click="openPageFolderContainerPicker"
                                >
                                    <Upload class="size-4" />
                                    Import container folder
                                </Button>
                            </div>
                            <InputError
                                :message="
                                    bulkFolderFieldError ??
                                    bulkFolderInlineError ??
                                    undefined
                                "
                            />
                            <p
                                v-if="bulkFolderForm.progress"
                                class="text-xs text-muted-foreground"
                            >
                                Uploading
                                {{ bulkFolderForm.progress.percentage }}%
                            </p>
                        </div>

                        <div
                            class="rounded-2xl border bg-background p-4"
                        >
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <p class="font-medium text-foreground">
                                        Page order preview
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{
                                            pageFoldersForDisplay.length === 0
                                                ? 'No page folders selected yet.'
                                                : `${pageFoldersForDisplay.length} page folders selected.`
                                        }}
                                    </p>
                                    <p class="mt-1 text-sm text-muted-foreground">
                                        Each output becomes
                                        <span class="font-medium text-foreground">
                                            {{ bulkFolderOutputPreview }}
                                        </span>
                                        .
                                    </p>
                                </div>
                                <Badge variant="outline">
                                    {{ validSelectedPageFolderCount }} valid
                                </Badge>
                            </div>

                            <div
                                v-if="pageFoldersForDisplay.length > 0"
                                class="mt-4 space-y-3"
                            >
                                <div
                                    v-for="pageFolder in pageFoldersForDisplay"
                                    :key="pageFolder.key"
                                    class="rounded-2xl border bg-muted/20 p-4"
                                >
                                    <div
                                        class="flex flex-wrap items-start justify-between gap-3"
                                    >
                                        <div class="min-w-0 space-y-1">
                                            <p
                                                class="max-w-[24rem] truncate font-medium text-foreground"
                                            >
                                                {{ pageFolder.name }}
                                            </p>
                                            <p
                                                class="text-sm text-muted-foreground"
                                            >
                                                {{
                                                    pageFolder.number === null
                                                        ? 'Needs page number'
                                                        : `Page ${pageFolder.number}`
                                                }}
                                                •
                                                {{
                                                    pageFolder.files.length === 1
                                                        ? '1 direct PDF'
                                                        : `${pageFolder.files.length} direct PDFs`
                                                }}
                                            </p>
                                        </div>
                                        <div
                                            class="flex items-center gap-2"
                                        >
                                            <Badge
                                                v-if="pageFolder.issueMessage"
                                                variant="destructive"
                                            >
                                                Needs attention
                                            </Badge>
                                            <Badge
                                                v-else
                                                variant="outline"
                                                class="border-emerald-200 bg-emerald-50 text-emerald-700"
                                            >
                                                Ready
                                            </Badge>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                :disabled="
                                                    bulkFolderForm.processing
                                                "
                                                @click="
                                                    removePageFolder(
                                                        pageFolder.key,
                                                    )
                                                "
                                            >
                                                <Trash2 class="size-4" />
                                                <span class="sr-only">
                                                    Remove page folder
                                                </span>
                                            </Button>
                                        </div>
                                    </div>
                                    <p
                                        v-if="pageFolder.issueMessage"
                                        class="mt-3 text-sm text-destructive"
                                    >
                                        {{ pageFolder.issueMessage }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <DialogFooter class="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                :disabled="bulkFolderForm.processing"
                                @click="
                                    handleBulkFolderDialogOpenChange(false)
                                "
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                class="gap-2"
                                :disabled="!canSubmitBulkFolderMerge"
                            >
                                <LoaderCircle
                                    v-if="bulkFolderForm.processing"
                                    class="size-4 animate-spin"
                                />
                                <Upload v-else class="size-4" />
                                {{
                                    bulkFolderForm.processing
                                        ? 'Processing folders...'
                                        : 'Bulk merge folders'
                                }}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog
                :open="isBulkZipDialogOpen"
                @update:open="handleBulkZipDialogOpenChange"
            >
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Bulk merge ZIP</DialogTitle>
                        <DialogDescription>
                            Upload one ZIP with page folders like PAGE 1 and
                            PAGE 2 at the ZIP root, or inside one wrapper
                            folder. PDFs with the same base name across all
                            page folders will be merged together.
                        </DialogDescription>
                    </DialogHeader>

                    <form
                        class="space-y-6"
                        @submit.prevent="submitBulkZipMerge"
                    >
                        <input
                            ref="bulkZipInput"
                            type="file"
                            accept=".zip,application/zip,application/x-zip-compressed"
                            class="hidden"
                            @change="handleBulkZipSelection"
                        />

                        <div
                            class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground"
                        >
                            <p class="font-medium text-foreground">
                                ZIP rules
                            </p>
                            <p class="mt-2">
                                PAGE 1, PAGE 2, PAGE 10, and the other page
                                folders can sit directly in the ZIP, or inside
                                one extra wrapper folder.
                            </p>
                            <p class="mt-2">
                                Each page folder must contain only direct PDFs.
                                Matching uses the PDF name without the ending
                                page number, so
                                <span class="font-medium text-foreground">
                                    invoice 1.pdf
                                </span>
                                and
                                <span class="font-medium text-foreground">
                                    invoice 2.pdf
                                </span>
                                become one output.
                            </p>
                            <p class="mt-2">
                                If a PDF ends with a page number, that number
                                must match its page folder.
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="bulkZipOutputPrefix">
                                Output prefix
                                <span class="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="bulkZipOutputPrefix"
                                v-model="bulkZipForm.outputPrefix"
                                type="text"
                                placeholder="ClientA_"
                            />
                            <p class="text-xs text-muted-foreground">
                                Each output becomes
                                <span class="font-medium text-foreground">
                                    {{ bulkZipOutputPreview }}
                                </span>
                                .
                            </p>
                            <InputError
                                :message="
                                    bulkZipOutputPrefixError ?? undefined
                                "
                            />
                        </div>

                        <div
                            class="rounded-2xl border bg-background p-4 text-sm"
                        >
                            <p class="font-medium text-foreground">
                                Output preview
                            </p>
                            <p class="mt-2 text-muted-foreground">
                                Each output becomes
                                <span class="font-medium text-foreground">
                                    {{ bulkZipOutputPreview }}
                                </span>
                                .
                            </p>
                        </div>

                        <div class="space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="gap-2"
                                    :disabled="bulkZipForm.processing"
                                    @click="openBulkZipPicker"
                                >
                                    <Upload class="size-4" />
                                    Choose ZIP
                                </Button>
                                <span
                                    v-if="bulkZipForm.zip"
                                    class="self-center text-sm text-muted-foreground"
                                >
                                    <span class="font-medium text-foreground">
                                        {{ bulkZipForm.zip.name }}
                                    </span>
                                    ({{
                                        formatFileSize(
                                            bulkZipForm.zip.size,
                                        )
                                    }})
                                </span>
                            </div>
                            <InputError
                                :message="bulkZipFieldError ?? undefined"
                            />
                            <p
                                v-if="bulkZipForm.progress"
                                class="text-xs text-muted-foreground"
                            >
                                Uploading
                                {{ bulkZipForm.progress.percentage }}%
                            </p>
                        </div>

                        <DialogFooter class="gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                :disabled="bulkZipForm.processing"
                                @click="handleBulkZipDialogOpenChange(false)"
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                class="gap-2"
                                :disabled="!canSubmitBulkZipMerge"
                            >
                                <LoaderCircle
                                    v-if="bulkZipForm.processing"
                                    class="size-4 animate-spin"
                                />
                                <Upload v-else class="size-4" />
                                {{
                                    bulkZipForm.processing
                                        ? 'Processing ZIP...'
                                        : 'Bulk merge ZIP'
                                }}
                            </Button>
                        </DialogFooter>
                    </form>
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
                            <template
                                v-if="mergeHistoryForDeletion.length === 1"
                            >
                                Delete
                                <span class="font-medium text-foreground">
                                    {{
                                        mergeHistoryForDeletion[0]
                                            ? mergeHistoryDeleteLabel(
                                                  mergeHistoryForDeletion[0],
                                              )
                                            : ''
                                    }}
                                </span>
                                ? Saved PDFs also remove any attached receipt.
                            </template>
                            <template
                                v-else-if="mergeHistoryForDeletion.length > 1"
                            >
                                Delete
                                <span class="font-medium text-foreground">
                                    {{ mergeHistoryForDeletion.length }}
                                </span>
                                selected merge results? Saved PDFs also remove
                                any attached receipts.
                            </template>
                            <template v-else>
                                Delete the selected merge results.
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
                                    : mergeHistoryForDeletion.length === 1
                                      ? 'Delete result'
                                      : `Delete ${mergeHistoryForDeletion.length} results`
                            }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog
                :open="isFailureDialogOpen"
                @update:open="handleFailureDialogOpenChange"
            >
                <DialogContent class="sm:max-w-lg">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Bulk merge error</DialogTitle>
                        <DialogDescription>
                            Review why this output was skipped during bulk
                            merge.
                        </DialogDescription>
                    </DialogHeader>

                    <div
                        v-if="mergeFailureForDialog"
                        class="space-y-4 text-sm"
                    >
                        <div class="rounded-2xl border bg-muted/30 p-4">
                            <div class="space-y-1">
                                <p class="font-medium text-foreground">
                                    Output file
                                </p>
                                <p>
                                    {{ mergeFailureForDialog.fileName }}
                                </p>
                            </div>
                            <div class="mt-4 space-y-1">
                                <p class="font-medium text-foreground">
                                    Matched PDF
                                </p>
                                <p>
                                    {{ mergeFailureForDialog.groupLabel }}
                                </p>
                            </div>
                            <div class="mt-4 space-y-1">
                                <p class="font-medium text-foreground">
                                    Source type
                                </p>
                                <p>
                                    {{
                                        mergeFailureInputModeLabel(
                                            mergeFailureForDialog.inputMode,
                                        )
                                    }}
                                </p>
                            </div>
                            <div class="mt-4 space-y-1">
                                <p class="font-medium text-foreground">
                                    Source label
                                </p>
                                <p class="break-words">
                                    {{
                                        mergeFailureForDialog.inputLabel
                                    }}
                                </p>
                            </div>
                        </div>

                        <div
                            class="rounded-2xl border border-destructive/20 bg-destructive/5 p-4"
                        >
                            <p class="font-medium text-destructive">Error</p>
                            <p class="mt-2 text-foreground">
                                {{ mergeFailureForDialog.errorMessage }}
                            </p>
                        </div>
                    </div>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            @click="handleFailureDialogOpenChange(false)"
                        >
                            Close
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
