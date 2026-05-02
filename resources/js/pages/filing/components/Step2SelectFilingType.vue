<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowLeft, ArrowRight, Building2, Check, FileSpreadsheet, ListChecks } from 'lucide-vue-next'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { ScrollArea, ScrollBar } from '@/components/ui/scroll-area'
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog'

const props = withDefaults(defineProps<{
    selectedCompanyIds: number[]
    selectedCompanies: Array<{
        id: number
        name: string
        tin: string
        address: string
        data: Record<string, string>
    }>
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
}>(), {
    selectedCompanies: () => [],
})

const emit = defineEmits<{
    back: []
    next: [filingType: 'afs' | '1702ex']
}>()

const selectedType = ref<'afs' | '1702ex'>(props.initialFilingType ?? 'afs')
const showMissingDialog = ref(false)

function hasNonEmpty(value: string | null | undefined): boolean {
    return (value ?? '').trim() !== ''
}

function pickDataValue(company: { data: Record<string, string> }, aliases: string[]): string {
    const entries = Object.entries(company.data ?? {})
    const normalized = entries.map(([key, value]) => ({
        key: key.trim().toLowerCase(),
        value,
    }))

    for (const alias of aliases) {
        const target = alias.trim().toLowerCase()
        const hit = normalized.find((entry) => entry.key === target)
        if (hit && String(hit.value).trim() !== '') {
            return String(hit.value)
        }
    }

    return ''
}

const afsMissingCompanies = computed(() =>
    (props.selectedCompanies ?? [])
        .map((company) => {
            const missing: string[] = []
            const requireField = (label: string, aliases: string[], fallback?: string) => {
                const fromData = pickDataValue(company, aliases)
                if (!hasNonEmpty(fromData) && !hasNonEmpty(fallback)) {
                    missing.push(label)
                }
            }

            requireField('President’s Name', ['president_name', 'president', 'presidentname', 'presidentsname'])
            requireField('Company TIN', ['tin', 'company_tin', 'companytin'], company.tin)
            requireField('Company Address', ['company_address', 'address', 'registered_address'], company.address)
            requireField('NET SALES', ['net_sales', 'netsales'])
            requireField('COGS', ['cogs'])
            requireField('GROSS PROFIT', ['gross_profit', 'grossprofit'])
            requireField('OPEX', ['opex'])
            requireField('NET INCOME', ['net_income', 'netincome'])
            requireField('SHARE CAPITAL', ['share_capital', 'sharecapital'])
            requireField('SHE', ['she'])
            requireField('CASH', ['cash'])
            requireField('TRADE RECEIVABLES', ['trade_receivables', 'tradereceivables'])
            requireField('INVENTORY', ['inventory'])
            requireField('TOTAL CURRENT ASSETS', ['total_current_assets', 'totalcurrentassets'])
            requireField('TRADE PAYABLE', ['trade_payable', 'tradepayable'])
            requireField('TOTAL LIAB AND SHE', ['total_liab_and_she', 'totalliabandshe'])
            requireField('PT Payable', ['pt_payable', 'ptpayable'])
            requireField('PAYABLE TO SUPPLIERS', ['payable_to_suppliers', 'payabletosuppliers'])
            requireField('OPERATING CASH', ['operating_cash', 'operatingcash'])
            requireField('CASHFLOWS', ['cashflows', 'cash_flows'])
            requireField('CASH END', ['cash_end', 'cashend'])
            requireField('SEC Registration Date', ['sec_registration_date', 'sec_date', 'registration_date', 'secregistrationdate'])
            requireField('CIB', ['cib'])
            requireField('COH', ['coh'])
            requireField('Net Sales', ['net_sales', 'netsales'])
            requireField('Purchases', ['purchases'])
            requireField('TGAS', ['tgas'])
            requireField('Inventory', ['inventory'])
            requireField('Marketing', ['marketing'])
            requireField('Outside Services', ['outside_services', 'outsideservices'])
            requireField('Communication', ['communication'])
            requireField('Tax', ['tax', 'taxes'])
            requireField('Travel', ['travel'])
            requireField('Supplies', ['supplies'])
            requireField('Opex', ['opex'])
            requireField('Trade Receivables', ['trade_receivables', 'tradereceivables'])
            requireField('Trade Payable', ['trade_payable', 'tradepayable'])

            return {
                id: company.id,
                name: hasNonEmpty(company.name) ? company.name : `Company #${company.id}`,
                missing,
            }
        })
        .filter((company) => company.missing.length > 0),
)

const exMissingCompanies = computed(() =>
    (props.selectedCompanies ?? [])
        .map((company) => {
            const missing: string[] = []
            if (!hasNonEmpty(company.name)) missing.push('Company Name')
            if (!hasNonEmpty(company.tin)) missing.push('TIN')

            return {
                id: company.id,
                name: hasNonEmpty(company.name) ? company.name : `Company #${company.id}`,
                missing,
            }
        })
        .filter((company) => company.missing.length > 0),
)

