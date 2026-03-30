<script setup lang="ts">
import { Download } from 'lucide-vue-next';
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
import type { BatchMergedOutput } from '@/components/doc-merge-batch-components/types';
import { formatFileSize } from '@/components/doc-merge-batch-components/utils';

const props = defineProps<{
    mergedPdf: BatchMergedOutput | null;
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-5xl">
            <DialogHeader class="space-y-3">
                <DialogTitle>{{ props.mergedPdf?.fileName }}</DialogTitle>
                <DialogDescription>
                    Preview the saved merged PDF before downloading or adding a
                    receipt.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    <Badge variant="secondary">
                        {{
                            props.mergedPdf
                                ? formatFileSize(props.mergedPdf.fileSize)
                                : '0 B'
                        }}
                    </Badge>
                    <Badge variant="outline">
                        {{
                            props.mergedPdf
                                ? `${props.mergedPdf.sourceCount} ${
                                      props.mergedPdf.sourceCount === 1
                                          ? 'source'
                                          : 'sources'
                                  }`
                                : '0 sources'
                        }}
                    </Badge>
                </div>

                <iframe
                    v-if="props.mergedPdf"
                    :key="props.mergedPdf.id"
                    :src="props.mergedPdf.previewUrl"
                    title="Merged PDF preview"
                    class="h-[70vh] w-full rounded-2xl border bg-white"
                />
            </div>

            <DialogFooter class="gap-2">
                <Button v-if="props.mergedPdf" as-child class="gap-2">
                    <a :href="props.mergedPdf.downloadUrl">
                        <Download class="size-4" />
                        Download
                    </a>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
