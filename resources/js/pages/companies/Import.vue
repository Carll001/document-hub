<script setup lang="ts">
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    open: boolean;
    processing: boolean;
    error?: string;
}>();

const emit = defineEmits<{
    submit: [file: File | null];
    'update:open': [open: boolean];
}>();

const selectedFile = ref<File | null>(null);

function handleSubmit(): void {
    emit('submit', selectedFile.value);
}

function handleFileChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    selectedFile.value = target.files?.[0] ?? null;
}
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader class="space-y-2">
                <DialogTitle>Import Companies</DialogTitle>
                <DialogDescription>
                    Upload Excel/CSV with name and tin headers (aliases like company name, registered name,
                    company tin are accepted). Address is optional, rows missing name/tin are skipped, and
                    other columns are saved under company data.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="handleSubmit">
                <div class="space-y-2">
                    <Label for="companiesImportSpreadsheet">Spreadsheet</Label>
                    <Input
                        id="companiesImportSpreadsheet"
                        type="file"
                        accept=".xlsx,.csv,.txt"
                        @change="handleFileChange"
                    />
                    <InputError :message="props.error" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="props.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="bg-[#2563EB] hover:bg-[#1D4ED8]"
                        :disabled="props.processing || selectedFile === null"
                    >
                        Import Companies
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
