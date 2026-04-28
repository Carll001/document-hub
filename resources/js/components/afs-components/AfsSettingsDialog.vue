<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import type { SignatureSettings, TemplateMappingPayload } from '@/components/afs-components/types';
import { sendDelete } from '@/components/afs-components/utils';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { createToast, showToast } from '@/lib/toast';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    mapping: TemplateMappingPayload;
    initialSignature: SignatureSettings | null;
    signatureEnabled: boolean;
}>();

const emit = defineEmits<{
    mappingUpdated: [mapping: TemplateMappingPayload];
}>();

type SettingsSection = 'template' | 'signature';

const activeSection = ref<SettingsSection>('template');
const localMapping = ref<TemplateMappingPayload>(props.mapping);
const defaultTemplateFile = ref<File | null>(null);
const defaultTemplateInput = ref<HTMLInputElement | null>(null);
const defaultTemplateErrors = ref<Record<string, string[]>>({});
const defaultTemplateSaving = ref(false);

const signatureData = ref<SignatureSettings | null>(props.initialSignature);
const signatureFile = ref<File | null>(null);
const signatureInput = ref<HTMLInputElement | null>(null);
const signatureErrors = ref<Record<string, string[]>>({});
const signatureErrorMessage = ref<string | null>(null);
const signatureSaving = ref(false);
const signatureDeleting = ref(false);

const signatureForm = reactive({
    president: {
        page2: {
            anchor: props.initialSignature?.president.page2.anchor ?? 'bottom_right',
            placement_mode: props.initialSignature?.president.page2.placement_mode ?? 'fixed',
            anchor_text: props.initialSignature?.president.page2.anchor_text ?? '',
            offset_x: props.initialSignature?.president.page2.offset_x ?? 0,
            offset_y: props.initialSignature?.president.page2.offset_y ?? 0,
            width: props.initialSignature?.president.page2.width ?? 40,
            height: props.initialSignature?.president.page2.height ?? 16,
        },
        page3: {
            anchor: props.initialSignature?.president.page3.anchor ?? 'bottom_right',
            placement_mode: props.initialSignature?.president.page3.placement_mode ?? 'fixed',
            anchor_text: props.initialSignature?.president.page3.anchor_text ?? '',
            offset_x: props.initialSignature?.president.page3.offset_x ?? 0,
            offset_y: props.initialSignature?.president.page3.offset_y ?? 0,
            width: props.initialSignature?.president.page3.width ?? 40,
            height: props.initialSignature?.president.page3.height ?? 16,
        },
    },
    getor: {
        page4: {
            anchor: props.initialSignature?.getor.page4.anchor ?? 'bottom_right',
            placement_mode: props.initialSignature?.getor.page4.placement_mode ?? 'fixed',
            anchor_text: props.initialSignature?.getor.page4.anchor_text ?? '',
            offset_x: props.initialSignature?.getor.page4.offset_x ?? 0,
            offset_y: props.initialSignature?.getor.page4.offset_y ?? 0,
            width: props.initialSignature?.getor.page4.width ?? 40,
            height: props.initialSignature?.getor.page4.height ?? 16,
        },
        page8: {
            anchor: props.initialSignature?.getor.page8.anchor ?? 'bottom_right',
            placement_mode: props.initialSignature?.getor.page8.placement_mode ?? 'fixed',
            anchor_text: props.initialSignature?.getor.page8.anchor_text ?? '',
            offset_x: props.initialSignature?.getor.page8.offset_x ?? 0,
            offset_y: props.initialSignature?.getor.page8.offset_y ?? 0,
            width: props.initialSignature?.getor.page8.width ?? 40,
            height: props.initialSignature?.getor.page8.height ?? 16,
        },
    },
});

watch(
    () => props.mapping,
    (next) => {
        localMapping.value = next;
    },
    { deep: true },
);

watch(
    () => props.initialSignature,
    (next) => {
        signatureData.value = next;
    },
    { deep: true },
);

