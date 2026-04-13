<script setup lang="ts">
import { Copy, Mail, Paperclip } from 'lucide-vue-next';
import { toRef } from 'vue';
import EmailAttachmentList from '@/components/email-sync-components/EmailAttachmentList.vue';
import type {
    BodySegment,
    EmailAttachment,
    EmailRecord,
} from '@/components/email-sync-components/types';
import { useRenderedEmailFrame } from '@/components/email-sync-components/useRenderedEmailFrame';
import {
    attachmentCountLabel,
    avatarText,
    emailHeading,
    emailTimestampForDisplay,
    emptyBodyMessage,
    formatDateTime,
    formatRelativeTime,
    highlightText,
    senderDisplayName,
    senderLine,
    shouldShowBodyToggle,
} from '@/components/email-sync-components/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardFooter,
} from '@/components/ui/card';

const props = defineProps<{
    emptyDetailMessage: string;
    isBodyExpanded: (emailId: number) => boolean;
    query: string;
    selectedEmail: EmailRecord | null;
    selectedEmailAttachments: EmailAttachment[];
    selectedEmailBodyLines: BodySegment[][];
    selectedEmailHtmlUrl: string | null;
    selectedEmailReceiptClipboardText: string | null;
}>();

const emit = defineEmits<{
    copyBirDetails: [];
    toggleBody: [emailId: number];
}>();

const { onRenderedEmailLoad, renderedEmailFrame } = useRenderedEmailFrame(
    toRef(props, 'selectedEmailHtmlUrl'),
);
</script>

