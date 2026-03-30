export type FlashState = {
    success?: string | null;
    error?: string | null;
};

export type MergeHistoryRecordType = 'merged_pdf' | 'merge_failure';
export type BulkInputMode = 'zip' | 'folder' | 'batch';

export type ConfirmationTemplateState = {
    hasTemplate: boolean;
    fileName: string | null;
    fileSize: number | null;
    placeholders: string[];
    downloadUrl: string | null;
};

export type BatchSummary = {
    id: string;
    name: string;
    mergedCount: number;
    failedCount: number;
    lastProcessedAt: string | null;
    showUrl: string;
    downloadUrl: string;
};

export type BatchPaginationState = {
    currentPage: number;
    lastPage: number;
};

export type MergedPdfRecord = {
    recordType: 'merged_pdf';
    id: number;
    fileName: string;
    fileSize: number;
    sourceCount: number;
    sourceFileNames: string[];
    tinNumber: string | null;
    footerText: string | null;
    hasReceipt: boolean;
    receiptFileName: string | null;
    receiptFileSize: number | null;
    createdAt: string | null;
    downloadUrl: string;
    previewUrl: string;
    receiptStoreUrl: string;
    receiptRemoveUrl: string | null;
    receiptDownloadUrl: string | null;
    sendEmailUrl: string;
    batchName: string | null;
    batchShowUrl: string | null;
    inputMode: null;
    inputLabel: null;
    groupLabel: null;
    errorMessage: null;
};

export type MergeFailureRecord = {
    recordType: 'merge_failure';
    id: number;
    fileName: string;
    fileSize: null;
    sourceCount: null;
    sourceFileNames: string[];
    tinNumber: null;
    footerText: null;
    hasReceipt: false;
    receiptFileName: null;
    receiptFileSize: null;
    createdAt: string | null;
    downloadUrl: null;
    previewUrl: null;
    receiptStoreUrl: null;
    receiptRemoveUrl: null;
    receiptDownloadUrl: null;
    sendEmailUrl: null;
    batchName: string | null;
    batchShowUrl: string | null;
    inputMode: BulkInputMode;
    inputLabel: string;
    groupLabel: string;
    errorMessage: string;
};

export type MergeHistoryRecord = MergedPdfRecord | MergeFailureRecord;

export type MergeSourceType = 'upload' | 'merged_pdf';

export type DeleteItemPayload = {
    type: MergeHistoryRecordType;
    id: number;
};

export type MergeSourcePayload = {
    type: MergeSourceType;
    id?: number;
};

export type MergeQueueItem = {
    key: string;
    type: MergeSourceType;
    title: string;
    subtitle: string;
    size: number | null;
    id?: number;
    locked: boolean;
    file?: File;
};

export type PageFolderUploadItem = {
    key: string;
    name: string;
    number: number | null;
    files: File[];
    hasNestedEntries: boolean;
    hasInvalidFiles: boolean;
};

export type PageFolderDisplayItem = PageFolderUploadItem & {
    issueMessage: string | null;
};

export type PageFolderPayload = {
    name: string;
    number: number;
    hasNestedEntries: boolean;
    hasInvalidFiles: boolean;
    files: File[];
};