watch(
    () => open.value,
    (isOpen) => {
        if (!isOpen) return;
        activeSection.value = 'template';
    },
);

const csrfToken = () => {
    const xsrfCookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    if (!xsrfCookie) return '';
    return decodeURIComponent(xsrfCookie.split('=')[1] ?? '');
};

const showNotice = (type: 'success' | 'error', title: string, message: string) => {
    showToast(createToast(type, title, message));
};

const parseValidationError = async (response: Response) => {
    const payload = (await response.json()) as {
        message?: string;
        errors?: Record<string, string[]>;
    };
    const validationError = new Error(payload.message ?? 'Validation failed.');
    Object.assign(validationError, { validationErrors: payload.errors ?? {} });
    return validationError;
};

const sendForm = async (url: string, formData: FormData): Promise<TemplateMappingPayload> => {
    const response = await fetch(url, {
        method: 'POST',
        body: formData,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
    });
    if (response.status === 422) throw await parseValidationError(response);
    if (!response.ok) throw new Error(`Request failed with status ${response.status}`);
    return (await response.json()) as TemplateMappingPayload;
};

const onDefaultTemplateFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    defaultTemplateFile.value = input.files?.[0] ?? null;
};
const openDefaultTemplatePicker = () => defaultTemplateInput.value?.click();
const onTemplateCardKeydown = (event: KeyboardEvent) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    openDefaultTemplatePicker();
};

const updateDefaultTemplate = async () => {
    if (!defaultTemplateFile.value) {
        defaultTemplateErrors.value = { template_file: ['Template file is required.'] };
        return;
    }
    defaultTemplateSaving.value = true;
    defaultTemplateErrors.value = {};
    try {
        const formData = new FormData();
        formData.append('template_file', defaultTemplateFile.value);
        const nextMapping = await sendForm('/afs-filing/templates/default', formData);
        localMapping.value = nextMapping;
        emit('mappingUpdated', nextMapping);
        defaultTemplateFile.value = null;
        showNotice('success', 'Default template updated', 'Future batches will use the new default template.');
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            defaultTemplateErrors.value = (error as Error & { validationErrors?: Record<string, string[]> }).validationErrors ?? {};
        }
        showNotice('error', 'Default template was not updated', error instanceof Error ? error.message : 'Unable to update the default template.');
    } finally {
        defaultTemplateSaving.value = false;
    }
};

const onSignatureFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    signatureFile.value = input.files?.[0] ?? null;
};
const openSignaturePicker = () => signatureInput.value?.click();
const onSignatureCardKeydown = (event: KeyboardEvent) => {
    if (event.key !== 'Enter' && event.key !== ' ') return;
    event.preventDefault();
    openSignaturePicker();
};

