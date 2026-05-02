<script setup lang="ts">
import {
    ArrowLeft,
    ArrowUpFromLine,
    BadgeInfo,
    BriefcaseBusiness,
    Building2,
    Calculator,
    FileSpreadsheet,
} from 'lucide-vue-next'
import { computed, ref } from 'vue'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'

const emit = defineEmits<{
    backToImport: []
    backToFilingType: []
}>()
const props = defineProps<{
    selectedCompanies: Array<{
        id: number
        name: string
        tin: string
        address: string
        data: Record<string, string>
    }>
}>()
const activeCompanyId = ref<number | null>(props.selectedCompanies[0]?.id ?? null)
const activeCompany = computed(() =>
    props.selectedCompanies.find((company) => company.id === activeCompanyId.value) ?? null,
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
                <CardContent class="space-y-2">
                    <div
                        v-for="company in props.selectedCompanies"
                        :key="company.id"
                        class="flex items-center justify-between rounded-md border p-3"
                        :class="activeCompanyId === company.id ? 'border-blue-500 bg-blue-50' : 'border-slate-200'"
                    >
                        <div>
                            <p class="font-medium">{{ company.name }}</p>
                            <p class="text-xs text-muted-foreground">TIN: {{ company.tin }}</p>
                        </div>
                        <Button size="sm" variant="outline" @click="activeCompanyId = company.id">
                            Review Data
                        </Button>
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

            <div class="grid gap-4 xl:grid-cols-2">
                <Card class="border-slate-200">
                    <CardHeader class="pb-3">
                        <CardTitle class="flex items-center gap-2 text-base">
                            <Building2 class="size-4 text-blue-600" />
                            Main Identifiers
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
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
                    <Button variant="outline" class="gap-2" @click="emit('backToImport')">
                        <ArrowUpFromLine class="size-4" />
                        Go Back to Import
                    </Button>
                    <Button class="gap-2 bg-[#2563EB] hover:bg-[#1D4ED8]">
                        Generate Filing
                    </Button>
                </div>
            </div>
            </div>
        </CardContent>
    </Card>
</template>
