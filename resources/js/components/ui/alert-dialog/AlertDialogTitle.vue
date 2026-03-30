<script setup lang="ts">
import type { AlertDialogTitleProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { AlertDialogTitle, useForwardProps } from 'reka-ui';
import { cn } from '@/lib/utils';

const props = defineProps<
    AlertDialogTitleProps & { class?: HTMLAttributes['class'] }
>();

const forwarded = useForwardProps(props);
</script>

<template>
    <AlertDialogTitle
        v-bind="forwarded"
        data-slot="alert-dialog-title"
        :class="cn('text-lg font-semibold', props.class)"
    >
        <slot />
    </AlertDialogTitle>
</template>
