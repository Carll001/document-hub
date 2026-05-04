<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { Check, Circle, Dot } from 'lucide-vue-next'

import AppLayout from '@/layouts/AppLayout.vue'
import type { BreadcrumbItem } from '@/types'

import { Button } from '@/components/ui/button'
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import {
    Stepper,
    StepperDescription,
    StepperItem,
    StepperSeparator,
    StepperTitle,
    StepperTrigger,
} from '@/components/ui/stepper'
import { computed, onMounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { DataTable } from '@/components/ui/data-table'
import { createCompanySelectColumns, type CompanyOptionRow } from '@/pages/filing/columns'
import { toast } from 'vue-sonner'
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
        afsGetorPreview: string
        afsPresidentPreview: string
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
const presidentSignatures = ref<Record<number, File | null>>({})
const getorSignature = ref<File | null>(null)
const currentStep = computed(() => props.currentStep ?? 1)
const overwriteDialogOpen = ref(false)
const overwriteConflicts = ref<Array<{ company_name: string }>>([])
const missingPresidentWarningOpen = ref(false)
const missingGetorWarningOpen = ref(false)
const hasGlobalGetorSignature = ref<boolean | null>(null)
const persistedPresidentSignatureAvailability = ref<Record<number, boolean>>({})
const isGenerating = ref(false)

const rows = computed(() => props.companies.data)
const tableMeta = computed(() => props.companies.pagination)

const columns = createCompanySelectColumns({
    selectedCompanyIds,
})
const GENERATE_STARTED_TOAST_KEY = 'filing:show-generate-started-toast'

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

function onRemoveMissingCompanies(remainingCompanyIds: number[]) {
    const nextStep = remainingCompanyIds.length > 0 ? 2 : 1
    router.get(
        props.routes.index,
        {
            step: nextStep,
            companyId: remainingCompanyIds,
            filingType: remainingCompanyIds.length > 0 ? props.selectedFilingType : undefined,
            search: search.value,
        },
        {
            preserveScroll: true,
            preserveState: false,
            replace: true,
        },
    )
}

function queueGenerateStartedToast(message: string): void {
    try {
        window.sessionStorage.setItem(
            GENERATE_STARTED_TOAST_KEY,
            JSON.stringify({ message, at: Date.now() }),
        )
    } catch {
        // no-op: storage unavailable
    }
}

function showQueuedGenerateStartedToastIfNeeded(): void {
    if (currentStep.value !== 4) {
        return
    }

    try {
        const raw = window.sessionStorage.getItem(GENERATE_STARTED_TOAST_KEY)
        if (!raw) {
            return
        }
        window.sessionStorage.removeItem(GENERATE_STARTED_TOAST_KEY)
        const payload = JSON.parse(raw) as { message?: string; at?: number }
        const queuedAt = typeof payload.at === 'number' ? payload.at : 0
        if (Date.now() - queuedAt > 15000) {
            return
        }

        toast.success(payload.message ?? 'Generating started')
    } catch {
        // ignore malformed storage payload
    }
}

onMounted(() => {
    showQueuedGenerateStartedToastIfNeeded()
})

watch(
    () => currentStep.value,
    (step) => {
        if (step === 4) {
            showQueuedGenerateStartedToastIfNeeded()
        }
    },
)

async function submitAfsGenerate(overwriteExisting = false): Promise<boolean> {
    const formData = new FormData()
    formData.append('filingType', 'afs')
    if (overwriteExisting) {
        formData.append('overwriteExisting', '1')
    }
    selectedCompanyIds.value.forEach((id) => {
        formData.append('companyId[]', String(id))
        const signature = presidentSignatures.value[id]
        if (signature instanceof File) {
            formData.append(`presidentSignature[${id}]`, signature)
        }
    })
    if (getorSignature.value instanceof File) {
        formData.append('getorSignature', getorSignature.value)
    }

    const response = await fetch(props.routes.afsGenerate, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name=\"csrf-token\"]') as HTMLMetaElement | null)?.content ?? '',
        },
        body: formData,
    })

    if (response.status === 409) {
        const payload = (await response.json()) as {
            conflicts?: Array<{ company_name?: string }>
        }
        overwriteConflicts.value = (payload.conflicts ?? []).map((item) => ({
            company_name: String(item.company_name ?? ''),
        }))
        overwriteDialogOpen.value = true
        return false
    }

    return response.ok
}

async function loadGlobalGetorSignatureStatus(): Promise<boolean> {
    if (hasGlobalGetorSignature.value !== null) {
        return hasGlobalGetorSignature.value
    }

    try {
        const response = await fetch('/afs-filing/signature', {
            method: 'GET',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })

        if (!response.ok) {
            hasGlobalGetorSignature.value = false
            return false
        }

        const payload = (await response.json()) as {
            signature?: { getor?: { preview_url?: string } } | null
        }
        const hasGetor = Boolean(payload.signature?.getor?.preview_url)
        hasGlobalGetorSignature.value = hasGetor

        return hasGetor
    } catch {
        hasGlobalGetorSignature.value = false
        return false
    }
}

