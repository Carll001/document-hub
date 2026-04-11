<script setup lang="ts">
import { Clipboard, FileText, LoaderCircle } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import type {
    Form1702ExBatchRow,
    Form1702ExReceiptField,
} from '@/components/form-1702-ex-components/types';
import { formatFileSize } from '@/components/form-1702-ex-components/utils';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    canSubmit: boolean;
    fieldError: string | null;
    fields: Form1702ExReceiptField[];
    open: boolean;
    processing: boolean;
    row: Form1702ExBatchRow | null;
    valueErrors: Record<string, string | undefined>;
    values: Record<string, string>;
}>();

const emit = defineEmits<{
    pasteFromEmail: [];
    submit: [];
    'update:open': [value: boolean];
    'update:value': [payload: { key: string; value: string }];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader class="space-y-3">
                <DialogTitle>
                    {{
                        props.row?.hasReceipt
                            ? 'Queue receipt update'
                            : 'Queue receipt'
                    }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="props.row">
                        Fill the receipt fields for
                        <span class="font-medium text-foreground">
                            {{ props.row.taxpayerName }}
                        </span>
                        and queue the confirmation receipt to append at the end
                        of this row PDF.
                    </template>
                    <template v-else>
                        Queue a receipt for the selected 1702-EX row.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-6" @submit.prevent="emit('submit')">
                <div
                    v-if="props.row?.hasReceipt"
                    class="rounded-2xl border bg-muted/30 p-4 text-sm"
                >
                    <p class="font-medium text-foreground">Current receipt</p>
                    <div
                        class="mt-2 flex flex-wrap items-center gap-2 text-muted-foreground"
                    >
                        <span>{{ props.row.receiptFileName }}</span>
                        <span>{{
                            formatFileSize(props.row.receiptFileSize)
                        }}</span>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-medium text-foreground">
                                Receipt values
                            </p>
                            <p class="text-sm text-muted-foreground">
                                Provide values for the aligned receipt fields.
                            </p>
                        </div>
                        <Button
                            v-if="props.fields.length > 0"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="gap-2"
                            @click="emit('pasteFromEmail')"
                        >
                            <Clipboard class="size-4" />
                            Paste from email
                        </Button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="field in props.fields"
                            :key="field.key"
                            class="space-y-2"
                        >
                            <Label :for="`receipt-value-${field.key}`">
                                {{ field.label }}
                                <span class="text-muted-foreground"
                                    >({{ field.key }})</span
                                >
                            </Label>
                            <Input
                                :id="`receipt-value-${field.key}`"
                                :model-value="props.values[field.key] ?? ''"
                                type="text"
                                :placeholder="field.label"
                                @update:model-value="
                                    emit('update:value', {
                                        key: field.key,
                                        value: String($event),
                                    })
                                "
                            />
                            <InputError
                                :message="props.valueErrors[field.key]"
                            />
                        </div>
                    </div>

                    <InputError :message="props.fieldError ?? undefined" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="props.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="submit"
                        class="gap-2"
                        :disabled="!props.canSubmit"
                    >
                        <LoaderCircle
                            v-if="props.processing"
                            class="size-4 animate-spin"
                        />
                        <FileText v-else class="size-4" />
                        {{
                            props.processing
                                ? 'Queueing receipt...'
                                : props.row?.hasReceipt
                                  ? 'Queue receipt update'
                                  : 'Queue receipt'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
