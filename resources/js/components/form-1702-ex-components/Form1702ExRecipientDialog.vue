<script setup lang="ts">
import { LoaderCircle, Mail, SquarePen } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import type { Form1702ExBatchRow } from '@/components/form-1702-ex-components/types';
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
    errors: {
        recipientEmail?: string;
    };
    open: boolean;
    processing: boolean;
    recipientEmail: string;
    row: Form1702ExBatchRow | null;
}>();

const emit = defineEmits<{
    submit: [];
    'update:open': [value: boolean];
    'update:recipientEmail': [value: string];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader class="space-y-2">
                <DialogTitle>Edit recipient</DialogTitle>
                <DialogDescription>
                    Save the recipient email used for completed-file sending for
                    {{ props.row?.taxpayerName ?? 'this row' }}. Leave it blank to clear the recipient.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="emit('submit')">
                <div class="space-y-2">
                    <Label for="recipientEmail">Recipient email</Label>
                    <Input
                        id="recipientEmail"
                        :model-value="props.recipientEmail"
                        type="email"
                        maxlength="254"
                        placeholder="name@example.com"
                        @update:model-value="emit('update:recipientEmail', String($event))"
                    />
                    <InputError :message="props.errors.recipientEmail" />
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
                        <Mail
                            v-else-if="props.recipientEmail.trim() !== ''"
                            class="size-4"
                        />
                        <SquarePen v-else class="size-4" />
                        {{ props.processing ? 'Saving...' : 'Save recipient' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
