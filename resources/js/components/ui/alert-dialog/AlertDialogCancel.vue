<script setup lang="ts">
import type { AlertDialogCancelProps } from 'reka-ui';
import { AlertDialogCancel, useForwardProps } from 'reka-ui';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const props = defineProps<AlertDialogCancelProps & { class?: string }>();

const forwarded = useForwardProps(props);
</script>

<template>
    <AlertDialogCancel
        v-bind="forwarded"
        :class="
            cn(buttonVariants({ variant: 'outline' }), 'mt-2 sm:mt-0', props.class)
        "
    >
        <slot />
    </AlertDialogCancel>
</template>
