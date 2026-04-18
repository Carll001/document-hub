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
import { csrfToken, sendPostFormData } from '@/components/afs-components/utils';
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
const preflightMessage = ref<string | null>(null);
const presidentSignatureFile = ref<File | null>(null);

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    presidentSignatureFile.value = input.files?.[0] ?? null;
    error.value = null;
};

const firstValidationMessage = (err: unknown): string | null => {
    if (!err || typeof err !== 'object') {
        return null;
    }

    const validationErrors = (err as { validationErrors?: Record<string, string[]> }).validationErrors;
    if (!validationErrors) {
        return null;
    }

    for (const messages of Object.values(validationErrors)) {
        if (Array.isArray(messages) && messages.length > 0 && typeof messages[0] === 'string') {
            return messages[0];
        }
    }

    return null;
};

const runSingleItemAnchorPreflight = async (): Promise<boolean> => {
    if (!props.target || props.mode === 'bulk') {
        return true;
    }

    const url = `/document-generator/batches/${props.target.batch_id}/items/${props.target.id}/signature/preflight`;
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
    });

    if (response.ok) {
        const payload = (await response.json()) as { message?: string };
        preflightMessage.value = payload.message ?? 'Anchor preflight passed.';
        return true;
    }

    if (response.status === 422) {
        const payload = (await response.json()) as { message?: string };
        error.value = payload.message ?? 'Anchor preflight failed. Switch to fixed placement or update anchor text.';
        return false;
    }

    error.value = `Anchor preflight failed with status ${response.status}.`;
    return false;
};

const submit = async () => {
    preflightMessage.value = null;
    if (!await runSingleItemAnchorPreflight()) {
        return;
    }

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
        error.value = firstValidationMessage(err) ?? (err instanceof Error ? err.message : 'Unable to apply signature.');
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

            <p v-if="preflightMessage" class="text-xs text-muted-foreground">
                {{ preflightMessage }}
            </p>

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
