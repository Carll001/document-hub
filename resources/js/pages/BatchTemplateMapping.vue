<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
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

type BatchTemplateMapping = {
    id: number;
    source_excel_name: string;
    template_name: string;
    status: string;
    total_items: number;
    processed_items: number;
    success_items: number;
    failed_items: number;
    created_at: string | null;
    completed_at: string | null;
    default_template: TemplateEntry | null;
    year_templates: TemplateEntry[];
};

type EditableYearTemplate = {
    id: number;
    year: string;
    template_name: string;
    file: File | null;
    saving: boolean;
    deleting: boolean;
    errors: Record<string, string[]>;
};

const props = defineProps<{
    batch: BatchTemplateMapping;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Document Generator',
        href: documentGeneratorRoutes.index(),
    },
    {
        title: `Batch #${props.batch.id}`,
        href: documentGeneratorRoutes.index(),
    },
];

const mapping = ref<BatchTemplateMapping>(props.batch);

const defaultTemplateFile = ref<File | null>(null);
const defaultTemplateErrors = ref<Record<string, string[]>>({});
const defaultTemplateSaving = ref(false);

const newTemplate = reactive<{
    year: string;
    file: File | null;
    errors: Record<string, string[]>;
    saving: boolean;
}>({
    year: '',
    file: null,
    errors: {},
    saving: false,
});

const yearTemplates = ref<EditableYearTemplate[]>(
    props.batch.year_templates.map((template) => ({
        id: template.id,
        year: String(template.year ?? ''),
        template_name: template.template_name,
        file: null,
        saving: false,
        deleting: false,
        errors: {},
    })),
);

const sortedTemplates = computed(() =>
    [...yearTemplates.value].sort((left, right) => Number(left.year) - Number(right.year)),
);

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

const applyMapping = (payload: BatchTemplateMapping) => {
    mapping.value = payload;
    yearTemplates.value = payload.year_templates.map((template) => ({
        id: template.id,
        year: String(template.year ?? ''),
        template_name: template.template_name,
        file: null,
        saving: false,
        deleting: false,
        errors: {},
    }));
    defaultTemplateFile.value = null;
    defaultTemplateErrors.value = {};
    newTemplate.year = '';
    newTemplate.file = null;
    newTemplate.errors = {};
};

const parseErrorResponse = async (response: Response) => {
    const payload = (await response.json()) as {
        message?: string;
        errors?: Record<string, string[]>;
    };

    return {
        message: payload.message ?? 'Validation failed.',
        errors: payload.errors ?? {},
    };
};

const sendForm = async (
    url: string,
    formData: FormData,
): Promise<BatchTemplateMapping> => {
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
        const errorPayload = await parseErrorResponse(response);
        const validationError = new Error(errorPayload.message);
        Object.assign(validationError, { validationErrors: errorPayload.errors });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as BatchTemplateMapping;
};

const sendDelete = async (url: string): Promise<BatchTemplateMapping> => {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as BatchTemplateMapping;
};

const defaultRangeText = computed(() => {
    const firstYear = sortedTemplates.value[0];

    if (!firstYear) {
        return 'Default template applies to all years when no year-based rule is configured.';
    }

    return `Default template applies to years before ${firstYear.year}.`;
});

const rangeText = (templateId: number) => {
    const templates = sortedTemplates.value;
    const index = templates.findIndex((template) => template.id === templateId);
    const current = templates[index];
    const next = templates[index + 1];

    if (!current) {
        return '';
    }

    if (!next) {
        return `Applies to ${current.year} and above.`;
    }

    return `Applies from ${current.year} up to ${Number(next.year) - 1}.`;
};

const hasDuplicateYear = (templateId: number, year: string) => {
    const normalizedYear = year.trim();
    if (normalizedYear === '') {
        return false;
    }

    return yearTemplates.value.some(
        (template) => template.id !== templateId && template.year.trim() === normalizedYear,
    );
};

const updateDefaultTemplate = async () => {
    if (!defaultTemplateFile.value) {
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

        const payload = await sendForm(
            `/document-generator/batches/${mapping.value.id}/templates/default`,
            formData,
        );

        applyMapping(payload);
        showNotice('success', 'Default template updated', 'The batch now uses the new default DOCX template.');
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            defaultTemplateErrors.value =
                (
                    error as Error & {
                        validationErrors?: Record<string, string[]>;
                    }
                ).validationErrors ?? {};
        }

        showNotice(
            'error',
            'Default template was not updated',
            error instanceof Error ? error.message : 'Unable to update the default template.',
        );
    } finally {
        defaultTemplateSaving.value = false;
    }
};

