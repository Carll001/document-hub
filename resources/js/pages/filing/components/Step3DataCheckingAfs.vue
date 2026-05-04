<script setup lang="ts">
import {
    ArrowLeft,
    ArrowRight,
    BadgeInfo,
    BriefcaseBusiness,
    Building2,
    Calculator,
    FileText,
    FileSpreadsheet,
    Landmark,
} from 'lucide-vue-next'
import { computed, onMounted, ref } from 'vue'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { ScrollArea } from '@/components/ui/scroll-area'
import { Spinner } from '@/components/ui/spinner'

const emit = defineEmits<{
    backToFilingType: []
    generateFiling: []
    presidentSignatureChanged: [payload: { companyId: number; file: File | null }]
    getorSignatureChanged: [file: File | null]
}>()
const props = defineProps<{
    selectedCompanies: Array<{
        id: number
        name: string
        tin: string
        address: string
        data: Record<string, string>
    }>
    persistedGetorPreviewUrl?: string | null
    persistedPresidentPreviewUrl?: string | null
    isGenerating?: boolean
}>()
const activeCompanyId = ref<number | null>(props.selectedCompanies[0]?.id ?? null)
const activeCompany = computed(() =>
    props.selectedCompanies.find((company) => company.id === activeCompanyId.value) ?? null,
)
const activeCompanyIndex = computed(() =>
    props.selectedCompanies.findIndex((company) => company.id === activeCompanyId.value),
)
const companiesPerPage = 4
const companyPage = ref(0)
const totalCompanyPages = computed(() =>
    Math.max(1, Math.ceil(props.selectedCompanies.length / companiesPerPage)),
)
const companyPages = computed(() => {
    const pages: Array<Array<typeof props.selectedCompanies[number] | null>> = []
    for (let start = 0; start < props.selectedCompanies.length; start += companiesPerPage) {
        const page = props.selectedCompanies.slice(start, start + companiesPerPage)
        while (page.length < companiesPerPage) {
            page.push(null)
        }
        pages.push(page)
    }
    return pages.length > 0 ? pages : [[null, null, null, null]]
})
const showingFrom = computed(() => (props.selectedCompanies.length === 0 ? 0 : companyPage.value * companiesPerPage + 1))
const showingTo = computed(() =>
    Math.min((companyPage.value + 1) * companiesPerPage, props.selectedCompanies.length),
)

const signatureFiles = ref<Record<number, File | null>>({})
const signaturePreviewUrls = ref<Record<number, string | null>>({})
const persistedPresidentPreviewUrls = ref<Record<number, string | null>>({})
const getorSignatureFile = ref<File | null>(null)
const getorSignaturePreviewUrl = ref<string | null>(null)
const persistedGetorPreviewUrl = ref<string | null>(null)

function hasNonEmpty(value: string | null | undefined): boolean {
    return (value ?? '').trim() !== ''
}

const canGenerate = computed(() =>
    props.selectedCompanies.every((company) => {
        const requiredValues = [
            company.name,
            company.tin,
            company.address,
            pickDataValue(company, ['president_name', 'president', 'presidentname', 'presidentsname']),
            pickDataValue(company, ['sec_registration_date', 'sec_date', 'registration_date', 'secregistrationdate']),
            pickDataValue(company, ['net_sales', 'netsales']),
            pickDataValue(company, ['cogs']),
            pickDataValue(company, ['gross_profit', 'grossprofit']),
            pickDataValue(company, ['opex']),
            pickDataValue(company, ['net_income', 'netincome']),
            pickDataValue(company, ['cash']),
            pickDataValue(company, ['trade_receivables', 'tradereceivables']),
            pickDataValue(company, ['inventory']),
            pickDataValue(company, ['total_current_assets', 'totalcurrentassets']),
            pickDataValue(company, ['operating_cash', 'operatingcash']),
            pickDataValue(company, ['cashflows', 'cash_flows']),
            pickDataValue(company, ['cash_end', 'cashend']),
            pickDataValue(company, ['pt_payable', 'ptpayable']),
            pickDataValue(company, ['payable_to_suppliers', 'payabletosuppliers']),
        ]

        return requiredValues.every((value) => hasNonEmpty(value))
    }),
)

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

