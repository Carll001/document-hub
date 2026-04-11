export function formatDateTime(value: string | null): string {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-PH', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
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

export function pdfFileNamePreview(
    prefix: string | null,
    taxpayerName: string | null = 'TAXPAYER_NAME',
): string {
    const prefixToken = normalizeFileNameToken(prefix, false);
    const taxpayerToken =
        normalizeFileNameToken(taxpayerName, true) || 'TAXPAYER_NAME';
    const tokens = [prefixToken, taxpayerToken].filter((token) => token !== '');

    return `${tokens.join('_')}.pdf`;
}

export function pdfStatusLabel(status: string): string {
    if (status === 'queued') {
        return 'Queued';
    }

    if (status === 'processing') {
        return 'Processing';
    }

    if (status === 'generated') {
        return 'Generated';
    }

    if (status === 'failed') {
        return 'Failed';
    }

    return status;
}

export function pdfStatusClass(status: string): string | undefined {
    if (status === 'queued') {
        return 'border-amber-200 bg-amber-50 text-amber-700';
    }

    if (status === 'processing') {
        return 'border-sky-200 bg-sky-50 text-sky-700';
    }

    if (status === 'generated') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    }

    return undefined;
}

export function receiptJobIsActive(status: string | null): boolean {
    return status === 'queued' || status === 'processing';
}

export function receiptJobStatusLabel(status: string | null): string {
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

function normalizeFileNameToken(
    value: string | null,
    uppercase: boolean,
): string {
    const normalized = String(value ?? '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/[^A-Za-z0-9]+/g, '_')
        .replace(/_+/g, '_')
        .replace(/^_+|_+$/g, '');

    return uppercase ? normalized.toUpperCase() : normalized;
}
