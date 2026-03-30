<script setup lang="ts">
import type { AlertDialogActionProps } from 'reka-ui';
import { AlertDialogAction, useForwardProps } from 'reka-ui';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const props = defineProps<AlertDialogActionProps & { class?: string }>();

const forwarded = useForwardProps(props);
</script>

<template>
    <AlertDialogAction
        v-bind="forwarded"
        :class="cn(buttonVariants({ variant: 'destructive' }), props.class)"
    >
        <slot />
    </AlertDialogAction>
</template>
