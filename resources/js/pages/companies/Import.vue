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
    submit: [payload: { file: File | null; overwriteExisting: boolean }];
    'update:open': [open: boolean];
}>();

const selectedFile = ref<File | null>(null);
const overwriteExisting = ref(false);

function handleSubmit(): void {
    emit('submit', {
        file: selectedFile.value,
        overwriteExisting: overwriteExisting.value,
    });
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
                    other columns are saved under company data. If duplicates exist, choose whether to overwrite
                    existing company rows.
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

                <div class="space-y-2">
                    <p class="text-sm font-medium text-slate-700">If company already exists</p>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            v-model="overwriteExisting"
                            type="radio"
                            class="accent-[#2563EB]"
                            :value="false"
                        />
                        Keep existing row and import only new companies
                    </label>
                    <label class="flex items-center gap-2 text-sm text-slate-700">
                        <input
                            v-model="overwriteExisting"
                            type="radio"
                            class="accent-[#2563EB]"
                            :value="true"
                        />
                        Overwrite existing row with spreadsheet data
                    </label>
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
