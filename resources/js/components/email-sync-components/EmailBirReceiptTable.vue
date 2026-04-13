<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Search } from 'lucide-vue-next';
import { computed, nextTick, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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

const paginationControls = ref<HTMLElement | null>(null);
const ALL_FORM_TYPES_VALUE = '__all_form_types__';
const ALL_ACCOUNTS_VALUE = '__all_accounts__';

type PaginationItem = number | 'ellipsis-start' | 'ellipsis-end';

const paginationItems = computed<PaginationItem[]>(() => {
    const { currentPage, lastPage } = props.pagination;

    if (lastPage <= 1) {
        return [1];
    }

    if (lastPage <= 7) {
        return Array.from({ length: lastPage }, (_, index) => index + 1);
    }

    if (currentPage <= 4) {
        return [1, 2, 3, 4, 5, 'ellipsis-end', lastPage];
    }

    if (currentPage >= lastPage - 3) {
        return [1, 'ellipsis-start', lastPage - 4, lastPage - 3, lastPage - 2, lastPage - 1, lastPage];
    }

    return [1, 'ellipsis-start', currentPage - 1, currentPage, currentPage + 1, 'ellipsis-end', lastPage];
});

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
            onSuccess: () => {
                void nextTick(() => {
                    paginationControls.value?.scrollIntoView({
                        block: 'end',
                    });
                });
            },
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
</script>

<template>
    <section class="flex min-h-0 flex-1 flex-col">
        <div class="border-b px-5 py-4">
            <div
                class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between"
            >
                <div class="flex w-full max-w-5xl flex-col gap-3 md:flex-row">
                    <div class="relative flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            :model-value="props.searchTerm"
                            class="h-10 rounded-2xl pl-10 text-sm"
                            type="search"
                            placeholder="Search TIN, file name, date, time, or status"
                            @update:model-value="
                                emit('update:searchTerm', String($event))
                            "
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
                        <SelectTrigger class="h-10 w-full rounded-2xl md:w-[15rem]">
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
                        <SelectTrigger class="h-10 w-full rounded-2xl md:w-[15rem]">
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

                <p class="text-sm text-muted-foreground">
                    {{ props.emails.length }} parsed BIR email{{
                        props.emails.length === 1 ? '' : 's'
                    }}
                </p>
            </div>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto p-3">
            <div class="overflow-hidden rounded-2xl border bg-background">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>ACCOUNT</TableHead>
                            <TableHead>TIN</TableHead>
                            <TableHead>FILE NAME</TableHead>
                            <TableHead>FORM TYPE</TableHead>
                            <TableHead>DATE RECEIVED</TableHead>
                            <TableHead>TIME RECEIVED</TableHead>
                            <TableHead>MATCH STATUS</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <template v-if="props.emails.length > 0">
                            <TableRow
                                v-for="email in props.emails"
                                :key="email.id"
                            >
                                <TableCell class="text-sm text-muted-foreground">
                                    <div class="space-y-1">
                                        <p class="font-medium text-foreground">
                                            {{ email.accountLabel }}
                                        </p>
                                        <p v-if="email.accountEmail">
                                            {{ email.accountEmail }}
                                        </p>
                                    </div>
                                </TableCell>
                                <TableCell class="font-medium text-foreground">
                                    {{ email.matchedTin || '-' }}
                                </TableCell>
                                <TableCell
                                    class="max-w-[26rem] truncate text-sm text-foreground"
                                    :title="
                                        email.parsedBirReceiptDetails.fileName ||
                                        undefined
                                    "
                                >
                                    {{
                                        email.parsedBirReceiptDetails.fileName ||
                                        '-'
                                    }}
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{
                                        email.parsedBirReceiptDetails.formType ||
                                        'Not detected'
                                    }}
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{
                                        email.parsedBirReceiptDetails.dateReceived ||
                                        '-'
                                    }}
                                </TableCell>
                                <TableCell class="text-sm text-muted-foreground">
                                    {{
                                        email.parsedBirReceiptDetails.timeReceived ||
                                        '-'
                                    }}
                                </TableCell>
                                <TableCell>
                                    <div class="space-y-1">
                                        <Badge
                                            :variant="
                                                statusVariant(email.matchStatus)
                                            "
                                            class="rounded-full"
                                        >
                                            {{
                                                emailMatchStatusLabel(
                                                    email.matchStatus,
                                                )
                                            }}
                                        </Badge>
                                        <p
                                            v-if="email.matchError"
                                            class="max-w-[18rem] text-xs text-destructive"
                                        >
                                            {{ email.matchError }}
                                        </p>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </template>

                        <TableEmpty v-else :colspan="7">
                            {{
                                props.totalStoredEmails === 0
                                    ? 'No unmatched BIR receipt email yet. Sync your inbox to build the list.'
                                    : 'No unmatched BIR receipt email matches your current filters.'
                            }}
                        </TableEmpty>
                    </TableBody>
                </Table>
            </div>

            <div
                v-if="props.pagination.lastPage > 1"
                ref="paginationControls"
                class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t px-1 pt-3"
            >
                <p class="text-xs text-muted-foreground">
                    Showing
                    {{ props.pagination.from ?? 0 }}
                    to
                    {{ props.pagination.to ?? 0 }}
                    of
                    {{ props.pagination.total }}
                    saved emails.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        :disabled="props.pagination.currentPage <= 1"
                        @click="visitPage(props.pagination.currentPage - 1)"
                    >
                        <ChevronLeft class="size-4" />
                    </Button>

                    <template
                        v-for="item in paginationItems"
                        :key="String(item)"
                    >
                        <span
                            v-if="typeof item !== 'number'"
                            class="px-1 text-xs text-muted-foreground"
                        >
                            ...
                        </span>
                        <Button
                            v-else
                            type="button"
                            size="sm"
                            class="min-w-9 rounded-full"
                            :variant="
                                item === props.pagination.currentPage
                                    ? 'default'
                                    : 'outline'
                            "
                            :aria-current="
                                item === props.pagination.currentPage
                                    ? 'page'
                                    : undefined
                            "
                            @click="visitPage(item)"
                        >
                            {{ item }}
                        </Button>
                    </template>

                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                        :disabled="
                            props.pagination.currentPage >=
                            props.pagination.lastPage
                        "
                        @click="visitPage(props.pagination.currentPage + 1)"
                    >
                        <ChevronRight class="size-4" />
                    </Button>
                </div>
            </div>

            <p
                v-else-if="props.totalStoredEmails > 0"
                class="mt-3 px-1 text-xs text-muted-foreground"
            >
                You are caught up with the email saved in this view.
            </p>
            <div v-else class="mt-3" />
        </div>
    </section>
</template>
