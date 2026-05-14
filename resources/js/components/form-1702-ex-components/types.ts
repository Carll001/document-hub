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
    clientName: string | null;
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
    temporaryReceiptStoreUrl: string | null;
    receiptRemoveUrl: string | null;
    receiptDownloadUrl: string | null;
    isTemporaryReceipt: boolean;
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
    updateRecipientUrl: string;
    signatureUploadUrl: string;
    signatureApplied: boolean;
    signaturePreviewUrl: string | null;
    sendEmailUrl: string | null;
    cancelUrl: string | null;
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

export type Form1702ExImportStatus = 'queued' | 'processing' | 'failed' | 'cancelled' | null;

export type Form1702ExCompletedExportStatus =
    | 'queued'
    | 'processing'
    | 'failed'
    | 'ready'
    | null;

export type Form1702ExCompletedExportState = {
    status: Form1702ExCompletedExportStatus;
    error: string | null;
    rowCount: number | null;
    downloadUrl: string | null;
};

export type Form1702ExIndexPageProps = {
    flash: FlashState;
    indexUrl: string;
    completedFilesUrl: string;
    completedCount: number;
    importUrl: string;
    importCancelUrl: string;
    bulkDeleteUrl: string;
    rowsExportUrl: string;
    rowsPdfExportUrl: string;
    settingsUpdateUrl: string;
    signatureUploadUrl: string;
    templateSpreadsheetUrl: string;
    receiptTemplateUrl: string;
    receiptTemplate: Form1702ExReceiptTemplateState;
    settings: Form1702ExSettings;
    rows: Form1702ExBatchRow[];
    pagination: Form1702ExRowPagination;
    filters: Form1702ExRowFilters;
    importStatus: Form1702ExImportStatus;
    importError: string | null;
    importSourceName: string | null;
    rowsExportState: Form1702ExCompletedExportState;
    rowsPdfExportState: Form1702ExCompletedExportState;
    hasActiveJobs: boolean;
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
    status: 'all' | 'generated' | 'processing' | 'signed' | 'not_signed' | 'receipt_attached';
};

export type Form1702ExCompletedPageProps = {
    flash: FlashState;
    indexUrl: string;
    completedFilesUrl: string;
    completedBulkCancelUrl: string;
    completedBulkSendUrl: string;
    rows: Form1702ExBatchRow[];
    pagination: Form1702ExRowPagination;
    filters: Form1702ExRowFilters;
    exportState: Form1702ExCompletedExportState;
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
