<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import {
    ArrowDownToLine,
    LoaderCircle,
    Mail,
    Paperclip,
    RefreshCcw,
    Search,
} from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import emailSync from '@/routes/email-sync';
import type { BreadcrumbItem } from '@/types';

type EmailAttachment = {
    id: number;
    fileName: string;
    fileSize: number | null;
    contentType: string | null;
    isInline: boolean;
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
    hasHtmlBody: boolean;
    htmlUrl: string | null;
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

type InboxFilter = 'all' | 'attachments';

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Inbox',
        href: emailSync.index(),
    },
];

const storedEmails = ref<EmailRecord[]>([...props.emails]);
const hasMoreEmails = ref(props.hasMoreEmails);
const nextCursor = ref(props.nextCursor);
const isLoadingMore = ref(false);
const loadMoreError = ref<string | null>(null);
const expandedBodyEmailIds = ref<number[]>([]);
const searchTerm = ref('');
const inboxFilter = ref<InboxFilter>('all');
const selectedEmailId = ref<number | null>(props.emails[0]?.id ?? null);
const renderedEmailFrame = ref<HTMLIFrameElement | null>(null);
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

const BODY_TRUNCATE_LIMIT = 1200;
const PH_TIME_ZONE = 'Asia/Manila';
let renderedEmailResizeObserver: ResizeObserver | null = null;

