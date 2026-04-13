<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Bug, FileSearch, RefreshCcw } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmailDetailPanel from '@/components/email-sync-components/EmailDetailPanel.vue';
import EmailList from '@/components/email-sync-components/EmailList.vue';
import EmailSyncStats from '@/components/email-sync-components/EmailSyncStats.vue';
import EmailSyncToolbar from '@/components/email-sync-components/EmailSyncToolbar.vue';
import type { AllEmailSyncPageProps } from '@/components/email-sync-components/types';
import { useEmailSelection } from '@/components/email-sync-components/useEmailSelection';
import { formatDateTime } from '@/components/email-sync-components/utils';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AppLayout from '@/layouts/AppLayout.vue';
import emailSync from '@/routes/email-sync';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<AllEmailSyncPageProps>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Email Sync',
        href: emailSync.index(),
    },
    {
        title: 'All Emails',
        href: emailSync.allEmails(),
    },
];

const storedEmails = ref([...props.emails]);
const hasMoreEmails = ref(props.hasMoreEmails);
const nextEmailsCursor = ref(props.nextEmailsCursor);
const loadMoreError = ref<string | null>(null);
const isLoadingMore = ref(false);
const isSyncDialogOpen = ref(false);
const runningActionLabel = ref<string | null>(null);
const runningAccountLabels = ref<string[]>([]);
const hasSubmittedSyncAction = ref(false);
const startDate = ref('');
const selectedSyncAccountIds = ref(
    props.syncAccounts.options.map((account) => account.id),
);
const accountFilterIds = ref<number[]>([...props.filters.accountIds]);
const syncForm = useForm<{
    accountIds: number[];
}>({
    accountIds: [],
});
const backfillForm = useForm<{
    startDate: string;
    accountIds: number[];
}>({
    startDate: '',
    accountIds: [],
});

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
    toggleBodyExpanded,
} = useEmailSelection(storedEmails);

const latestSyncLabel = computed(() =>
    formatDateTime(props.stats.latestSyncedAt, 'No inbox sync yet'),
);
const syncResultSummary = computed(() => {
    if (!props.flash.syncResult || props.flash.syncResult.length === 0) {
        return null;
    }

    return props.flash.syncResult
        .map((result) =>
            result.skipped
                ? `${result.accountLabel} skipped`
                : `${result.accountLabel}: ${result.fetched} fetched, ${result.created} created, ${result.updated} updated`,
        )
        .join(' | ');
});
const filterLabel = computed(() => {
    if (accountFilterIds.value.length === 0) {
        return 'All accounts';
    }

    if (accountFilterIds.value.length === props.filters.accountOptions.length) {
        return 'All accounts';
    }

    return `${accountFilterIds.value.length} account${accountFilterIds.value.length === 1 ? '' : 's'}`;
});

watch(
    () => [props.emails, props.hasMoreEmails, props.nextEmailsCursor] as const,
    ([emails, hasMore, nextCursor]) => {
        storedEmails.value = [...emails];
        hasMoreEmails.value = hasMore;
        nextEmailsCursor.value = nextCursor;
        loadMoreError.value = null;
    },
    { deep: true },
);

watch(
    () => props.filters.accountIds,
    (value) => {
        accountFilterIds.value = [...value];
    },
    { deep: true },
);

watch(
    () => [props.flash.success, props.flash.error, syncResultSummary.value, props.flash.syncResultDetails] as const,
    ([success, error, summary, resultDetails]) => {
        if (success) {
            toast.success(success, {
                description: summary ?? undefined,
            });
        }

        if (error) {
            toast.error(error);
        }

        if (hasSubmittedSyncAction.value && (success || error || resultDetails)) {
            isSyncDialogOpen.value = true;
            hasSubmittedSyncAction.value = false;
        }
    },
    { immediate: true },
);

