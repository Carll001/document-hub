<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    company: {
        id: number;
        name: string;
        tin: string;
        address: string;
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Companies', href: '/companies' },
    { title: 'Edit', href: `/companies/${props.company.id}/edit` },
];
</script>

<template>
    <Head title="Edit Company" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
            <h1 class="text-3xl font-semibold tracking-tight text-slate-900">Edit Company</h1>
            <p class="text-sm text-slate-600">Editing {{ company.name }}.</p>
        </div>
    </AppLayout>
</template>
