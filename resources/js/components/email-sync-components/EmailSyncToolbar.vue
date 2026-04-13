<script setup lang="ts">
import { ArrowDownToLine, ChevronDown, LoaderCircle, RefreshCcw } from 'lucide-vue-next';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { ConnectionState, EmailSyncAccountOption } from '@/components/email-sync-components/types';

const props = defineProps<{
    canBackfill: boolean;
    connection: ConnectionState;
    startDate: string;
    selectedAccountIds: number[];
    accountOptions: EmailSyncAccountOption[];
    syncProcessing: boolean;
    backfillProcessing: boolean;
    errors?: {
        startDate?: string;
        accountIds?: string;
    };
}>();

const emit = defineEmits<{
    'update:startDate': [value: string];
    'update:selectedAccountIds': [value: number[]];
    syncAll: [];
    syncSelected: [];
    importSelected: [];
}>();

const selectedCount = computed(() => props.selectedAccountIds.length);
const hasSelectedAccounts = computed(() => selectedCount.value > 0);
const selectedLabel = computed(() => {
    if (selectedCount.value === 0) {
        return 'Choose accounts';
    }

    if (selectedCount.value === props.accountOptions.length) {
        return 'All accounts selected';
    }

    return `${selectedCount.value} account${selectedCount.value === 1 ? '' : 's'} selected`;
});

function toggleAccount(accountId: number, checked: boolean | 'indeterminate'): void {
    if (checked === true) {
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
        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
            <Button
                type="button"
                size="sm"
                class="gap-1.5 text-xs"
                :disabled="syncProcessing || !props.connection.hasActiveAccounts"
                @click="emit('syncAll')"
            >
                <LoaderCircle
                    v-if="syncProcessing"
                    class="size-3.5 animate-spin"
                />
                <RefreshCcw v-else class="size-3.5" />
                {{ syncProcessing ? 'Syncing...' : 'Sync all' }}
            </Button>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="gap-1.5 rounded-full text-xs"
                        :disabled="props.accountOptions.length === 0"
                    >
                        {{ selectedLabel }}
                        <ChevronDown class="size-3.5" />
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
                        :checked="props.selectedAccountIds.includes(account.id)"
                        @update:checked="toggleAccount(account.id, $event)"
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
                class="gap-1.5 rounded-full text-xs"
                :disabled="syncProcessing || !hasSelectedAccounts"
                @click="emit('syncSelected')"
            >
                <RefreshCcw class="size-3.5" />
                Sync selected
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2 lg:justify-end">
            <Input
                :model-value="props.startDate"
                type="date"
                class="h-8 w-[160px] rounded-full text-xs"
                :disabled="backfillProcessing || !props.connection.hasActiveAccounts"
                @update:model-value="emit('update:startDate', String($event ?? ''))"
            />

            <Button
                type="button"
                variant="outline"
                size="sm"
                class="gap-1.5 rounded-full text-xs"
                :disabled="
                    backfillProcessing
                    || !props.startDate
                    || !hasSelectedAccounts
                "
                @click="emit('importSelected')"
            >
                <LoaderCircle
                    v-if="backfillProcessing"
                    class="size-3.5 animate-spin"
                />
                <ArrowDownToLine v-else class="size-3.5" />
                {{ backfillProcessing ? 'Importing...' : 'Import older' }}
            </Button>
        </div>

        <InputError
            v-if="props.errors?.accountIds || props.errors?.startDate"
            class="text-[11px] lg:text-right"
            :message="props.errors?.accountIds || props.errors?.startDate"
        />
    </div>
</template>
