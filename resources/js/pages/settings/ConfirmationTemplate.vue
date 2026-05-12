<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ExternalLink, FileText, Upload } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    flash: {
        success: string | null;
        error: string | null;
    };
    receiptTemplate: {
        alignmentUrl: string;
        updateUrl: string;
        activePdfUrl: string;
        activePdfPath: string;
        fallbackPdfPath: string;
        usesFpdiTemplate: boolean;
        schemaPath: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Confirmation receipt',
        href: '/settings/confirmation-template',
    },
];

const fileInput = ref<HTMLInputElement | null>(null);
const form = useForm<{
    receipt_template: File | null;
}>({
    receipt_template: null,
});
const selectedFileName = computed(
    () => form.receipt_template?.name ?? 'No PDF selected',
);
const canSubmit = computed(
    () => form.receipt_template instanceof File && !form.processing,
);

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            toast.success(success);
        }

        if (error) {
            toast.error(error);
        }
    },
);

function handleFileSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    form.receipt_template = input.files?.item(0) ?? null;
    form.clearErrors('receipt_template');
}

function resetFileInput(): void {
    form.reset();
    form.clearErrors();

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function submitReceiptTemplate(): void {
    if (!(form.receipt_template instanceof File)) {
        return;
    }

    form.post(props.receiptTemplate.updateUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: resetFileInput,
    });
}
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Confirmation receipt settings" />

        <SettingsLayout>
            <div class="space-y-6">
                <Heading
                    variant="small"
                    title="Confirmation receipt"
                    description="Manage the PDF-based 1702-EX confirmation receipt template"
                />

                <div class="space-y-4 rounded-xl border bg-muted/30 p-4">
                    <div class="flex items-start gap-3">
                        <FileText class="mt-0.5 size-5 text-primary" />
                        <div class="space-y-1">
                            <p class="text-sm font-medium text-foreground">
                                Active PDF receipt template
                            </p>
                            <p class="text-sm text-muted-foreground">
                                The 1702-EX confirmation receipt uses a PDF asset
                                and field alignment schema. When the FPDI PDF is
                                present, that file is used for receipt generation.
                            </p>
                        </div>
                    </div>

                    <dl class="space-y-2 text-sm">
                        <div>
                            <dt class="font-medium text-foreground">
                                Active PDF asset
                            </dt>
                            <dd class="text-muted-foreground">
                                {{ props.receiptTemplate.activePdfPath }}
                            </dd>
                        </div>
                        <div v-if="props.receiptTemplate.usesFpdiTemplate">
                            <dt class="font-medium text-foreground">
                                Fallback PDF asset
                            </dt>
                            <dd class="text-muted-foreground">
                                {{ props.receiptTemplate.fallbackPdfPath }}
                            </dd>
                        </div>
                        <div>
                            <dt class="font-medium text-foreground">
                                Field alignment
                            </dt>
                            <dd class="text-muted-foreground">
                                {{ props.receiptTemplate.schemaPath }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button as-child class="gap-2">
                        <a :href="props.receiptTemplate.alignmentUrl">
                            <ExternalLink class="size-4" />
                            Open alignment tool
                        </a>
                    </Button>

                    <Button as-child variant="outline" class="gap-2">
                        <a
                            :href="props.receiptTemplate.activePdfUrl"
                            target="_blank"
                        >
                            <FileText class="size-4" />
                            View PDF
                        </a>
                    </Button>
                </div>

                <form class="space-y-4" @submit.prevent="submitReceiptTemplate">
                    <div class="grid gap-2">
                        <Label for="receipt-template">
                            Replace confirmation receipt PDF
                        </Label>
                        <input
                            id="receipt-template"
                            ref="fileInput"
                            type="file"
                            accept="application/pdf,.pdf"
                            class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm"
                            @change="handleFileSelected"
                        />
                        <p class="text-sm text-muted-foreground">
                            {{ selectedFileName }}
                        </p>
                        <InputError
                            :message="form.errors.receipt_template"
                        />
                    </div>

                    <div class="flex items-center gap-3">
                        <Button
                            type="submit"
                            class="gap-2"
                            :disabled="!canSubmit"
                        >
                            <Upload class="size-4" />
                            Replace PDF
                        </Button>

                        <p
                            v-if="form.recentlySuccessful"
                            class="text-sm text-muted-foreground"
                        >
                            Updated.
                        </p>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
