<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowLeft, ArrowRight, Building2, Check, FileSpreadsheet, ListChecks } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'

const props = defineProps<{
    selectedCompanyIds: number[]
    initialFilingType: 'afs' | '1702ex' | null
    filingTypeAvailability: {
        afs: {
            hasTemplate: boolean
            ownerLabel: string
        }
        '1702ex': {
            hasTemplate: boolean
            ownerLabel: string
        }
    }
}>()

const emit = defineEmits<{
    back: []
    next: [filingType: 'afs' | '1702ex']
}>()

const selectedType = ref<'afs' | '1702ex'>(props.initialFilingType ?? 'afs')

const options = computed(() => [
    {
        id: 'afs' as const,
        title: 'AFS Filing',
        description: 'Generate Annual Financial Statements (AFS) filing for your company.',
        detail: 'Includes balance sheet, income statement, notes, and other required schedules.',
        icon: Building2,
        accent: 'blue',
        badge: null,
        hasTemplate: props.filingTypeAvailability.afs.hasTemplate,
        ownerLabel: props.filingTypeAvailability.afs.ownerLabel,
    },
    {
        id: '1702ex' as const,
        title: '1702EX Filing',
        description: 'Generate BIR Form 1702EX filing for information return.',
        detail: 'For compensation, fringe benefits, and other income payments.',
        icon: FileSpreadsheet,
        accent: 'emerald',
        badge: null,
        hasTemplate: props.filingTypeAvailability['1702ex'].hasTemplate,
        ownerLabel: props.filingTypeAvailability['1702ex'].ownerLabel,
    },
])

function selectType(type: 'afs' | '1702ex') {
    const option = options.value.find((item) => item.id === type)
    if (!option?.hasTemplate) return

    selectedType.value = type
}

const canProceed = computed(() => {
    const option = options.value.find((item) => item.id === selectedType.value)

    return Boolean(option?.hasTemplate)
})

if (!canProceed.value) {
    const firstAvailable = options.value.find((item) => item.hasTemplate)
    if (firstAvailable) {
        selectedType.value = firstAvailable.id
    }
}
</script>

<template>
    <Card class="rounded-3xl border border-slate-200 bg-white shadow-sm">
        <CardHeader>
            <CardTitle>Step 2: Select Filing Type</CardTitle>
            <p class="text-base font-normal text-muted-foreground">
                Choose the type of filing you want to generate.
            </p>
        </CardHeader>
        <CardContent class="space-y-6">
            <ScrollArea class="w-full">
                <div class="flex items-stretch gap-4 pb-3">
                    <button
                        v-for="option in options"
                        :key="option.id"
                        type="button"
                        class="relative flex h-full min-h-[20rem] w-[22rem] shrink-0 flex-col rounded-2xl border p-5 text-left transition-all sm:w-[24rem]"
                        :disabled="!option.hasTemplate"
                        :class="[
                            selectedType === option.id ? 'border-blue-500 ring-2 ring-inset ring-blue-100' : 'border-slate-200',
                            option.hasTemplate ? 'hover:border-slate-300' : 'cursor-not-allowed opacity-60',
                        ]"
                        @click="selectType(option.id)"
                    >
                        <span
                            v-if="selectedType === option.id"
                            class="absolute right-4 top-4 inline-flex size-7 items-center justify-center rounded-full bg-blue-600 text-white"
                        >
                            <Check class="size-4" />
                        </span>

                        <span
                            class="mb-4 inline-flex size-14 items-center justify-center rounded-full"
                            :class="{
                                'bg-blue-100 text-blue-700': option.accent === 'blue',
                                'bg-emerald-100 text-emerald-700': option.accent === 'emerald',
                            }"
                        >
                            <component :is="option.icon" class="size-7" />
                        </span>

                        <h3 class="text-2xl font-semibold text-slate-900">
                            {{ option.title }}
                        </h3>

                        <span
                            v-if="option.badge"
                            class="mt-3 inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700"
                        >
                            {{ option.badge }}
                        </span>

                        <p class="mt-4 min-h-[7rem] text-lg leading-relaxed text-slate-600">
                            {{ option.description }}
                        </p>

                        <div class="mt-6 flex min-h-[5.5rem] items-start gap-3 text-slate-600">
                            <ListChecks class="mt-1 size-5 shrink-0 text-blue-600" />
                            <p class="text-base leading-relaxed">
                                {{ option.detail }}
                            </p>
                        </div>

                        <p class="mt-4 text-sm font-medium" :class="option.hasTemplate ? 'text-emerald-600' : 'text-amber-600'">
                            {{ option.hasTemplate ? 'Template ready' : 'Template not set' }}: {{ option.ownerLabel }}
                        </p>
                    </button>
                </div>
                <ScrollBar orientation="horizontal" />
            </ScrollArea>

            <div class="flex items-center justify-between border-t pt-6">
                <Button variant="outline" class="gap-2" @click="emit('back')">
                    <ArrowLeft class="size-4" />
                    Back
                </Button>

                <Button class="gap-2 bg-[#2563EB] hover:bg-[#1D4ED8]" :disabled="!canProceed" @click="emit('next', selectedType)">
                    Next Step
                    <ArrowRight class="size-4" />
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
