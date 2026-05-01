<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmailAppliedReceiptsDialog from '@/components/email-sync-components/EmailAppliedReceiptsDialog.vue';
import EmailBirReceiptTable from '@/components/email-sync-components/EmailBirReceiptTable.vue';
import EmailSyncStats from '@/components/email-sync-components/EmailSyncStats.vue';
import EmailSyncToolbar from '@/components/email-sync-components/EmailSyncToolbar.vue';
import type { EmailSyncPageProps } from '@/components/email-sync-components/types';
import { formatDateTime } from '@/components/email-sync-components/utils';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import emailSync from '@/routes/email-sync';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<EmailSyncPageProps>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Email Sync',
        href: emailSync.index(),
    },
];

const isAppliedDialogOpen = ref(false);
const isSyncDialogOpen = ref(false);
const runningActionLabel = ref<string | null>(null);
const runningAccountLabels = ref<string[]>([]);
const hasSubmittedSyncAction = ref(false);
const searchTerm = ref(props.filters.search);
const formTypeFilter = ref(props.filters.formType);
const accountFilterIds = ref<number[]>([...props.filters.accountIds]);
const selectedSyncAccountIds = ref(
    props.syncAccounts.options.map((account) => account.id),
);
const searchTimeoutId = ref<number | null>(null);
const pollTimeoutId = ref<number | null>(null);
const startDate = ref('');
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

const canBackfill = computed(() => props.stats.totalStored > 0 || props.connection.hasActiveAccounts);
const isSyncQueuedOrRunning = computed(
    () => props.syncState.status === 'queued' || props.syncState.status === 'processing',
);
const toolbarRunningActionLabel = computed(() => props.syncState.actionLabel ?? runningActionLabel.value);
const toolbarRunningAccountLabels = computed(() =>
    props.syncState.accountLabels.length > 0 ? props.syncState.accountLabels : runningAccountLabels.value,
);
const toolbarFlashError = computed(() =>
    props.syncState.status === 'failed' ? props.syncState.error : props.flash.error,
);
const toolbarResultDetails = computed(() => props.syncState.resultDetails ?? props.flash.syncResultDetails);
const toolbarSyncProcessing = computed(() =>
    isSyncQueuedOrRunning.value && toolbarRunningActionLabel.value !== 'Import older',
);
const toolbarBackfillProcessing = computed(() =>
    isSyncQueuedOrRunning.value && toolbarRunningActionLabel.value === 'Import older',
);
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

watch(
    () => props.filters,
    (value) => {
        searchTerm.value = value.search;
        formTypeFilter.value = value.formType;
        accountFilterIds.value = [...value.accountIds];
    },
    { deep: true },
);

function reloadReceiptFilters(
    searchValue: string,
    formTypeValue: string,
    accountIds: number[],
    perPage: number = props.pagination.perPage,
): void {
    router.get(
        emailSync.index.url(),
        {
            page: 1,
            appliedPage: 1,
            per_page: perPage,
            search: searchValue.trim() || undefined,
            formType: formTypeValue || undefined,
            accountIds: accountIds.length > 0 ? accountIds : undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: [
                'emails',
                'pagination',
                'filters',
                'receiptCounts',
                'appliedEmails',
                'appliedPagination',
                'flash',
            ],
        },
    );
}

watch([searchTerm, formTypeFilter], ([searchValue, formTypeValue]) => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }

    searchTimeoutId.value = window.setTimeout(() => {
        reloadReceiptFilters(
            searchValue,
            formTypeValue,
            accountFilterIds.value,
            props.pagination.perPage,
        );
    }, 300);
});

function updateAccountFilterIds(value: number[]): void {
    accountFilterIds.value = value;

    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
        searchTimeoutId.value = null;
    }

    reloadReceiptFilters(
        searchTerm.value,
        formTypeFilter.value,
        value,
        props.pagination.perPage,
    );
}

watch(
    () =>
        [
            props.flash.success,
            props.flash.error,
            syncResultSummary.value,
            props.flash.syncResultDetails,
        ] as const,
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

watch(
    () => props.syncState.status,
    (status) => {
        if (status !== 'queued' && status !== 'processing') {
            clearPollTimeout();

            return;
        }

        schedulePoll();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }

    clearPollTimeout();
});

