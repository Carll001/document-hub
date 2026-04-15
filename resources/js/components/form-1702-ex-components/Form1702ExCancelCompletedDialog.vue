<script setup lang="ts">
import type { Form1702ExBatchRow } from '@/components/form-1702-ex-components/types';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

const props = defineProps<{
    open: boolean;
    processing: boolean;
    row: Form1702ExBatchRow | null;
}>();

const emit = defineEmits<{
    submit: [];
    'update:open': [value: boolean];
}>();
</script>

<template>
    <AlertDialog :open="props.open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Cancel completed file</AlertDialogTitle>
                <AlertDialogDescription>
                    Cancel the completed file for
                    <span class="font-medium text-foreground">
                        {{ props.row?.taxpayerName ?? 'this row' }}
                    </span>
                    ? This will delete the completed row and return its linked
                    receipt email to Email Sync so it can be used again later.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel :disabled="props.processing">
                    Keep completed file
                </AlertDialogCancel>
                <AlertDialogAction
                    :disabled="props.processing"
                    class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    @click="emit('submit')"
                >
                    {{ props.processing ? 'Cancelling...' : 'Cancel file' }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