function onPresidentSignatureChange(companyId: number, event: Event): void {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0] ?? null
    const existingUrl = signaturePreviewUrls.value[companyId]
    if (existingUrl) {
        URL.revokeObjectURL(existingUrl)
    }
    signatureFiles.value[companyId] = file
    signaturePreviewUrls.value[companyId] = file ? URL.createObjectURL(file) : null
    emit('presidentSignatureChanged', { companyId, file })
}

function clearPresidentSignature(companyId: number): void {
    const existingUrl = signaturePreviewUrls.value[companyId]
    if (existingUrl) {
        URL.revokeObjectURL(existingUrl)
    }
    signatureFiles.value[companyId] = null
    signaturePreviewUrls.value[companyId] = null
    emit('presidentSignatureChanged', { companyId, file: null })
}

function onGetorSignatureChange(event: Event): void {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0] ?? null
    if (getorSignaturePreviewUrl.value) {
        URL.revokeObjectURL(getorSignaturePreviewUrl.value)
    }
    getorSignatureFile.value = file
    getorSignaturePreviewUrl.value = file ? URL.createObjectURL(file) : null
    emit('getorSignatureChanged', file)
}

function clearGetorSignature(): void {
    if (getorSignaturePreviewUrl.value) {
        URL.revokeObjectURL(getorSignaturePreviewUrl.value)
    }
    getorSignatureFile.value = null
    getorSignaturePreviewUrl.value = null
    emit('getorSignatureChanged', null)
}

async function loadPersistedGetorPreview(): Promise<void> {
    if (typeof props.persistedGetorPreviewUrl === 'string' && props.persistedGetorPreviewUrl.trim() !== '') {
        persistedGetorPreviewUrl.value = `${props.persistedGetorPreviewUrl}${props.persistedGetorPreviewUrl.includes('?') ? '&' : '?'}t=${Date.now()}`
        return
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
            return
        }
        const payload = (await response.json()) as {
            signature?: { getor?: { preview_url?: string } } | null
        }
        const url = payload.signature?.getor?.preview_url
        persistedGetorPreviewUrl.value = typeof url === 'string' && url.trim() !== '' ? url : null
    } catch {
        persistedGetorPreviewUrl.value = null
    }
}

async function loadPersistedPresidentPreview(companyId: number): Promise<void> {
    const baseUrl = props.persistedPresidentPreviewUrl
    if (typeof baseUrl !== 'string' || baseUrl.trim() === '') {
        return
    }
    const url = `${baseUrl}${baseUrl.includes('?') ? '&' : '?'}companyId=${companyId}&t=${Date.now()}`

    try {
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                Accept: 'image/*',
            },
        })
        if (!response.ok) {
            persistedPresidentPreviewUrls.value[companyId] = null
            return
        }
        persistedPresidentPreviewUrls.value[companyId] = url
    } catch {
        persistedPresidentPreviewUrls.value[companyId] = null
    }
}

function goToPreviousCompanyPage(): void {
    if (companyPage.value <= 0) {
        return
    }
    companyPage.value -= 1
}

function goToNextCompanyPage(): void {
    if (companyPage.value >= totalCompanyPages.value - 1) {
        return
    }
    companyPage.value += 1
}

function ensureActiveCompanyPage(): void {
    if (activeCompanyIndex.value < 0) {
        return
    }
    const expectedPage = Math.floor(activeCompanyIndex.value / companiesPerPage)
    if (companyPage.value !== expectedPage) {
        companyPage.value = expectedPage
    }
}

