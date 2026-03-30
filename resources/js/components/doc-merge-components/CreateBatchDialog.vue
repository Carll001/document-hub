<script setup lang="ts">
import { LoaderCircle, Plus } from 'lucide-vue-next';
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
    errors: {
        name?: string;
    };
    name: string;
    open: boolean;
    processing: boolean;
}>();

const emit = defineEmits<{
    submit: [];
    'update:name': [value: string];
    'update:open': [value: boolean];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader class="space-y-1">
                <DialogTitle>Create batch</DialogTitle>
                <DialogDescription>
                    Create a saved batch workspace, upload page folders or a
                    ZIP into it, then run merge when you are ready.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-6" @submit.prevent="emit('submit')">
                <div class="space-y-2">
                    <Label for="batchName">Batch name</Label>
                    <Input
                        id="batchName"
                        :model-value="props.name"
                        type="text"
                        maxlength="120"
                        placeholder="March 30 BIR batch"
                        @update:model-value="emit('update:name', String($event))"
                    />
                    <InputError :message="props.errors.name" />
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
                        <Plus v-else class="size-4" />
                        {{ props.processing ? 'Creating...' : 'Create batch' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
