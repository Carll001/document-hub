<script setup lang="ts">
import { FileText, Plus } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

const emit = defineEmits<{
    openCreateBatch: [];
    openTemplate: [];
}>();
</script>

<template>
    <div class="flex flex-wrap items-center justify-end gap-2">
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="gap-2 text-xs"
            @click="emit('openCreateBatch')"
        >
            <Plus class="size-4" />
            Create batch
        </Button>
        <Button
            type="button"
            variant="outline"
            size="sm"
            class="gap-2 text-xs"
            @click="emit('openTemplate')"
        >
            <FileText class="size-4" />
            Receipt template
        </Button>
    </div>
</template>
