<script setup lang="ts">
import { Download, LoaderCircle, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import type { ConfirmationTemplateState } from '@/components/doc-merge-components/types';
import {
    confirmationPlaceholderToken,
    formatFileSize,
} from '@/components/doc-merge-components/utils';

const props = defineProps<{
    canSubmit: boolean;
    fieldError: string | null | undefined;
    open: boolean;
    processing: boolean;
    selectedTemplate: File | null;
    template: ConfirmationTemplateState;
}>();

const emit = defineEmits<{
    selectFile: [file: File | null];
    submit: [];
    'update:open': [value: boolean];
}>();

const confirmationTemplateInput = ref<HTMLInputElement | null>(null);

function handleConfirmationTemplateSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const [file] = Array.from(input.files ?? []);

    emit('selectFile', file ?? null);
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader class="space-y-1">
                <DialogTitle>Shared receipt template</DialogTitle>
                <DialogDescription>
                    Upload one DOCX template for everyone, then generate
                    appended receipt pages by replacing placeholders like
                    <span class="font-medium text-foreground">
                        {client_name}
                    </span>
                    and
                    <span class="font-medium text-foreground">
                        {tin_number}
                    </span>
                    for any saved merged PDF.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-6">
                <form
                    class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto]"
                    @submit.prevent="emit('submit')"
                >
                    <div class="space-y-2">
                        <Label for="confirmationTemplateFile">
                            Template file
                            <span class="text-muted-foreground">(DOCX)</span>
                        </Label>
                        <input
                            id="confirmationTemplateFile"
                            ref="confirmationTemplateInput"
                            type="file"
                            accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                            class="block w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-2 file:text-sm file:font-medium file:text-secondary-foreground hover:file:bg-secondary/80 focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50"
                            @change="handleConfirmationTemplateSelection"
                        />
                        <p class="text-xs text-muted-foreground">
                            Use only plain typed placeholders in normal DOCX
                            text, like
                            <span class="font-medium text-foreground">
                                {customer_name}
                            </span>
                            . Word content controls, text boxes, and other
                            special placeholder widgets are not supported.
                        </p>
                        <p v-if="props.selectedTemplate" class="text-sm text-muted-foreground">
                            Selected:
                            <span class="font-medium text-foreground">
                                {{ props.selectedTemplate.name }}
                            </span>
                            ({{ formatFileSize(props.selectedTemplate.size) }})
                        </p>
                        <InputError :message="props.fieldError ?? undefined" />
                    </div>

                    <div class="flex items-end justify-start lg:justify-end">
                        <Button
                            type="submit"
                            class="gap-2"
                            :disabled="!props.canSubmit"
                        >
                            <LoaderCircle
                                v-if="props.processing"
                                class="size-4 animate-spin"
                            />
                            <Upload v-else class="size-4" />
                            {{
                                props.processing
                                    ? 'Saving template...'
                                    : props.template.hasTemplate
                                      ? 'Replace shared template'
                                      : 'Save shared template'
                            }}
                        </Button>
                    </div>
                </form>

                <div
                    class="rounded-2xl border bg-muted/20 p-4"
                    :class="props.template.hasTemplate ? 'border-border' : 'border-dashed'"
                >
                    <template v-if="props.template.hasTemplate">
                        <div
                            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
                        >
                            <div class="space-y-2">
                                <p class="font-medium text-foreground">
                                    Shared template
                                </p>
                                <div class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                    <span class="font-medium text-foreground">
                                        {{ props.template.fileName }}
                                    </span>
                                    <span>
                                        {{ formatFileSize(props.template.fileSize) }}
                                    </span>
                                    <span>
                                        {{ props.template.placeholders.length }}
                                        placeholder{{
                                            props.template.placeholders.length === 1
                                                ? ''
                                                : 's'
                                        }}
                                    </span>
                                </div>
                            </div>

                            <Button
                                v-if="props.template.downloadUrl"
                                as-child
                                type="button"
                                variant="outline"
                                class="gap-2 self-start"
                            >
                                <a :href="props.template.downloadUrl">
                                    <Download class="size-4" />
                                    Download shared template
                                </a>
                            </Button>
                        </div>

                        <div class="mt-4 space-y-2">
                            <p class="text-sm font-medium text-foreground">
                                Detected placeholders
                            </p>
                            <div
                                v-if="props.template.placeholders.length > 0"
                                class="flex flex-wrap gap-2"
                            >
                                <Badge
                                    v-for="placeholder in props.template.placeholders"
                                    :key="placeholder"
                                    variant="secondary"
                                >
                                    {{ confirmationPlaceholderToken(placeholder) }}
                                </Badge>
                            </div>
                            <p v-else class="text-sm text-muted-foreground">
                                No placeholders were detected in this template
                                yet.
                            </p>
                        </div>
                    </template>

                    <template v-else>
                        <p class="font-medium text-foreground">
                            No shared template uploaded yet
                        </p>
                        <p class="mt-2 text-sm text-muted-foreground">
                            Upload a DOCX file first, then anyone can generate
                            and append receipt pages from that shared template.
                        </p>
                    </template>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
