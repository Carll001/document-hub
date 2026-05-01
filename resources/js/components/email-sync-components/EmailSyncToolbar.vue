<script setup lang="ts">
import { ArrowDownToLine, ChevronDown, LoaderCircle, MailSearch, RefreshCcw } from 'lucide-vue-next';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { ConnectionState, EmailSyncAccountOption, SyncResultDetails } from '@/components/email-sync-components/types';

const props = defineProps<{
    canBackfill: boolean;
    connection: ConnectionState;
    open: boolean;
    startDate: string;
    selectedAccountIds: number[];
    runningActionLabel: string | null;
    runningAccountLabels: string[];
    accountOptions: EmailSyncAccountOption[];
    syncProcessing: boolean;
    backfillProcessing: boolean;
    flashError?: string | null;
    syncResultDetails?: SyncResultDetails | null;
    errors?: {
        startDate?: string;
        accountIds?: string;
    };
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
    'update:startDate': [value: string];
    'update:selectedAccountIds': [value: number[]];
    syncAll: [];
    syncSelected: [];
    importSelected: [];
}>();

const selectedCount = computed(() => props.selectedAccountIds.length);
const hasSelectedAccounts = computed(() => selectedCount.value > 0);
const isRunning = computed(() => props.syncProcessing || props.backfillProcessing);
const totalFetchedEmails = computed(() =>
    props.syncResultDetails?.accountResults.reduce(
        (total, result) => total + result.fetched,
        0,
    ) ?? 0,
);
const selectedLabel = computed(() => {
    if (selectedCount.value === 0) {
        return 'Choose accounts';
    }

    if (selectedCount.value === props.accountOptions.length) {
        return 'All accounts selected';
    }

    return `${selectedCount.value} account${selectedCount.value === 1 ? '' : 's'} selected`;
});

function toggleAccount(accountId: number): void {
    if (!props.selectedAccountIds.includes(accountId)) {
        emit('update:selectedAccountIds', Array.from(new Set([...props.selectedAccountIds, accountId])));

        return;
    }

    emit(
        'update:selectedAccountIds',
        props.selectedAccountIds.filter((id) => id !== accountId),
    );
}

function selectAllAccounts(): void {
    emit(
        'update:selectedAccountIds',
        props.accountOptions.map((account) => account.id),
    );
}

function clearSelection(): void {
    emit('update:selectedAccountIds', []);
}
</script>

