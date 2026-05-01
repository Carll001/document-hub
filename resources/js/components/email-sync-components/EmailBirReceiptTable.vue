<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import type { ColumnDef } from '@tanstack/vue-table';
import { Search } from 'lucide-vue-next';
import { computed, h } from 'vue';
import { Badge } from '@/components/ui/badge';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { EmailRecord, EmailSyncAccountOption } from '@/components/email-sync-components/types';
import { emailMatchStatusLabel } from '@/components/email-sync-components/utils';

const props = defineProps<{
    emails: EmailRecord[];
    appliedPage: number;
    pageUrl: string;
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    searchTerm: string;
    formTypeFilter: string;
    formTypeOptions: string[];
    accountFilterIds: number[];
    accountOptions: EmailSyncAccountOption[];
    totalStoredEmails: number;
}>();

const emit = defineEmits<{
    'update:searchTerm': [value: string];
    'update:formTypeFilter': [value: string];
    'update:accountFilterIds': [value: number[]];
}>();

const ALL_FORM_TYPES_VALUE = '__all_form_types__';
const ALL_ACCOUNTS_VALUE = '__all_accounts__';

const selectedAccountValue = computed(() => {
    if (props.accountFilterIds.length !== 1) {
        return ALL_ACCOUNTS_VALUE;
    }

    return String(props.accountFilterIds[0]);
});

function statusVariant(status: EmailRecord['matchStatus']): 'outline' | 'secondary' | 'destructive' {
    if (status === 'failed' || status === 'unmatched') {
        return 'destructive';
    }

    if (status === 'applied') {
        return 'secondary';
    }

    return 'outline';
}

function visitPage(page: number): void {
    if (page === props.pagination.currentPage) {
        return;
    }

    router.get(
        props.pageUrl,
        {
            page,
            appliedPage: props.appliedPage,
            search: props.searchTerm.trim() || undefined,
            formType: props.formTypeFilter || undefined,
            accountIds: props.accountFilterIds.length > 0 ? props.accountFilterIds : undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['emails', 'pagination', 'stats', 'flash', 'receiptCounts', 'appliedPagination', 'filters'],
        },
    );
}

function updatePerPage(perPage: number): void {
    if (perPage === props.pagination.perPage) {
        return;
    }

    router.get(
        props.pageUrl,
        {
            page: 1,
            appliedPage: props.appliedPage,
            per_page: perPage,
            search: props.searchTerm.trim() || undefined,
            formType: props.formTypeFilter || undefined,
            accountIds: props.accountFilterIds.length > 0 ? props.accountFilterIds : undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['emails', 'pagination', 'stats', 'flash', 'receiptCounts', 'appliedPagination', 'filters'],
        },
    );
}

function updateSelectedAccount(value: string): void {
    if (value === ALL_ACCOUNTS_VALUE) {
        emit('update:accountFilterIds', []);

        return;
    }

    emit('update:accountFilterIds', [Number(value)]);
}

const tableMeta = computed(() => ({
    current_page: props.pagination.currentPage,
    last_page: props.pagination.lastPage,
    per_page: props.pagination.perPage,
    total: props.pagination.total,
}));

const columns = computed<ColumnDef<EmailRecord>[]>(() => [
    {
        accessorKey: 'accountLabel',
        header: () => h('div', { class: 'text-left' }, 'Account'),
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'space-y-1 text-sm text-muted-foreground' }, [
                h('p', { class: 'font-medium text-foreground' }, row.original.accountLabel),
                row.original.accountEmail ? h('p', row.original.accountEmail) : null,
            ]),
    },
    {
        accessorKey: 'matchedTin',
        header: () => h('div', { class: 'text-left' }, 'TIN'),
        enableSorting: false,
        cell: ({ row }) => row.original.matchedTin || '-',
    },
    {
        id: 'fileName',
        header: () => h('div', { class: 'text-left' }, 'File Name'),
        enableSorting: false,
        cell: ({ row }) =>
            h(
                'div',
                {
                    class: 'max-w-[26rem] truncate text-sm text-foreground',
                    title: row.original.parsedBirReceiptDetails.fileName || undefined,
                },
                row.original.parsedBirReceiptDetails.fileName || '-',
            ),
    },
    {
        id: 'formType',
        header: () => h('div', { class: 'text-left' }, 'Form Type'),
        enableSorting: false,
        cell: ({ row }) => row.original.parsedBirReceiptDetails.formType || 'Not detected',
    },
    {
        id: 'dateReceived',
        header: () => h('div', { class: 'text-left' }, 'Date Received'),
        enableSorting: false,
        cell: ({ row }) => row.original.parsedBirReceiptDetails.dateReceived || '-',
    },
    {
        id: 'timeReceived',
        header: () => h('div', { class: 'text-left' }, 'Time Received'),
        enableSorting: false,
        cell: ({ row }) => row.original.parsedBirReceiptDetails.timeReceived || '-',
    },
    {
        accessorKey: 'matchStatus',
        header: () => h('div', { class: 'text-left' }, 'Match Status'),
        enableSorting: false,
        cell: ({ row }) =>
            h('div', { class: 'space-y-1' }, [
                h(
                    Badge,
                    { variant: statusVariant(row.original.matchStatus), class: 'rounded-full' },
                    () => emailMatchStatusLabel(row.original.matchStatus),
                ),
                row.original.matchError
                    ? h('p', { class: 'max-w-[18rem] text-xs text-destructive' }, row.original.matchError)
                    : null,
            ]),
    },
]);
</script>

<template>
    <section class="space-y-4">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="grid w-full grid-cols-1 gap-3 md:grid-cols-3">
                <div class="relative w-full">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <Input
                        :model-value="props.searchTerm"
                        class="pl-10 text-sm w-full"
                        type="search"
                        placeholder="Search TIN, file name, date, time, or status"
                        @update:model-value="emit('update:searchTerm', String($event))"
                    />
                </div>

                <Select
                    :model-value="
                        props.formTypeFilter === ''
                            ? ALL_FORM_TYPES_VALUE
                            : props.formTypeFilter
                    "
                    @update:model-value="
                        emit(
                            'update:formTypeFilter',
                            $event === ALL_FORM_TYPES_VALUE
                                ? ''
                                : String($event ?? ''),
                        )
                    "
                >
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="All form types" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_FORM_TYPES_VALUE">
                            All form types
                        </SelectItem>
                        <SelectItem
                            v-for="formType in props.formTypeOptions"
                            :key="formType"
                            :value="formType"
                        >
                            {{ formType }}
                        </SelectItem>
                    </SelectContent>
                </Select>

                <Select
                    :model-value="selectedAccountValue"
                    @update:model-value="
                        updateSelectedAccount(String($event ?? ALL_ACCOUNTS_VALUE))
                    "
                >
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="All accounts" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="ALL_ACCOUNTS_VALUE">
                            All accounts
                        </SelectItem>
                        <SelectItem
                            v-for="account in props.accountOptions"
                            :key="account.id"
                            :value="String(account.id)"
                        >
                            {{ account.label }}
                            <span v-if="account.isActive === false">
                                (inactive)
                            </span>
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
        </div>

        <DataTable
            :columns="columns"
            :data="props.emails"
            :meta="tableMeta"
            :empty-message="
                props.totalStoredEmails === 0
                    ? 'No unmatched BIR receipt email yet. Sync your inbox to build the list.'
                    : 'No unmatched BIR receipt email matches your current filters.'
            "
            @page-change="visitPage"
            @per-page-change="updatePerPage"
        />
    </section>
</template>