onMounted(() => {
    void loadPersistedGetorPreview()
    for (const company of props.selectedCompanies) {
        void loadPersistedPresidentPreview(company.id)
    }
    ensureActiveCompanyPage()
})
</script>

<template>
    <Card class="rounded-3xl border border-slate-200 bg-white shadow-sm">
        <CardHeader>
            <CardTitle>3. Data Checking</CardTitle>
            <p class="text-sm text-muted-foreground">
                Review all extracted data below. Please verify the information before generating the filing.
            </p>
        </CardHeader>

        <CardContent class="space-y-4">
            <Card class="border-slate-200">
                <CardHeader class="pb-3">
                    <CardTitle class="text-base">Selected Companies</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="space-y-3 overflow-hidden">
                        <div class="flex items-stretch gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                class="h-[78px] self-start px-3"
                                :disabled="companyPage <= 0"
                                @click="goToPreviousCompanyPage"
                            >
                                <div class="flex flex-col items-center gap-2 text-xs">
                                    <ArrowLeft class="size-4" />
                                    <span>Previous</span>
                                </div>
                            </Button>
                            <div class="w-full">
                                <ScrollArea class="w-full whitespace-nowrap overflow-hidden">
                                    <div class="relative min-h-[82px] overflow-hidden">
                                        <div
                                            class="flex w-full transition-transform duration-300 ease-out"
                                            :style="{ transform: `translateX(-${companyPage * 100}%)` }"
                                        >
                                            <div
                                                v-for="(page, pageIndex) in companyPages"
                                                :key="`page-${pageIndex}`"
                                                class="grid min-w-full grid-cols-1 gap-2 py-1 pr-2 md:grid-cols-2 xl:grid-cols-4"
                                            >
                                                <template v-for="(company, slotIndex) in page" :key="`slot-${pageIndex}-${slotIndex}`">
                                                    <button
                                                        v-if="company"
                                                        type="button"
                                                        class="rounded-xl border px-3 py-3 text-left transition-all"
                                                        :class="
                                                            activeCompanyId === company.id
                                                                ? 'border-blue-500 bg-blue-50 text-blue-700 shadow-sm'
                                                                : 'border-slate-200 bg-white text-slate-700 hover:border-slate-300 hover:bg-slate-50'
                                                        "
                                                        @click="activeCompanyId = company.id; ensureActiveCompanyPage()"
                                                    >
                                                        <div class="flex items-start gap-2">
                                                            <Landmark class="mt-0.5 size-4 shrink-0 text-slate-400" />
                                                            <div class="min-w-0 flex-1">
                                                                <p class="truncate text-sm font-semibold uppercase leading-tight">{{ company.name }}</p>
                                                                <p class="mt-1 truncate text-xs text-slate-500">TIN: {{ company.tin }}</p>
                                                            </div>
                                                        </div>
                                                    </button>
                                                    <div
                                                        v-else
                                                        class="rounded-xl border border-transparent px-3 py-3"
                                                        aria-hidden="true"
                                                    />
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </ScrollArea>
                                <div class="mt-2 flex items-center justify-center">
                                    <p class="text-xs text-slate-500">
                                        Showing {{ showingFrom }} to {{ showingTo }} in {{ props.selectedCompanies.length }}
                                    </p>
                                </div>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                class="h-[78px] self-start px-3"
                                :disabled="companyPage >= totalCompanyPages - 1"
                                @click="goToNextCompanyPage"
                            >
                                <div class="flex flex-col items-center gap-2 text-xs">
                                    <ArrowRight class="size-4" />
                                    <span>Next</span>
                                </div>
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div v-if="activeCompany" class="space-y-4">
            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700">
                <p class="flex items-center gap-2">
                    <BadgeInfo class="size-4" />
                    You can edit any field if needed. All changes will be used when generating the filing.
                </p>
            </div>

            <div class="grid gap-4 xl:grid-cols-3">
                <Card class="border-slate-200">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Building2 class="size-4 text-blue-600" />
                            Main Identifiers
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex h-full flex-col gap-3 text-sm">
                        <div>
                            <p class="mb-1 text-slate-600">Company Name</p>
                            <Input :model-value="activeCompany.name" />
                        </div>
                        <div>
                            <p class="mb-1 text-slate-600">President's Name</p>
                            <Input :model-value="pickDataValue(activeCompany, ['president_name', 'president', 'presidentname', 'presidentsname']) || null" />
                        </div>
                        <div>
                            <p class="mb-1 text-slate-600">Company TIN</p>
                            <Input :model-value="activeCompany.tin" />
                        </div>
                        <div>
                            <p class="mb-1 text-slate-600">Company Address</p>
                            <Input :model-value="activeCompany.address" />
                        </div>
                        <div>
                            <p class="mb-1 text-slate-600">SEC Registration Date</p>
                            <Input :model-value="pickDataValue(activeCompany, ['sec_registration_date', 'sec_date', 'registration_date', 'secregistrationdate']) || null" />
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-slate-200">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Calculator class="size-4 text-blue-600" />
                            Financial Data (Core Fields)
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div class="grid grid-cols-2 items-center gap-3"><p>NET SALES</p><Input :model-value="pickDataValue(activeCompany, ['net_sales', 'netsales']) || null" /></div>
                        <div class="grid grid-cols-2 items-center gap-3"><p>COGS</p><Input :model-value="pickDataValue(activeCompany, ['cogs']) || null" /></div>
                        <div class="grid grid-cols-2 items-center gap-3"><p>GROSS PROFIT</p><Input :model-value="pickDataValue(activeCompany, ['gross_profit', 'grossprofit']) || null" /></div>
                        <div class="grid grid-cols-2 items-center gap-3"><p>OPEX</p><Input :model-value="pickDataValue(activeCompany, ['opex']) || null" /></div>
                        <div class="grid grid-cols-2 items-center gap-3"><p>NET INCOME</p><Input :model-value="pickDataValue(activeCompany, ['net_income', 'netincome']) || null" /></div>
                    </CardContent>
                </Card>
                <Card class="border-slate-200">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <FileText class="size-4 text-blue-600" />
                            Signature
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div class="space-y-2 rounded-lg border p-3">
                            <p class="text-sm font-medium">GETOR Signature Image (global, optional)</p>
                            <input
                                type="file"
                                accept=".png,.jpg,.jpeg,.webp"
                                class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                @change="onGetorSignatureChange"
                            />
                            <p v-if="getorSignatureFile" class="text-xs text-slate-500">
                                Selected: {{ getorSignatureFile.name }}
                            </p>
                            <div class="flex min-h-24 items-center justify-center rounded-md border bg-slate-50 p-2">
                                <img
                                    v-if="getorSignaturePreviewUrl || persistedGetorPreviewUrl"
                                    :src="getorSignaturePreviewUrl ?? persistedGetorPreviewUrl ?? ''"
                                    alt="GETOR signature preview"
                                    class="max-h-24 object-contain"
                                />
                                <p v-else class="text-sm text-muted-foreground">No GETOR signature uploaded yet.</p>
                            </div>
                            <div class="flex justify-end pt-2">
                                <Button
                                    v-if="getorSignatureFile"
                                    size="sm"
                                    variant="destructive"
                                    @click="clearGetorSignature"
                                >
                                    Remove GETOR Signature
                                </Button>
                            </div>
                        </div>
                        <div class="space-y-2">
                            <p class="text-slate-600">President Signature Image (optional)</p>
                            <input
                                type="file"
                                accept=".png,.jpg,.jpeg,.webp"
                                class="file:text-foreground placeholder:text-muted-foreground selection:bg-primary selection:text-primary-foreground dark:bg-input/30 border-input h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none file:inline-flex file:h-7 file:border-0 file:bg-transparent file:text-sm file:font-medium disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                                @change="onPresidentSignatureChange(activeCompany.id, $event)"
                            />
                            <p v-if="signatureFiles[activeCompany.id]" class="text-xs text-slate-500">
                                Selected: {{ signatureFiles[activeCompany.id]?.name }}
                            </p>
                        </div>
                        <div class="space-y-2 rounded-lg border p-3">
                            <p class="text-sm font-medium">Current Signature Preview</p>
                            <div class="flex min-h-24 items-center justify-center rounded-md border bg-slate-50 p-2">
                                <img
                                    v-if="signaturePreviewUrls[activeCompany.id] || persistedPresidentPreviewUrls[activeCompany.id]"
                                    :src="signaturePreviewUrls[activeCompany.id] ?? persistedPresidentPreviewUrls[activeCompany.id] ?? ''"
                                    alt="President signature preview"
                                    class="max-h-24 object-contain"
                                />
                                <p v-else class="text-sm text-muted-foreground">No signature uploaded yet.</p>
                            </div>
                        </div>
                        <div class="mt-auto flex justify-end pt-2">
                            <Button
                                v-if="signatureFiles[activeCompany.id]"
                                size="sm"
                                variant="destructive"
                                @click="clearPresidentSignature(activeCompany.id)"
                            >
                                Remove Signature
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 xl:grid-cols-3">
                <Card class="border-slate-200">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <BriefcaseBusiness class="size-4 text-blue-600" />
                            Assets & Liabilities
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>CASH</p><Input :model-value="pickDataValue(activeCompany, ['cash']) || null" />
                        </div>
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>TRADE RECEIVABLES</p><Input :model-value="pickDataValue(activeCompany, ['trade_receivables', 'tradereceivables']) || null" />
                        </div>
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>INVENTORY</p><Input :model-value="pickDataValue(activeCompany, ['inventory']) || null" />
                        </div>
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>TOTAL CURRENT ASSETS</p><Input :model-value="pickDataValue(activeCompany, ['total_current_assets', 'totalcurrentassets']) || null" />
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-slate-200">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <FileSpreadsheet class="size-4 text-blue-600" />
                            Cash Flow / Operations
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>OPERATING CASH</p>
                            <Input :model-value="pickDataValue(activeCompany, ['operating_cash', 'operatingcash']) || null" />
                        </div>
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>CASHFLOWS</p>
                            <Input :model-value="pickDataValue(activeCompany, ['cashflows', 'cash_flows']) || null" />
                        </div>
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>CASH END</p>
                            <Input :model-value="pickDataValue(activeCompany, ['cash_end', 'cashend']) || null" />
                        </div>
                    </CardContent>
                </Card>

                <Card class="border-slate-200">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Calculator class="size-4 text-blue-600" />
                            Detailed Accounting Fields
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>PT Payable</p>
                            <Input :model-value="pickDataValue(activeCompany, ['pt_payable', 'ptpayable']) || null" />
                        </div>
                        <div class="grid grid-cols-2 items-center gap-3">
                            <p>PAYABLE TO SUPPLIERS</p>
                            <Input :model-value="pickDataValue(activeCompany, ['payable_to_suppliers', 'payabletosuppliers']) || null" />
                        </div>
                    </CardContent>
                </Card>
            </div>

            <div class="flex items-center justify-between border-t pt-6">
                <Button variant="outline" class="gap-2" @click="emit('backToFilingType')">
                    <ArrowLeft class="size-4" />
                    Back
                </Button>

                <div class="flex items-center gap-2">
                    <Button class="gap-2 bg-[#2563EB] hover:bg-[#1D4ED8]" :disabled="!canGenerate || props.isGenerating" @click="emit('generateFiling')">
                        <Spinner v-if="props.isGenerating" class="size-4" />
                        <FileText v-else class="size-4" />
                        {{ props.isGenerating ? 'Generating...' : 'Generate Filing' }}
                    </Button>
                </div>
            </div>
            </div>
        </CardContent>
    </Card>
</template>
