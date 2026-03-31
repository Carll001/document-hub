import type {
    BatchProcessingStatus,
    BulkInputMode,
    MergeHistoryRecord,
    MergeSourceType,
    MergedPdfRecord,
} from '@/components/doc-merge-components/types';

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

export function mergeFailureInputModeLabel(inputMode: BulkInputMode): string {
    if (inputMode === 'zip') {
        return 'ZIP upload';
    }

    if (inputMode === 'batch') {
        return 'Batch workspace';
    }

    return 'Folder upload';
}

export function mergeHistoryRecordKey(record: MergeHistoryRecord): string {
    return `${record.recordType}:${record.id}`;
}

export function isMergedPdfRecord(
    record: MergeHistoryRecord,
): record is MergedPdfRecord {
    return record.recordType === 'merged_pdf';
}

export function isMergeFailureRecord(
    record: MergeHistoryRecord,
): record is Extract<MergeHistoryRecord, { recordType: 'merge_failure' }> {
    return record.recordType === 'merge_failure';
}

export function mergeHistoryDeleteLabel(record: MergeHistoryRecord): string {
    return record.fileName;
}

export function mergeHistorySearchText(record: MergeHistoryRecord): string {
    return [
        record.fileName,
        ...record.sourceFileNames,
        record.tinNumber ?? '',
        formatTinDigitsOnly(record.tinNumber),
        record.batchName ?? '',
        record.footerText ?? '',
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

export function mergeSourceTypeLabel(type: MergeSourceType): string {
    if (type === 'upload') {
        return 'Upload';
    }

    return 'Saved merge';
}

export function mergeSourceTypeVariant(
    type: MergeSourceType,
): 'default' | 'secondary' | 'outline' {
    if (type === 'upload') {
        return 'secondary';
    }

    return 'default';
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

export function defaultOutputName(): string {
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

export function defaultEmailSubject(mergedPdf: MergedPdfRecord): string {
    return `Merged PDF: ${mergedPdf.fileName}`;
}

export function defaultEmailMessage(
    mergedPdf: MergedPdfRecord,
    appName: string,
): string {
    return [
        'Hello,',
        '',
        `Please find attached "${mergedPdf.fileName}".`,
        '',
        `Sent from ${appName}.`,
    ].join('\n');
}

export function defaultConfirmationPlaceholderValue(
    mergedPdf: MergedPdfRecord,
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

export function bulkOutputPreview(prefix: string): string {
    return `${prefix}PDF_NAME`;
}
