export type FlashState = {
    success: string | null;
    error: string | null;
};

export type ReceiptJobStatus = 'queued' | 'processing' | 'failed' | null;

export type Form1702ExBatchSummary = {
    id: string;
    name: string;
    rowCount: number;
    lastUploadedAt: string | null;
    showUrl: string;
};

export type Form1702ExReceiptField = {
    key: string;
    label: string;
};

export type Form1702ExBatchRow = {
    id: string;
    fileName: string;
    taxpayerName: string;
    tin: string;
    sourceRowNumber: number;
    sourceName: string;
    uploadedAt: string | null;
    receiptAcceptanceStartDate: string | null;
    pdfStatus: string;
    generatedAt: string | null;
    previewUrl: string | null;
    downloadUrl: string | null;
    hasReceipt: boolean;
    receiptFileName: string | null;
    receiptFileSize: number | null;
    receiptJobStatus: ReceiptJobStatus;
    receiptJobError: string | null;
    receiptStoreUrl: string;
    receiptRemoveUrl: string | null;
    receiptDownloadUrl: string | null;
    regenerateUrl: string;
    pdfError: string | null;
    autoReceiptStatus:
        | 'pending_pdf'
        | 'queued'
        | 'applied'
        | 'failed'
        | null;
    autoReceiptError: string | null;
    recipientEmail: string | null;
    sendEmailUrl: string | null;
    footerSourcePath: string;
    footerPrintedDate: string;
};

export type Form1702ExReceiptTemplateState = {
    fields: Form1702ExReceiptField[];
};

export type Form1702ExSettings = {
    fileNamePrefix: string;
    footerSourcePath: string;
    footerPrintedDate: string;
    receiptAcceptanceStartDate: string | null;
};

export type Form1702ExRowPagination = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type Form1702ExRowFilters = {
    search: string;
    sort: 'uploadedAt' | 'generatedAt' | 'pdfStatus' | 'sourceRowNumber';
    direction: 'asc' | 'desc';
};

export type Form1702ExCompletedPageProps = {
    flash: FlashState;
    indexUrl: string;
    completedFilesUrl: string;
    completedBulkSendUrl: string;
    rows: Form1702ExBatchRow[];
    pagination: Form1702ExRowPagination;
    filters: Form1702ExRowFilters;
};

export type Form1702ExBatchDetail = {
    id: string;
    name: string;
    showUrl: string;
    importUrl: string;
    prefixUpdateUrl: string;
    footerUpdateUrl: string;
    receiptTemplateUrl: string;
    receiptTemplate: Form1702ExReceiptTemplateState;
    bulkDeleteUrl: string;
    templateSpreadsheetUrl: string;
    fileNamePrefix: string;
    footerSourcePath: string;
    footerPrintedDate: string;
    receiptAcceptanceStartDate: string | null;
    rows: Form1702ExBatchRow[];
    isProcessing: boolean;
    hasActiveReceiptJobs: boolean;
};