const saveSignature = async () => {
    signatureSaving.value = true;
    signatureErrorMessage.value = null;
    signatureErrors.value = {};

    try {
        const formData = new FormData();
        formData.append('page2_anchor', signatureForm.president.page2.anchor);
        formData.append('page2_placement_mode', signatureForm.president.page2.placement_mode);
        formData.append('page2_anchor_text', signatureForm.president.page2.anchor_text);
        formData.append('page2_offset_x', String(signatureForm.president.page2.offset_x));
        formData.append('page2_offset_y', String(signatureForm.president.page2.offset_y));
        formData.append('page2_width', String(signatureForm.president.page2.width));
        formData.append('page2_height', String(signatureForm.president.page2.height));
        formData.append('page3_anchor', signatureForm.president.page3.anchor);
        formData.append('page3_placement_mode', signatureForm.president.page3.placement_mode);
        formData.append('page3_anchor_text', signatureForm.president.page3.anchor_text);
        formData.append('page3_offset_x', String(signatureForm.president.page3.offset_x));
        formData.append('page3_offset_y', String(signatureForm.president.page3.offset_y));
        formData.append('page3_width', String(signatureForm.president.page3.width));
        formData.append('page3_height', String(signatureForm.president.page3.height));
        formData.append('page4_anchor', signatureForm.getor.page4.anchor);
        formData.append('page4_placement_mode', signatureForm.getor.page4.placement_mode);
        formData.append('page4_anchor_text', signatureForm.getor.page4.anchor_text);
        formData.append('page4_offset_x', String(signatureForm.getor.page4.offset_x));
        formData.append('page4_offset_y', String(signatureForm.getor.page4.offset_y));
        formData.append('page4_width', String(signatureForm.getor.page4.width));
        formData.append('page4_height', String(signatureForm.getor.page4.height));
        formData.append('page8_anchor', signatureForm.getor.page8.anchor);
        formData.append('page8_placement_mode', signatureForm.getor.page8.placement_mode);
        formData.append('page8_anchor_text', signatureForm.getor.page8.anchor_text);
        formData.append('page8_offset_x', String(signatureForm.getor.page8.offset_x));
        formData.append('page8_offset_y', String(signatureForm.getor.page8.offset_y));
        formData.append('page8_width', String(signatureForm.getor.page8.width));
        formData.append('page8_height', String(signatureForm.getor.page8.height));
        if (signatureFile.value) formData.append('signature_file', signatureFile.value);

        const method = signatureData.value ? 'PUT' : 'POST';
        const response = await fetch('/afs-filing/signature', {
            method,
            body: formData,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
        });

        if (response.status === 422) {
            const payload = (await response.json()) as { errors?: Record<string, string[]>; message?: string };
            signatureErrors.value = payload.errors ?? {};
            signatureErrorMessage.value = payload.message ?? 'Validation failed.';
            return;
        }
        if (!response.ok) throw new Error(`Request failed with status ${response.status}`);

        const payload = (await response.json()) as { signature: SignatureSettings | null };
        signatureData.value = payload.signature;
        signatureFile.value = null;
        showNotice('success', 'Signature updated', 'Default signature settings were saved.');
    } catch (error) {
        signatureErrorMessage.value = error instanceof Error ? error.message : 'Unable to save signature settings.';
    } finally {
        signatureSaving.value = false;
    }
};

