<script setup lang="ts">
import { LoaderCircle, Mail } from 'lucide-vue-next';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    canSubmit: boolean;
    fileName: string | null;
    message: string;
    open: boolean;
    processing: boolean;
    recipientEmail: string;
    subject: string;
    errors: {
        message?: string;
        recipientEmail?: string;
        subject?: string;
    };
}>();

const emit = defineEmits<{
    submit: [];
    'update:message': [value: string];
    'update:open': [value: boolean];
    'update:recipientEmail': [value: string];
    'update:subject': [value: string];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader class="space-y-3">
                <DialogTitle>Send to email</DialogTitle>
                <DialogDescription>
                    Send {{ props.fileName }} as an attachment.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="emit('submit')">
                <div class="space-y-2">
                    <Label for="batchRecipientEmail">Recipient email</Label>
                    <Input
                        id="batchRecipientEmail"
                        :model-value="props.recipientEmail"
                        type="email"
                        autocomplete="email"
                        placeholder="name@example.com"
                        @update:model-value="
                            emit('update:recipientEmail', String($event))
                        "
                    />
                    <InputError :message="props.errors.recipientEmail" />
                </div>

                <div class="space-y-2">
                    <Label for="batchEmailSubject">Subject</Label>
                    <Input
                        id="batchEmailSubject"
                        :model-value="props.subject"
                        type="text"
                        maxlength="150"
                        @update:model-value="emit('update:subject', String($event))"
                    />
                    <InputError :message="props.errors.subject" />
                </div>

                <div class="space-y-2">
                    <Label for="batchEmailMessage">Message</Label>
                    <textarea
                        id="batchEmailMessage"
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
                        {{ props.processing ? 'Sending email...' : 'Send email' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
