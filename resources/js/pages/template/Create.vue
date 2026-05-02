<script setup lang="ts">
import { ref, watch } from 'vue'

import { Button } from '@/components/ui/button'
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'

const props = defineProps<{
    open: boolean
    processing: boolean
    errors: {
        name?: string
        filing_type?: string
        template_file?: string
    }
}>()

const emit = defineEmits<{
    'update:open': [open: boolean]
    submit: [payload: { name: string; filing_type: 'afs' | '1702ex'; template_file: File | null }]
}>()

const name = ref('')
const filingType = ref<'afs' | '1702ex'>('afs')
const templateFile = ref<File | null>(null)

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            name.value = ''
            filingType.value = 'afs'
            templateFile.value = null
        }
    },
)

function onFileChange(event: Event): void {
    const input = event.target as HTMLInputElement
    templateFile.value = input.files?.[0] ?? null
}

function submit(): void {
    emit('submit', {
        name: name.value,
        filing_type: filingType.value,
        template_file: templateFile.value,
    })
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader class="space-y-1">
                <DialogTitle>New Template</DialogTitle>
                <DialogDescription>
                    Upload a filing template. For AFS, only `.docx` is accepted.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submit">
                <div class="space-y-2">
                    <Label for="templateName">Template Name</Label>
                    <Input id="templateName" v-model="name" placeholder="e.g. AFS - Default Template" />
                    <p v-if="props.errors.name" class="text-xs text-rose-600">{{ props.errors.name }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="filingType">Filing Type</Label>
                    <select
                        id="filingType"
                        v-model="filingType"
                        class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs"
                    >
                        <option value="afs">AFS</option>
                        <option value="1702ex" disabled>1702EX (System defined)</option>
                    </select>
                    <p class="text-xs text-muted-foreground">AFS template file must be `.docx`.</p>
                    <p v-if="props.errors.filing_type" class="text-xs text-rose-600">{{ props.errors.filing_type }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="templateFile">Template File</Label>
                    <Input id="templateFile" type="file" accept=".docx" @change="onFileChange" />
                    <p v-if="props.errors.template_file" class="text-xs text-rose-600">{{ props.errors.template_file }}</p>
                </div>

                <DialogFooter class="gap-2">
                    <Button type="button" variant="secondary" :disabled="props.processing" @click="emit('update:open', false)">
                        Cancel
                    </Button>
                    <Button type="submit" class="bg-[#2563EB] hover:bg-[#1D4ED8]" :disabled="props.processing || !name || !templateFile">
                        Save Template
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

