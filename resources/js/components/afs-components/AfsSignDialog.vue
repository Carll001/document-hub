<script setup lang="ts">
import { ref } from 'vue';
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
import { sendPostFormData } from '@/components/afs-components/utils';
import type { UnifiedItem } from '@/components/afs-components/types';
import documentGeneratorRoutes from '@/routes/document-generator';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    target: UnifiedItem | null;
    mode?: 'single' | 'bulk';
    bulkItemIds?: number[];
}>();

const emit = defineEmits<{
    signed: [pdfUrl?: string];
}>();

const submitting = ref(false);
const error = ref<string | null>(null);
const presidentSignatureFile = ref<File | null>(null);

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    presidentSignatureFile.value = input.files?.[0] ?? null;
    error.value = null;
};

const submit = async () => {
    if (!presidentSignatureFile.value) {
        error.value = 'President signature image is required.';
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        if (props.mode === 'bulk' && props.bulkItemIds?.length) {
            const formData = new FormData();
            formData.append('president_signature_file', presidentSignatureFile.value);

            for (const id of props.bulkItemIds) {
                formData.append('item_ids[]', String(id));
            }

            await sendPostFormData<{ message: string }>(
                documentGeneratorRoutes.items.signature.bulk.url(),
                formData,
            );

            emit('signed');
        } else if (props.target) {
            const formData = new FormData();
            formData.append('president_signature_file', presidentSignatureFile.value);

            const payload = await sendPostFormData<{
                message: string;
                item: Record<string, unknown>;
                pdf_url: string;
            }>(
                documentGeneratorRoutes.batches.items.signature.url({
                    batch: props.target.batch_id,
                    item: props.target.id,
                }),
                formData,
            );

            emit('signed', payload.pdf_url);
        }

        open.value = false;
        presidentSignatureFile.value = null;
    } catch (err) {
        error.value = err instanceof Error ? err.message : 'Unable to apply signature.';
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(val) => { open = val; }">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Apply Signature</DialogTitle>
                <DialogDescription>
                    <template v-if="mode === 'bulk'">
                        Upload President signature image to apply to {{ bulkItemIds?.length ?? 0 }} selected items.
                    </template>
                    <template v-else>
                        Upload President signature image for this signing action. Getor default signature will be applied automatically.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2 py-2">
                <Label for="president-signature-file">President Signature Image</Label>
                <Input
                    id="president-signature-file"
                    type="file"
                    accept=".png,.jpg,.jpeg,.webp"
                    @change="onFileChange"
                />
            </div>

            <p v-if="error" class="text-sm text-destructive">
                {{ error }}
            </p>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancel</Button>
                <Button :disabled="submitting" @click="submit">
                    <Spinner v-if="submitting" class="size-4" />
                    Apply
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