const removeSignature = async () => {
    signatureDeleting.value = true;
    signatureErrorMessage.value = null;
    signatureErrors.value = {};
    try {
        await sendDelete('/afs-filing/signature');
        signatureData.value = null;
        signatureFile.value = null;
        showNotice('success', 'Signature removed', 'Default signature settings were removed.');
    } catch (error) {
        signatureErrorMessage.value = error instanceof Error ? error.message : 'Unable to remove signature.';
    } finally {
        signatureDeleting.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(val) => { open = val; }">
        <DialogContent class="max-h-[85vh] overflow-y-auto overflow-x-hidden sm:max-w-4xl">
            <DialogHeader class="space-y-1">
                <DialogTitle>AFS Settings</DialogTitle>
                <DialogDescription>
                    Configure signature setup and the default template for future batches.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4 py-2 lg:grid-cols-[220px_minmax(0,1fr)] lg:items-start">
                <nav class="grid grid-cols-2 gap-2 lg:grid-cols-1" aria-label="AFS settings sections">
                    <Button variant="ghost" :class="['justify-start', activeSection === 'template' ? 'bg-muted font-medium' : '']" @click="activeSection = 'template'">Template</Button>
                    <Button variant="ghost" :class="['justify-start', activeSection === 'signature' ? 'bg-muted font-medium' : '']" @click="activeSection = 'signature'">Signature</Button>
                </nav>

                <div class="min-h-[420px] min-w-0 rounded-lg border p-4">
                    <div v-if="activeSection === 'template'" class="flex min-h-[388px] h-full flex-col gap-4">
                        <div class="space-y-1">
                            <h3 class="text-lg font-semibold">AFS Template</h3>
                            <p class="text-sm text-muted-foreground">This default DOCX template is used for future AFS batches.</p>
                        </div>
                        <div class="flex h-full flex-1 flex-col gap-4">
                            <button type="button" class="w-full rounded-lg border p-4 text-left text-sm transition hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none" @click="openDefaultTemplatePicker" @keydown="onTemplateCardKeydown">
                                <p class="font-medium">Current Default Template</p>
                                <p class="text-muted-foreground">{{ localMapping.default_template?.template_name ?? 'No default template yet' }}</p>
                                <p class="mt-2 text-xs text-muted-foreground">Click to choose a replacement DOCX.</p>
                                <p v-if="defaultTemplateFile" class="mt-1 text-xs font-medium">Selected: {{ defaultTemplateFile.name }}</p>
                            </button>
                            <input id="default-template-file" ref="defaultTemplateInput" type="file" accept=".docx" class="sr-only" @change="onDefaultTemplateFileChange" />
                            <p v-if="defaultTemplateErrors.template_file" class="text-sm text-destructive">{{ defaultTemplateErrors.template_file[0] }}</p>
                            <div class="mt-auto flex justify-end pt-2">
                                <Button :disabled="defaultTemplateSaving || !defaultTemplateFile" @click="updateDefaultTemplate">
                                    <Spinner v-if="defaultTemplateSaving" class="size-4" />
                                    Save Default
                                </Button>
                            </div>
                        </div>
                    </div>

                    <div v-else class="flex min-h-[388px] h-full flex-col gap-4">
                        <div class="space-y-1">
                            <h3 class="text-lg font-semibold">Signature Setup</h3>
                            <p class="text-sm text-muted-foreground">Upload or replace the default signature image used during signing.</p>
                        </div>
                        <div class="flex h-full flex-1 flex-col">
                            <div v-if="props.signatureEnabled" class="grid gap-4 py-2">
                                <button type="button" class="w-full rounded-lg border p-4 text-left text-sm transition hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none" @click="openSignaturePicker" @keydown="onSignatureCardKeydown">
                                    <p class="font-medium">Current Signature Image</p>
                                    <p class="text-muted-foreground">{{ signatureData ? 'Saved signature is available.' : 'No signature uploaded yet.' }}</p>
                                    <p class="mt-2 text-xs text-muted-foreground">Click to choose a replacement signature image.</p>
                                    <p v-if="signatureFile" class="mt-1 text-xs font-medium">Selected: {{ signatureFile.name }}</p>
                                </button>
                                <input id="signature-file" ref="signatureInput" type="file" accept=".png,.jpg,.jpeg,.webp" class="sr-only" @change="onSignatureFileChange" />
                                <p v-if="signatureErrors.signature_file" class="text-sm text-destructive">{{ signatureErrors.signature_file[0] }}</p>

                                <div class="grid gap-2">
                                    <p class="text-sm font-medium">Current Signature Preview</p>
                                    <div class="rounded-md border bg-muted p-3">
                                        <img v-if="signatureData?.getor.preview_url" :src="signatureData.getor.preview_url" alt="Signature preview" class="max-h-24 object-contain" />
                                        <p v-else class="text-sm text-muted-foreground">No signature uploaded yet.</p>
                                    </div>
                                </div>
                                <p v-if="signatureErrorMessage" class="text-sm text-destructive">{{ signatureErrorMessage }}</p>
                            </div>
                            <p v-else class="py-2 text-sm text-muted-foreground">Signature setup is currently disabled.</p>

                            <div v-if="props.signatureEnabled" class="mt-auto flex flex-wrap justify-end gap-2 pt-2">
                                <Button v-if="signatureData" variant="destructive" :disabled="signatureDeleting" @click="removeSignature">
                                    <Spinner v-if="signatureDeleting" class="size-4" />
                                    Remove Signature
                                </Button>
                                <Button :disabled="signatureSaving || !signatureFile" @click="saveSignature">
                                    <Spinner v-if="signatureSaving" class="size-4" />
                                    Save Signature
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
