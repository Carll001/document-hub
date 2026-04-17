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

type TemplateMappingPayload = {
    default_template: TemplateEntry | null;
    year_templates: TemplateEntry[];
};

type EditableYearTemplate = {
    id: number;
    year: string | number;
    template_name: string;
    file: File | null;
    saving: boolean;
    deleting: boolean;
    errors: Record<string, string[]>;
};

const props = defineProps<{
    mapping: TemplateMappingPayload;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Document Generator',
        href: documentGeneratorRoutes.index(),
    },
    {
        title: 'Template Mapping',
        href: '/document-generator/template-mapping',
    },
];

const mapping = ref<TemplateMappingPayload>(props.mapping);

const defaultTemplateFile = ref<File | null>(null);
const defaultTemplateErrors = ref<Record<string, string[]>>({});
const defaultTemplateSaving = ref(false);

const newTemplate = reactive<{
    year: string | number;
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
    props.mapping.year_templates.map((template) => ({
        id: template.id,
        year: String(template.year ?? ''),
        template_name: template.template_name,
        file: null,
        saving: false,
        deleting: false,
        errors: {},
    })),
);

const normalizedYearValue = (value: string | number) => String(value).trim();

const sortedTemplates = computed(() =>
    [...yearTemplates.value].sort((left, right) => Number(normalizedYearValue(left.year)) - Number(normalizedYearValue(right.year))),
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

const applyMapping = (payload: TemplateMappingPayload) => {
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

const sendDelete = async (url: string): Promise<TemplateMappingPayload> => {
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

    return (await response.json()) as TemplateMappingPayload;
};

const defaultRangeText = computed(() => {
    const firstYear = sortedTemplates.value[0];

    if (!firstYear) {
        return 'Default template applies to all years.';
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
        return `Applies to ${normalizedYearValue(current.year)} and above.`;
    }

    return `Applies from ${normalizedYearValue(current.year)} up to ${Number(normalizedYearValue(next.year)) - 1}.`;
};

const hasDuplicateYear = (templateId: number, year: string) =>
    yearTemplates.value.some(
        (template) =>
            template.id !== templateId
            && normalizedYearValue(template.year) !== ''
            && normalizedYearValue(template.year) === normalizedYearValue(year),
    );

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
        applyMapping(await sendForm('/document-generator/templates/default', formData));
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

const createYearTemplate = async () => {
    newTemplate.errors = {};
    const normalizedYear = normalizedYearValue(newTemplate.year);

    if (normalizedYear === '' || !newTemplate.file) {
        newTemplate.errors = {
            ...(normalizedYear === '' ? { year: ['Year is required.'] } : {}),
            ...(!newTemplate.file ? { template_file: ['Template file is required.'] } : {}),
        };
        return;
    }

    if (hasDuplicateYear(0, normalizedYear)) {
        newTemplate.errors = {
            year: ['Year template entries must use unique years.'],
        };
        return;
    }

    newTemplate.saving = true;

    try {
        const formData = new FormData();
        formData.append('year', normalizedYear);
        formData.append('template_file', newTemplate.file);
        applyMapping(await sendForm('/document-generator/templates', formData));
        showNotice('success', 'Year template added', 'Future batches will use the new year rule.');
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            newTemplate.errors = (error as Error & { validationErrors?: Record<string, string[]> }).validationErrors ?? {};
        }
        showNotice('error', 'Year template was not added', error instanceof Error ? error.message : 'Unable to add the year template.');
    } finally {
        newTemplate.saving = false;
    }
};

const updateYearTemplate = async (template: EditableYearTemplate) => {
    template.errors = {};
    const normalizedYear = normalizedYearValue(template.year);

    if (normalizedYear === '') {
        template.errors = { year: ['Year is required.'] };
        return;
    }

    if (hasDuplicateYear(template.id, normalizedYear)) {
        template.errors = { year: ['Year template entries must use unique years.'] };
        return;
    }

    template.saving = true;

    try {
        const formData = new FormData();
        formData.append('year', normalizedYear);
        if (template.file) {
            formData.append('template_file', template.file);
        }

        applyMapping(await sendForm(`/document-generator/templates/${template.id}/update`, formData));
        showNotice('success', 'Year template updated', 'The template mapping has been updated.');
    } catch (error) {
        if (error instanceof Error && 'validationErrors' in error) {
            template.errors = (error as Error & { validationErrors?: Record<string, string[]> }).validationErrors ?? {};
        }
        showNotice('error', 'Year template was not updated', error instanceof Error ? error.message : 'Unable to update the year template.');
    } finally {
        template.saving = false;
    }
};

const removeYearTemplate = async (template: EditableYearTemplate) => {
    template.deleting = true;

    try {
        applyMapping(await sendDelete(`/document-generator/templates/${template.id}`));
        showNotice('success', 'Year template removed', 'The year rule has been removed.');
    } catch (error) {
        showNotice('error', 'Year template was not removed', error instanceof Error ? error.message : 'Unable to remove the year template.');
    } finally {
        template.deleting = false;
    }
};
</script>

<template>
    <Head title="Template Mapping" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <h1 class="text-2xl font-semibold tracking-tight">Template Mapping</h1>
                    <p class="text-sm text-muted-foreground">
                        Set the global default and threshold-based year rules used by future document batches.
                    </p>
                </div>

                <Button variant="outline" as-child>
                    <Link :href="documentGeneratorRoutes.index()">Back to Document Generator</Link>
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Template Table</CardTitle>
                    <CardDescription>
                        This is the mapping the generator uses for new batches.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-3">
                    <div class="rounded-lg border p-4">
                        <div class="grid grid-cols-[140px_minmax(0,1fr)] gap-3 text-sm">
                            <p class="font-medium">Default</p>
                            <p>{{ mapping.default_template?.template_name ?? 'No default template yet' }}</p>
                        </div>
                    </div>

                    <div
                        v-for="template in sortedTemplates"
                        :key="template.id"
                        class="rounded-lg border p-4"
                    >
                        <div class="grid grid-cols-[140px_minmax(0,1fr)] gap-3 text-sm">
                            <p class="font-medium">{{ template.year }}</p>
                            <div>
                                <p>{{ template.template_name }}</p>
                                <p class="text-xs text-muted-foreground">{{ rangeText(template.id) }}</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="sortedTemplates.length === 0" class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        No year templates configured yet.
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Default Template</CardTitle>
                    <CardDescription>{{ defaultRangeText }}</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-4 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
                    <div class="grid gap-2">
                        <Label for="default-template-file">Replace default DOCX</Label>
                        <Input id="default-template-file" type="file" accept=".docx" @change="onDefaultTemplateFileChange" />
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
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Year Templates</CardTitle>
                    <CardDescription>Add and edit the global year rules.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <div class="grid gap-4 rounded-lg border p-4 lg:grid-cols-[160px_minmax(0,1fr)_auto] lg:items-end">
                        <div class="grid gap-2">
                            <Label for="new-template-year">Year</Label>
                            <Input id="new-template-year" v-model="newTemplate.year" type="number" min="1900" max="9999" placeholder="2025" />
                            <p v-if="newTemplate.errors.year" class="text-sm text-destructive">{{ newTemplate.errors.year[0] }}</p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="new-template-file">DOCX Template</Label>
                            <Input id="new-template-file" type="file" accept=".docx" @change="onNewTemplateFileChange" />
                            <p v-if="newTemplate.errors.template_file" class="text-sm text-destructive">{{ newTemplate.errors.template_file[0] }}</p>
                        </div>

                        <Button :disabled="newTemplate.saving" @click="createYearTemplate">
                            <Spinner v-if="newTemplate.saving" class="size-4" />
                            Add Year Rule
                        </Button>
                    </div>

                    <div v-for="template in sortedTemplates" :key="`editor-${template.id}`" class="space-y-3 rounded-lg border p-4">
                        <div>
                            <p class="font-medium">{{ template.template_name }}</p>
                            <p class="text-sm text-muted-foreground">{{ rangeText(template.id) }}</p>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-[160px_minmax(0,1fr)_auto_auto] lg:items-end">
                            <div class="grid gap-2">
                                <Label :for="`template-year-${template.id}`">Year</Label>
                                <Input :id="`template-year-${template.id}`" v-model="template.year" type="number" min="1900" max="9999" />
                                <p v-if="template.errors.year" class="text-sm text-destructive">{{ template.errors.year[0] }}</p>
                            </div>

                            <div class="grid gap-2">
                                <Label :for="`template-file-${template.id}`">Replace DOCX</Label>
                                <Input :id="`template-file-${template.id}`" type="file" accept=".docx" @change="onExistingTemplateFileChange(template, $event)" />
                                <p class="text-xs text-muted-foreground">Leave empty if you only want to change the year.</p>
                                <p v-if="template.errors.template_file" class="text-sm text-destructive">{{ template.errors.template_file[0] }}</p>
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
