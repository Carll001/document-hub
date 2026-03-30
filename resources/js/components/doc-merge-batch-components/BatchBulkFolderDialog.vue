<script setup lang="ts">
import { LoaderCircle, Trash2, Upload } from 'lucide-vue-next';
import { ref } from 'vue';
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
import type { PageFolderDisplayItem } from '@/components/doc-merge-batch-components/types';

const props = defineProps<{
    canSubmit: boolean;
    fieldError: string | null | undefined;
    inlineError: string | null | undefined;
    open: boolean;
    outputPrefix: string;
    outputPrefixError: string | null | undefined;
    outputPreview: string;
    pageFolders: PageFolderDisplayItem[];
    processing: boolean;
    progressPercentage: number | null;
    validSelectedPageFolderCount: number;
}>();

const emit = defineEmits<{
    removePageFolder: [pageFolderKey: string];
    selectContainerFolder: [files: File[]];
    selectPageFolders: [files: File[]];
    submit: [];
    'update:open': [value: boolean];
    'update:outputPrefix': [value: string];
}>();

const bulkPageFolderInput = ref<HTMLInputElement | null>(null);
const bulkPageFolderContainerInput = ref<HTMLInputElement | null>(null);

function openPageFolderPicker(): void {
    bulkPageFolderInput.value?.click();
}

function openPageFolderContainerPicker(): void {
    bulkPageFolderContainerInput.value?.click();
}

function handlePageFolderSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    input.value = '';
    emit('selectPageFolders', files);
}

function handlePageFolderContainerSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const files = Array.from(input.files ?? []);

    input.value = '';
    emit('selectContainerFolder', files);
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader class="space-y-3">
                <DialogTitle>Bulk merge folders</DialogTitle>
                <DialogDescription>
                    Add one or more page folders like PAGE 1 and PAGE 2, or
                    import one container folder with page folders inside it.
                    Matching PDFs will merge into this batch immediately.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-6" @submit.prevent="emit('submit')">
                <input
                    ref="bulkPageFolderInput"
                    type="file"
                    accept=".pdf,application/pdf"
                    webkitdirectory
                    directory
                    multiple
                    class="hidden"
                    @change="handlePageFolderSelection"
                />
                <input
                    ref="bulkPageFolderContainerInput"
                    type="file"
                    accept=".pdf,application/pdf"
                    webkitdirectory
                    directory
                    multiple
                    class="hidden"
                    @change="handlePageFolderContainerSelection"
                />

                <div class="rounded-2xl border bg-muted/20 p-4 text-sm text-muted-foreground">
                    <p class="font-medium text-foreground">Folder rules</p>
                    <p class="mt-2">
                        Each page folder name must end with a positive number
                        like
                        <span class="font-medium text-foreground">PAGE 1</span>,
                        <span class="font-medium text-foreground">PAGE 2</span>,
                        or
                        <span class="font-medium text-foreground">PAGE 10</span>.
                    </p>
                    <p class="mt-2">
                        Only direct PDF files are allowed inside each page
                        folder, and matching uses the PDF name without the
                        ending page number.
                    </p>
                    <p class="mt-2">
                        To add multiple page folders in one pick, choose their
                        parent folder so it contains PAGE 1, PAGE 2, and the
                        other page folders inside.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="bulkFolderOutputPrefix">
                        Output prefix
                        <span class="text-muted-foreground">(optional)</span>
                    </Label>
                    <Input
                        id="bulkFolderOutputPrefix"
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

                <div class="space-y-3">
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            class="gap-2"
                            :disabled="props.processing"
                            @click="openPageFolderPicker"
                        >
                            <Upload class="size-4" />
                            Add page folders
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            class="gap-2"
                            :disabled="props.processing"
                            @click="openPageFolderContainerPicker"
                        >
                            <Upload class="size-4" />
                            Import container folder
                        </Button>
                    </div>
                    <InputError
                        :message="props.fieldError ?? props.inlineError ?? undefined"
                    />
                    <p v-if="props.progressPercentage !== null" class="text-xs text-muted-foreground">
                        Uploading {{ props.progressPercentage }}%
                    </p>
                </div>

                <div class="rounded-2xl border bg-background p-4">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="font-medium text-foreground">
                                Page order preview
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    props.pageFolders.length === 0
                                        ? 'No page folders selected yet.'
                                        : `${props.pageFolders.length} page folders selected.`
                                }}
                            </p>
                            <p class="mt-1 text-sm text-muted-foreground">
                                Each output becomes
                                <span class="font-medium text-foreground">
                                    {{ props.outputPreview }}
                                </span>
                                .
                            </p>
                        </div>
                        <Badge variant="outline">
                            {{ props.validSelectedPageFolderCount }} valid
                        </Badge>
                    </div>

                    <div v-if="props.pageFolders.length > 0" class="mt-4 space-y-3">
                        <div
                            v-for="pageFolder in props.pageFolders"
                            :key="pageFolder.key"
                            class="rounded-2xl border bg-muted/20 p-4"
                        >
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0 space-y-1">
                                    <p class="max-w-[24rem] truncate font-medium text-foreground">
                                        {{ pageFolder.name }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{
                                            pageFolder.number === null
                                                ? 'Needs page number'
                                                : `Page ${pageFolder.number}`
                                        }}
                                        &middot;
                                        {{
                                            pageFolder.files.length === 1
                                                ? '1 direct PDF'
                                                : `${pageFolder.files.length} direct PDFs`
                                        }}
                                    </p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <Badge
                                        v-if="pageFolder.issueMessage"
                                        variant="destructive"
                                    >
                                        Needs attention
                                    </Badge>
                                    <Badge
                                        v-else
                                        variant="outline"
                                        class="border-emerald-200 bg-emerald-50 text-emerald-700"
                                    >
                                        Ready
                                    </Badge>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        :disabled="props.processing"
                                        @click="
                                            emit('removePageFolder', pageFolder.key)
                                        "
                                    >
                                        <Trash2 class="size-4" />
                                        <span class="sr-only">
                                            Remove page folder
                                        </span>
                                    </Button>
                                </div>
                            </div>
                            <p
                                v-if="pageFolder.issueMessage"
                                class="mt-3 text-sm text-destructive"
                            >
                                {{ pageFolder.issueMessage }}
                            </p>
                        </div>
                    </div>
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
                        {{
                            props.processing
                                ? 'Processing folders...'
                                : 'Bulk merge folders'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