<template>
    <div class="flex flex-col gap-2 lg:items-end">
        <Button
            type="button"
            size="sm"
            class="gap-1.5 text-xs"
            @click="emit('update:open', true)"
        >
            <LoaderCircle v-if="isRunning" class="size-3.5 animate-spin" />
            <MailSearch v-else class="size-3.5" />
            Sync email
        </Button>

        <Dialog :open="props.open" @update:open="emit('update:open', $event)">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader class="space-y-2">
                    <DialogTitle>Email sync</DialogTitle>
                    <DialogDescription>
                        Choose which accounts to sync, or import older emails for the selected accounts.
                    </DialogDescription>
                </DialogHeader>

                <div v-if="isRunning" class="space-y-4">
                    <div class="rounded-2xl border bg-muted/40 p-4">
                        <div class="flex items-center gap-3">
                            <LoaderCircle class="size-5 animate-spin text-primary" />
                            <div class="space-y-1">
                                <p class="text-sm font-medium text-foreground">
                                    {{ props.runningActionLabel ?? 'Syncing email' }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    The page will refresh automatically when this finishes.
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-2 rounded-2xl border p-4">
                        <p class="text-sm font-medium text-foreground">
                            Selected accounts
                        </p>
                        <div
                            v-if="props.runningAccountLabels.length > 0"
                            class="flex flex-wrap gap-2"
                        >
                            <span
                                v-for="label in props.runningAccountLabels"
                                :key="label"
                                class="rounded-full border px-3 py-1 text-xs text-muted-foreground"
                            >
                                {{ label }}
                            </span>
                        </div>
                        <p v-else class="text-xs text-muted-foreground">
                            All active accounts will be processed.
                        </p>
                    </div>
                </div>

                <div v-else class="space-y-4">
                    <div
                        v-if="props.flashError"
                        class="rounded-2xl border border-destructive/30 bg-destructive/5 p-4"
                    >
                        <p class="text-sm font-medium text-foreground">
                            {{ props.flashError }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <p class="text-sm font-medium text-foreground">
                            Accounts
                        </p>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    type="button"
                                    variant="outline"
                                    class="w-full justify-between rounded-xl text-sm"
                                    :disabled="props.accountOptions.length === 0"
                                >
                                    {{ selectedLabel }}
                                    <ChevronDown class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" class="w-72">
                                <DropdownMenuLabel>Sync accounts</DropdownMenuLabel>
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
                                    @click="clearSelection"
                                >
                                    Clear selection
                                </button>
                                <DropdownMenuSeparator />
                                <DropdownMenuCheckboxItem
                                    v-for="account in props.accountOptions"
                                    :key="account.id"
                                    :model-value="props.selectedAccountIds.includes(account.id)"
                                    @select.prevent="toggleAccount(account.id)"
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
                    </div>

                    <div class="grid gap-2 sm:grid-cols-2">
                        <Button
                            type="button"
                            class="gap-1.5 rounded-xl"
                            :disabled="syncProcessing || !props.connection.hasActiveAccounts"
                            @click="emit('syncAll')"
                        >
                            <LoaderCircle
                                v-if="syncProcessing"
                                class="size-4 animate-spin"
                            />
                            <RefreshCcw v-else class="size-4" />
                            {{ syncProcessing ? 'Syncing...' : 'Sync all' }}
                        </Button>

                        <Button
                            type="button"
                            variant="outline"
                            class="gap-1.5 rounded-xl"
                            :disabled="syncProcessing || !hasSelectedAccounts"
                            @click="emit('syncSelected')"
                        >
                            <RefreshCcw class="size-4" />
                            Sync selected
                        </Button>
                    </div>

                    <div class="space-y-2 rounded-2xl border bg-muted/40 p-4">
                        <p class="text-sm font-medium text-foreground">
                            Import older emails
                        </p>
                        <Input
                            :model-value="props.startDate"
                            type="date"
                            class="h-10 rounded-xl text-sm"
                            :disabled="backfillProcessing || !props.connection.hasActiveAccounts"
                            @update:model-value="emit('update:startDate', String($event ?? ''))"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            class="w-full gap-1.5 rounded-xl"
                            :disabled="
                                backfillProcessing
                                || !props.startDate
                                || !hasSelectedAccounts
                            "
                            @click="emit('importSelected')"
                        >
                            <LoaderCircle
                                v-if="backfillProcessing"
                                class="size-4 animate-spin"
                            />
                            <ArrowDownToLine v-else class="size-4" />
                            {{ backfillProcessing ? 'Importing...' : 'Import older' }}
                        </Button>
                    </div>

                    <div
                        v-if="props.syncResultDetails"
                        class="space-y-3 rounded-2xl border p-4"
                    >
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-foreground">
                                {{ props.syncResultDetails.actionLabel }} results
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    totalFetchedEmails > 0
                                        ? `${totalFetchedEmails} fetched email${totalFetchedEmails === 1 ? '' : 's'} from the latest run.`
                                        : 'No emails were fetched in the latest run.'
                                }}
                            </p>
                        </div>

                        <div
                            v-for="result in props.syncResultDetails.accountResults"
                            :key="`${props.syncResultDetails.actionLabel}-${result.accountId}`"
                            class="space-y-2 rounded-2xl border bg-muted/30 p-3"
                        >
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-medium text-foreground">
                                    {{ result.accountLabel }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ result.fetched }} fetched, {{ result.created }} created, {{ result.updated }} updated
                                </p>
                            </div>
                            <p class="text-xs text-muted-foreground">
                                Mailbox: {{ result.mailbox }}
                            </p>
                        </div>
                    </div>
                </div>

                <InputError
                    v-if="props.errors?.accountIds || props.errors?.startDate"
                    class="text-[11px]"
                    :message="props.errors?.accountIds || props.errors?.startDate"
                />

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="rounded-xl"
                        :disabled="isRunning"
                        @click="emit('update:open', false)"
                    >
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
