<script setup lang="ts">
import { Plus, Trash2 } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';

const props = defineProps<{
    canBulkDelete: boolean;
    selectedCount: number;
}>();

const emit = defineEmits<{
    createUser: [];
    deleteSelected: [];
}>();
</script>

<template>
    <div class="flex flex-wrap items-center justify-end gap-2">
        <Button
            type="button"
            variant="destructive"
            size="sm"
            class="gap-2 text-xs"
            :disabled="props.canBulkDelete === false"
            @click="emit('deleteSelected')"
        >
            <Trash2 class="size-4" />
            {{
                props.selectedCount > 0
                    ? `Delete selected (${props.selectedCount})`
                    : 'Delete selected'
            }}
        </Button>
        <Button
            type="button"
            size="sm"
            class="gap-2 text-xs"
            @click="emit('createUser')"
        >
            <Plus class="size-4" />
            Create user
        </Button>
    </div>
</template>
