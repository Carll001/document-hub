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
    accountId: number | null;
    accountLabel: string;
    accountEmail: string | null;
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
    accountId: number;
    accountLabel: string;
    fetched: number;
    created: number;
    updated: number;
    mailbox: string;
    skipped: boolean;
};

export type SyncResultDetails = {
    actionLabel: string;
    accountResults: Array<{
        accountId: number;
        accountLabel: string;
        fetched: number;
        created: number;
        updated: number;
        mailbox: string;
    }>;
};

export type ConnectionState = {
    accountCount: number;
    hasActiveAccounts: boolean;
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
    syncResult: SyncResult[] | null;
    syncResultDetails: SyncResultDetails | null;
};

export type EmailSyncAccountOption = {
    id: number;
    label: string;
    username: string | null;
    isActive?: boolean;
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
    syncAccounts: {
        options: EmailSyncAccountOption[];
    };
    filters: {
        search: string;
        formType: string;
        formTypeOptions: string[];
        accountIds: number[];
        accountOptions: EmailSyncAccountOption[];
    };
};

export type AllEmailSyncPageProps = {
    connection: ConnectionState;
    flash: FlashState;
    stats: StatsState;
    emails: EmailRecord[];
    hasMoreEmails: boolean;
    nextEmailsCursor: string | null;
    syncAccounts: {
        options: EmailSyncAccountOption[];
    };
    filters: {
        accountIds: number[];
        accountOptions: EmailSyncAccountOption[];
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
