<script setup lang="ts">
import { Eye, EyeOff } from 'lucide-vue-next';
import { ref, useTemplateRef } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    defaultValue?: string | number;
    modelValue?: string | number;
    class?: HTMLAttributes['class'];
}>();
const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number): void;
}>();

const showPassword = ref(false);
const inputRef = useTemplateRef('inputRef');

defineExpose({
    $el: inputRef,
    focus: () => inputRef.value?.$el?.focus(),
});
</script>

<template>
    <div class="relative">
        <Input
            ref="inputRef"
            :model-value="props.modelValue"
            :default-value="props.defaultValue"
            :type="showPassword ? 'text' : 'password'"
            :class="cn('pr-10', props.class)"
            v-bind="$attrs"
            @update:model-value="emits('update:modelValue', $event)"
        />
        <button
            type="button"
            @click="showPassword = !showPassword"
            :class="
                cn(
                    'absolute inset-y-0 right-0 flex items-center rounded-r-md px-3 text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                )
            "
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            :tabindex="-1"
        >
            <EyeOff v-if="showPassword" class="size-4" />
            <Eye v-else class="size-4" />
        </button>
    </div>
</template>
