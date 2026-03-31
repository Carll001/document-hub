<script setup lang="ts">
import { LoaderCircle, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { formatFileSize } from '@/components/doc-merge-batch-components/utils';

const props = defineProps<{
    canSubmit: boolean;
    fieldError: string | null | undefined;
    open: boolean;
    outputPrefix: string;
    outputPrefixError: string | null | undefined;
    outputPreview: string;
    processing: boolean;
    progressPercentage: number | null;
    zipFile: File | null;
}>();

const emit = defineEmits<{
    selectFile: [file: File | null];
    submit: [];
    'update:open': [value: boolean];
    'update:outputPrefix': [value: string];
}>();

const bulkZipInput = ref<HTMLInputElement | null>(null);

function openBulkZipPicker(): void {
    bulkZipInput.value?.click();
}

function handleBulkZipSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const [file] = Array.from(input.files ?? []);

    emit('selectFile', file ?? null);
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader class="space-y-3">
                <DialogTitle>Bulk merge ZIP</DialogTitle>
                <DialogDescription>
                    Upload one ZIP with page folders like PAGE 1 and PAGE 2 at
                    the ZIP root, or inside one wrapper folder. Matching PDFs
                    will be queued for this batch and the table will refresh
                    automatically when processing finishes.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-6" @submit.prevent="emit('submit')">
                <input
                    ref="bulkZipInput"
                    type="file"
                    accept=".zip,application/zip,application/x-zip-compressed"
                    class="hidden"
                    @change="handleBulkZipSelection"
                />

                <div class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground">
                    <p class="font-medium text-foreground">ZIP rules</p>
                    <p class="mt-2">
                        PAGE 1, PAGE 2, PAGE 10, and the other page folders can
                        sit directly in the ZIP, or inside one extra wrapper
                        folder.
                    </p>
                    <p class="mt-2">
                        Each page folder must contain only direct PDFs, and
                        matching uses the PDF name without the ending page
                        number.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="bulkZipOutputPrefix">
                        Output prefix
                        <span class="text-muted-foreground">(optional)</span>
                    </Label>
                    <Input
                        id="bulkZipOutputPrefix"
                        :model-value="props.outputPrefix"
                        type="text"
                        placeholder="ClientA_"
                        @update:model-value="
                            emit('update:outputPrefix', String($event))
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Each output becomes
                        <span class="font-medium text-foreground">
                            {{ props.outputPreview }}
                        </span>
                        .
                    </p>
                    <InputError :message="props.outputPrefixError ?? undefined" />
                </div>

                <div class="rounded-2xl border bg-background p-4 text-sm">
                    <p class="font-medium text-foreground">Output preview</p>
                    <p class="mt-2 text-muted-foreground">
                        Each output becomes
                        <span class="font-medium text-foreground">
                            {{ props.outputPreview }}
                        </span>
                        .
                    </p>
                </div>

                <div class="space-y-3">
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="gap-2"
                            :disabled="props.processing"
                            @click="openBulkZipPicker"
                        >
                            <Upload class="size-4" />
                            Choose ZIP
                        </Button>
                        <span
                            v-if="props.zipFile"
                            class="self-center text-sm text-muted-foreground"
                        >
                            <span class="font-medium text-foreground">
                                {{ props.zipFile.name }}
                            </span>
                            ({{ formatFileSize(props.zipFile.size) }})
                        </span>
                    </div>
                    <InputError :message="props.fieldError ?? undefined" />
                    <p v-if="props.progressPercentage !== null" class="text-xs text-muted-foreground">
                        Uploading {{ props.progressPercentage }}%
                    </p>
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
                        <Upload v-else class="size-4" />
                        {{ props.processing ? 'Queueing batch run...' : 'Queue batch run' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
