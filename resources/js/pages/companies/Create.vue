<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Companies', href: '/companies' },
    { title: 'Create', href: '/companies/create' },
];
</script>

<template>
    <Head title="Create Company" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-4 p-4 md:p-6">
            <h1 class="text-3xl font-semibold tracking-tight text-slate-900">Create Company</h1>
            <p class="text-sm text-slate-600">Create form placeholder.</p>
        </div>
    </AppLayout>
</template>

