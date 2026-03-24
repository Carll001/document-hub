<script setup lang="ts">
import { useSlots } from 'vue';
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

const slots = useSlots();
</script>

<template>
    <AppShell variant="header">
        <AppHeader :breadcrumbs="breadcrumbs">
            <template v-if="slots.subheader" #subheader>
                <slot name="subheader" />
            </template>
        </AppHeader>
        <AppContent variant="header">
            <slot />
        </AppContent>
    </AppShell>
</template>
