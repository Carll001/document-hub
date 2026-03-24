<script setup lang="ts">
import { useSlots } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);

const slots = useSlots();
</script>

<template>
    <header
        :class="
            slots.subheader
                ? 'flex min-h-16 shrink-0 flex-col gap-3 border-b border-sidebar-border/70 px-6 py-3 transition-[width,height] ease-linear md:px-4 lg:flex-row lg:items-center lg:justify-between'
                : 'flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4'
        "
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-1" />
            <template v-if="breadcrumbs && breadcrumbs.length > 0">
                <Breadcrumbs :breadcrumbs="breadcrumbs" />
            </template>
        </div>

        <div v-if="slots.subheader" class="flex w-full justify-end lg:w-auto">
            <slot name="subheader" />
        </div>
    </header>
</template>
