<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
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
import { Spinner } from '@/components/ui/spinner';
import { sendJson } from '@/components/afs-components/utils';
import type { UnifiedItem } from '@/components/afs-components/types';
import documentGeneratorRoutes from '@/routes/afs-filing';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    item: UnifiedItem | null;
}>();

const emit = defineEmits<{
    saved: [];
}>();

const submitting = ref(false);
const errorMessage = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});
const editForm = reactive<Record<string, string>>({});

const formEntries = computed(() => Object.entries(editForm));

const resetForm = () => {
    for (const key of Object.keys(editForm)) {
        delete editForm[key];
    }
};

const initForm = (item: UnifiedItem) => {
    resetForm();
    errorMessage.value = null;
    errors.value = {};

    for (const [key, value] of Object.entries(item.row_data)) {
        editForm[key] = value;
    }
};

const close = () => {
    open.value = false;
    errorMessage.value = null;
    errors.value = {};
    resetForm();
};

const save = async () => {
    if (!props.item) {
        return;
    }

    submitting.value = true;
    errorMessage.value = null;
    errors.value = {};

    try {
        await sendJson<UnifiedItem>(
            documentGeneratorRoutes.items.update.url({ item: props.item.id }),
            'PUT',
            {
                row_data: editForm,
            },
        );

        emit('saved');
        close();
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            errors.value = (error as Error & { validationErrors?: Record<string, string[]> }).validationErrors ?? {};
        }

        errorMessage.value = error instanceof Error ? error.message : 'Unable to update row.';
    } finally {
        submitting.value = false;
    }
};

defineExpose({ initForm });
</script>

<template>
    <Dialog :open="open" @update:open="(val) => { if (!val) close(); }">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Edit Row {{ item?.row_number ?? '-' }}</DialogTitle>
                <DialogDescription>
                    Update the row data and regenerate documents. Old outputs will be deleted first.
                </DialogDescription>
            </DialogHeader>

            <div class="grid max-h-[60vh] gap-4 overflow-y-auto py-2">
                <div v-for="[key] in formEntries" :key="key" class="grid gap-2">
                    <Label :for="`edit-${key}`">{{ key }}</Label>
                    <Input :id="`edit-${key}`" v-model="editForm[key]" type="text" />
                    <p v-if="errors[`row_data.${key}`]" class="text-sm text-destructive">
                        {{ errors[`row_data.${key}`][0] }}
                    </p>
                </div>
            </div>

            <p v-if="errorMessage" class="text-sm text-destructive">
                {{ errorMessage }}
            </p>

            <DialogFooter>
                <Button variant="outline" @click="close">Cancel</Button>
                <Button :disabled="submitting" @click="save">
                    <Spinner v-if="submitting" class="size-4" />
                    Save and Regenerate
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

