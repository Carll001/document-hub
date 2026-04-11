<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ArrowDownToLine, LoaderCircle, RefreshCcw } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ConnectionState } from '@/components/email-sync-components/types';
import emailSync from '@/routes/email-sync';

const props = defineProps<{
    canBackfill: boolean;
    connection: ConnectionState;
    startDate: string;
}>();

const emit = defineEmits<{
    'update:startDate': [value: string];
}>();
</script>

<template>
    <div class="flex flex-wrap items-center gap-2 lg:justify-end">
        <Form
            :action="emailSync.sync.url()"
            method="post"
            v-slot="{ processing }"
        >
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
            :action="emailSync.backfill.url()"
            method="post"
            class="flex flex-wrap items-center gap-2 lg:justify-end"
            v-slot="{ errors, processing }"
        >
            <Input
                :model-value="props.startDate"
                name="startDate"
                type="date"
                class="h-8 w-[150px] rounded-full text-xs"
                :disabled="
                    processing ||
                    !props.connection.imapConfigured
                "
                @update:model-value="
                    emit('update:startDate', String($event ?? ''))
                "
            />

            <Button
                type="submit"
                variant="outline"
                size="sm"
                class="gap-1.5 rounded-full text-xs"
                :disabled="
                    processing ||
                    !props.startDate ||
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
                :message="errors.startDate"
            />
        </Form>
    </div>
</template>
