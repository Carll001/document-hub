import type {
    BatchProcessingStatus,
    BatchFailure,
    BatchInputMode,
    BatchMergeHistoryRecord,
    BatchMergedOutput,
    ReceiptJobStatus,
} from '@/components/doc-merge-batch-components/types';

export function isMergedRecord(
    record: BatchMergeHistoryRecord,
): record is BatchMergedOutput {
    return record.recordType === 'merged_pdf';
}

export function isFailureRecord(
    record: BatchMergeHistoryRecord,
): record is BatchFailure {
    return record.recordType === 'merge_failure';
}

export function mergeHistoryRecordKey(record: BatchMergeHistoryRecord): string {
    return `${record.recordType}:${record.id}`;
}

export function formatFileSize(bytes: number | null): string {
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

export function formatDateTime(value: string | null): string {
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

export function formatDateOnly(value: string | null): string {
    if (!value) {
        return 'Unknown date';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Unknown date';
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'long',
    }).format(date);
}

export function formatTinDigitsOnly(value: string | null): string {
    return value ? value.replace(/\D+/g, '') : '';
}

export function mergeHistorySearchText(record: BatchMergeHistoryRecord): string {
    return [
        record.fileName,
        ...record.sourceFileNames,
        record.tinNumber ?? '',
        formatTinDigitsOnly(record.tinNumber),
        record.footerText ?? '',
        record.receiptFileName ?? '',
        record.recordType === 'merged_pdf' ? record.receiptJobError ?? '' : '',
        record.recordType === 'merge_failure' ? record.groupLabel : '',
        record.recordType === 'merge_failure' ? record.errorMessage : '',
        formatDateTime(record.createdAt),
    ]
        .join(' ')
        .toLowerCase();
}

export function defaultConfirmationPlaceholderValue(
    mergedPdf: BatchMergedOutput,
    placeholder: string,
): string {
    const normalizedPlaceholder = placeholder
        .trim()
        .toLowerCase()
        .replace(/[.\s-]+/g, '_');

    switch (normalizedPlaceholder) {
        case 'file_name':
        case 'filename':
        case 'pdf_name':
        case 'document_name':
        case 'merged_pdf':
            return mergedPdf.fileName;
        case 'tin':
        case 'tin_number':
            return mergedPdf.tinNumber ?? '';
        case 'footer':
        case 'footer_text':
            return mergedPdf.footerText ?? '';
        case 'source_count':
        case 'sources':
            return String(mergedPdf.sourceCount);
        case 'saved_at':
        case 'created_at':
            return formatDateTime(mergedPdf.createdAt);
        case 'saved_date':
        case 'confirmation_date':
        case 'date':
            return formatDateOnly(new Date().toISOString());
        default:
            return '';
    }
}

export function confirmationPlaceholderLabel(placeholder: string): string {
    const label = placeholder.replace(/[._-]+/g, ' ').trim();

    if (label === '') {
        return placeholder;
    }

    return label.replace(/\b\w/g, (match) => match.toUpperCase());
}

export function confirmationPlaceholderToken(placeholder: string): string {
    return `{${placeholder}}`;
}

export function mergeFailureInputModeLabel(inputMode: BatchInputMode): string {
    if (inputMode === 'zip') {
        return 'ZIP upload';
    }

    if (inputMode === 'batch') {
        return 'Batch run';
    }

    return 'Folder upload';
}

export function bulkOutputPreview(prefix: string): string {
    return `${prefix}PDF_NAME`;
}

export function batchProcessingIsActive(status: BatchProcessingStatus): boolean {
    return status === 'queued' || status === 'processing';
}

export function batchProcessingStatusLabel(status: BatchProcessingStatus): string {
    if (status === 'queued') {
        return 'Queued';
    }

    if (status === 'processing') {
        return 'Processing';
    }

    if (status === 'failed') {
        return 'Failed';
    }

    return 'Ready';
}

export function defaultEmailSubject(mergedPdf: BatchMergedOutput): string {
    return `Merged PDF: ${mergedPdf.fileName}`;
}

export function defaultEmailMessage(mergedPdf: BatchMergedOutput): string {
    return `Hi,\n\nAttached is ${mergedPdf.fileName}.\n\nThanks.`;
}

export function receiptJobIsActive(status: ReceiptJobStatus): boolean {
    return status === 'queued' || status === 'processing';
}

export function receiptJobStatusLabel(status: ReceiptJobStatus): string {
    if (status === 'queued') {
        return 'Queued';
    }

    if (status === 'processing') {
        return 'Processing';
    }

    if (status === 'failed') {
        return 'Failed';
    }

    return 'Ready';
}
