export type EmailAttachment = {
    id: number;
    fileName: string;
    fileSize: number | null;
    contentType: string | null;
    isInline: boolean;
    downloadUrl: string;
};

export type EmailRecord = {
    id: number;
    mailbox: string;
    fromName: string | null;
    fromEmail: string | null;
    subject: string | null;
    bodyPreview: string | null;
    bodyText: string | null;
    hasHtmlBody: boolean;
    htmlUrl: string | null;
    attachments: EmailAttachment[];
    receivedAt: string | null;
    syncedAt: string | null;
    matchedTin: string | null;
    matchStatus:
        | 'no_details'
        | 'no_tin'
        | 'unmatched'
        | 'pending_pdf'
        | 'queued'
        | 'applied'
        | 'failed'
        | null;
    matchError: string | null;
    parsedBirReceiptDetails: {
        fileName: string | null;
        formType: string | null;
        dateReceived: string | null;
        timeReceived: string | null;
    };
};

export type SyncResult = {
    fetched: number;
    created: number;
    updated: number;
    mailbox: string;
};

export type ConnectionState = {
    gmailAddressMasked: string | null;
    imapConfigured: boolean;
    imapHost: string | null;
    imapPort: number | string | null;
    imapEncryption: string | null;
    mailbox: string | null;
    smtpConfigured: boolean;
    smtpHost: string | null;
    smtpPort: number | string | null;
    smtpScheme: string | null;
};

export type StatsState = {
    totalStored: number;
    latestSyncedAt: string | null;
};

export type FlashState = {
    success: string | null;
    error: string | null;
    syncResult: SyncResult | null;
};

export type EmailSyncPageProps = {
    connection: ConnectionState;
    flash: FlashState;
    stats: StatsState;
    emails: EmailRecord[];
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    appliedEmails: EmailRecord[];
    appliedPagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    receiptCounts: {
        unmatched: number;
        applied: number;
    };
    filters: {
        search: string;
        formType: string;
        formTypeOptions: string[];
    };
};

export type JsonEmailPayload = {
    emails: EmailRecord[];
    hasMoreEmails: boolean;
    nextCursor: string | null;
};

export type BodySegment = {
    type: 'text' | 'link';
    value: string;
    href?: string;
};

export type HighlightSegment = {
    value: string;
    isMatch: boolean;
};

export type InboxFilter = 'all' | 'attachments';
