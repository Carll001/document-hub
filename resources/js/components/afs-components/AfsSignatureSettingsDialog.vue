<script setup lang="ts">
import { reactive, ref } from 'vue';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { csrfToken, sendDelete } from '@/components/afs-components/utils';
import type { SignatureAnchor, SignatureSettings } from '@/components/afs-components/types';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    initialSignature: SignatureSettings | null;
}>();

const emit = defineEmits<{
    saved: [signature: SignatureSettings | null];
    removed: [];
}>();

const saving = ref(false);
const deleting = ref(false);
const errorMessage = ref<string | null>(null);
const errors = ref<Record<string, string[]>>({});
const signatureFile = ref<File | null>(null);
const signatureData = ref<SignatureSettings | null>(props.initialSignature);

const form = reactive({
    president: {
        page2: {
            anchor: (props.initialSignature?.president.page2.anchor ?? 'bottom_right') as SignatureAnchor,
            offset_x: props.initialSignature?.president.page2.offset_x ?? 0,
            offset_y: props.initialSignature?.president.page2.offset_y ?? 0,
            width: props.initialSignature?.president.page2.width ?? 40,
            height: props.initialSignature?.president.page2.height ?? 16,
        },
        page3: {
            anchor: (props.initialSignature?.president.page3.anchor ?? 'bottom_right') as SignatureAnchor,
            offset_x: props.initialSignature?.president.page3.offset_x ?? 0,
            offset_y: props.initialSignature?.president.page3.offset_y ?? 0,
            width: props.initialSignature?.president.page3.width ?? 40,
            height: props.initialSignature?.president.page3.height ?? 16,
        },
    },
    getor: {
        page4: {
            anchor: (props.initialSignature?.getor.page4.anchor ?? 'bottom_right') as SignatureAnchor,
            offset_x: props.initialSignature?.getor.page4.offset_x ?? 0,
            offset_y: props.initialSignature?.getor.page4.offset_y ?? 0,
            width: props.initialSignature?.getor.page4.width ?? 40,
            height: props.initialSignature?.getor.page4.height ?? 16,
        },
        page8: {
            anchor: (props.initialSignature?.getor.page8.anchor ?? 'bottom_right') as SignatureAnchor,
            offset_x: props.initialSignature?.getor.page8.offset_x ?? 0,
            offset_y: props.initialSignature?.getor.page8.offset_y ?? 0,
            width: props.initialSignature?.getor.page8.width ?? 40,
            height: props.initialSignature?.getor.page8.height ?? 16,
        },
    },
});

const onFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    signatureFile.value = input.files?.[0] ?? null;
};

const save = async () => {
    saving.value = true;
    errorMessage.value = null;
    errors.value = {};

    try {
        const formData = new FormData();
        formData.append('page2_anchor', form.president.page2.anchor);
        formData.append('page2_offset_x', String(form.president.page2.offset_x));
        formData.append('page2_offset_y', String(form.president.page2.offset_y));
        formData.append('page2_width', String(form.president.page2.width));
        formData.append('page2_height', String(form.president.page2.height));
        formData.append('page3_anchor', form.president.page3.anchor);
        formData.append('page3_offset_x', String(form.president.page3.offset_x));
        formData.append('page3_offset_y', String(form.president.page3.offset_y));
        formData.append('page3_width', String(form.president.page3.width));
        formData.append('page3_height', String(form.president.page3.height));
        formData.append('page4_anchor', form.getor.page4.anchor);
        formData.append('page4_offset_x', String(form.getor.page4.offset_x));
        formData.append('page4_offset_y', String(form.getor.page4.offset_y));
        formData.append('page4_width', String(form.getor.page4.width));
        formData.append('page4_height', String(form.getor.page4.height));
        formData.append('page8_anchor', form.getor.page8.anchor);
        formData.append('page8_offset_x', String(form.getor.page8.offset_x));
        formData.append('page8_offset_y', String(form.getor.page8.offset_y));
        formData.append('page8_width', String(form.getor.page8.width));
        formData.append('page8_height', String(form.getor.page8.height));

        if (signatureFile.value) {
            formData.append('signature_file', signatureFile.value);
        }

        const response = await fetch('/document-generator/signature', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
        });

        if (response.status === 422) {
            const payload = (await response.json()) as {
                errors?: Record<string, string[]>;
                message?: string;
            };
            errors.value = payload.errors ?? {};
            errorMessage.value = payload.message ?? 'Validation failed.';
            return;
        }

        if (!response.ok) {
            throw new Error(`Request failed with status ${response.status}`);
        }

        const payload = (await response.json()) as {
            signature: SignatureSettings | null;
        };

        signatureData.value = payload.signature;

        if (payload.signature) {
            form.president.page2.anchor = payload.signature.president.page2.anchor;
            form.president.page2.offset_x = payload.signature.president.page2.offset_x;
            form.president.page2.offset_y = payload.signature.president.page2.offset_y;
            form.president.page2.width = payload.signature.president.page2.width;
            form.president.page2.height = payload.signature.president.page2.height;
            form.president.page3.anchor = payload.signature.president.page3.anchor;
            form.president.page3.offset_x = payload.signature.president.page3.offset_x;
            form.president.page3.offset_y = payload.signature.president.page3.offset_y;
            form.president.page3.width = payload.signature.president.page3.width;
            form.president.page3.height = payload.signature.president.page3.height;
            form.getor.page4.anchor = payload.signature.getor.page4.anchor;
            form.getor.page4.offset_x = payload.signature.getor.page4.offset_x;
            form.getor.page4.offset_y = payload.signature.getor.page4.offset_y;
            form.getor.page4.width = payload.signature.getor.page4.width;
            form.getor.page4.height = payload.signature.getor.page4.height;
            form.getor.page8.anchor = payload.signature.getor.page8.anchor;
            form.getor.page8.offset_x = payload.signature.getor.page8.offset_x;
            form.getor.page8.offset_y = payload.signature.getor.page8.offset_y;
            form.getor.page8.width = payload.signature.getor.page8.width;
            form.getor.page8.height = payload.signature.getor.page8.height;
        }

        signatureFile.value = null;
        emit('saved', payload.signature);
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Unable to save signature settings.';
    } finally {
        saving.value = false;
    }
};

