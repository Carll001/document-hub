<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
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
const searchTimeoutId = ref<number | null>(null);
const startDate = ref('');

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
    () => props.filters.search,
    (value) => {
        searchTerm.value = value;
    },
);

watch(searchTerm, (value) => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }

    searchTimeoutId.value = window.setTimeout(() => {
        router.get(
            emailSync.index.url(),
            {
                page: 1,
                appliedPage: props.appliedPagination.currentPage,
                search: value.trim(),
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: [
                    'emails',
                    'pagination',
                    'filters',
                    'receiptCounts',
                    'appliedPagination',
                    'flash',
                ],
            },
        );
    }, 300);
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

onBeforeUnmount(() => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }
});
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
                        email{{ props.receiptCounts.applied === 1 ? '' : 's' }}.
                    </p>
                </div>

                <div class="flex flex-col gap-2 lg:items-end">
                    <EmailSyncToolbar
                        :can-backfill="canBackfill"
                        :connection="props.connection"
                        :start-date="startDate"
                        @update:start-date="startDate = $event"
                    />
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
                        :total-stored-emails="props.receiptCounts.unmatched"
                        @update:search-term="searchTerm = $event"
                    />
                </div>
            </div>
        </div>

        <EmailAppliedReceiptsDialog
            :emails="props.appliedEmails"
            :open="isAppliedDialogOpen"
            :page-url="emailSync.index.url()"
            :pagination="props.appliedPagination"
            :unmatched-page="props.pagination.currentPage"
            @update:open="isAppliedDialogOpen = $event"
        />
    </AppLayout>
</template>
