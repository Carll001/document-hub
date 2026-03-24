<script setup lang="ts">
import { useSlots } from 'vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { Toaster } from '@/components/ui/sonner';
import 'vue-sonner/style.css';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const slots = useSlots();
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <template v-if="slots.subheader" #subheader>
            <slot name="subheader" />
        </template>
        <slot />
        <Toaster />
    </AppLayout>
</template>
