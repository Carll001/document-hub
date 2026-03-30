<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ArrowDownToLine, LoaderCircle, RefreshCcw } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
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
import type {
    BackfillState,
    ConnectionState,
} from '@/components/email-sync-components/types';
import emailSync from '@/routes/email-sync';

const props = defineProps<{
    backfill: BackfillState;
    backfillMode: string;
    canBackfill: boolean;
    connection: ConnectionState;
    customBackfillLimit: string;
}>();

const emit = defineEmits<{
    'update:backfillMode': [value: string];
    'update:customBackfillLimit': [value: string];
}>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
        <Form v-bind="emailSync.sync.form()" v-slot="{ processing }">
            <Button
                type="submit"
                size="sm"
                class="gap-1.5 text-xs"
                :disabled="processing || !props.connection.imapConfigured"
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-3.5 animate-spin"
                />
                <RefreshCcw v-else class="size-3.5" />
                {{ processing ? 'Syncing...' : 'Sync inbox' }}
            </Button>
        </Form>

        <Form
            v-bind="emailSync.backfill.form()"
            class="flex flex-wrap items-center gap-2 lg:justify-end"
            v-slot="{ errors, processing }"
        >
            <input type="hidden" name="mode" :value="props.backfillMode" />

            <Badge
                variant="outline"
                class="rounded-full px-2.5 py-0.5 text-[11px]"
            >
                Limit
            </Badge>

            <Select
                :model-value="props.backfillMode"
                :disabled="
                    processing ||
                    !props.canBackfill ||
                    !props.connection.imapConfigured
                "
                @update:model-value="
                    emit('update:backfillMode', String($event ?? 'all'))
                "
            >
                <SelectTrigger size="sm" class="w-[96px] rounded-full text-xs">
                    <SelectValue placeholder="Limit" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="preset in props.backfill.presets"
                        :key="preset"
                        :value="String(preset)"
                    >
                        {{ preset }}
                    </SelectItem>
                    <SelectItem value="all">All</SelectItem>
                    <SelectItem value="custom">Custom</SelectItem>
                </SelectContent>
            </Select>

            <Input
                v-if="props.backfillMode === 'custom'"
                :model-value="props.customBackfillLimit"
                name="customLimit"
                type="number"
                min="1"
                :max="props.backfill.customMax"
                inputmode="numeric"
                class="h-8 w-24 rounded-full text-xs"
                placeholder="Custom"
                :disabled="
                    processing ||
                    !props.canBackfill ||
                    !props.connection.imapConfigured
                "
                @update:model-value="
                    emit('update:customBackfillLimit', String($event))
                "
            />

            <Button
                type="submit"
                variant="outline"
                size="sm"
                class="gap-1.5 rounded-full text-xs"
                :disabled="
                    processing ||
                    !props.canBackfill ||
                    !props.connection.imapConfigured
                "
            >
                <LoaderCircle
                    v-if="processing"
                    class="size-3.5 animate-spin"
                />
                <ArrowDownToLine v-else class="size-3.5" />
                {{ processing ? 'Importing...' : 'Import older' }}
            </Button>

            <InputError
                class="basis-full text-[11px] lg:text-right"
                :message="errors.mode"
            />
            <InputError
                class="basis-full text-[11px] lg:text-right"
                :message="errors.customLimit"
            />
        </Form>
    </div>
</template>