function applyAccountFilters(value: number[]): void {
    router.get(
        emailSync.allEmails.url({
            query: {
                accountIds: value.length > 0 ? value : undefined,
            },
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            only: ['emails', 'hasMoreEmails', 'nextEmailsCursor', 'filters', 'flash'],
        },
    );
}

function toggleAccountFilter(accountId: number, checked: boolean | 'indeterminate'): void {
    if (checked === true) {
        accountFilterIds.value = Array.from(new Set([...accountFilterIds.value, accountId]));
    } else {
        accountFilterIds.value = accountFilterIds.value.filter((id) => id !== accountId);
    }

    applyAccountFilters(accountFilterIds.value);
}

function selectAllAccounts(): void {
    accountFilterIds.value = props.filters.accountOptions.map((account) => account.id);
    applyAccountFilters(accountFilterIds.value);
}

function clearAccountFilter(): void {
    accountFilterIds.value = [];
    applyAccountFilters(accountFilterIds.value);
}

async function loadMoreEmails(): Promise<void> {
    if (!nextEmailsCursor.value || isLoadingMore.value) {
        return;
    }

    isLoadingMore.value = true;
    loadMoreError.value = null;

    try {
        const response = await fetch(
            emailSync.allEmails.messages.url({
                query: {
                    cursor: nextEmailsCursor.value,
                    accountIds: accountFilterIds.value.length > 0 ? accountFilterIds.value : undefined,
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
            throw new Error('Unable to load more emails right now.');
        }

        const payload = await response.json() as {
            emails: typeof props.emails;
            hasMoreEmails: boolean;
            nextCursor: string | null;
        };
        const seenIds = new Set(storedEmails.value.map((email) => email.id));

        storedEmails.value = [
            ...storedEmails.value,
            ...payload.emails.filter((email) => !seenIds.has(email.id)),
        ];
        hasMoreEmails.value = payload.hasMoreEmails;
        nextEmailsCursor.value = payload.nextCursor;
    } catch (error) {
        loadMoreError.value =
            error instanceof Error
                ? error.message
                : 'Unable to load more emails right now.';
    } finally {
        isLoadingMore.value = false;
    }
}

function submitSyncAll(): void {
    hasSubmittedSyncAction.value = true;
    isSyncDialogOpen.value = true;
    runningActionLabel.value = 'Sync all';
    runningAccountLabels.value = props.syncAccounts.options.map((account) => account.label);
    syncForm.accountIds = [];
    syncForm.post(emailSync.sync.url(), {
        preserveScroll: true,
        preserveState: true,
    });
}

function submitSyncSelected(): void {
    hasSubmittedSyncAction.value = true;
    isSyncDialogOpen.value = true;
    runningActionLabel.value = 'Sync selected';
    runningAccountLabels.value = props.syncAccounts.options
        .filter((account) => selectedSyncAccountIds.value.includes(account.id))
        .map((account) => account.label);
    syncForm.accountIds = [...selectedSyncAccountIds.value];
    syncForm.post(emailSync.sync.url(), {
        preserveScroll: true,
        preserveState: true,
    });
}

function submitImportSelected(): void {
    hasSubmittedSyncAction.value = true;
    isSyncDialogOpen.value = true;
    runningActionLabel.value = startDate.value
        ? `Import older from ${startDate.value}`
        : 'Import older';
    runningAccountLabels.value = props.syncAccounts.options
        .filter((account) => selectedSyncAccountIds.value.includes(account.id))
        .map((account) => account.label);
    backfillForm.startDate = startDate.value;
    backfillForm.accountIds = [...selectedSyncAccountIds.value];
    backfillForm.post(emailSync.backfill.url(), {
        preserveScroll: true,
        preserveState: true,
    });
}

async function copyBirDetails(): Promise<void> {
    if (!selectedEmailReceiptClipboardText.value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(selectedEmailReceiptClipboardText.value);
        toast.success('Copied BIR details from the selected email.');
    } catch {
        toast.error('Unable to copy email details right now.');
    }
}
</script>

<template>
    <Head title="All Emails" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex flex-col gap-2">
                    <EmailSyncStats
                        :latest-sync-label="latestSyncLabel"
                        :total-stored="props.stats.totalStored"
                    />
                    <p class="text-xs text-muted-foreground">
                        Testing inbox view for all synced emails across
                        {{ props.connection.accountCount }} active account{{
                            props.connection.accountCount === 1 ? '' : 's'
                        }}.
                    </p>
                </div>

                <div class="flex flex-col gap-2 lg:items-end">
                    <EmailSyncToolbar
                        :can-backfill="true"
                        :connection="props.connection"
                        :open="isSyncDialogOpen"
                        :start-date="startDate"
                        :selected-account-ids="selectedSyncAccountIds"
                        :running-action-label="runningActionLabel"
                        :running-account-labels="runningAccountLabels"
                        :account-options="props.syncAccounts.options"
                        :sync-processing="syncForm.processing"
                        :backfill-processing="backfillForm.processing"
                        :flash-error="props.flash.error"
                        :sync-result-details="props.flash.syncResultDetails"
                        :errors="{
                            accountIds: syncForm.errors.accountIds ?? backfillForm.errors.accountIds,
                            startDate: backfillForm.errors.startDate,
                        }"
                        @update:open="isSyncDialogOpen = $event"
                        @sync-all="submitSyncAll"
                        @sync-selected="submitSyncSelected"
                        @import-selected="submitImportSelected"
                        @update:start-date="startDate = $event"
                        @update:selected-account-ids="selectedSyncAccountIds = $event"
                    />

                    <div class="flex flex-wrap gap-2">
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    class="rounded-full"
                                >
                                    <Bug class="mr-2 size-4" />
                                    {{ filterLabel }}
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-72">
                                <DropdownMenuLabel>Filter test accounts</DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <button
                                    type="button"
                                    class="px-2 py-1 text-left text-xs text-muted-foreground hover:text-foreground"
                                    @click="selectAllAccounts"
                                >
                                    Select all
                                </button>
                                <button
                                    type="button"
                                    class="px-2 py-1 text-left text-xs text-muted-foreground hover:text-foreground"
                                    @click="clearAccountFilter"
                                >
                                    Clear filter
                                </button>
                                <DropdownMenuSeparator />
                                <DropdownMenuCheckboxItem
                                    v-for="account in props.filters.accountOptions"
                                    :key="account.id"
                                    :checked="accountFilterIds.includes(account.id)"
                                    @update:checked="toggleAccountFilter(account.id, $event)"
                                >
                                    <div class="flex flex-col">
                                        <span>{{ account.label }}</span>
                                        <span class="text-xs text-muted-foreground">
                                            {{ account.username }}
                                        </span>
                                    </div>
                                </DropdownMenuCheckboxItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="rounded-full"
                            @click="router.get(emailSync.index())"
                        >
                            <FileSearch class="mr-2 size-4" />
                            Receipt view
                        </Button>
                    </div>
                </div>
            </div>
        </template>

        <div class="p-2 md:p-4">
            <div class="overflow-hidden rounded-[28px] border bg-background shadow-sm">
                <div class="grid min-h-[calc(100vh-10rem)] lg:grid-cols-[360px_minmax(0,1fr)]">
                    <EmailList
                        :emails="filteredEmails"
                        :empty-list-message="emptyListMessage"
                        :has-more-emails="hasMoreEmails"
                        :inbox-filter="inboxFilter"
                        :is-loading-more="isLoadingMore"
                        load-more-button-label="Load more synced emails"
                        :load-more-error="loadMoreError"
                        :search-term="searchTerm"
                        :selected-email-id="selectedEmailId"
                        :total-stored-emails="storedEmails.length"
                        @load-more="loadMoreEmails"
                        @select="selectEmail"
                        @update:inbox-filter="inboxFilter = $event"
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
                        :selected-email-receipt-clipboard-text="selectedEmailReceiptClipboardText"
                        @copy-bir-details="copyBirDetails"
                        @toggle-body="toggleBodyExpanded"
                    />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
