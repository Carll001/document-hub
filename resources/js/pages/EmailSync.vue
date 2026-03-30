<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmailDetailPanel from '@/components/email-sync-components/EmailDetailPanel.vue';
import EmailList from '@/components/email-sync-components/EmailList.vue';
import EmailSyncStats from '@/components/email-sync-components/EmailSyncStats.vue';
import EmailSyncToolbar from '@/components/email-sync-components/EmailSyncToolbar.vue';
import type {
    EmailRecord,
    EmailSyncPageProps,
    JsonEmailPayload,
} from '@/components/email-sync-components/types';
import { useEmailSelection } from '@/components/email-sync-components/useEmailSelection';
import { formatDateTime } from '@/components/email-sync-components/utils';
import AppLayout from '@/layouts/AppLayout.vue';
import emailSync from '@/routes/email-sync';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<EmailSyncPageProps>();

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

const {
    emptyDetailMessage,
    emptyListMessage,
    filteredEmails,
    inboxFilter,
    isBodyExpanded,
    searchTerm,
    selectedEmail,
    selectedEmailAttachments,
    selectedEmailBodyLines,
    selectedEmailHtmlUrl,
    selectedEmailId,
    selectedEmailReceiptClipboardText,
    selectEmail,
    setInboxFilter,
    toggleBodyExpanded,
} = useEmailSelection(storedEmails);

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

async function copySelectedEmailReceiptDetails(): Promise<void> {
    const clipboardText = selectedEmailReceiptClipboardText.value;

    if (!clipboardText) {
        toast.error('No BIR receipt details were found in this email.');

        return;
    }

    if (typeof navigator === 'undefined' || !navigator.clipboard?.writeText) {
        toast.error('Clipboard copy is not available in this browser.');

        return;
    }

    try {
        await navigator.clipboard.writeText(clipboardText);
        toast.success('BIR receipt details copied.', {
            description: 'Use Paste from email in Add receipt.',
        });
    } catch {
        toast.error('Unable to copy to the clipboard right now.');
    }
}
</script>

<template>
    <Head title="Inbox" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <div class="flex flex-col gap-2 lg:items-end">
                <EmailSyncToolbar
                    :backfill="props.backfill"
                    :backfill-mode="backfillMode"
                    :can-backfill="canBackfill"
                    :connection="props.connection"
                    :custom-backfill-limit="customBackfillLimit"
                    @update:backfill-mode="backfillMode = $event"
                    @update:custom-backfill-limit="customBackfillLimit = $event"
                />
                <EmailSyncStats
                    :latest-sync-label="latestSyncLabel"
                    :total-stored="props.stats.totalStored"
                />
            </div>
        </template>

        <div class="p-2 md:p-4">
            <div class="overflow-hidden rounded-[28px] border bg-background shadow-sm">
                <div
                    class="flex min-h-[calc(100vh-10rem)] flex-col lg:h-[calc(100vh-10rem)]"
                >
                    <div
                        class="grid min-h-0 flex-1 lg:grid-cols-[360px_minmax(0,1fr)]"
                    >
                        <EmailList
                            :emails="filteredEmails"
                            :empty-list-message="emptyListMessage"
                            :has-more-emails="hasMoreEmails"
                            :inbox-filter="inboxFilter"
                            :is-loading-more="isLoadingMore"
                            :load-more-button-label="loadMoreButtonLabel"
                            :load-more-error="loadMoreError"
                            :search-term="searchTerm"
                            :selected-email-id="selectedEmailId"
                            :total-stored-emails="storedEmails.length"
                            @load-more="loadMoreEmails"
                            @select="selectEmail"
                            @update:inbox-filter="setInboxFilter"
                            @update:search-term="searchTerm = $event"
                        />

                        <EmailDetailPanel
                            :empty-detail-message="emptyDetailMessage"
                            :is-body-expanded="isBodyExpanded"
                            :query="searchTerm"
                            :selected-email="selectedEmail"
                            :selected-email-attachments="selectedEmailAttachments"
                            :selected-email-body-lines="selectedEmailBodyLines"
                            :selected-email-html-url="selectedEmailHtmlUrl"
                            :selected-email-receipt-clipboard-text="
                                selectedEmailReceiptClipboardText
                            "
                            @copy-bir-details="copySelectedEmailReceiptDetails"
                            @toggle-body="toggleBodyExpanded"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
