<script setup lang="ts">
import { Clipboard, FileText, LoaderCircle } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import type {
    BatchMergedOutput,
    ConfirmationTemplateState,
} from '@/components/doc-merge-batch-components/types';
import {
    confirmationPlaceholderLabel,
    confirmationPlaceholderToken,
    formatFileSize,
} from '@/components/doc-merge-batch-components/utils';

const props = defineProps<{
    canSubmit: boolean;
    fieldError: string | null;
    mergedPdf: BatchMergedOutput | null;
    open: boolean;
    placeholderErrors: Record<string, string | undefined>;
    placeholders: Record<string, string>;
    processing: boolean;
    template: ConfirmationTemplateState;
}>();

const emit = defineEmits<{
    pasteFromEmail: [];
    submit: [];
    'update:open': [value: boolean];
    'update:placeholder': [payload: { placeholder: string; value: string }];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader class="space-y-3">
                <DialogTitle>
                    {{ props.mergedPdf?.hasReceipt ? 'Replace receipt' : 'Add receipt' }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="props.mergedPdf">
                        Fill the receipt template placeholders for
                        <span class="font-medium text-foreground">
                            {{ props.mergedPdf.fileName }}
                        </span>
                        and append the generated PDF as the final pages of the
                        saved merged PDF.
                    </template>
                    <template v-else>
                        Generate a receipt PDF from the shared template and
                        append it to the selected merged PDF.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-6" @submit.prevent="emit('submit')">
                <div
                    v-if="props.mergedPdf?.hasReceipt"
                    class="rounded-2xl border bg-muted/30 p-4 text-sm"
                >
                    <p class="font-medium text-foreground">Current receipt</p>
                    <div class="mt-2 flex flex-wrap items-center gap-2 text-muted-foreground">
                        <span>{{ props.mergedPdf.receiptFileName }}</span>
                        <span>
                            {{ formatFileSize(props.mergedPdf.receiptFileSize) }}
                        </span>
                    </div>
                </div>

                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-medium text-foreground">
                                Placeholder values
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    props.template.placeholders.length === 0
                                        ? 'This template has no detected placeholders.'
                                        : `Provide values for ${props.template.placeholders.length} placeholder${
                                              props.template.placeholders.length ===
                                              1
                                                  ? ''
                                                  : 's'
                                          }.`
                                }}
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <Button
                                v-if="props.template.placeholders.length > 0"
                                type="button"
                                variant="outline"
                                size="sm"
                                class="gap-2"
                                @click="emit('pasteFromEmail')"
                            >
                                <Clipboard class="size-4" />
                                Paste from email
                            </Button>
                            <Badge variant="outline">
                                {{ props.template.placeholders.length }} fields
                            </Badge>
                        </div>
                    </div>

                    <div
                        v-if="props.template.placeholders.length > 0"
                        class="grid gap-4 sm:grid-cols-2"
                    >
                        <div
                            v-for="placeholder in props.template.placeholders"
                            :key="placeholder"
                            class="space-y-2"
                        >
                            <Label :for="`receipt-placeholder-${placeholder}`">
                                {{ confirmationPlaceholderLabel(placeholder) }}
                                <span class="text-muted-foreground">
                                    ({{ confirmationPlaceholderToken(placeholder) }})
                                </span>
                            </Label>
                            <Input
                                :id="`receipt-placeholder-${placeholder}`"
                                :model-value="props.placeholders[placeholder] ?? ''"
                                type="text"
                                :placeholder="
                                    confirmationPlaceholderLabel(placeholder)
                                "
                                @update:model-value="
                                    emit('update:placeholder', {
                                        placeholder,
                                        value: String($event),
                                    })
                                "
                            />
                            <InputError
                                :message="props.placeholderErrors[placeholder]"
                            />
                        </div>
                    </div>

                    <div
                        v-else
                        class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground"
                    >
                        This template has no placeholders, so it will be
                        converted to PDF and appended as-is.
                    </div>

                    <InputError :message="props.fieldError ?? undefined" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="props.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>

                    <Button
                        type="submit"
                        class="gap-2"
                        :disabled="!props.canSubmit"
                    >
                        <LoaderCircle
                            v-if="props.processing"
                            class="size-4 animate-spin"
                        />
                        <FileText v-else class="size-4" />
                        {{
                            props.processing
                                ? 'Generating receipt...'
                                : props.mergedPdf?.hasReceipt
                                  ? 'Replace receipt'
                                  : 'Add receipt'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
