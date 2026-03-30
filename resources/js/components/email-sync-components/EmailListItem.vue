<script setup lang="ts">
import { Paperclip } from 'lucide-vue-next';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { EmailRecord } from '@/components/email-sync-components/types';
import {
    attachmentCountLabel,
    emailHeading,
    emailTimestampForDisplay,
    formatRelativeTime,
    highlightText,
    previewLine,
    senderDisplayName,
    visibleAttachments,
} from '@/components/email-sync-components/utils';

const props = defineProps<{
    email: EmailRecord;
    query: string;
    selected: boolean;
}>();

const emit = defineEmits<{
    select: [emailId: number];
}>();
</script>

<template>
    <Card
        role="button"
        tabindex="0"
        class="w-full cursor-pointer gap-0 py-0 text-left transition"
        :class="
            props.selected
                ? 'border-foreground/10 bg-muted shadow-sm'
                : 'border-border bg-background hover:bg-muted/40'
        "
        @click="emit('select', props.email.id)"
        @keydown.enter.prevent="emit('select', props.email.id)"
        @keydown.space.prevent="emit('select', props.email.id)"
    >
        <CardHeader class="px-4 pt-4 pb-0">
            <CardTitle class="min-w-0 truncate text-base">
                <template
                    v-for="(segment, segmentIndex) in highlightText(
                        senderDisplayName(props.email),
                        props.query,
                    )"
                    :key="`sender-${props.email.id}-${segmentIndex}`"
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
            </CardTitle>
            <CardAction class="shrink-0 text-xs text-muted-foreground">
                {{
                    formatRelativeTime(
                        emailTimestampForDisplay(props.email),
                        'Unknown',
                    )
                }}
            </CardAction>
            <CardDescription class="mt-1 truncate text-xs font-medium text-foreground">
                <template
                    v-for="(segment, segmentIndex) in highlightText(
                        emailHeading(props.email),
                        props.query,
                    )"
                    :key="`subject-${props.email.id}-${segmentIndex}`"
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
            </CardDescription>
        </CardHeader>

        <CardContent class="px-4 pt-3 pb-0">
            <p class="line-clamp-3 text-sm leading-6 text-muted-foreground">
                <template
                    v-for="(segment, segmentIndex) in highlightText(
                        previewLine(props.email),
                        props.query,
                    )"
                    :key="`preview-${props.email.id}-${segmentIndex}`"
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
        </CardContent>

        <CardFooter class="mt-4 flex flex-wrap items-center gap-2 px-4 pt-0 pb-4">
            <Badge
                v-if="visibleAttachments(props.email).length > 0"
                variant="outline"
                class="gap-1 rounded-full"
            >
                <Paperclip class="size-3" />
                {{ attachmentCountLabel(props.email) }}
            </Badge>
            <Badge variant="secondary" class="rounded-full">
                {{ props.email.mailbox }}
            </Badge>
        </CardFooter>
    </Card>
</template>
