<script setup lang="ts">
import { LoaderCircle } from 'lucide-vue-next';
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
import type { BatchMergedOutput } from '@/components/doc-merge-batch-components/types';

const props = defineProps<{
    mergedPdf: BatchMergedOutput | null;
    open: boolean;
    processing: boolean;
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
                    <template v-if="props.mergedPdf">
                        Remove the receipt from
                        <span class="font-medium text-foreground">
                            {{ props.mergedPdf.fileName }}
                        </span>
                        ? This will remove the appended receipt pages from the
                        saved merged PDF.
                    </template>
                    <template v-else>
                        Remove the attached receipt from this saved merged PDF.
                    </template>
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
                    <LoaderCircle
                        v-if="props.processing"
                        class="mr-2 size-4 animate-spin"
                    />
                    {{ props.processing ? 'Removing...' : 'Remove receipt' }}
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
