<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppHeaderLayout from '@/layouts/app/AppHeaderLayout.vue';
import { dashboard } from '@/routes';
import emailSync from '@/routes/email-sync';
import type { BreadcrumbItem } from '@/types';

type EmailAttachment = {
    id: number;
    fileName: string;
    fileSize: number | null;
    contentType: string | null;
    downloadUrl: string;
};

type EmailRecord = {
    id: number;
    mailbox: string;
    fromName: string | null;
    fromEmail: string | null;
    subject: string | null;
    bodyPreview: string | null;
    bodyText: string | null;
    attachments: EmailAttachment[];
    receivedAt: string | null;
    syncedAt: string | null;
};

type SyncResult = {
    fetched: number;
    created: number;
    updated: number;
    mailbox: string;
};

type Props = {
    connection: {
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
    flash: {
        success: string | null;
        error: string | null;
        syncResult: SyncResult | null;
    };
    stats: {
        totalStored: number;
        latestSyncedAt: string | null;
    };
    backfill: {
        presets: number[];
        customMax: number;
    };
    emails: EmailRecord[];
    hasMoreEmails: boolean;
    nextCursor: string | null;
};

type JsonEmailPayload = {
    emails: EmailRecord[];
    hasMoreEmails: boolean;
    nextCursor: string | null;
};

type BodySegment = {
    type: 'text' | 'link';
    value: string;
    href?: string;
};

const props = defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
    {
        title: 'Email sync',
        href: emailSync.index(),
    },
];

const page = usePage();

const storedEmails = ref<EmailRecord[]>([...props.emails]);
const hasMoreEmails = ref(props.hasMoreEmails);
const nextCursor = ref(props.nextCursor);
const isLoadingMore = ref(false);
const loadMoreError = ref<string | null>(null);
const backfillMode = ref(
    props.backfill.presets[0] ? String(props.backfill.presets[0]) : 'all',
);
const customBackfillLimit = ref(
    String(
        Math.min(
            Math.max(props.backfill.presets[0] ?? 50, 1),
            props.backfill.customMax,
        ),
    ),
);

watch(
    () => [props.emails, props.hasMoreEmails, props.nextCursor] as const,
    ([emails, hasMore, cursor]) => {
        storedEmails.value = [...emails];
        hasMoreEmails.value = hasMore;
        nextCursor.value = cursor;
        loadMoreError.value = null;
    },
    { deep: true },
);

const signedInUserEmail = computed(
    () => page.props.auth?.user?.email ?? 'Unavailable',
);

const canBackfill = computed(() => props.stats.totalStored > 0);

const latestSyncLabel = computed(() =>
    formatDateTime(props.stats.latestSyncedAt, 'No inbox sync yet'),
);

const syncResultSummary = computed(() => {
    if (!props.flash.syncResult) {
        return null;
    }

    const result = props.flash.syncResult;

    return `${result.fetched} fetched, ${result.created} created, ${result.updated} updated in ${result.mailbox}.`;
});

const loadMoreButtonLabel = computed(() =>
    isLoadingMore.value ? 'Loading older email...' : 'Load more',
);