const remove = async () => {
    deleting.value = true;
    errorMessage.value = null;
    errors.value = {};

    try {
        await sendDelete('/document-generator/signature');
        signatureData.value = null;
        signatureFile.value = null;
        emit('removed');
    } catch (error) {
        errorMessage.value = error instanceof Error ? error.message : 'Unable to remove signature.';
    } finally {
        deleting.value = false;
    }
};

const anchorOptions: { value: SignatureAnchor; label: string }[] = [
    { value: 'top_left', label: 'Top Left' },
    { value: 'top_right', label: 'Top Right' },
    { value: 'bottom_left', label: 'Bottom Left' },
    { value: 'bottom_right', label: 'Bottom Right' },
    { value: 'center', label: 'Center' },
];
</script>

<template>
    <Dialog :open="open" @update:open="(val) => { open = val; }">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Signature Settings</DialogTitle>
                <DialogDescription>
                    Upload the default Getor signature image and configure layouts for President (pages 2/3) and Getor (pages 4/8).
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 py-2">
                <div class="grid gap-2">
                    <Label for="signature-file">Signature Image</Label>
                    <Input id="signature-file" type="file" accept=".png,.jpg,.jpeg,.webp" @change="onFileChange" />
                    <p v-if="errors.signature_file" class="text-sm text-destructive">
                        {{ errors.signature_file[0] }}
                    </p>
                </div>

                <div v-if="signatureData?.getor.preview_url" class="grid gap-2">
                    <Label>Current Signature Preview</Label>
                    <div class="rounded-md border bg-muted p-3">
                        <img :src="signatureData.getor.preview_url" alt="Signature preview" class="max-h-24 object-contain" />
                    </div>
                </div>

                <template v-for="section in [
                    { title: 'President Signature: Page 2', model: form.president.page2, prefix: 'page2' },
                    { title: 'President Signature: Page 3', model: form.president.page3, prefix: 'page3' },
                    { title: 'Getor Signature: Page 4', model: form.getor.page4, prefix: 'page4' },
                    { title: 'Getor Signature: Page 8', model: form.getor.page8, prefix: 'page8' },
                ]" :key="section.prefix">
                    <div class="grid gap-4 rounded-md border p-3">
                        <h4 class="text-sm font-semibold">{{ section.title }}</h4>
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="grid gap-2">
                                <Label>Anchor</Label>
                                <Select
                                    :model-value="section.model.anchor"
                                    @update:model-value="(value) => section.model.anchor = String(value) as SignatureAnchor"
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="opt in anchorOptions" :key="opt.value" :value="opt.value">
                                            {{ opt.label }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`sig-${section.prefix}-width`">Width (mm)</Label>
                                <Input :id="`sig-${section.prefix}-width`" v-model.number="section.model.width" type="number" min="1" max="300" step="0.1" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`sig-${section.prefix}-height`">Height (mm)</Label>
                                <Input :id="`sig-${section.prefix}-height`" v-model.number="section.model.height" type="number" min="1" max="300" step="0.1" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`sig-${section.prefix}-offset-x`">Offset X (mm)</Label>
                                <Input :id="`sig-${section.prefix}-offset-x`" v-model.number="section.model.offset_x" type="number" min="-500" max="500" step="0.1" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`sig-${section.prefix}-offset-y`">Offset Y (mm)</Label>
                                <Input :id="`sig-${section.prefix}-offset-y`" v-model.number="section.model.offset_y" type="number" min="-500" max="500" step="0.1" />
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <p v-if="errorMessage" class="text-sm text-destructive">
                {{ errorMessage }}
            </p>

            <DialogFooter>
                <Button v-if="signatureData" variant="destructive" :disabled="deleting" @click="remove">
                    <Spinner v-if="deleting" class="size-4" />
                    Remove Signature
                </Button>
                <Button :disabled="saving" @click="save">
                    <Spinner v-if="saving" class="size-4" />
                    Save Signature
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
