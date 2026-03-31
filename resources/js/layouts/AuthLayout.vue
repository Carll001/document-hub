<script setup lang="ts">
import AuthLayout from '@/layouts/auth/AuthSplitLayout.vue';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <template v-if="$slots.panel" #panel>
            <slot name="panel" />
        </template>
        <slot />
    </AuthLayout>
</template>
