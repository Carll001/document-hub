<script setup lang="ts">
import {
    ArrowDownToLine,
    ArrowLeft,
    ArrowRight,
    Check,
    Circle,
} from 'lucide-vue-next'
import { computed, ref } from 'vue'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'

const emit = defineEmits<{
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
        <CardHeader class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <CardTitle class="text-xl">Generate Filing</CardTitle>
                    <p class="mt-1 text-sm font-medium text-slate-700">Step 3 of 4: Review & Edit 1702-EX (Page 1)</p>
                    <p class="text-sm text-muted-foreground">Review and edit the information below. You can modify any field before generating the filing.</p>
                </div>
                <Button variant="outline" class="gap-2">
                    <ArrowDownToLine class="size-4" />
                    Download Template (1702-EX)
                </Button>
            </div>

            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-700">
                You can edit any field before generating the filing.
            </div>
        </CardHeader>

        <CardContent class="space-y-3">
            <section class="rounded-xl border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">SELECTED COMPANIES</p>
                <div class="space-y-2">
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
                </div>
            </section>

            <div v-if="activeCompany" class="space-y-3">
            <section class="rounded-xl border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">FILING INFORMATION</p>
                <div class="grid gap-3 md:grid-cols-4">
                    <div>
                        <p class="mb-1 text-xs text-slate-600">1. For</p>
                        <div class="flex items-center gap-3 text-sm">
                            <label class="flex items-center gap-2 text-[#2563EB]"><input checked type="radio" name="filing_for" class="accent-[#2563EB]" /> Calendar</label>
                            <label class="flex items-center gap-2 text-[#2563EB]"><input type="radio" name="filing_for" class="accent-[#2563EB]" /> Fiscal</label>
                        </div>
                        <div class="mt-2">
                            <p class="mb-1 text-xs text-slate-600">2. Year Ended (MM/20YY)</p>
                            <div class="grid grid-cols-2 gap-2">
                                <Input model-value="09" />
                                <Input model-value="24" />
                            </div>
                        </div>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-slate-600">3. Amended Return?</p>
                        <div class="flex items-center gap-3 text-sm">
                            <label class="flex items-center gap-2 text-[#2563EB]"><input type="radio" name="amended_return" class="accent-[#2563EB]" /> Yes</label>
                            <label class="flex items-center gap-2 text-[#2563EB]"><input checked type="radio" name="amended_return" class="accent-[#2563EB]" /> No</label>
                        </div>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-slate-600">4. Short Period Return?</p>
                        <div class="flex items-center gap-3 text-sm">
                            <label class="flex items-center gap-2 text-[#2563EB]"><input type="radio" name="short_period_return" class="accent-[#2563EB]" /> Yes</label>
                            <label class="flex items-center gap-2 text-[#2563EB]"><input checked type="radio" name="short_period_return" class="accent-[#2563EB]" /> No</label>
                        </div>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-slate-600">5. Alphanumeric Tax Code (ATC)</p>
                        <div class="space-y-1 text-sm">
                            <label class="flex items-center gap-2 text-[#2563EB]"><input checked type="radio" name="atc" class="accent-[#2563EB]" /> IC 011 - Exempt Corporation on Exempt Activities</label>
                            <label class="flex items-center gap-2 text-[#2563EB]"><input type="radio" name="atc" class="accent-[#2563EB]" /> IC 021 - General Professional Partnership</label>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">BACKGROUND INFORMATION</p>
                <div class="grid gap-2 sm:grid-cols-4">
                    <div class="sm:col-span-2">
                        <p class="mb-1 text-xs text-slate-600">6. Taxpayer Identification Number (TIN)</p>
                        <Input :model-value="activeCompany.tin" />
                    </div>
                    <div class="sm:col-span-2">
                        <p class="mb-1 text-xs text-slate-600">7. RDO Code</p>
                        <Input :model-value="pickDataValue(activeCompany, ['rdo_code', 'rdo']) || null" />
                    </div>
                    

                    <div class="sm:col-span-4">
                        <p class="mb-1 text-xs text-slate-600">8. Registered Name</p>
                        <Input :model-value="activeCompany.name" />
                    </div>
                    

                    <div class="sm:col-span-3">
                        <p class="mb-1 text-xs text-slate-600">9. Registered Address</p>
                        <textarea class="min-h-[66px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm">{{ activeCompany.address }}</textarea>
                    </div>
                    <div>
                        <p class="mb-1 text-xs text-slate-600">9A. Zip Code</p>
                        <Input :model-value="pickDataValue(activeCompany, ['zip_code', 'zipcode', 'zip']) || null" />
                    </div>

                    <div class="sm:col-span-2">
                        <p class="mb-1 text-xs text-slate-600">10. Date of Incorporation</p>
                        <div class="grid grid-cols-3 gap-2">
                            <Input model-value="07" />
                            <Input model-value="04" />
                            <Input model-value="2024" />
                        </div>
                    </div>
                    <div class="sm:col-span-2">
                        <p class="mb-1 text-xs text-slate-600">11. Contact Number</p>
                        <Input :model-value="pickDataValue(activeCompany, ['contact_number', 'contact', 'phone']) || null" />
                    </div>
                    
                    <div class="sm:col-span-4 sm:col-start-1">
                        <p class="mb-1 text-xs text-slate-600">12. Email Address</p>
                        <Input :model-value="pickDataValue(activeCompany, ['email_address', 'email']) || null" />
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">METHOD OF DEDUCTIONS</p>
                <div class="mb-3 flex flex-wrap items-center gap-5 text-sm">
                    <label class="flex items-center gap-2"><Circle class="size-4 text-[#2563EB] fill-[#2563EB]" /> Itemized Deduction</label>
                    <label class="flex items-center gap-2"><Circle class="size-4 text-slate-300" /> Optional Standard Deduction (OSD)</label>
                </div>
                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <div><p class="mb-1 text-xs text-slate-600">14. Legal Basis of Tax Relief</p><Input model-value="SEC. 30 - Exempt Corporation" /></div>
                    <div><p class="mb-1 text-xs text-slate-600">15. Investment Promotion Agency</p><Input model-value="N/A" /></div>
                    <div><p class="mb-1 text-xs text-slate-600">16. Registered Activity / Program</p><Input model-value="N/A" /></div>
                    <div>
                        <p class="mb-1 text-xs text-slate-600">17. Effectivity Date</p>
                        <div class="space-y-2">
                            <div>
                                <p class="mb-1 text-[11px] text-slate-500">From (MM/DD/YYYY)</p>
                                <div class="grid grid-cols-3 gap-2">
                                    <Input model-value="07" />
                                    <Input model-value="04" />
                                    <Input model-value="2024" />
                                </div>
                            </div>
                            <div>
                                <p class="mb-1 text-[11px] text-slate-500">To (MM/DD/YYYY)</p>
                                <div class="grid grid-cols-3 gap-2">
                                    <Input model-value="MM" />
                                    <Input model-value="DD" />
                                    <Input model-value="YYYY" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">PART II - TOTAL TAX PAYABLE</p>
                <div class="space-y-2 text-sm">
                    <div class="grid grid-cols-[1fr_220px] items-center gap-3"><p>18 Tax Due</p><Input model-value="3,255,000" /></div>
                    <div class="grid grid-cols-[1fr_220px] items-center gap-3"><p>19 Less: Tax Credits</p><Input model-value="1,800,000" /></div>
                    <div class="grid grid-cols-[1fr_220px] items-center gap-3"><p>20 Total (Overpayment)</p><Input model-value="1,455,000" /></div>
                    <div class="grid grid-cols-[1fr_220px] items-center gap-3"><p>21 Add: Penalty</p><Input model-value="0" /></div>
                    <div class="grid grid-cols-[1fr_220px] items-center gap-3 border-t pt-2">
                        <p class="text-base font-semibold">22 TOTAL AMOUNT PAYABLE / (Overpayment) <span class="text-xs font-normal text-slate-500">(Sum of Items 20 &amp; 21)</span></p>
                        <Input class="font-semibold text-[#2563EB]" model-value="1,455,000" />
                    </div>
                </div>
                <p class="mt-3 text-sm text-slate-600">
                    If overpayment, mark one (1) box only. (Once the choice is made, the same is irrevocable)
                </p>
                <div class="mt-2 flex flex-wrap gap-5 text-sm">
                    <label class="flex items-center gap-2"><Circle class="size-4 text-[#2563EB] fill-[#2563EB]" /> To be refunded</label>
                    <label class="flex items-center gap-2"><Circle class="size-4 text-slate-300" /> To be issued TCC</label>
                    <label class="flex items-center gap-2"><Circle class="size-4 text-slate-300" /> To be carried over</label>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 p-3">
                <div class="rounded-md bg-slate-50 p-3 text-xs leading-relaxed text-slate-600">
                    We declare under the penalties of perjury that this return and all its attachments have been made in good faith,
                    verified by us, and to the best of our knowledge and belief, are true and correct.
                </div>
                <div class="mt-3 grid gap-3 lg:grid-cols-3">
                    <div class="grid gap-2">
                        <div><p class="mb-1 text-xs text-slate-600">Signature over Printed Name</p><Input model-value="Juan Dela Cruz" /></div>
                        <div><p class="mb-1 text-xs text-slate-600">Title of Signatory</p><Input model-value="President" /></div>
                        <div><p class="mb-1 text-xs text-slate-600">TIN</p><Input model-value="010-123-456-00000" /></div>
                    </div>
                    <div class="grid gap-2">
                        <div><p class="mb-1 text-xs text-slate-600">Signature over Printed Name</p><Input model-value="Jose Garcia" /></div>
                        <div><p class="mb-1 text-xs text-slate-600">Title of Signatory</p><Input model-value="Treasurer" /></div>
                        <div><p class="mb-1 text-xs text-slate-600">TIN</p><Input model-value="010-987-654-00000" /></div>
                    </div>
                    <div><p class="mb-1 text-xs text-slate-600">23. Number of Attachments</p><Input model-value="5" /></div>
                </div>
            </section>

            <section class="rounded-xl border border-slate-200 p-3">
                <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">PART III - DETAILS OF PAYMENT</p>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[860px] border-collapse text-sm">
                        <thead>
                            <tr class="bg-slate-50">
                                <th class="border p-2 text-left">Particulars</th>
                                <th class="border p-2 text-left">Drawee Bank / Agency</th>
                                <th class="border p-2 text-left">Number</th>
                                <th class="border p-2 text-left">Date</th>
                                <th class="border p-2 text-left">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td class="border p-2">Cash / Bank Debit Memo</td><td class="border p-2"><Input /></td><td class="border p-2"><Input /></td><td class="border p-2"><div class="grid grid-cols-3 gap-1"><Input model-value="MM" /><Input model-value="DD" /><Input model-value="YYYY" /></div></td><td class="border p-2"><Input /></td></tr>
                            <tr><td class="border p-2">Check</td><td class="border p-2"><Input /></td><td class="border p-2"><Input /></td><td class="border p-2"><div class="grid grid-cols-3 gap-1"><Input model-value="MM" /><Input model-value="DD" /><Input model-value="YYYY" /></div></td><td class="border p-2"><Input /></td></tr>
                            <tr><td class="border p-2">Tax Debit Memo</td><td class="border p-2"><Input /></td><td class="border p-2"><Input /></td><td class="border p-2"><div class="grid grid-cols-3 gap-1"><Input model-value="MM" /><Input model-value="DD" /><Input model-value="YYYY" /></div></td><td class="border p-2"><Input /></td></tr>
                            <tr><td class="border p-2">Others</td><td class="border p-2"><Input /></td><td class="border p-2"><Input /></td><td class="border p-2"><div class="grid grid-cols-3 gap-1"><Input model-value="MM" /><Input model-value="DD" /><Input model-value="YYYY" /></div></td><td class="border p-2"><Input /></td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="flex items-center justify-between border-t pt-4">
                <Button variant="outline" class="gap-2" @click="emit('backToFilingType')">
                    <ArrowLeft class="size-4" />
                    Back
                </Button>
                <div class="flex items-center gap-2">
                    <Button variant="outline">Save as Draft</Button>
                    <Button class="gap-2 bg-[#2563EB] hover:bg-[#1D4ED8]">
                        Next: Page 2
                        <ArrowRight class="size-4" />
                    </Button>
                </div>
            </div>
            </div>
        </CardContent>
    </Card>
</template>
