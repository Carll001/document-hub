<script setup lang="ts">
import { LoaderCircle, Mail } from 'lucide-vue-next';
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
    canSubmit: boolean;
    extraAttachment: File | null;
    open: boolean;
    processing: boolean;
    row: Form1702ExBatchRow | null;
    subject: string;
    message: string;
    errors: {
        subject?: string;
        message?: string;
        extraAttachment?: string;
    };
}>();

const emit = defineEmits<{
    selectExtraAttachment: [file: File | null];
    submit: [];
    'update:message': [value: string];
    'update:open': [value: boolean];
    'update:subject': [value: string];
}>();

function handleAttachmentSelected(event: Event): void {
    const input = event.target as HTMLInputElement | null;
    const file = input?.files?.[0] ?? null;

    emit('selectExtraAttachment', file);

    if (input) {
        input.value = '';
    }
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader class="space-y-2">
                <DialogTitle>TFCI FILER</DialogTitle>
                <DialogDescription>
                    Queue {{ props.row?.fileName ?? 'the completed PDF' }} to
                    {{ props.row?.recipientEmail ?? 'the saved recipient email' }}.
                    The final PDF already includes the receipt.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="emit('submit')">
                <div class="space-y-2">
                    <Label>Recipient email</Label>
                    <Input
                        :model-value="props.row?.recipientEmail ?? ''"
                        type="email"
                        disabled
                    />
                </div>

                <div class="space-y-2">
                    <Label for="completedEmailSubject">Subject</Label>
                    <Input
                        id="completedEmailSubject"
                        :model-value="props.subject"
                        type="text"
                        maxlength="150"
                        @update:model-value="emit('update:subject', String($event))"
                    />
                    <InputError :message="props.errors.subject" />
                </div>

                <div class="space-y-2">
                    <Label for="completedEmailMessage">Message</Label>
                    <textarea
                        id="completedEmailMessage"
                        :value="props.message"
                        rows="6"
                        maxlength="5000"
                        class="flex min-h-[120px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50"
                        @input="
                            emit(
                                'update:message',
                                ($event.target as HTMLTextAreaElement).value,
                            )
                        "
                    />
                    <InputError :message="props.errors.message" />
                </div>

                <div class="space-y-2">
                    <Label for="completedExtraAttachment">Extra attachment</Label>
                    <Input
                        id="completedExtraAttachment"
                        type="file"
                        @change="handleAttachmentSelected"
                    />
                    <p
                        v-if="props.extraAttachment"
                        class="text-xs text-muted-foreground"
                    >
                        {{ props.extraAttachment.name }} ({{
                            formatFileSize(props.extraAttachment.size)
                        }})
                    </p>
                    <InputError :message="props.errors.extraAttachment" />
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
                        <Mail v-else class="size-4" />
                        {{ props.processing ? 'Queueing email...' : 'Queue email' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