const createYearTemplate = async () => {
    newTemplate.errors = {};

    if (newTemplate.year.trim() === '' || !newTemplate.file) {
        newTemplate.errors = {
            ...(newTemplate.year.trim() === '' ? { year: ['Year is required.'] } : {}),
            ...(!newTemplate.file ? { template_file: ['Template file is required.'] } : {}),
        };
        return;
    }

    if (hasDuplicateYear(0, newTemplate.year)) {
        newTemplate.errors = {
            year: ['Year template entries must use unique years.'],
        };
        return;
    }

    newTemplate.saving = true;

    try {
        const formData = new FormData();
        formData.append('year', newTemplate.year.trim());
        formData.append('template_file', newTemplate.file);

        const payload = await sendForm(
            `/document-generator/batches/${mapping.value.id}/templates`,
            formData,
        );

        applyMapping(payload);
        showNotice('success', 'Year template added', 'The new year rule has been saved.');
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            newTemplate.errors =
                (
                    error as Error & {
                        validationErrors?: Record<string, string[]>;
                    }
                ).validationErrors ?? {};
        }

        showNotice(
            'error',
            'Year template was not added',
            error instanceof Error ? error.message : 'Unable to add the year template.',
        );
    } finally {
        newTemplate.saving = false;
    }
};

const updateYearTemplate = async (template: EditableYearTemplate) => {
    template.errors = {};

    if (template.year.trim() === '') {
        template.errors = {
            year: ['Year is required.'],
        };
        return;
    }

    if (hasDuplicateYear(template.id, template.year)) {
        template.errors = {
            year: ['Year template entries must use unique years.'],
        };
        return;
    }

    template.saving = true;

    try {
        const formData = new FormData();
        formData.append('year', template.year.trim());

        if (template.file) {
            formData.append('template_file', template.file);
        }

        const payload = await sendForm(
            `/document-generator/batches/${mapping.value.id}/templates/${template.id}/update`,
            formData,
        );

        applyMapping(payload);
        showNotice('success', 'Year template updated', 'The selected year rule has been updated.');
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            template.errors =
                (
                    error as Error & {
                        validationErrors?: Record<string, string[]>;
                    }
                ).validationErrors ?? {};
        }

        showNotice(
            'error',
            'Year template was not updated',
            error instanceof Error ? error.message : 'Unable to update the year template.',
        );
    } finally {
        template.saving = false;
    }
};

const removeYearTemplate = async (template: EditableYearTemplate) => {
    template.deleting = true;

    try {
        const payload = await sendDelete(
            `/document-generator/batches/${mapping.value.id}/templates/${template.id}`,
        );

        applyMapping(payload);
        showNotice('success', 'Year template removed', 'The selected year rule has been removed.');
    } catch (error) {
        showNotice(
            'error',
            'Year template was not removed',
            error instanceof Error ? error.message : 'Unable to remove the year template.',
        );
    } finally {
        template.deleting = false;
    }
};

const onDefaultTemplateFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    defaultTemplateFile.value = input.files?.[0] ?? null;
};

const onNewTemplateFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    newTemplate.file = input.files?.[0] ?? null;
};

const onExistingTemplateFileChange = (template: EditableYearTemplate, event: Event) => {
    const input = event.target as HTMLInputElement;
    template.file = input.files?.[0] ?? null;
};
</script>

