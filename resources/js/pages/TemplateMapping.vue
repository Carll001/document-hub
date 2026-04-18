<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AfsSignatureSettingsDialog from '@/components/afs-components/AfsSignatureSettingsDialog.vue';
import type { SignatureSettings } from '@/components/afs-components/types';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AppLayout from '@/layouts/AppLayout.vue';
import { createToast, showToast } from '@/lib/toast';
import documentGeneratorRoutes from '@/routes/document-generator';
import type { BreadcrumbItem } from '@/types';

type TemplateEntry = {
    id: number;
    year: number | null;
    template_name: string;
};

type TemplateMappingPayload = {
    default_template: TemplateEntry | null;
    year_templates: TemplateEntry[];
};

const props = defineProps<{
    mapping: TemplateMappingPayload;
    initialSignature: {
        signature: SignatureSettings | null;
    };
    signatureEnabled: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Document Generator',
        href: documentGeneratorRoutes.index(),
    },
    {
        title: 'Settings',
        href: documentGeneratorRoutes.templateMapping(),
    },
];

const mapping = ref<TemplateMappingPayload>(props.mapping);
const signatureDialogOpen = ref(false);

const defaultTemplateFile = ref<File | null>(null);
const defaultTemplateErrors = ref<Record<string, string[]>>({});
const defaultTemplateSaving = ref(false);

const csrfToken = () => {
    const xsrfCookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!xsrfCookie) {
        return '';
    }

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
    Object.assign(validationError, {
        validationErrors: payload.errors ?? {},
    });

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

    if (response.status === 422) {
        throw await parseValidationError(response);
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as TemplateMappingPayload;
};

const onDefaultTemplateFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    defaultTemplateFile.value = input.files?.[0] ?? null;
};

const updateDefaultTemplate = async () => {
    if (! defaultTemplateFile.value) {
        defaultTemplateErrors.value = {
            template_file: ['Template file is required.'],
        };
        return;
    }

    defaultTemplateSaving.value = true;
    defaultTemplateErrors.value = {};

    try {
        const formData = new FormData();
        formData.append('template_file', defaultTemplateFile.value);
        mapping.value = await sendForm('/document-generator/templates/default', formData);
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
</script>

<template>
    <Head title="AFS Settings" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <AfsSignatureSettingsDialog
            v-if="props.signatureEnabled"
            v-model:open="signatureDialogOpen"
            :initial-signature="props.initialSignature.signature"
        />

        <div class="space-y-6 p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Settings</h1>
                    <p class="text-sm text-muted-foreground">
                        Configure signature setup and the default template for future batches.
                    </p>
                </div>

                <Button variant="outline" as-child>
                    <Link :href="documentGeneratorRoutes.index()">Back to Document Generator</Link>
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Signature Setup</CardTitle>
                    <CardDescription>
                        Manage the default signature image used during signing.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button
                        v-if="props.signatureEnabled"
                        variant="outline"
                        @click="signatureDialogOpen = true"
                    >
                        Open Signature Setup
                    </Button>
                    <p v-else class="text-sm text-muted-foreground">
                        Signature setup is currently disabled.
                    </p>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Default Template</CardTitle>
                    <CardDescription>
                        This default DOCX template is used for future AFS batches.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="rounded-lg border p-4 text-sm">
                        <p class="font-medium">Current Default Template</p>
                        <p class="text-muted-foreground">
                            {{ mapping.default_template?.template_name ?? 'No default template yet' }}
                        </p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                        <div class="grid gap-2">
                            <Label for="default-template-file">Replace default DOCX</Label>
                            <Input id="default-template-file" type="file" accept=".docx" @change="onDefaultTemplateFileChange" />
                            <p v-if="defaultTemplateErrors.template_file" class="text-sm text-destructive">
                                {{ defaultTemplateErrors.template_file[0] }}
                            </p>
                        </div>

                        <Button :disabled="defaultTemplateSaving" @click="updateDefaultTemplate">
                            <Spinner v-if="defaultTemplateSaving" class="size-4" />
                            Save Default
                        </Button>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
