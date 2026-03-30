<script setup lang="ts">
defineProps<{
    latestSyncLabel: string;
    totalStored: number;
}>();
</script>

<template>
    <p class="text-[11px] text-muted-foreground">
        {{ totalStored }} stored - Last sync {{ latestSyncLabel }}
    </p>
</template>
