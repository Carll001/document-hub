export type FlashState = {
    success?: string | null;
    error?: string | null;
};

export type BatchProcessingStatus = 'queued' | 'processing' | 'failed' | null;
export type ReceiptJobStatus = 'queued' | 'processing' | 'failed' | null;
export type BatchDownloadExportStatus = 'queued' | 'processing' | 'failed' | 'ready' | null;
export type BatchDownloadExportState = {
    status: BatchDownloadExportStatus;
    error: string | null;
    itemCount: number | null;
    downloadUrl: string | null;
};

export type ConfirmationTemplateState = {
    hasTemplate: boolean;
    fileName: string | null;
    fileSize: number | null;
    placeholders: string[];
    downloadUrl: string | null;
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

export type BatchResultType = 'merged_pdf' | 'merge_failure';
export type BatchInputMode = 'zip' | 'folder' | 'batch';

export type BatchMergedOutput = {
    recordType: 'merged_pdf';
    id: string;
    fileName: string;
    fileSize: number | null;
    sourceCount: number;
    sourceFileNames: string[];
    tinNumber: string | null;
    footerText: string | null;
    hasReceipt: boolean;
    receiptFileName: string | null;
    receiptFileSize: number | null;
    receiptJobStatus: ReceiptJobStatus;
    receiptJobError: string | null;
    createdAt: string | null;
    downloadUrl: string;
    previewUrl: string;
    receiptStoreUrl: string;
    receiptRemoveUrl: string | null;
    sendEmailUrl: string;
};

export type BatchFailure = {
    recordType: 'merge_failure';
    id: string;
    fileName: string;
    fileSize: null;
    sourceCount: null;
    sourceFileNames: [];
    tinNumber: null;
    footerText: null;
    hasReceipt: false;
    receiptFileName: null;
    receiptFileSize: null;
    downloadUrl: null;
    previewUrl: null;
    receiptStoreUrl: null;
    receiptRemoveUrl: null;
    sendEmailUrl: null;
    inputMode: BatchInputMode;
    inputLabel: string;
    groupLabel: string;
    errorMessage: string;
    createdAt: string | null;
};

export type BatchMergeHistoryRecord = BatchMergedOutput | BatchFailure;

export type BatchResultsPaginationState = {
    currentPage: number;
    lastPage: number;
};

export type BatchDetail = {
    id: string;
    name: string;
    mergedCount: number;
    failedCount: number;
    lastProcessedAt: string | null;
    processingStatus: BatchProcessingStatus;
    processingError: string | null;
    showUrl: string;
    downloadUrl: string;
    downloadQueueUrl: string;
    downloadStateUrl: string;
    downloadExportState: BatchDownloadExportState;
    deleteUrl: string;
    uploadPageFoldersUrl: string;
    uploadZipUrl: string;
    results: BatchMergeHistoryRecord[];
    resultsPagination: BatchResultsPaginationState;
};

export type DeleteItemPayload = {
    type: BatchResultType;
    id: string;
};
