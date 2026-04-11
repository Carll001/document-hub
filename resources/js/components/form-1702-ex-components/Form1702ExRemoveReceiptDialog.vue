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
                <AlertDialogTitle>Remove receipt</AlertDialogTitle>
                <AlertDialogDescription>
                    Remove the appended receipt from
                    <span class="font-medium text-foreground">
                        {{ props.row?.taxpayerName ?? 'this row' }}
                    </span>
                    ? This restores the plain 1702-EX PDF and deletes the stored
                    receipt file.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel :disabled="props.processing">
                    Cancel
                </AlertDialogCancel>
                <AlertDialogAction
                    :disabled="props.processing"
                    class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    @click="emit('submit')"
                >
                    {{ props.processing ? 'Removing...' : 'Remove receipt' }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