function clearPollTimeout(): void {
    if (pollTimeoutId.value !== null) {
        window.clearTimeout(pollTimeoutId.value);
        pollTimeoutId.value = null;
    }
}

function schedulePoll(): void {
    if (pollTimeoutId.value !== null) {
        return;
    }

    pollTimeoutId.value = window.setTimeout(() => {
        router.reload({
            only: [
                'flash',
                'stats',
                'emails',
                'pagination',
                'appliedEmails',
                'appliedPagination',
                'receiptCounts',
                'syncState',
            ],
            preserveState: true,
            onFinish: () => {
                pollTimeoutId.value = null;

                if (props.syncState.status === 'queued' || props.syncState.status === 'processing') {
                    schedulePoll();
                }
            },
        });
    }, 3000);
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
</script>

<template>
    <Head title="Email Sync" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <div class="flex flex-col gap-5 p-6 md:flex-row md:items-center md:justify-between md:p-8">
                    <div class="space-y-2">
                        <p class="text-xs font-semibold tracking-[0.3em] text-teal-700 uppercase">Email Sync Workspace</p>
                        <h1 class="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">BIR Receipt Inbox</h1>
                        <EmailSyncStats
                            :latest-sync-label="latestSyncLabel"
                            :total-stored="props.stats.totalStored"
                        />
                        <p class="text-xs text-muted-foreground">
                            {{ props.receiptCounts.unmatched }} unmatched and
                            {{ props.receiptCounts.applied }} applied BIR receipt
                            email{{ props.receiptCounts.applied === 1 ? '' : 's' }} across
                            {{ props.connection.accountCount }} active account{{
                                props.connection.accountCount === 1 ? '' : 's'
                            }}.
                        </p>
                    </div>

                    <div class="flex gap-2 lg:items-end">
                        <EmailSyncToolbar
                            :can-backfill="canBackfill"
                            :connection="props.connection"
                            :open="isSyncDialogOpen"
                            :start-date="startDate"
                            :selected-account-ids="selectedSyncAccountIds"
                            :running-action-label="toolbarRunningActionLabel"
                            :running-account-labels="toolbarRunningAccountLabels"
                            :account-options="props.syncAccounts.options"
                            :sync-processing="toolbarSyncProcessing"
                            :backfill-processing="toolbarBackfillProcessing"
                            :flash-error="toolbarFlashError"
                            :sync-result-details="toolbarResultDetails"
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
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="gap-2 self-start lg:self-end"
                            @click="isAppliedDialogOpen = true"
                        >
                            <CheckCircle2 class="size-4" />
                            Applied receipts ({{ props.receiptCounts.applied }})
                        </Button>
                    </div>
                </div>
            </Card>
            <Card class="rounded-3xl p-4 md:p-6">
                <div class="flex flex-col">
                    <EmailBirReceiptTable
                        :emails="props.emails"
                        :applied-page="props.appliedPagination.currentPage"
                        :page-url="emailSync.index.url()"
                        :pagination="props.pagination"
                        :search-term="searchTerm"
                        :form-type-filter="formTypeFilter"
                        :form-type-options="props.filters.formTypeOptions"
                        :account-filter-ids="accountFilterIds"
                        :account-options="props.filters.accountOptions"
                        :total-stored-emails="props.receiptCounts.unmatched"
                        @update:search-term="searchTerm = $event"
                        @update:form-type-filter="formTypeFilter = $event"
                        @update:account-filter-ids="updateAccountFilterIds"
                    />
                </div>
            </Card>
        </div>

        <EmailAppliedReceiptsDialog
            :emails="props.appliedEmails"
            :open="isAppliedDialogOpen"
            :page-url="emailSync.index.url()"
            :pagination="props.appliedPagination"
            :form-type-filter="formTypeFilter"
            :account-filter-ids="accountFilterIds"
            :unmatched-page="props.pagination.currentPage"
            @update:open="isAppliedDialogOpen = $event"
        />
    </AppLayout>
</template>