watch(
    () => [props.emails, props.hasMoreEmails, props.nextCursor] as const,
    ([emails, hasMore, cursor]) => {
        storedEmails.value = [...emails];
        hasMoreEmails.value = hasMore;
        nextCursor.value = cursor;
        loadMoreError.value = null;
        expandedBodyEmailIds.value = expandedBodyEmailIds.value.filter((id) =>
            emails.some((email) => email.id === id),
        );
    },
    { deep: true },
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

watch(
    () =>
        [
            props.flash.success,
            props.flash.error,
            syncResultSummary.value,
        ] as const,
    ([success, error, summary]) => {
        if (success) {
            toast.success(success, {
                description: summary ?? undefined,
            });

            return;
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

const loadMoreButtonLabel = computed(() =>
    isLoadingMore.value ? 'Loading older email...' : 'Load more',
);

const filteredEmails = computed(() => {
    const query = searchTerm.value.trim().toLowerCase();

    return storedEmails.value.filter((email) => {
        if (
            inboxFilter.value === 'attachments' &&
            visibleAttachments(email).length === 0
        ) {
            return false;
        }

        if (query === '') {
            return true;
        }

        return emailSearchableText(email).includes(query);
    });
});

watch(
    () => filteredEmails.value.map((email) => email.id),
    (emailIds) => {
        if (
            selectedEmailId.value !== null &&
            emailIds.includes(selectedEmailId.value)
        ) {
            return;
        }

        selectedEmailId.value = emailIds[0] ?? null;
    },
    { immediate: true },
);

const selectedEmail = computed(
    () =>
        filteredEmails.value.find(
            (email) => email.id === selectedEmailId.value,
        ) ?? null,
);

const selectedEmailBodyLines = computed(() =>
    bodyLines(
        selectedEmail.value ? bodyTextForDisplay(selectedEmail.value) : null,
    ),
);

const selectedEmailAttachments = computed(() =>
    selectedEmail.value ? visibleAttachments(selectedEmail.value) : [],
);

const selectedEmailHtmlUrl = computed(() =>
    selectedEmail.value?.hasHtmlBody ? selectedEmail.value.htmlUrl : null,
);

const emptyListMessage = computed(() => {
    if (searchTerm.value.trim() !== '') {
        return 'No synced email matches your search yet.';
    }

    if (storedEmails.value.length === 0) {
        return 'No synced email yet. Sync your inbox to build the list.';
    }

    if (inboxFilter.value === 'attachments') {
        return 'No synced email with attachments in the currently loaded list.';
    }

    return 'No email available right now.';
});

const emptyDetailMessage = computed(() => {
    if (searchTerm.value.trim() !== '' || inboxFilter.value === 'attachments') {
        return 'Choose a message from the filtered list once one appears.';
    }

    return 'Select a synced email to read it here.';
});

function setInboxFilter(filter: InboxFilter): void {
    inboxFilter.value = filter;
}

function selectEmail(emailId: number): void {
    selectedEmailId.value = emailId;
}

function formatDateTime(
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

function formatRelativeTime(
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

function parseDateValue(value: string | null): Date | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

function emailTimestampForDisplay(email: EmailRecord): string | null {
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

function senderDisplayName(email: EmailRecord): string {
    return (
        email.fromName?.trim() || email.fromEmail?.trim() || 'Unknown sender'
    );
}

function senderLine(email: EmailRecord): string {
    return email.fromEmail?.trim() || 'No reply-to address';
}

function previewLine(email: EmailRecord): string {
    return email.bodyPreview?.trim() || 'No preview available yet.';
}

function emailSearchableText(email: EmailRecord): string {
    return [
        email.subject,
        email.fromName,
        email.fromEmail,
        email.bodyPreview,
        email.bodyText,
    ]
        .filter((value): value is string => Boolean(value))
        .join(' ')
        .toLowerCase();
}

function visibleAttachments(email: EmailRecord): EmailAttachment[] {
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

function attachmentCountLabel(email: EmailRecord): string {
    const attachmentCount = visibleAttachments(email).length;

    return attachmentCount === 1 ? '1 file' : `${attachmentCount} files`;
}

function avatarText(email: EmailRecord): string {
    return senderDisplayName(email)
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((chunk) => chunk.charAt(0))
        .join('')
        .toUpperCase()
        .slice(0, 2);
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

function isBodyExpanded(emailId: number): boolean {
    return expandedBodyEmailIds.value.includes(emailId);
}

function toggleBodyExpanded(emailId: number): void {
    if (isBodyExpanded(emailId)) {
        expandedBodyEmailIds.value = expandedBodyEmailIds.value.filter(
            (id) => id !== emailId,
        );

        return;
    }

    expandedBodyEmailIds.value = [...expandedBodyEmailIds.value, emailId];
}

function bodyTextForDisplay(email: EmailRecord): string | null {
    if (!email.bodyText || email.bodyText.trim() === '') {
        return null;
    }

    if (
        isBodyExpanded(email.id) ||
        email.bodyText.length <= BODY_TRUNCATE_LIMIT
    ) {
        return email.bodyText;
    }

    return truncatedBodyText(email.bodyText);
}

function shouldShowBodyToggle(email: EmailRecord): boolean {
    return (email.bodyText?.length ?? 0) > BODY_TRUNCATE_LIMIT;
}

function truncatedBodyText(bodyText: string): string {
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

function cleanupRenderedEmailObserver(): void {
    renderedEmailResizeObserver?.disconnect();
    renderedEmailResizeObserver = null;
}

function resizeRenderedEmailFrame(): void {
    const frame = renderedEmailFrame.value;
    const document = frame?.contentDocument;
    const body = document?.body;
    const root = document?.documentElement;

    if (!frame || !body || !root) {
        return;
    }

    const height = Math.max(
        body.scrollHeight,
        body.offsetHeight,
        root.scrollHeight,
        root.offsetHeight,
        320,
    );

    frame.style.height = `${height}px`;
}

function bindRenderedEmailFrameObserver(): void {
    cleanupRenderedEmailObserver();
    resizeRenderedEmailFrame();

    if (typeof window === 'undefined' || !('ResizeObserver' in window)) {
        return;
    }

    const frame = renderedEmailFrame.value;
    const document = frame?.contentDocument;
    const body = document?.body;
    const root = document?.documentElement;

    if (!body || !root) {
        return;
    }

    renderedEmailResizeObserver = new window.ResizeObserver(() => {
        resizeRenderedEmailFrame();
    });

    renderedEmailResizeObserver.observe(body);
    renderedEmailResizeObserver.observe(root);
}

async function onRenderedEmailLoad(): Promise<void> {
    await nextTick();
    bindRenderedEmailFrameObserver();

    if (typeof window === 'undefined') {
        return;
    }

    for (const delay of [150, 600, 1500]) {
        window.setTimeout(() => {
            resizeRenderedEmailFrame();
        }, delay);
    }
}

watch(selectedEmailHtmlUrl, async () => {
    cleanupRenderedEmailObserver();

    await nextTick();

    if (renderedEmailFrame.value) {
        renderedEmailFrame.value.style.height = '320px';
    }
});

onBeforeUnmount(() => {
    cleanupRenderedEmailObserver();
});

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
    <Head title="Inbox" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <div class="flex flex-col gap-2 lg:items-end">
                <div class="flex flex-wrap items-center gap-2 lg:justify-end">
                    <Form
                        v-bind="emailSync.sync.form()"
                        v-slot="{ processing }"
                    >
                        <Button
                            type="submit"
                            size="sm"
                            class="gap-1.5 text-xs"
                            :disabled="processing || !connection.imapConfigured"
                        >
                            <LoaderCircle
                                v-if="processing"
                                class="size-3.5 animate-spin"
                            />
                            <RefreshCcw v-else class="size-3.5" />
                            {{ processing ? 'Syncing...' : 'Sync inbox' }}
                        </Button>
                    </Form>

                    <Form
                        v-bind="emailSync.backfill.form()"
                        class="flex flex-wrap items-center gap-2 lg:justify-end"
                        v-slot="{ errors, processing }"
                    >
                        <input
                            type="hidden"
                            name="mode"
                            :value="backfillMode"
                        />

                        <Badge
                            variant="outline"
                            class="rounded-full px-2.5 py-0.5 text-[11px]"
                        >
                            Limit
                        </Badge>

                        <Select
                            v-model="backfillMode"
                            :disabled="
                                processing ||
                                !canBackfill ||
                                !connection.imapConfigured
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="w-[96px] rounded-full text-xs"
                            >
                                <SelectValue placeholder="Limit" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="preset in backfill.presets"
                                    :key="preset"
                                    :value="String(preset)"
                                >
                                    {{ preset }}
                                </SelectItem>
                                <SelectItem value="all">All</SelectItem>
                                <SelectItem value="custom">Custom</SelectItem>
                            </SelectContent>
                        </Select>

                        <Input
                            v-if="backfillMode === 'custom'"
                            v-model="customBackfillLimit"
                            name="customLimit"
                            type="number"
                            min="1"
                            :max="backfill.customMax"
                            inputmode="numeric"
                            class="h-8 w-24 rounded-full text-xs"
                            placeholder="Custom"
                            :disabled="
                                processing ||
                                !canBackfill ||
                                !connection.imapConfigured
                            "
                        />

                        <Button
                            type="submit"
                            variant="outline"
                            size="sm"
                            class="gap-1.5 rounded-full text-xs"
                            :disabled="
                                processing ||
                                !canBackfill ||
                                !connection.imapConfigured
                            "
                        >
                            <LoaderCircle
                                v-if="processing"
                                class="size-3.5 animate-spin"
                            />
                            <ArrowDownToLine v-else class="size-3.5" />
                            {{ processing ? 'Importing...' : 'Import older' }}
                        </Button>

                        <InputError
                            class="basis-full text-[11px] lg:text-right"
                            :message="errors.mode"
                        />
                        <InputError
                            class="basis-full text-[11px] lg:text-right"
                            :message="errors.customLimit"
                        />
                    </Form>
                </div>

                <p class="text-[11px] text-muted-foreground">
                    {{ stats.totalStored }} stored - Last sync
                    {{ latestSyncLabel }}
                </p>
            </div>
        </template>
        <div class="p-2 md:p-4">
            <div
                class="overflow-hidden rounded-[28px] border bg-background shadow-sm"
            >
                <div
                    class="flex min-h-[calc(100vh-10rem)] flex-col lg:h-[calc(100vh-10rem)]"
                >
                    <div
                        class="grid min-h-0 flex-1 lg:grid-cols-[360px_minmax(0,1fr)]"
                    >
                        <aside
                            class="flex min-h-0 flex-col border-r lg:overflow-hidden"
                        >
                            <div class="border-b px-5 py-4">
                                <div class="relative">
                                    <Search
                                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                    />
                                    <Input
                                        v-model="searchTerm"
                                        class="h-10 rounded-2xl pl-10 text-sm"
                                        type="search"
                                        placeholder="Search mail"
                                    />
                                </div>

                                <div
                                    class="mt-3 flex flex-wrap items-center gap-2"
                                >
                                    <Button
                                        type="button"
                                        size="sm"
                                        :variant="
                                            inboxFilter === 'all'
                                                ? 'default'
                                                : 'secondary'
                                        "
                                        class="rounded-full text-xs"
                                        @click="setInboxFilter('all')"
                                    >
                                        All
                                    </Button>
                                    <Button
                                        type="button"
                                        size="sm"
                                        :variant="
                                            inboxFilter === 'attachments'
                                                ? 'default'
                                                : 'secondary'
                                        "
                                        class="rounded-full text-xs"
                                        @click="setInboxFilter('attachments')"
                                    >
                                        With files
                                    </Button>
                                </div>
                            </div>

                            <div class="min-h-0 flex-1 overflow-y-auto p-3">
                                <div
                                    v-if="filteredEmails.length === 0"
                                    class="rounded-2xl border border-dashed px-4 py-6 text-sm text-muted-foreground"
                                >
                                    {{ emptyListMessage }}
                                </div>

                                <div v-else class="space-y-3">
                                    <Card
                                        v-for="email in filteredEmails"
                                        :key="email.id"
                                        role="button"
                                        tabindex="0"
                                        class="w-full cursor-pointer gap-0 py-0 text-left transition"
                                        :class="
                                            selectedEmail?.id === email.id
                                                ? 'border-foreground/10 bg-muted shadow-sm'
                                                : 'border-border bg-background hover:bg-muted/40'
                                        "
                                        @click="selectEmail(email.id)"
                                        @keydown.enter.prevent="
                                            selectEmail(email.id)
                                        "
                                        @keydown.space.prevent="
                                            selectEmail(email.id)
                                        "
                                    >
                                        <CardHeader class="px-4 pt-4 pb-0">
                                            <CardTitle
                                                class="min-w-0 truncate text-base"
                                            >
                                                {{ senderDisplayName(email) }}
                                            </CardTitle>
                                            <CardAction
                                                class="shrink-0 text-xs text-muted-foreground"
                                            >
                                                {{
                                                    formatRelativeTime(
                                                        emailTimestampForDisplay(
                                                            email,
                                                        ),
                                                        'Unknown',
                                                    )
                                                }}
                                            </CardAction>
                                            <CardDescription
                                                class="mt-1 truncate text-xs font-medium text-foreground"
                                            >
                                                {{ emailHeading(email) }}
                                            </CardDescription>
                                        </CardHeader>

                                        <CardContent class="px-4 pt-3 pb-0">
                                            <p
                                                class="line-clamp-3 text-sm leading-6 text-muted-foreground"
                                            >
                                                {{ previewLine(email) }}
                                            </p>
                                        </CardContent>

                                        <CardFooter
                                            class="mt-4 flex flex-wrap items-center gap-2 px-4 pt-0 pb-4"
                                        >
                                            <Badge
                                                v-if="
                                                    visibleAttachments(email)
                                                        .length > 0
                                                "
                                                variant="outline"
                                                class="gap-1 rounded-full"
                                            >
                                                <Paperclip class="size-3" />
                                                {{
                                                    attachmentCountLabel(email)
                                                }}
                                            </Badge>
                                            <Badge
                                                variant="secondary"
                                                class="rounded-full"
                                            >
                                                {{ email.mailbox }}
                                            </Badge>
                                        </CardFooter>
                                    </Card>
                                </div>

                                <div class="mt-3">
                                    <Alert
                                        v-if="loadMoreError"
                                        variant="destructive"
                                        class="mb-3"
                                    >
                                        <AlertTitle
                                            >Load more failed</AlertTitle
                                        >
                                        <AlertDescription>
                                            {{ loadMoreError }}
                                        </AlertDescription>
                                    </Alert>

                                    <Button
                                        v-if="hasMoreEmails"
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        class="w-full rounded-full text-xs"
                                        :disabled="isLoadingMore"
                                        @click="loadMoreEmails"
                                    >
                                        <LoaderCircle
                                            v-if="isLoadingMore"
                                            class="mr-2 size-4 animate-spin"
                                        />
                                        {{ loadMoreButtonLabel }}
                                    </Button>

                                    <p
                                        v-else-if="storedEmails.length > 0"
                                        class="px-1 text-xs text-muted-foreground"
                                    >
                                        You are caught up with the email saved
                                        in this view.
                                    </p>
                                </div>
                            </div>
                        </aside>

                        <section
                            class="flex min-h-0 flex-col lg:overflow-hidden"
                        >
                            <template v-if="selectedEmail">
                                <div class="border-b p-4">
                                    <div
                                        class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between"
                                    >
                                        <div class="flex items-start gap-4">
                                            <div
                                                class="flex size-14 shrink-0 items-center justify-center rounded-full bg-muted text-base font-semibold"
                                            >
                                                {{ avatarText(selectedEmail) }}
                                            </div>

                                            <div class="min-w-0 space-y-2">
                                                <div
                                                    class="flex flex-wrap items-center gap-2"
                                                >
                                                    <p
                                                        class="text-lg font-semibold tracking-tight"
                                                    >
                                                        {{
                                                            senderDisplayName(
                                                                selectedEmail,
                                                            )
                                                        }}
                                                    </p>
                                                    <Badge
                                                        variant="outline"
                                                        class="rounded-full"
                                                    >
                                                        {{
                                                            selectedEmail.mailbox
                                                        }}
                                                    </Badge>
                                                    <Badge
                                                        v-if="
                                                            selectedEmailAttachments.length >
                                                            0
                                                        "
                                                        variant="secondary"
                                                        class="gap-1 rounded-full"
                                                    >
                                                        <Paperclip
                                                            class="size-3"
                                                        />
                                                        {{
                                                            attachmentCountLabel(
                                                                selectedEmail,
                                                            )
                                                        }}
                                                    </Badge>
                                                </div>

                                                <p class="text-md font-medium">
                                                    {{
                                                        emailHeading(
                                                            selectedEmail,
                                                        )
                                                    }}
                                                </p>
                                                <p
                                                    class="text-sm text-muted-foreground"
                                                >
                                                    Reply-To:
                                                    {{
                                                        senderLine(
                                                            selectedEmail,
                                                        )
                                                    }}
                                                </p>
                                            </div>
                                        </div>

                                        <div
                                            class="text-sm text-muted-foreground xl:text-right"
                                        >
                                            <p>
                                                {{
                                                    formatDateTime(
                                                        emailTimestampForDisplay(
                                                            selectedEmail,
                                                        ),
                                                        'Unknown date',
                                                    )
                                                }}
                                            </p>
                                            <p class="mt-1">
                                                Saved
                                                {{
                                                    formatRelativeTime(
                                                        selectedEmail.syncedAt,
                                                        'Unknown',
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <div class="min-h-0 flex-1 overflow-y-auto p-2">
                                    <div class="max-w-4xl space-y-6">
                                        <Card
                                            v-if="selectedEmailHtmlUrl"
                                            class="gap-0 overflow-hidden rounded-[24px] bg-background py-0"
                                        >
                                            <CardContent class="p-0">
                                                <iframe
                                                    ref="renderedEmailFrame"
                                                    :src="selectedEmailHtmlUrl"
                                                    title="Rendered email body"
                                                    class="block min-h-[320px] w-full border-0 bg-transparent"
                                                    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                                    referrerpolicy="no-referrer"
                                                    @load="onRenderedEmailLoad"
                                                />
                                            </CardContent>
                                        </Card>

                                        <Card
                                            v-else-if="
                                                selectedEmailBodyLines.length >
                                                0
                                            "
                                            class="gap-0 rounded-[24px] bg-muted/20 py-0"
                                        >
                                            <CardContent
                                                class="space-y-3 px-5 py-5 text-[15px] leading-8 break-words whitespace-pre-wrap text-foreground"
                                            >
                                                <div
                                                    v-for="(
                                                        line, lineIndex
                                                    ) in selectedEmailBodyLines"
                                                    :key="lineIndex"
                                                    class="min-h-6"
                                                >
                                                    <template
                                                        v-if="line.length > 0"
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
                                                            <template v-else>
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
                                            </CardContent>

                                            <CardFooter
                                                v-if="
                                                    shouldShowBodyToggle(
                                                        selectedEmail,
                                                    )
                                                "
                                                class="px-5 pt-0 pb-5"
                                            >
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="sm"
                                                    class="rounded-full text-xs"
                                                    @click="
                                                        toggleBodyExpanded(
                                                            selectedEmail.id,
                                                        )
                                                    "
                                                >
                                                    {{
                                                        isBodyExpanded(
                                                            selectedEmail.id,
                                                        )
                                                            ? 'Show less'
                                                            : 'Show full body'
                                                    }}
                                                </Button>
                                            </CardFooter>
                                        </Card>

                                        <Card
                                            v-else
                                            class="gap-0 rounded-[24px] border-dashed py-0 shadow-none"
                                        >
                                            <CardContent
                                                class="px-5 py-6 text-sm text-muted-foreground"
                                            >
                                                No message body was extracted
                                                for this email yet.
                                            </CardContent>
                                        </Card>

                                        <div
                                            v-if="
                                                selectedEmailAttachments.length >
                                                0
                                            "
                                            class="space-y-3"
                                        >
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <Paperclip
                                                    class="size-4 text-muted-foreground"
                                                />
                                                <p
                                                    class="text-sm font-semibold"
                                                >
                                                    Attachments
                                                </p>
                                            </div>

                                            <div
                                                class="grid gap-3 md:grid-cols-2"
                                            >
                                                <a
                                                    v-for="attachment in selectedEmailAttachments"
                                                    :key="attachment.id"
                                                    :href="
                                                        attachment.downloadUrl
                                                    "
                                                    class="block"
                                                >
                                                    <Card
                                                        class="gap-0 rounded-2xl py-0 transition hover:bg-muted/40"
                                                    >
                                                        <CardContent
                                                            class="px-4 py-4"
                                                        >
                                                            <div
                                                                class="flex items-start gap-3"
                                                            >
                                                                <div
                                                                    class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-muted"
                                                                >
                                                                    <Paperclip
                                                                        class="size-4 text-muted-foreground"
                                                                    />
                                                                </div>

                                                                <div
                                                                    class="min-w-0"
                                                                >
                                                                    <p
                                                                        class="truncate font-medium"
                                                                    >
                                                                        {{
                                                                            attachment.fileName
                                                                        }}
                                                                    </p>
                                                                    <p
                                                                        class="mt-1 text-xs text-muted-foreground"
                                                                    >
                                                                        {{
                                                                            attachment.contentType ||
                                                                            'Unknown type'
                                                                        }}
                                                                        -
                                                                        {{
                                                                            formatFileSize(
                                                                                attachment.fileSize,
                                                                            )
                                                                        }}
                                                                    </p>
                                                                </div>
                                                            </div>
                                                        </CardContent>
                                                    </Card>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>

                            <div
                                v-else
                                class="flex flex-1 items-center justify-center px-6 py-10"
                            >
                                <div class="max-w-sm text-center">
                                    <div
                                        class="mx-auto flex size-16 items-center justify-center rounded-full bg-muted"
                                    >
                                        <Mail
                                            class="size-7 text-muted-foreground"
                                        />
                                    </div>
                                    <h2 class="mt-4 text-xl font-semibold">
                                        No email selected
                                    </h2>
                                    <p
                                        class="mt-2 text-sm text-muted-foreground"
                                    >
                                        {{ emptyDetailMessage }}
                                    </p>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
