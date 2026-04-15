<script setup lang="ts">
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
    rowCount: number;
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
                <AlertDialogTitle>Cancel selected completed files</AlertDialogTitle>
                <AlertDialogDescription>
                    Cancel
                    <span class="font-medium text-foreground">
                        {{ props.rowCount }}
                    </span>
                    selected completed file{{ props.rowCount === 1 ? '' : 's' }}?
                    This will delete those completed rows and return any linked
                    receipt emails to Email Sync so they can be used again later.
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel :disabled="props.processing">
                    Keep selected files
                </AlertDialogCancel>
                <AlertDialogAction
                    :disabled="props.processing"
                    class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                    @click="emit('submit')"
                >
                    {{ props.processing ? 'Cancelling...' : 'Cancel selected' }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