<template>
    <section class="flex min-h-0 flex-col lg:overflow-hidden">
        <template v-if="props.selectedEmail">
            <div class="border-b p-4">
                <div
                    class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between"
                >
                    <div class="flex items-start gap-4">
                        <div
                            class="flex size-14 shrink-0 items-center justify-center rounded-full bg-muted text-base font-semibold"
                        >
                            {{ avatarText(props.selectedEmail) }}
                        </div>

                        <div class="min-w-0 space-y-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="text-lg font-semibold tracking-tight">
                                    <template
                                        v-for="(segment, segmentIndex) in highlightText(
                                            senderDisplayName(props.selectedEmail),
                                            props.query,
                                        )"
                                        :key="`detail-sender-${props.selectedEmail.id}-${segmentIndex}`"
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
                                <Badge variant="outline" class="rounded-full">
                                    {{ props.selectedEmail.accountLabel }}
                                </Badge>
                                <Badge variant="outline" class="rounded-full">
                                    {{ props.selectedEmail.mailbox }}
                                </Badge>
                                <Badge
                                    v-if="props.selectedEmailAttachments.length > 0"
                                    variant="secondary"
                                    class="gap-1 rounded-full"
                                >
                                    <Paperclip class="size-3" />
                                    {{ attachmentCountLabel(props.selectedEmail) }}
                                </Badge>
                            </div>

                            <p class="text-md font-medium">
                                <template
                                    v-for="(segment, segmentIndex) in highlightText(
                                        emailHeading(props.selectedEmail),
                                        props.query,
                                    )"
                                    :key="`detail-subject-${props.selectedEmail.id}-${segmentIndex}`"
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
                            <p class="text-sm text-muted-foreground">
                                Reply-To:
                                <template
                                    v-for="(segment, segmentIndex) in highlightText(
                                        senderLine(props.selectedEmail),
                                        props.query,
                                    )"
                                    :key="`detail-reply-${props.selectedEmail.id}-${segmentIndex}`"
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
                        </div>
                    </div>

                    <div
                        class="flex flex-col items-start gap-3 text-sm text-muted-foreground xl:items-end xl:text-right"
                    >
                        <div>
                            <p>
                                {{
                                    formatDateTime(
                                        emailTimestampForDisplay(props.selectedEmail),
                                        'Unknown date',
                                    )
                                }}
                            </p>
                            <p class="mt-1">
                                Saved
                                {{
                                    formatRelativeTime(
                                        props.selectedEmail.syncedAt,
                                        'Unknown',
                                    )
                                }}
                            </p>
                        </div>

                        <Button
                            v-if="props.selectedEmailReceiptClipboardText"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="gap-2 text-xs"
                            @click="emit('copyBirDetails')"
                        >
                            <Copy class="size-4" />
                            Copy BIR details
                        </Button>
                    </div>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto p-2">
                <div class="max-w-4xl space-y-6">
                    <Card
                        v-if="props.selectedEmailHtmlUrl"
                        class="gap-0 overflow-hidden rounded-[24px] bg-background py-0"
                    >
                        <CardContent class="p-2">
                            <iframe
                                ref="renderedEmailFrame"
                                :src="props.selectedEmailHtmlUrl"
                                title="Rendered email body"
                                class="block min-h-[320px] w-full border-0 bg-transparent"
                                sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                                referrerpolicy="no-referrer"
                                @load="onRenderedEmailLoad"
                            />
                        </CardContent>
                    </Card>

                    <Card
                        v-else-if="props.selectedEmailBodyLines.length > 0"
                        class="gap-0 rounded-[24px] bg-muted/20 py-0"
                    >
                        <CardContent
                            class="space-y-3 px-5 py-5 text-[15px] leading-8 break-words whitespace-pre-wrap text-foreground"
                        >
                            <div
                                v-for="(line, lineIndex) in props.selectedEmailBodyLines"
                                :key="lineIndex"
                                class="min-h-6"
                            >
                                <template v-if="line.length > 0">
                                    <template
                                        v-for="(segment, segmentIndex) in line"
                                        :key="`${lineIndex}-${segmentIndex}`"
                                    >
                                        <a
                                            v-if="segment.type === 'link'"
                                            :href="segment.href"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-medium text-primary underline underline-offset-4"
                                        >
                                            <template
                                                v-for="(
                                                    highlighted,
                                                    highlightIndex
                                                ) in highlightText(
                                                    segment.value,
                                                    props.query,
                                                )"
                                                :key="`${lineIndex}-${segmentIndex}-link-${highlightIndex}`"
                                            >
                                                <mark
                                                    v-if="highlighted.isMatch"
                                                    class="rounded bg-amber-200/80 px-0.5 text-inherit"
                                                >
                                                    {{ highlighted.value }}
                                                </mark>
                                                <template v-else>
                                                    {{ highlighted.value }}
                                                </template>
                                            </template>
                                        </a>
                                        <template v-else>
                                            <template
                                                v-for="(
                                                    highlighted,
                                                    highlightIndex
                                                ) in highlightText(
                                                    segment.value,
                                                    props.query,
                                                )"
                                                :key="`${lineIndex}-${segmentIndex}-text-${highlightIndex}`"
                                            >
                                                <mark
                                                    v-if="highlighted.isMatch"
                                                    class="rounded bg-amber-200/80 px-0.5 text-inherit"
                                                >
                                                    {{ highlighted.value }}
                                                </mark>
                                                <template v-else>
                                                    {{ highlighted.value }}
                                                </template>
                                            </template>
                                        </template>
                                    </template>
                                </template>
                                <template v-else>
                                    &nbsp;
                                </template>
                            </div>
                        </CardContent>

                        <CardFooter
                            v-if="shouldShowBodyToggle(props.selectedEmail)"
                            class="px-5 pt-0 pb-5"
                        >
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="rounded-full text-xs"
                                @click="emit('toggleBody', props.selectedEmail.id)"
                            >
                                {{
                                    props.isBodyExpanded(props.selectedEmail.id)
                                        ? 'Show less'
                                        : 'Show full body'
                                }}
                            </Button>
                        </CardFooter>
                    </Card>

                    <Card
                        v-else
                        class="gap-0 rounded-[24px] border-dashed py-0 shadow-none"
                    >
                        <CardContent class="px-5 py-6 text-sm text-muted-foreground">
                            {{ emptyBodyMessage(props.selectedEmail) }}
                        </CardContent>
                    </Card>

                    <EmailAttachmentList
                        v-if="props.selectedEmailAttachments.length > 0"
                        :attachments="props.selectedEmailAttachments"
                        :query="props.query"
                    />
                </div>
            </div>
        </template>

        <div v-else class="flex flex-1 items-center justify-center px-6 py-10">
            <div class="max-w-sm text-center">
                <div
                    class="mx-auto flex size-16 items-center justify-center rounded-full bg-muted"
                >
                    <Mail class="size-7 text-muted-foreground" />
                </div>
                <h2 class="mt-4 text-xl font-semibold">No email selected</h2>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{ props.emptyDetailMessage }}
                </p>
            </div>
        </div>
    </section>
</template>