function formatDateTime(
    value: string | null,
    fallback = 'Unavailable',
): string {
    if (!value) {
        return fallback;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return fallback;
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function formatFileSize(bytes: number | null): string {
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

function emailHeading(email: EmailRecord): string {
    return email.subject?.trim() || '(No subject)';
}

function senderLine(email: EmailRecord): string {
    if (email.fromName && email.fromEmail) {
        return `${email.fromName} <${email.fromEmail}>`;
    }

    return email.fromEmail || email.fromName || 'Unknown sender';
}

function previewLine(email: EmailRecord): string {
    return email.bodyPreview?.trim() || 'No preview available yet.';
}

function bodyLines(bodyText: string | null): BodySegment[][] {
    if (!bodyText || bodyText.trim() === '') {
        return [];
    }

    return bodyText.split(/\r?\n/).map((line) => linkifyLine(line));
}

function linkifyLine(line: string): BodySegment[] {
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

async function loadMoreEmails(): Promise<void> {
    if (!nextCursor.value || isLoadingMore.value) {
        return;
    }

    isLoadingMore.value = true;
    loadMoreError.value = null;

    try {
        const response = await fetch(
            emailSync.emails.url({
                query: {
                    cursor: nextCursor.value,
                },
            }),
            {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        if (!response.ok) {
            throw new Error('Unable to load more email right now.');
        }

        const payload = (await response.json()) as JsonEmailPayload;
        const seenIds = new Set(storedEmails.value.map((email) => email.id));

        storedEmails.value = [
            ...storedEmails.value,
            ...payload.emails.filter((email) => !seenIds.has(email.id)),
        ];
        hasMoreEmails.value = payload.hasMoreEmails;
        nextCursor.value = payload.nextCursor;
    } catch (error) {
        loadMoreError.value =
            error instanceof Error
                ? error.message
                : 'Unable to load more email right now.';
    } finally {
        isLoadingMore.value = false;
    }
}
</script>

<template>
    <Head title="Email sync" />

    <AppHeaderLayout :breadcrumbs="breadcrumbItems">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Heading
                title="Email sync"
                description="Keep a local inbox view that syncs new mail incrementally and imports older history when you ask for it."
            />

            <div
                class="grid gap-4 lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1.3fr)]"
            >
                <div class="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Mailbox context</CardTitle>
                            <CardDescription>
                                Your signed-in account and the configured Gmail
                                mailbox are shown separately so the app identity
                                stays distinct from the mailbox used for IMAP
                                and SMTP.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="rounded-lg border bg-muted/30 p-4">
                                <p
                                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                >
                                    Signed-in user
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ signedInUserEmail }}
                                </p>
                            </div>

                            <div class="rounded-lg border bg-muted/30 p-4">
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <p
                                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        Configured Gmail mailbox
                                    </p>
                                    <Badge
                                        :variant="
                                            connection.imapConfigured
                                                ? 'default'
                                                : 'secondary'
                                        "
                                    >
                                        {{
                                            connection.imapConfigured
                                                ? 'Configured'
                                                : 'Needs setup'
                                        }}
                                    </Badge>
                                </div>
                                <p class="mt-1 text-sm font-medium">
                                    {{
                                        connection.gmailAddressMasked ??
                                        'Set MAIL_USERNAME to the Gmail mailbox you want this app to sync.'
                                    }}
                                </p>
                                <p class="mt-2 text-xs text-muted-foreground">
                                    Sync and outgoing email still use the
                                    configured Gmail mailbox from your `.env`
                                    settings.
                                </p>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-lg border p-4">
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <p class="text-sm font-medium">
                                            Gmail IMAP
                                        </p>
                                        <Badge
                                            :variant="
                                                connection.imapConfigured
                                                    ? 'default'
                                                    : 'secondary'
                                            "
                                        >
                                            {{
                                                connection.imapConfigured
                                                    ? 'Ready'
                                                    : 'Setup needed'
                                            }}
                                        </Badge>
                                    </div>
                                    <p
                                        class="mt-2 text-sm text-muted-foreground"
                                    >
                                        {{
                                            connection.imapHost
                                                ? `${connection.imapHost}:${connection.imapPort} (${connection.imapEncryption || 'none'})`
                                                : 'IMAP host not configured'
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Mailbox:
                                        {{ connection.mailbox || 'INBOX' }}
                                    </p>
                                </div>

                                <div class="rounded-lg border p-4">
                                    <div
                                        class="flex items-center justify-between gap-2"
                                    >
                                        <p class="text-sm font-medium">
                                            Gmail SMTP
                                        </p>
                                        <Badge
                                            :variant="
                                                connection.smtpConfigured
                                                    ? 'default'
                                                    : 'secondary'
                                            "
                                        >
                                            {{
                                                connection.smtpConfigured
                                                    ? 'Ready'
                                                    : 'Setup needed'
                                            }}
                                        </Badge>
                                    </div>
                                    <p
                                        class="mt-2 text-sm text-muted-foreground"
                                    >
                                        {{
                                            connection.smtpHost
                                                ? `${connection.smtpHost}:${connection.smtpPort} (${connection.smtpScheme || 'default'})`
                                                : 'SMTP host not configured'
                                        }}
                                    </p>
                                    <p
                                        class="mt-1 text-xs text-muted-foreground"
                                    >
                                        Sending stays tied to the configured
                                        Gmail mailbox, not the signed-in user
                                        identity.
                                    </p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Sync controls</CardTitle>
                            <CardDescription>
                                Sync new mail like Gmail does: the first run
                                imports your newest 25 emails, and later syncs
                                only pull newer messages. Use older-mail import
                                only when you want more history.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-6">
                            <Form
                                v-bind="emailSync.sync.form()"
                                class="space-y-3"
                                v-slot="{ processing }"
                            >
                                <div class="rounded-lg border bg-muted/30 p-4">
                                    <p class="text-sm font-medium">
                                        Sync inbox now
                                    </p>
                                    <p
                                        class="mt-1 text-sm text-muted-foreground"
                                    >
                                        Imports the newest 25 emails on the
                                        first run, then only newer emails than
                                        your latest saved message.
                                    </p>
                                </div>
                                <Button
                                    type="submit"
                                    class="w-full sm:w-auto"
                                    :disabled="
                                        processing || !connection.imapConfigured
                                    "
                                >
                                    {{
                                        processing
                                            ? 'Syncing inbox...'
                                            : 'Sync inbox now'
                                    }}
                                </Button>
                            </Form>

                            <Separator />

                            <Form
                                v-bind="emailSync.backfill.form()"
                                class="space-y-4"
                                v-slot="{ errors, processing }"
                            >
                                <div class="space-y-2">
                                    <div
                                        class="rounded-lg border bg-muted/30 p-4"
                                    >
                                        <p class="text-sm font-medium">
                                            Import older mail
                                        </p>
                                        <p
                                            class="mt-1 text-sm text-muted-foreground"
                                        >
                                            Pulls older unsaved messages that
                                            sit behind your oldest saved email.
                                            This does not change your normal
                                            incremental sync behavior.
                                        </p>
                                    </div>

                                    <div
                                        v-if="!canBackfill"
                                        class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                                    >
                                        Run your first inbox sync before
                                        importing older history.
                                    </div>
                                </div>

                                <div
                                    class="grid gap-4 md:grid-cols-[minmax(0,220px)_minmax(0,1fr)]"
                                >
                                    <div class="grid gap-2">
                                        <Label for="backfill-mode">
                                            Import size
                                        </Label>
                                        <select
                                            id="backfill-mode"
                                            v-model="backfillMode"
                                            name="mode"
                                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                                            :disabled="
                                                processing ||
                                                !canBackfill ||
                                                !connection.imapConfigured
                                            "
                                        >
                                            <option
                                                v-for="preset in backfill.presets"
                                                :key="preset"
                                                :value="String(preset)"
                                            >
                                                {{ preset }} emails
                                            </option>
                                            <option value="all">
                                                All remaining older mail
                                            </option>
                                            <option value="custom">
                                                Custom amount
                                            </option>
                                        </select>
                                    </div>

                                    <div
                                        v-if="backfillMode === 'custom'"
                                        class="grid gap-2"
                                    >
                                        <Label for="custom-limit">
                                            Custom amount
                                        </Label>
                                        <Input
                                            id="custom-limit"
                                            v-model="customBackfillLimit"
                                            name="customLimit"
                                            type="number"
                                            min="1"
                                            :max="backfill.customMax"
                                            inputmode="numeric"
                                            placeholder="Enter a custom amount"
                                            :disabled="
                                                processing ||
                                                !canBackfill ||
                                                !connection.imapConfigured
                                            "
                                        />
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Enter a value from 1 to
                                            {{ backfill.customMax }}.
                                        </p>
                                        <InputError
                                            :message="errors.customLimit"
                                        />
                                    </div>
                                </div>

                                <InputError :message="errors.mode" />

                                <Button
                                    type="submit"
                                    class="w-full sm:w-auto"
                                    variant="secondary"
                                    :disabled="
                                        processing ||
                                        !canBackfill ||
                                        !connection.imapConfigured
                                    "
                                >
                                    {{
                                        processing
                                            ? 'Importing older mail...'
                                            : 'Import older mail'
                                    }}
                                </Button>
                            </Form>
                        </CardContent>
                    </Card>
                </div>
                <div class="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Saved inbox</CardTitle>
                            <CardDescription>
                                Stored email is loaded from your database in
                                batches of 25. Use Load more to browse older
                                saved messages without hitting Gmail again.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="grid gap-3 sm:grid-cols-3">
                                <div class="rounded-lg border bg-muted/30 p-4">
                                    <p
                                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        Stored emails
                                    </p>
                                    <p class="mt-1 text-2xl font-semibold">
                                        {{ stats.totalStored }}
                                    </p>
                                </div>
                                <div class="rounded-lg border bg-muted/30 p-4">
                                    <p
                                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        Last sync
                                    </p>
                                    <p class="mt-1 text-sm font-medium">
                                        {{ latestSyncLabel }}
                                    </p>
                                </div>
                                <div class="rounded-lg border bg-muted/30 p-4">
                                    <p
                                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                    >
                                        Saved mailbox
                                    </p>
                                    <p class="mt-1 text-sm font-medium">
                                        {{ connection.mailbox || 'INBOX' }}
                                    </p>
                                </div>
                            </div>

                            <Alert
                                v-if="flash.success"
                                class="border-emerald-200 bg-emerald-50 text-emerald-950"
                            >
                                <AlertTitle>Success</AlertTitle>
                                <AlertDescription class="space-y-1">
                                    <p>{{ flash.success }}</p>
                                    <p
                                        v-if="syncResultSummary"
                                        class="text-sm text-emerald-800"
                                    >
                                        {{ syncResultSummary }}
                                    </p>
                                </AlertDescription>
                            </Alert>

                            <Alert v-if="flash.error" variant="destructive">
                                <AlertTitle>Sync problem</AlertTitle>
                                <AlertDescription>
                                    {{ flash.error }}
                                </AlertDescription>
                            </Alert>

                            <div
                                v-if="storedEmails.length === 0"
                                class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground"
                            >
                                No email has been saved locally yet. Start with
                                Sync inbox now to import your newest messages.
                            </div>

                            <div v-else class="space-y-3">
                                <details
                                    v-for="email in storedEmails"
                                    :key="email.id"
                                    class="group rounded-xl border bg-card"
                                >
                                    <summary
                                        class="cursor-pointer list-none p-4"
                                    >
                                        <div
                                            class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"
                                        >
                                            <div class="space-y-2">
                                                <div
                                                    class="flex flex-wrap items-center gap-2"
                                                >
                                                    <p
                                                        class="text-base font-semibold"
                                                    >
                                                        {{
                                                            emailHeading(email)
                                                        }}
                                                    </p>
                                                    <Badge variant="secondary">
                                                        {{ email.mailbox }}
                                                    </Badge>
                                                    <Badge
                                                        v-if="
                                                            email.attachments
                                                                .length > 0
                                                        "
                                                        variant="outline"
                                                    >
                                                        {{
                                                            email.attachments
                                                                .length
                                                        }}
                                                        attachment{{
                                                            email.attachments
                                                                .length === 1
                                                                ? ''
                                                                : 's'
                                                        }}
                                                    </Badge>
                                                </div>
                                                <p
                                                    class="text-sm text-muted-foreground"
                                                >
                                                    {{ senderLine(email) }}
                                                </p>
                                                <p
                                                    class="text-sm leading-6 text-muted-foreground"
                                                >
                                                    {{ previewLine(email) }}
                                                </p>
                                            </div>

                                            <div
                                                class="min-w-0 text-sm text-muted-foreground md:text-right"
                                            >
                                                <p>
                                                    Received
                                                    {{
                                                        formatDateTime(
                                                            email.receivedAt,
                                                            'Unknown',
                                                        )
                                                    }}
                                                </p>
                                                <p class="mt-1">
                                                    Saved
                                                    {{
                                                        formatDateTime(
                                                            email.syncedAt,
                                                            'Unknown',
                                                        )
                                                    }}
                                                </p>
                                                <p
                                                    class="mt-2 text-xs font-medium tracking-wide uppercase group-open:hidden"
                                                >
                                                    Open message
                                                </p>
                                                <p
                                                    class="mt-2 hidden text-xs font-medium tracking-wide uppercase group-open:block"
                                                >
                                                    Hide message
                                                </p>
                                            </div>
                                        </div>
                                    </summary>

                                    <div class="px-4 pb-4">
                                        <Separator class="mb-4" />

                                        <div class="space-y-4">
                                            <div
                                                v-if="
                                                    bodyLines(email.bodyText)
                                                        .length > 0
                                                "
                                                class="rounded-lg border bg-muted/20 p-4"
                                            >
                                                <p
                                                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                                >
                                                    Message body
                                                </p>
                                                <div
                                                    class="mt-3 space-y-2 text-sm leading-6 break-words whitespace-pre-wrap text-foreground"
                                                >
                                                    <div
                                                        v-for="(
                                                            line, lineIndex
                                                        ) in bodyLines(
                                                            email.bodyText,
                                                        )"
                                                        :key="lineIndex"
                                                        class="min-h-6"
                                                    >
                                                        <template
                                                            v-if="
                                                                line.length > 0
                                                            "
                                                        >
                                                            <template
                                                                v-for="(
                                                                    segment,
                                                                    segmentIndex
                                                                ) in line"
                                                                :key="`${lineIndex}-${segmentIndex}`"
                                                            >
                                                                <a
                                                                    v-if="
                                                                        segment.type ===
                                                                        'link'
                                                                    "
                                                                    :href="
                                                                        segment.href
                                                                    "
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    class="font-medium text-primary underline underline-offset-4"
                                                                >
                                                                    {{
                                                                        segment.value
                                                                    }}
                                                                </a>
                                                                <template
                                                                    v-else
                                                                >
                                                                    {{
                                                                        segment.value
                                                                    }}
                                                                </template>
                                                            </template>
                                                        </template>
                                                        <template v-else>
                                                            &nbsp;
                                                        </template>
                                                    </div>
                                                </div>
                                            </div>

                                            <div
                                                v-if="
                                                    email.attachments.length > 0
                                                "
                                                class="space-y-3"
                                            >
                                                <p
                                                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                                                >
                                                    Attachments
                                                </p>
                                                <div class="space-y-2">
                                                    <a
                                                        v-for="attachment in email.attachments"
                                                        :key="attachment.id"
                                                        :href="
                                                            attachment.downloadUrl
                                                        "
                                                        class="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm transition-colors hover:bg-muted/40"
                                                    >
                                                        <div class="min-w-0">
                                                            <p
                                                                class="truncate font-medium"
                                                            >
                                                                {{
                                                                    attachment.fileName
                                                                }}
                                                            </p>
                                                            <p
                                                                class="text-xs text-muted-foreground"
                                                            >
                                                                {{
                                                                    attachment.contentType ||
                                                                    'Unknown type'
                                                                }}
                                                            </p>
                                                        </div>
                                                        <span
                                                            class="shrink-0 text-xs text-muted-foreground"
                                                        >
                                                            {{
                                                                formatFileSize(
                                                                    attachment.fileSize,
                                                                )
                                                            }}
                                                        </span>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </details>

                                <div class="space-y-3 pt-2">
                                    <Alert
                                        v-if="loadMoreError"
                                        variant="destructive"
                                    >
                                        <AlertTitle>
                                            Unable to load more email
                                        </AlertTitle>
                                        <AlertDescription>
                                            {{ loadMoreError }}
                                        </AlertDescription>
                                    </Alert>

                                    <Button
                                        v-if="hasMoreEmails"
                                        type="button"
                                        variant="outline"
                                        class="w-full"
                                        :disabled="isLoadingMore"
                                        @click="loadMoreEmails"
                                    >
                                        {{ loadMoreButtonLabel }}
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppHeaderLayout>
</template>
