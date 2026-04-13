<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Mail } from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmailAppliedReceiptsDialog from '@/components/email-sync-components/EmailAppliedReceiptsDialog.vue';
import EmailBirReceiptTable from '@/components/email-sync-components/EmailBirReceiptTable.vue';
import EmailSyncStats from '@/components/email-sync-components/EmailSyncStats.vue';
import EmailSyncToolbar from '@/components/email-sync-components/EmailSyncToolbar.vue';
import type { EmailSyncPageProps } from '@/components/email-sync-components/types';
import { formatDateTime } from '@/components/email-sync-components/utils';
import { Button } from '@/components/ui/button';
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
const searchTerm = ref(props.filters.search);
const formTypeFilter = ref(props.filters.formType);
const accountFilterIds = ref<number[]>([...props.filters.accountIds]);
const selectedSyncAccountIds = ref(
    props.syncAccounts.options.map((account) => account.id),
);
const searchTimeoutId = ref<number | null>(null);
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
): void {
    router.get(
        emailSync.index.url(),
        {
            page: 1,
            appliedPage: 1,
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
    );
}

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

onBeforeUnmount(() => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }
});

function submitSyncAll(): void {
    syncForm.accountIds = [];
    syncForm.post(emailSync.sync.url(), {
        preserveScroll: true,
    });
}

function submitSyncSelected(): void {
    syncForm.accountIds = [...selectedSyncAccountIds.value];
    syncForm.post(emailSync.sync.url(), {
        preserveScroll: true,
    });
}

function submitImportSelected(): void {
    backfillForm.startDate = startDate.value;
    backfillForm.accountIds = [...selectedSyncAccountIds.value];
    backfillForm.post(emailSync.backfill.url(), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Email Sync" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex flex-col gap-2">
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

                <div class="flex flex-col gap-2 lg:items-end">
                    <EmailSyncToolbar
                        :can-backfill="canBackfill"
                        :connection="props.connection"
                        :start-date="startDate"
                        :selected-account-ids="selectedSyncAccountIds"
                        :account-options="props.syncAccounts.options"
                        :sync-processing="syncForm.processing"
                        :backfill-processing="backfillForm.processing"
                        :errors="{
                            accountIds: syncForm.errors.accountIds ?? backfillForm.errors.accountIds,
                            startDate: backfillForm.errors.startDate,
                        }"
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
                        class="gap-2 self-start rounded-full lg:self-end"
                        @click="router.get(emailSync.allEmails())"
                    >
                        <Mail class="size-4" />
                        All emails
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="gap-2 self-start rounded-full lg:self-end"
                        @click="isAppliedDialogOpen = true"
                    >
                        <CheckCircle2 class="size-4" />
                        Applied receipts ({{ props.receiptCounts.applied }})
                    </Button>
                </div>
            </div>
        </template>

        <div class="p-2 md:p-4">
            <div class="overflow-hidden rounded-[28px] border bg-background shadow-sm">
                <div class="flex min-h-[calc(100vh-10rem)] flex-col">
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
            </div>
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
