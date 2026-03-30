<script setup lang="ts">
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { BatchFailure } from '@/components/doc-merge-batch-components/types';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    failure: BatchFailure | null;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader class="space-y-3">
                <DialogTitle>Bulk merge error</DialogTitle>
                <DialogDescription>
                    Review why this output was skipped during bulk merge.
                </DialogDescription>
            </DialogHeader>

            <div v-if="props.failure" class="space-y-4 text-sm">
                <div class="rounded-2xl border bg-muted/30 p-4">
                    <div class="space-y-1">
                        <p class="font-medium text-foreground">Output file</p>
                        <p>{{ props.failure.fileName }}</p>
                    </div>
                    <div class="mt-4 space-y-1">
                        <p class="font-medium text-foreground">Matched PDF</p>
                        <p>{{ props.failure.groupLabel }}</p>
                    </div>
                </div>

                <div
                    class="rounded-2xl border border-destructive/20 bg-destructive/5 p-4"
                >
                    <p class="font-medium text-destructive">Error</p>
                    <p class="mt-2 text-foreground">
                        {{ props.failure.errorMessage }}
                    </p>
                </div>
            </div>

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="secondary"
                    @click="emit('update:open', false)"
                >
                    Close
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