const selectedTypeHasMissing = computed(() => {
    if (selectedType.value === 'afs') return afsMissingCompanies.value.length > 0
    return exMissingCompanies.value.length > 0
})

const selectedMissingCompanies = computed(() =>
    selectedType.value === 'afs' ? afsMissingCompanies.value : exMissingCompanies.value,
)

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

    return Boolean(option?.hasTemplate) && !selectedTypeHasMissing.value
})

if (!canProceed.value) {
    const firstAvailable = options.value.find((item) => item.hasTemplate)
    if (firstAvailable) {
        selectedType.value = firstAvailable.id
    }
}
</script>

<template>
    <AlertDialog :open="showMissingDialog" @update:open="showMissingDialog = $event">
        <AlertDialogContent>
            <AlertDialogHeader>
                <AlertDialogTitle>Missing required data</AlertDialogTitle>
                <AlertDialogDescription>
                    Complete these fields before proceeding to the next step.
                </AlertDialogDescription>
            </AlertDialogHeader>

            <div class="max-h-64 space-y-2 overflow-y-auto text-sm text-slate-700">
                <p v-for="company in selectedMissingCompanies" :key="`missing-${company.id}`">
                    <span class="font-semibold">{{ company.name }}</span>: {{ company.missing.join(', ') }}
                </p>
            </div>
            <div class="rounded-md border border-blue-200 bg-blue-50 p-3 text-xs text-blue-800">
                Note: You can add or complete required data using spreadsheet import or manual entry.
            </div>

            <AlertDialogFooter>
                <AlertDialogAction @click="showMissingDialog = false">
                    OK
                </AlertDialogAction>
            </AlertDialogFooter>
        </AlertDialogContent>
    </AlertDialog>

    <Card class="rounded-3xl border border-slate-200 bg-white shadow-sm">
        <CardHeader>
            <CardTitle>Step 2: Select Filing Type</CardTitle>
            <p class="text-sm font-normal text-muted-foreground">
                Choose the type of filing you want to generate.
            </p>
        </CardHeader>
        <CardContent class="space-y-4">
            <ScrollArea class="w-full">
                <div class="flex items-stretch gap-4 pb-3">
                    <button
                        v-for="option in options"
                        :key="option.id"
                        type="button"
                        class="relative flex h-full min-h-[15rem] w-[20rem] shrink-0 flex-col rounded-2xl border p-4 text-left transition-all sm:w-[22rem]"
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

                        <div class="mb-3 flex items-center gap-3">
                            <span
                                class="inline-flex size-10 items-center justify-center rounded-full"
                                :class="{
                                    'bg-blue-100 text-blue-700': option.accent === 'blue',
                                    'bg-emerald-100 text-emerald-700': option.accent === 'emerald',
                                }"
                            >
                                <component :is="option.icon" class="size-5" />
                            </span>

                            <h3 class="text-lg font-semibold text-slate-900">
                                {{ option.title }}
                            </h3>
                        </div>

                        <span
                            v-if="option.badge"
                            class="mt-3 inline-flex rounded-full bg-blue-100 px-3 py-1 text-xs font-semibold text-blue-700"
                        >
                            {{ option.badge }}
                        </span>

                        <p class="mt-2 min-h-[4rem] text-sm leading-relaxed text-slate-600">
                            {{ option.description }}
                        </p>

                        <div class="mt-3 flex min-h-[3.5rem] items-start gap-2 text-slate-600">
                            <ListChecks class="mt-0.5 size-4 shrink-0 text-blue-600" />
                            <p class="text-sm leading-relaxed">
                                {{ option.detail }}
                            </p>
                        </div>

                        <p class="mt-3 text-xs font-medium" :class="option.hasTemplate ? 'text-emerald-600' : 'text-amber-600'">
                            {{ option.hasTemplate ? 'Template ready' : 'Template not set' }}: {{ option.ownerLabel }}
                        </p>

                        <div class="mt-3 min-h-[2.75rem]">
                            <div
                                v-if="option.id === 'afs' && afsMissingCompanies.length > 0"
                                class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                            >
                                <p class="font-semibold">Selected companies with missing data</p>
                                <Button size="sm" variant="outline" class="h-7 px-2 text-xs" @click.stop="selectedType = 'afs'; showMissingDialog = true">
                                    View
                                </Button>
                            </div>

                            <div
                                v-else-if="option.id === '1702ex' && exMissingCompanies.length > 0"
                                class="flex items-center justify-between rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800"
                            >
                                <p class="font-semibold">Selected companies with missing data</p>
                                <Button size="sm" variant="outline" class="h-7 px-2 text-xs" @click.stop="selectedType = '1702ex'; showMissingDialog = true">
                                    View
                                </Button>
                            </div>
                        </div>
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
