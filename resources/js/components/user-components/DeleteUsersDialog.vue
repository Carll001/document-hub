<script setup lang="ts">
import { LoaderCircle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';

const props = defineProps<{
    canConfirm: boolean;
    description: string;
    open: boolean;
    processing: boolean;
    title: string;
}>();

const emit = defineEmits<{
    submit: [];
    'update:open': [open: boolean];
}>();
</script>

<template>
    <AlertDialog :open="props.open" @update:open="emit('update:open', $event)">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>{{ props.title }}</AlertDialogTitle>
                <AlertDialogDescription>
                    {{ props.description }}
                </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
                <AlertDialogCancel :disabled="props.processing">
                    Cancel
                </AlertDialogCancel>
                <Button
                    type="button"
                    variant="destructive"
                    :disabled="props.canConfirm === false"
                    class="sm:ml-2"
                    @click="emit('submit')"
                >
                    <LoaderCircle
                        v-if="props.processing"
                        class="mr-2 size-4 animate-spin"
                    />
                    Delete
                </Button>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>
</template>
