import type {
    BodySegment,
    EmailAttachment,
    EmailRecord,
    HighlightSegment,
} from '@/components/email-sync-components/types';

const BODY_TRUNCATE_LIMIT = 1200;
const PH_TIME_ZONE = 'Asia/Manila';

export function formatDateTime(
    value: string | null,
    fallback = 'Unavailable',
): string {
    const date = parseDateValue(value);

    if (!date) {
        return fallback;
    }

    return `${new Intl.DateTimeFormat('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
        timeZone: PH_TIME_ZONE,
    }).format(date)} PH time`;
}

export function formatRelativeTime(
    value: string | null,
    fallback = 'Unknown',
): string {
    const date = parseDateValue(value);

    if (!date) {
        return fallback;
    }

    const diffInSeconds = Math.round((date.getTime() - Date.now()) / 1000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    const units = [
        ['year', 60 * 60 * 24 * 365],
        ['month', 60 * 60 * 24 * 30],
        ['week', 60 * 60 * 24 * 7],
        ['day', 60 * 60 * 24],
        ['hour', 60 * 60],
        ['minute', 60],
    ] as const;

    for (const [unit, seconds] of units) {
        if (Math.abs(diffInSeconds) >= seconds) {
            return formatter.format(Math.round(diffInSeconds / seconds), unit);
        }
    }

    return 'just now';
}

export function parseDateValue(value: string | null): Date | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

export function emailTimestampForDisplay(email: EmailRecord): string | null {
    const receivedAt = parseDateValue(email.receivedAt);
    const syncedAt = parseDateValue(email.syncedAt);

    if (!receivedAt) {
        return email.syncedAt;
    }

    if (!syncedAt) {
        return email.receivedAt;
    }

    return receivedAt.getTime() > syncedAt.getTime()
        ? email.syncedAt
        : email.receivedAt;
}

export function formatFileSize(bytes: number | null): string {
    if (bytes === null || Number.isNaN(bytes)) {
        return 'Unknown size';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    const units = ['KB', 'MB', 'GB'];
    let value = bytes / 1024;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unitIndex]}`;
}

export function emailHeading(email: EmailRecord): string {
    return email.subject?.trim() || '(No subject)';
}

export function senderDisplayName(email: EmailRecord): string {
    return (
        email.fromName?.trim() || email.fromEmail?.trim() || 'Unknown sender'
    );
}

export function senderLine(email: EmailRecord): string {
    return email.fromEmail?.trim() || 'No reply-to address';
}

export function previewLine(email: EmailRecord): string {
    const preview = email.bodyPreview?.trim();

    if (preview) {
        return preview;
    }

    if (visibleAttachments(email).length > 0) {
        return 'Attachment-only email.';
    }

    return 'No preview available yet.';
}

export function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

export function highlightText(
    value: string,
    query: string,
): HighlightSegment[] {
    if (value === '') {
        return [];
    }

    const trimmedQuery = query.trim();

    if (trimmedQuery === '') {
        return [{ value, isMatch: false }];
    }

    const pattern = new RegExp(`(${escapeRegExp(trimmedQuery)})`, 'gi');

    return value
        .split(pattern)
        .filter((part) => part !== '')
        .map((part) => ({
            value: part,
            isMatch: part.toLowerCase() === trimmedQuery.toLowerCase(),
        }));
}

export function emailSearchableText(email: EmailRecord): string {
    return [
        email.accountLabel,
        email.accountEmail,
        email.mailbox,
        email.subject,
        email.fromName,
        email.fromEmail,
        email.bodyPreview,
        email.bodyText,
        ...visibleAttachments(email).map((attachment) => attachment.fileName),
    ]
        .filter((value): value is string => Boolean(value))
        .join(' ')
        .toLowerCase();
}

export function visibleAttachments(email: EmailRecord): EmailAttachment[] {
    return email.attachments.filter((attachment) => {
        if (!attachment.isInline) {
            return true;
        }

        return !(
            email.hasHtmlBody &&
            (attachment.contentType?.startsWith('image/') ?? false)
        );
    });
}

export function attachmentCountLabel(email: EmailRecord): string {
    const attachmentCount = visibleAttachments(email).length;

    return attachmentCount === 1 ? '1 file' : `${attachmentCount} files`;
}

export function avatarText(email: EmailRecord): string {
    return senderDisplayName(email)
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((chunk) => chunk.charAt(0))
        .join('')
        .toUpperCase()
        .slice(0, 2);
}

export function bodyLines(bodyText: string | null): BodySegment[][] {
    if (!bodyText || bodyText.trim() === '') {
        return [];
    }

    return bodyText.split(/\r?\n/).map((line) => linkifyLine(line));
}

export function linkifyLine(line: string): BodySegment[] {
    if (line === '') {
        return [];
    }

    const pattern = /https?:\/\/[^\s<>()]+/g;
    const segments: BodySegment[] = [];
    let lastIndex = 0;

    for (const match of line.matchAll(pattern)) {
        const index = match.index ?? 0;
        const rawUrl = match[0];
        const cleanUrl = rawUrl.replace(/[),.;!?]+$/u, '');
        const trailingText = rawUrl.slice(cleanUrl.length);

        if (index > lastIndex) {
            segments.push({
                type: 'text',
                value: line.slice(lastIndex, index),
            });
        }

        segments.push({
            type: 'link',
            value: cleanUrl,
            href: cleanUrl,
        });

        if (trailingText !== '') {
            segments.push({
                type: 'text',
                value: trailingText,
            });
        }

        lastIndex = index + rawUrl.length;
    }

    if (lastIndex < line.length) {
        segments.push({
            type: 'text',
            value: line.slice(lastIndex),
        });
    }

    return segments.length > 0 ? segments : [{ type: 'text', value: line }];
}

export function bodyTextForDisplay(
    email: EmailRecord,
    expanded: boolean,
): string | null {
    if (!email.bodyText || email.bodyText.trim() === '') {
        return null;
    }

    if (expanded || email.bodyText.length <= BODY_TRUNCATE_LIMIT) {
        return email.bodyText;
    }

    return truncatedBodyText(email.bodyText);
}

export function shouldShowBodyToggle(email: EmailRecord): boolean {
    return (email.bodyText?.length ?? 0) > BODY_TRUNCATE_LIMIT;
}

export function truncatedBodyText(bodyText: string): string {
    if (bodyText.length <= BODY_TRUNCATE_LIMIT) {
        return bodyText;
    }

    const truncatedText = bodyText.slice(0, BODY_TRUNCATE_LIMIT);
    const lastWhitespace = truncatedText.lastIndexOf(' ');
    const safeEnd =
        lastWhitespace > BODY_TRUNCATE_LIMIT * 0.7
            ? lastWhitespace
            : BODY_TRUNCATE_LIMIT;

    return `${truncatedText.slice(0, safeEnd).trimEnd()}...`;
}

export function emptyBodyMessage(email: EmailRecord): string {
    if (visibleAttachments(email).length > 0) {
        return 'This email does not have message text. You can download its attachment below.';
    }

    return 'No message body was extracted for this email yet.';
}

export function emailMatchStatusLabel(status: EmailRecord['matchStatus']): string {
    switch (status) {
        case 'no_details':
            return 'No receipt details';
        case 'no_tin':
            return 'No TIN found';
        case 'unmatched':
            return 'Unmatched';
        case 'pending_pdf':
            return 'Waiting for PDF';
        case 'queued':
            return 'Queued';
        case 'applied':
            return 'Applied';
        case 'failed':
            return 'Failed';
        default:
            return 'Unknown';
    }
}
