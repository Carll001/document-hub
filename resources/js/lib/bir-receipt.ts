export type BirReceiptDetails = {
    fileName: string | null;
    dateReceived: string | null;
    timeReceived: string | null;
};

function cleanReceiptValue(value: string | undefined): string | null {
    if (!value) {
        return null;
    }

    const normalizedValue = value.replace(/\s+/g, ' ').trim();

    return normalizedValue === '' ? null : normalizedValue;
}

function matchReceiptField(text: string, pattern: RegExp): string | null {
    const match = text.match(pattern);

    return cleanReceiptValue(match?.[1]);
}

function normalizePlaceholderKey(placeholder: string): string {
    return placeholder.trim().toLowerCase().replace(/[.\s-]+/g, '_');
}

export function parseBirReceiptEmailText(
    text: string | null | undefined,
): BirReceiptDetails | null {
    if (!text || text.trim() === '') {
        return null;
    }

    const normalizedText = text.replace(/\r\n?/g, '\n');
    const fileName = matchReceiptField(
        normalizedText,
        /(?:^|\n)\s*File\s*name\s*:\s*(.+?)\s*(?=\n|$)/im,
    );
    const dateReceived = matchReceiptField(
        normalizedText,
        /(?:^|\n)\s*Date\s*received(?:\s+by\s+BIR)?\s*:\s*(.+?)\s*(?=\n|$)/im,
    );
    const timeReceived = matchReceiptField(
        normalizedText,
        /(?:^|\n)\s*Time\s*received(?:\s+by\s+BIR)?\s*:\s*(.+?)\s*(?=\n|$)/im,
    );

    if (!fileName && !dateReceived && !timeReceived) {
        return null;
    }

    return {
        fileName,
        dateReceived,
        timeReceived,
    };
}

export function buildBirReceiptClipboardText(
    details: BirReceiptDetails,
): string {
    return [
        details.fileName ? `File name: ${details.fileName}` : null,
        details.dateReceived
            ? `Date received by BIR: ${details.dateReceived}`
            : null,
        details.timeReceived
            ? `Time received by BIR: ${details.timeReceived}`
            : null,
    ]
        .filter((value): value is string => value !== null)
        .join('\n');
}

export function birReceiptPlaceholderValue(
    placeholder: string,
    details: BirReceiptDetails,
): string | null {
    switch (normalizePlaceholderKey(placeholder)) {
        case 'file_name':
        case 'filename':
        case 'xml_file_name':
        case 'received_file_name':
        case 'submission_file_name':
            return details.fileName;
        case 'date_received':
        case 'received_date':
        case 'bir_received_date':
        case 'date_received_by_bir':
            return details.dateReceived;
        case 'time_received':
        case 'received_time':
        case 'bir_received_time':
        case 'time_received_by_bir':
            return details.timeReceived;
        default:
            return null;
    }
}