async function hasPersistedPresidentSignature(companyId: number): Promise<boolean> {
    if (typeof persistedPresidentSignatureAvailability.value[companyId] === 'boolean') {
        return persistedPresidentSignatureAvailability.value[companyId]
    }

    const baseUrl = props.routes.afsPresidentPreview
    const url = `${baseUrl}${baseUrl.includes('?') ? '&' : '?'}companyId=${companyId}`

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'image/*',
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
        const hasSignature = response.ok
        persistedPresidentSignatureAvailability.value[companyId] = hasSignature
        return hasSignature
    } catch {
        persistedPresidentSignatureAvailability.value[companyId] = false
        return false
    }
}

async function hasMissingPresidentSignatureForSelection(): Promise<boolean> {
    for (const companyId of selectedCompanyIds.value) {
        const uploaded = presidentSignatures.value[companyId]
        if (uploaded instanceof File) {
            continue
        }

        const hasPersisted = await hasPersistedPresidentSignature(companyId)
        if (!hasPersisted) {
            return true
        }
    }

    return false
}

async function proceedGenerateAfterWarnings() {
    isGenerating.value = true
    try {
        const queued = await submitAfsGenerate(false)
        if (!queued) return

        queueGenerateStartedToast('Your filing generation has been queued.')
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
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to start generation.')
    } finally {
        isGenerating.value = false
    }
}

async function goToStep4() {
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

    const missingPresident = await hasMissingPresidentSignatureForSelection()
    const hasGetor = await loadGlobalGetorSignatureStatus()
    const missingGetor = !hasGetor && !(getorSignature.value instanceof File)

    if (missingPresident) {
        missingPresidentWarningOpen.value = true
        return
    }

    if (missingGetor) {
        missingGetorWarningOpen.value = true
        return
    }

    await proceedGenerateAfterWarnings()
}

function onPresidentSignatureChanged(payload: { companyId: number; file: File | null }) {
    presidentSignatures.value[payload.companyId] = payload.file
}

function onGetorSignatureChanged(file: File | null) {
    getorSignature.value = file
}

async function confirmOverwriteAndGenerate() {
    overwriteDialogOpen.value = false
    isGenerating.value = true
    try {
        const queued = await submitAfsGenerate(true)
        if (!queued) return

        queueGenerateStartedToast('Overwrite confirmed. Filing generation has been queued.')
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
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to start generation.')
    } finally {
        isGenerating.value = false
    }
}

function cancelMissingPresidentWarning() {
    missingPresidentWarningOpen.value = false
}

async function continueMissingPresidentWarning() {
    missingPresidentWarningOpen.value = false
    const hasGetor = await loadGlobalGetorSignatureStatus()
    if (!hasGetor && !(getorSignature.value instanceof File)) {
        missingGetorWarningOpen.value = true
        return
    }

    await proceedGenerateAfterWarnings()
}

function cancelMissingGetorWarning() {
    missingGetorWarningOpen.value = false
}

async function continueMissingGetorWarning() {
    missingGetorWarningOpen.value = false
    await proceedGenerateAfterWarnings()
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
        <AlertDialog v-model:open="overwriteDialogOpen">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Existing generated output found</AlertDialogTitle>
                    <AlertDialogDescription>
                        Generated output already exists for:
                        {{ overwriteConflicts.map((x) => x.company_name).filter((x) => x !== '').join(', ') }}.
                        Overwrite existing outputs?
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="confirmOverwriteAndGenerate">Overwrite</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
        <AlertDialog v-model:open="missingPresidentWarningOpen">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>President signature missing</AlertDialogTitle>
                    <AlertDialogDescription>
                        One or more selected companies have no President signature image. Signatures will not be attached for those companies. Continue?
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="cancelMissingPresidentWarning">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="continueMissingPresidentWarning">Continue</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
        <AlertDialog v-model:open="missingGetorWarningOpen">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>GETOR signature missing</AlertDialogTitle>
                    <AlertDialogDescription>
                        Global GETOR signature is not configured. GETOR signature will not be attached. Continue?
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel @click="cancelMissingGetorWarning">Cancel</AlertDialogCancel>
                    <AlertDialogAction @click="continueMissingGetorWarning">Continue</AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

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
                @remove-missing-companies="onRemoveMissingCompanies"
            />

            <Step3DataCheckingAfs
                v-else-if="currentStep === 3 && props.selectedFilingType === 'afs'"
                :selected-companies="props.selectedCompanies"
                :persisted-getor-preview-url="props.routes.afsGetorPreview"
                :persisted-president-preview-url="props.routes.afsPresidentPreview"
                :is-generating="isGenerating"
                @back-to-filing-type="goToStep2"
                @generate-filing="goToStep4"
                @president-signature-changed="onPresidentSignatureChanged"
                @getor-signature-changed="onGetorSignatureChanged"
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