<template>
    <Head :title="`Batch #${mapping.id} Template Mapping`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Template Mapping</h1>
                    <p class="text-sm text-muted-foreground">
                        Review and edit the year-based DOCX rules for Batch #{{ mapping.id }}.
                    </p>
                </div>

                <Button variant="outline" as-child>
                    <Link :href="documentGeneratorRoutes.index()">Back to Document Generator</Link>
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Batch Summary</CardTitle>
                    <CardDescription>
                        {{ mapping.source_excel_name }} currently defaults to
                        {{ mapping.default_template?.template_name ?? mapping.template_name }}.
                    </CardDescription>
                </CardHeader>
                <CardContent class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-4">
                    <div class="rounded-lg border p-4">
                        <p class="text-muted-foreground">Status</p>
                        <p class="mt-1 font-medium">{{ mapping.status }}</p>
                    </div>
                    <div class="rounded-lg border p-4">
                        <p class="text-muted-foreground">Rows</p>
                        <p class="mt-1 font-medium">{{ mapping.processed_items }}/{{ mapping.total_items }}</p>
                    </div>
                    <div class="rounded-lg border p-4">
                        <p class="text-muted-foreground">Successful</p>
                        <p class="mt-1 font-medium">{{ mapping.success_items }}</p>
                    </div>
                    <div class="rounded-lg border p-4">
                        <p class="text-muted-foreground">Failed</p>
                        <p class="mt-1 font-medium">{{ mapping.failed_items }}</p>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Default Template</CardTitle>
                    <CardDescription>{{ defaultRangeText }}</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="rounded-lg border p-4">
                        <p class="text-sm text-muted-foreground">Current default</p>
                        <p class="mt-1 font-medium">
                            {{ mapping.default_template?.template_name ?? 'No default template found' }}
                        </p>
                    </div>

                    <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                        <div class="grid gap-2">
                            <Label for="default-template-file">Replace default DOCX</Label>
                            <Input
                                id="default-template-file"
                                type="file"
                                accept=".docx"
                                @change="onDefaultTemplateFileChange"
                            />
                                <p class="text-xs text-muted-foreground">
                                    In the 2025 template, placeholders like
                                    <code>{NET INCOME}</code> treat the current file value as 2025 and add the matched
                                    old-file base value, and
                                    subtraction stays explicit, such as
                                    <code>{TRADE RECEIVABLES 2025-TRADE RECEIVABLES}</code>.
                                </p>
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

            <Card>
                <CardHeader>
                    <CardTitle>Year Template Rules</CardTitle>
                    <CardDescription>
                        Each configured year works as a threshold and applies until the next higher year rule takes over.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-4 rounded-lg border p-4 lg:grid-cols-[160px_minmax(0,1fr)_auto] lg:items-end">
                        <div class="grid gap-2">
                            <Label for="new-template-year">Year</Label>
                            <Input id="new-template-year" v-model="newTemplate.year" type="number" min="1900" max="9999" placeholder="2025" />
                            <p v-if="newTemplate.errors.year" class="text-sm text-destructive">
                                {{ newTemplate.errors.year[0] }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="new-template-file">DOCX Template</Label>
                            <Input
                                id="new-template-file"
                                type="file"
                                accept=".docx"
                                @change="onNewTemplateFileChange"
                            />
                            <p v-if="newTemplate.errors.template_file" class="text-sm text-destructive">
                                {{ newTemplate.errors.template_file[0] }}
                            </p>
                        </div>

                        <Button :disabled="newTemplate.saving" @click="createYearTemplate">
                            <Spinner v-if="newTemplate.saving" class="size-4" />
                            Add Year Rule
                        </Button>
                    </div>

                    <div v-if="sortedTemplates.length === 0" class="rounded-lg border border-dashed p-6 text-sm text-muted-foreground">
                        No year-based templates yet. The default template will be used for every year.
                    </div>

                    <div v-for="template in sortedTemplates" :key="template.id" class="space-y-3 rounded-lg border p-4">
                        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p class="font-medium">{{ template.template_name }}</p>
                                <p class="text-sm text-muted-foreground">{{ rangeText(template.id) }}</p>
                            </div>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-[160px_minmax(0,1fr)_auto_auto] lg:items-end">
                            <div class="grid gap-2">
                                <Label :for="`template-year-${template.id}`">Year</Label>
                                <Input :id="`template-year-${template.id}`" v-model="template.year" type="number" min="1900" max="9999" />
                                <p v-if="template.errors.year" class="text-sm text-destructive">
                                    {{ template.errors.year[0] }}
                                </p>
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`template-file-${template.id}`">Replace DOCX</Label>
                                <Input
                                    :id="`template-file-${template.id}`"
                                    type="file"
                                    accept=".docx"
                                    @change="onExistingTemplateFileChange(template, $event)"
                                />
                                <p class="text-xs text-muted-foreground">
                                    Leave this empty if you only want to change the year.
                                </p>
                                <p v-if="template.errors.template_file" class="text-sm text-destructive">
                                    {{ template.errors.template_file[0] }}
                                </p>
                            </div>

                            <Button :disabled="template.saving" @click="updateYearTemplate(template)">
                                <Spinner v-if="template.saving" class="size-4" />
                                Save
                            </Button>

                            <Button variant="outline" :disabled="template.deleting" @click="removeYearTemplate(template)">
                                <Spinner v-if="template.deleting" class="size-4" />
                                Remove
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
