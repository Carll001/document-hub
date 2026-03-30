<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Clock3,
    Files,
    Mail,
    Paperclip,
    Sparkles,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import docMerge from '@/routes/doc-merge';
import { dashboard } from '@/routes';
import emailSync from '@/routes/email-sync';
import type { Auth, BreadcrumbItem } from '@/types';

type Overview = {
    totalSyncedEmails: number;
    emailsWithAttachments: number;
    totalMergedPdfs: number;
    totalMergedSize: number;
    lastInboxSyncAt: string | null;
    lastMergeAt: string | null;
};

type RecentEmail = {
    id: number;
    fromName: string | null;
    fromEmail: string | null;
    subject: string | null;
    bodyPreview: string | null;
    mailbox: string;
    attachmentCount: number;
    receivedAt: string | null;
    syncedAt: string | null;
};

type RecentMergedPdf = {
    id: number;
    fileName: string;
    fileSize: number;
    sourceCount: number;
    createdAt: string | null;
    downloadUrl: string;
};

const props = defineProps<{
    overview: Overview;
    recentEmails: RecentEmail[];
    recentMergedPdfs: RecentMergedPdf[];
}>();

const page = usePage<{ auth: Auth }>();
const auth = computed(() => page.props.auth);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
    },
];

const summaryCards = computed(() => [
    {
        title: 'Synced emails',
        value: formatCount(props.overview.totalSyncedEmails),
        hint:
            props.overview.totalSyncedEmails === 1
                ? 'message stored'
                : 'messages stored',
        icon: Mail,
    },
    {
        title: 'With attachments',
        value: formatCount(props.overview.emailsWithAttachments),
        hint:
            props.overview.emailsWithAttachments === 1
                ? 'email includes files'
                : 'emails include files',
        icon: Paperclip,
    },
    {
        title: 'Merged PDFs',
        value: formatCount(props.overview.totalMergedPdfs),
        hint:
            props.overview.totalMergedPdfs === 1
                ? 'saved output'
                : 'saved outputs',
        icon: Files,
    },
    {
        title: 'Merged storage',
        value: formatFileSize(props.overview.totalMergedSize),
        hint: 'saved merged output size',
        icon: Sparkles,
    },
]);

const dashboardStatus = computed(() => [
    {
        label: 'Last inbox sync',
        value: formatDateTime(
            props.overview.lastInboxSyncAt,
            'No inbox sync yet',
        ),
    },
    {
        label: 'Last PDF merge',
        value: formatDateTime(
            props.overview.lastMergeAt,
            'No merged files yet',
        ),
    },
]);

function formatCount(value: number): string {
    return new Intl.NumberFormat('en-US').format(value);
}

