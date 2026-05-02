<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Check, Circle, Dot } from 'lucide-vue-next'

import AppLayout from '@/layouts/AppLayout.vue'
import type { BreadcrumbItem } from '@/types'

import { Button } from '@/components/ui/button'
import {
    Stepper,
    StepperDescription,
    StepperItem,
    StepperSeparator,
    StepperTitle,
    StepperTrigger,
} from '@/components/ui/stepper'
import { computed, ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { DataTable } from '@/components/ui/data-table'
import { createCompanySelectColumns, type CompanyOptionRow } from '@/pages/filing/columns'
import Step2SelectFilingType from '@/pages/filing/components/Step2SelectFilingType.vue'
import Step3DataCheckingAfs from '@/pages/filing/components/Step3DataCheckingAfs.vue'
import Step3DataChecking1702Ex from '@/pages/filing/components/Step3DataChecking1702Ex.vue'
import Step4ReviewingOutput from '@/pages/filing/components/Step4ReviewingOutput.vue'
import filing from '@/routes/filing'

type PaginationMeta = {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number
    to: number
}
type SelectedCompany = {
    id: number
    name: string
    tin: string
    address: string
    data: Record<string, string>
}

const props = defineProps<{
    routes: {
        index: string
        afsGenerate: string
        afsOutputs: string
    }
    companies: {
        data: CompanyOptionRow[]
        pagination: PaginationMeta
    }
    filters: {
        search: string
        perPage: 5 | 10 | 25 | 50 | 100
    }
    currentStep: number
    selectedCompanyIds: number[]
    selectedFilingType: 'afs' | '1702ex' | null
    selectedCompanies: SelectedCompany[]
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

const search = ref(props.filters.search ?? '')
const selectedCompanyIds = ref<number[]>(props.selectedCompanyIds ?? [])
const currentStep = computed(() => props.currentStep ?? 1)

const rows = computed(() => props.companies.data)
const tableMeta = computed(() => props.companies.pagination)

const columns = createCompanySelectColumns({
    selectedCompanyIds,
})

function refreshTable(overrides: Partial<{ page: number; search: string; perPage: number }>) {
    router.get(
        props.routes.index,
        {
            step: 1,
            page: overrides.page ?? props.companies.pagination.current_page,
            perPage: overrides.perPage ?? props.companies.pagination.per_page,
            search: overrides.search ?? search.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => {
                window.scrollTo({
                    top: document.body.scrollHeight,
                    behavior: 'smooth',
                })
            },
        },
    )
}

function pageChange(page: number) {
    refreshTable({ page })
}

function perPageChange(value: number) {
    refreshTable({ page: 1, perPage: value })
}

function goToStep2() {
    if (selectedCompanyIds.value.length === 0) return

    router.get(
        props.routes.index,
        {
            step: 2,
            companyId: selectedCompanyIds.value,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    )
}

function goToStep1() {
    router.get(
        props.routes.index,
        {
            step: 1,
            companyId: selectedCompanyIds.value,
            search: search.value,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    )
}

function goToStep3(filingType: 'afs' | '1702ex') {
    router.get(
        props.routes.index,
        {
            step: 3,
            companyId: selectedCompanyIds.value,
            filingType,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    )
}

function goToStep4() {
    if (props.selectedFilingType !== 'afs') {
        router.get(
            props.routes.index,
            {
                step: 4,
                companyId: selectedCompanyIds.value,
                filingType: props.selectedFilingType,
            },
            {
                preserveScroll: true,
                preserveState: false,
                replace: true,
            },
        )
        return
    }

    fetch(props.routes.afsGenerate, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name=\"csrf-token\"]') as HTMLMetaElement | null)?.content ?? '',
        },
        body: JSON.stringify({
            filingType: 'afs',
            companyId: selectedCompanyIds.value,
        }),
    }).finally(() => {
        router.get(
            props.routes.index,
            {
                step: 4,
                companyId: selectedCompanyIds.value,
                filingType: props.selectedFilingType,
            },
            {
                preserveScroll: true,
                preserveState: false,
                replace: true,
            },
        )
    })
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Generate Filing',
        href: filing.index(),
    },
]

const steps = [
    {
        step: 1,
        title: 'Select Company',
        description: 'Choose one company',
    },
    {
        step: 2,
        title: 'Select Filing Type',
        description: 'Choose filing type',
    },
    {
        step: 3,
        title: 'Data Checking',
        description: 'Review extracted data',
    },
    {
        step: 4,
        title: 'Reviewing Output',
        description: 'Review generated output',
    },
]
</script>

<template>

    <Head title="Generate Filing" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-2xl font-bold tracking-tight">
                    Generate Filing
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Select a company, choose a filing type, import data, and generate the filing.
                </p>
            </div>

            <div class="rounded-xl border bg-card p-6 shadow-sm">
                <Stepper class="flex w-full items-start gap-2" :model-value="currentStep">
                <StepperItem v-for="step in steps" :key="step.step" v-slot="{ state }"
                    class="relative flex w-full flex-col items-center justify-center" :step="step.step">
                    <StepperSeparator v-if="step.step !== steps[steps.length - 1]?.step"
                        class="absolute left-[calc(50%+20px)] right-[calc(-50%+10px)] top-5 block h-0.5 shrink-0 rounded-full bg-blue-100 group-data-[state=completed]:bg-[#2563EB]" />

                    <StepperTrigger as-child>
                        <Button variant="outline" size="icon"
                            class="z-10 shrink-0 rounded-full border transition-all hover:bg-blue-100 hover:text-[#2563EB] hover:border-[#2563EB]"
                            :class="[
                                state === 'completed' && 'bg-[#2563EB] text-white border-[#2563EB] hover:bg-[#1D4ED8] hover:text-white',
                                state === 'active' && 'bg-[#2563EB] text-white border-[#2563EB] ring-2 ring-[#2563EB]/30 ring-offset-2 hover:bg-[#1D4ED8] hover:text-white',
                                state === 'inactive' && 'bg-blue-50 text-blue-300 border-blue-200 hover:bg-blue-100 hover:text-[#2563EB] hover:border-blue-300'
                            ]">
                            <Check v-if="state === 'completed'" class="size-5" />
                            <Circle v-if="state === 'active'" class="size-5" />
                            <Dot v-if="state === 'inactive'" class="size-5" />
                        </Button>
                    </StepperTrigger>

                    <div class="mt-5 flex flex-col items-center text-center">
                        <StepperTitle :class="[state === 'active' && 'text-primary']"
                            class="text-sm font-semibold transition lg:text-base">
                            {{ step.title }}
                        </StepperTitle>

                        <StepperDescription :class="[state === 'active' && 'text-primary']"
                            class="sr-only text-xs text-muted-foreground transition md:not-sr-only lg:text-sm">
                            {{ step.description }}
                        </StepperDescription>
                    </div>
                </StepperItem>
                </Stepper>
            </div>

            <Card v-if="currentStep === 1" class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <CardHeader>
                    <CardTitle>Step 1: Select Company</CardTitle>
                </CardHeader>

                <CardContent class="space-y-4">
                    <Input v-model="search" placeholder="Search company name, TIN, or address..."
                        @keyup.enter="refreshTable({ page: 1, search })" />

                    <DataTable :columns="columns" :data="rows" :meta="tableMeta" empty-message="No companies found"
                        @page-change="pageChange" @per-page-change="perPageChange" />
                </CardContent>
            </Card>

            <Step2SelectFilingType
                v-else-if="currentStep === 2"
                :selected-company-ids="selectedCompanyIds"
                :selected-companies="props.selectedCompanies"
                :initial-filing-type="props.selectedFilingType"
                :filing-type-availability="props.filingTypeAvailability"
                @back="goToStep1"
                @next="goToStep3"
            />

            <Step3DataCheckingAfs
                v-else-if="currentStep === 3 && props.selectedFilingType === 'afs'"
                :selected-companies="props.selectedCompanies"
                @back-to-filing-type="goToStep2"
                @generate-filing="goToStep4"
            />

            <Step3DataChecking1702Ex
                v-else-if="currentStep === 3 && props.selectedFilingType === '1702ex'"
                :selected-companies="props.selectedCompanies"
                @back-to-filing-type="goToStep2"
                @generate-filing="goToStep4"
            />

            <Step4ReviewingOutput
                v-else-if="currentStep === 4"
                :selected-company-ids="selectedCompanyIds"
                :filing-type="props.selectedFilingType"
                :routes="{ afsOutputs: props.routes.afsOutputs }"
            />

            <div class="flex justify-end gap-2">
                <Button v-if="currentStep === 1" class="bg-[#2563EB] hover:bg-[#1D4ED8]" :disabled="selectedCompanyIds.length === 0"
                    @click="goToStep2">
                    Next Step
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
