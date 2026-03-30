<script setup lang="ts">
import { Paperclip } from 'lucide-vue-next';
import {
    Card,
    CardContent,
} from '@/components/ui/card';
import type { EmailAttachment } from '@/components/email-sync-components/types';
import {
    formatFileSize,
    highlightText,
} from '@/components/email-sync-components/utils';

const props = defineProps<{
    attachments: EmailAttachment[];
    query: string;
}>();
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center gap-2">
            <Paperclip class="size-4 text-muted-foreground" />
            <p class="text-sm font-semibold">Attachments</p>
        </div>

        <div class="grid gap-3 md:grid-cols-2">
            <a
                v-for="attachment in props.attachments"
                :key="attachment.id"
                :href="attachment.downloadUrl"
                class="block"
            >
                <Card class="gap-0 rounded-2xl py-0 transition hover:bg-muted/40">
                    <CardContent class="px-4 py-4">
                        <div class="flex items-start gap-3">
                            <div
                                class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-muted"
                            >
                                <Paperclip class="size-4 text-muted-foreground" />
                            </div>

                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    <template
                                        v-for="(segment, segmentIndex) in highlightText(
                                            attachment.fileName,
                                            props.query,
                                        )"
                                        :key="`attachment-${attachment.id}-${segmentIndex}`"
                                    >
                                        <mark
                                            v-if="segment.isMatch"
                                            class="rounded bg-amber-200/80 px-0.5 text-inherit"
                                        >
                                            {{ segment.value }}
                                        </mark>
                                        <template v-else>
                                            {{ segment.value }}
                                        </template>
                                    </template>
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ attachment.contentType || 'Unknown type' }} -
                                    {{ formatFileSize(attachment.fileSize) }}
                                </p>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </a>
        </div>
    </div>
</template>
