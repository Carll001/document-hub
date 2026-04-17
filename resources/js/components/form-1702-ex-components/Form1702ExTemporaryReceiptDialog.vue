<script setup lang="ts">
import { Save } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import type { Form1702ExBatchRow } from '@/components/form-1702-ex-components/types';
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
    errors: {
        recipientEmail?: string;
        temporaryReceipt?: string;
    };
    open: boolean;
    recipientEmail: string;
    row: Form1702ExBatchRow | null;
    temporaryReceipt: File | null;
}>();

const emit = defineEmits<{
    selectTemporaryReceipt: [file: File | null];
    submit: [];
    'update:open': [value: boolean];
    'update:recipientEmail': [value: string];
}>();

function handleTemporaryReceiptSelected(event: Event): void {
    const input = event.target as HTMLInputElement | null;
    const file = input?.files?.[0] ?? null;

    emit('selectTemporaryReceipt', file);

    if (input) {
        input.value = '';
    }
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader class="space-y-2">
                <DialogTitle>Add temporary receipt</DialogTitle>
                <DialogDescription>
                    Upload a temporary receipt for
                    {{ props.row?.taxpayerName ?? 'this row' }}.
                    Optionally replace the saved recipient email for sending.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="emit('submit')">
                <div class="space-y-2">
                    <Label for="temporaryReceipt">Temporary receipt file</Label>
                    <Input
                        id="temporaryReceipt"
                        type="file"
                        accept="image/*,.pdf"
                        @change="handleTemporaryReceiptSelected"
                    />
                    <p
                        v-if="props.temporaryReceipt"
                        class="text-xs text-muted-foreground"
                    >
                        {{ props.temporaryReceipt.name }} ({{
                            formatFileSize(props.temporaryReceipt.size)
                        }})
                    </p>
                    <InputError :message="props.errors.temporaryReceipt" />
                </div>

                <div class="space-y-2">
                    <Label for="temporaryRecipientEmail">
                        Replace recipient (optional)
                    </Label>
                    <Input
                        id="temporaryRecipientEmail"
                        :model-value="props.recipientEmail"
                        type="email"
                        maxlength="254"
                        placeholder="name@example.com"
                        @update:model-value="
                            emit('update:recipientEmail', String($event))
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Leave blank to keep no recipient.
                    </p>
                    <InputError :message="props.errors.recipientEmail" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" class="gap-2">
                        <Save class="size-4" />
                        Save temporary receipt
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