function formatFileSize(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
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

function formatDateTime(value: string | null, fallback: string): string {
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

function formatRelativeTime(value: string | null, fallback: string): string {
    if (!value) {
        return fallback;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return fallback;
    }

    const diffInSeconds = Math.round((date.getTime() - Date.now()) / 1000);
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' });
    const units = [
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

function emailHeading(email: RecentEmail): string {
    return email.subject?.trim() || '(No subject)';
}

function emailSender(email: RecentEmail): string {
    return (
        email.fromName?.trim() || email.fromEmail?.trim() || 'Unknown sender'
    );
}

function emailPreview(email: RecentEmail): string {
    return email.bodyPreview?.trim() || 'No preview saved yet.';
}
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Button
                    as-child
                    size="sm"
                    variant="outline"
                    class="gap-2 text-xs"
                >
                    <Link :href="emailSync.index()">
                        <Mail class="size-4" />
                        Open inbox
                    </Link>
                </Button>
                <Button as-child size="sm" class="gap-2 text-xs">
                    <Link :href="docMerge.index()">
                        <Files class="size-4" />
                        Doc merge
                    </Link>
                </Button>
            </div>
        </template>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card
                class="overflow-hidden rounded-3xl border-0 bg-gradient-to-br from-primary/10 via-background to-muted shadow-sm"
            >
                <CardContent
                    class="grid gap-6 p-6 lg:grid-cols-[minmax(0,1.4fr)_minmax(280px,0.6fr)] lg:p-8"
                >
                    <div class="space-y-4">
                        <Badge
                            variant="outline"
                            class="rounded-full px-3 py-1 text-[11px] tracking-[0.2em] uppercase"
                        >
                            Workspace overview
                        </Badge>

                        <div class="space-y-3">
                            <h1
                                class="max-w-2xl text-3xl font-semibold tracking-tight"
                            >
                                {{
                                    auth.user?.name ?? 'Your'
                                }}, your document workspace is ready.
                            </h1>
                            <p
                                class="max-w-2xl text-sm leading-7 text-muted-foreground"
                            >
                                Keep an eye on synced inbox activity, recent
                                merged PDFs, and jump straight into the tools
                                you use most.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <Button as-child class="gap-2">
                                <Link :href="emailSync.index()">
                                    Go to inbox
                                    <ArrowRight class="size-4" />
                                </Link>
                            </Button>
                            <Button as-child variant="secondary" class="gap-2">
                                <Link :href="docMerge.index()">
                                    Open doc merge
                                    <ArrowRight class="size-4" />
                                </Link>
                            </Button>
                        </div>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                        <Card
                            v-for="status in dashboardStatus"
                            :key="status.label"
                            class="rounded-2xl bg-background/80 shadow-none backdrop-blur"
                        >
                            <CardContent class="flex items-start gap-3 p-4">
                                <div
                                    class="flex size-10 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                                >
                                    <Clock3 class="size-4" />
                                </div>
                                <div class="space-y-1">
                                    <p
                                        class="text-xs tracking-[0.18em] text-muted-foreground uppercase"
                                    >
                                        {{ status.label }}
                                    </p>
                                    <p class="text-sm leading-6 font-medium">
                                        {{ status.value }}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Card
                    v-for="card in summaryCards"
                    :key="card.title"
                    class="rounded-3xl"
                >
                    <CardContent class="space-y-4 p-5">
                        <div class="flex items-center justify-between gap-3">
                            <p
                                class="text-sm font-medium text-muted-foreground"
                            >
                                {{ card.title }}
                            </p>
                            <div
                                class="flex size-10 items-center justify-center rounded-full bg-muted"
                            >
                                <component
                                    :is="card.icon"
                                    class="size-4 text-muted-foreground"
                                />
                            </div>
                        </div>

                        <div class="space-y-1">
                            <p class="text-3xl font-semibold tracking-tight">
                                {{ card.value }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ card.hint }}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div
                class="grid gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]"
            >
                <Card class="rounded-3xl">
                    <CardHeader class="space-y-1">
                        <CardTitle class="text-xl"
                            >Recent inbox activity</CardTitle
                        >
                        <CardDescription>
                            The latest synced messages saved to your workspace.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <div
                            v-if="recentEmails.length === 0"
                            class="rounded-2xl border border-dashed px-4 py-10 text-center text-sm text-muted-foreground"
                        >
                            No email has been synced yet.
                        </div>

                        <div v-else class="space-y-3">
                            <Link
                                v-for="email in recentEmails"
                                :key="email.id"
                                :href="emailSync.index()"
                                class="block"
                            >
                                <Card
                                    class="rounded-2xl transition hover:bg-muted/40"
                                >
                                    <CardContent class="space-y-3 p-4">
                                        <div
                                            class="flex items-start justify-between gap-3"
                                        >
                                            <div class="min-w-0 space-y-1">
                                                <p
                                                    class="truncate text-base font-semibold"
                                                >
                                                    {{ emailSender(email) }}
                                                </p>
                                                <p
                                                    class="truncate text-sm font-medium"
                                                >
                                                    {{ emailHeading(email) }}
                                                </p>
                                            </div>
                                            <p
                                                class="shrink-0 text-xs text-muted-foreground"
                                            >
                                                {{
                                                    formatRelativeTime(
                                                        email.receivedAt ??
                                                            email.syncedAt,
                                                        'Unknown',
                                                    )
                                                }}
                                            </p>
                                        </div>

                                        <p
                                            class="line-clamp-2 text-sm leading-6 text-muted-foreground"
                                        >
                                            {{ emailPreview(email) }}
                                        </p>

                                        <div
                                            class="flex flex-wrap items-center gap-2"
                                        >
                                            <Badge
                                                variant="secondary"
                                                class="rounded-full"
                                            >
                                                {{ email.mailbox }}
                                            </Badge>
                                            <Badge
                                                v-if="email.attachmentCount > 0"
                                                variant="outline"
                                                class="rounded-full"
                                            >
                                                {{ email.attachmentCount }}
                                                {{
                                                    email.attachmentCount === 1
                                                        ? 'attachment'
                                                        : 'attachments'
                                                }}
                                            </Badge>
                                        </div>
                                    </CardContent>
                                </Card>
                            </Link>
                        </div>
                    </CardContent>
                </Card>

                <Card class="rounded-3xl">
                    <CardHeader class="space-y-1">
                        <CardTitle class="text-xl"
                            >Recent merged PDFs</CardTitle
                        >
                        <CardDescription>
                            The newest saved merge outputs from your workspace.
                        </CardDescription>
                    </CardHeader>

                    <CardContent>
                        <div
                            v-if="recentMergedPdfs.length === 0"
                            class="rounded-2xl border border-dashed px-4 py-10 text-center text-sm text-muted-foreground"
                        >
                            No merged PDFs saved yet.
                        </div>

                        <div v-else class="space-y-3">
                            <Card
                                v-for="mergedPdf in recentMergedPdfs"
                                :key="mergedPdf.id"
                                class="rounded-2xl"
                            >
                                <CardContent class="space-y-3 p-4">
                                    <div class="space-y-1">
                                        <p class="truncate font-semibold">
                                            {{ mergedPdf.fileName }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{
                                                formatDateTime(
                                                    mergedPdf.createdAt,
                                                    'Unknown date',
                                                )
                                            }}
                                        </p>
                                    </div>

                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <Badge
                                            variant="secondary"
                                            class="rounded-full"
                                        >
                                            {{ mergedPdf.sourceCount }}
                                            {{
                                                mergedPdf.sourceCount === 1
                                                    ? 'source'
                                                    : 'sources'
                                            }}
                                        </Badge>
                                        <Badge
                                            variant="outline"
                                            class="rounded-full"
                                        >
                                            {{
                                                formatFileSize(
                                                    mergedPdf.fileSize,
                                                )
                                            }}
                                        </Badge>
                                    </div>

                                    <div class="flex gap-2">
                                        <Button
                                            as-child
                                            size="sm"
                                            variant="outline"
                                            class="gap-2"
                                        >
                                            <a :href="mergedPdf.downloadUrl">
                                                Download
                                                <ArrowRight class="size-4" />
                                            </a>
                                        </Button>
                                        <Button
                                            as-child
                                            size="sm"
                                            variant="ghost"
                                            class="gap-2"
                                        >
                                            <Link :href="docMerge.index()">
                                                Open merge
                                                <ArrowRight class="size-4" />
                                            </Link>
                                        </Button>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    </AppLayout>
</template>
