<script setup lang="ts">
import { Input } from '@/components/ui/input'
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table'

type CompanyData = {
    id: number
    name: string
    tin: string
    address: string
    data: Record<string, string>
}

const props = defineProps<{
    activeCompany: CompanyData
    pickDataValue: (company: CompanyData, aliases: string[]) => string
}>()

function value(aliases: string[]): string {
    return props.pickDataValue(props.activeCompany, aliases) || ''
}
</script>

<template>
    <div class="space-y-3">
        <section class="rounded-xl border border-slate-200 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">
                PART VI - SCHEDULES
            </p>

            <div class="grid gap-2 sm:grid-cols-2">
                <div>
                    <p class="mb-1 text-xs text-slate-600">Tax Identification Number (TIN)</p>
                    <Input :model-value="activeCompany.tin" />
                </div>

                <div>
                    <p class="mb-1 text-xs text-slate-600">Registered Name</p>
                    <Input :model-value="activeCompany.name" />
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">
                SCHEDULE 1 - ORDINARY ALLOWABLE ITEMIZED DEDUCTIONS
            </p>

            <div class="overflow-x-auto rounded-lg border">
                <Table class="min-w-[900px]">
                    <TableHeader>
                        <TableRow class="bg-slate-50">
                            <TableHead class="w-[80px]">Item</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead class="w-[260px] text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <TableRow>
                            <TableCell>1</TableCell>
                            <TableCell>Amortizations</TableCell>
                            <TableCell><Input :model-value="value(['schedule1amortizations'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>2</TableCell>
                            <TableCell>Bad Debts</TableCell>
                            <TableCell><Input :model-value="value(['schedule1baddebts'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>3</TableCell>
                            <TableCell>Charitable and Contributions</TableCell>
                            <TableCell><Input :model-value="value(['schedule1charitablecontributions'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>4</TableCell>
                            <TableCell>Depletion</TableCell>
                            <TableCell><Input :model-value="value(['schedule1depletion'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>5</TableCell>
                            <TableCell>Depreciation</TableCell>
                            <TableCell><Input :model-value="value(['schedule1depreciation'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>6</TableCell>
                            <TableCell>Entertainment, Amusement and Recreation</TableCell>
                            <TableCell><Input :model-value="value(['schedule1entertainmentamusementrecreation'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>7</TableCell>
                            <TableCell>Fringe Benefits</TableCell>
                            <TableCell><Input :model-value="value(['schedule1fringebenefits'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>8</TableCell>
                            <TableCell>Interest</TableCell>
                            <TableCell><Input :model-value="value(['schedule1interest'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>9</TableCell>
                            <TableCell>Losses</TableCell>
                            <TableCell><Input :model-value="value(['schedule1losses'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>10</TableCell>
                            <TableCell>Pension Trusts</TableCell>
                            <TableCell><Input :model-value="value(['schedule1pensiontrusts'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>11</TableCell>
                            <TableCell>Rental</TableCell>
                            <TableCell><Input :model-value="value(['schedule1rental'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>12</TableCell>
                            <TableCell>Research and Development</TableCell>
                            <TableCell><Input :model-value="value(['schedule1researchdevelopment'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>13</TableCell>
                            <TableCell>Salaries, Wages, and Allowances</TableCell>
                            <TableCell><Input :model-value="value(['schedule1salarieswagesallowances'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>14</TableCell>
                            <TableCell>SSS, GSIS, Philhealth, HDMF, and Other Contributions</TableCell>
                            <TableCell><Input :model-value="value(['schedule1sssgsisphilhealthhdmfothercontributions'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>15</TableCell>
                            <TableCell>Taxes and Licenses</TableCell>
                            <TableCell><Input :model-value="value(['schedule1taxesandlicenses'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>16</TableCell>
                            <TableCell>Transportation and Travel</TableCell>
                            <TableCell><Input :model-value="value(['schedule1transportationandtravel'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell colspan="3" class="bg-blue-50 font-semibold text-[#2563EB]">
                                17 Others (Deductions Subject to Withholding Tax and Other Expenses)
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>17d</TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otherddescription'])" placeholder="Specify..." />
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otherdamount'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>17e</TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otheredescription'])" placeholder="Specify..." />
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1othereamount'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>17f</TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otherfdescription'])" placeholder="Specify..." />
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otherfamount'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>17g</TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1othergdescription'])" placeholder="Specify..." />
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1othergamount'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>17h</TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otherhdescription'])" placeholder="Specify..." />
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otherhamount'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>17i</TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otheridescription'])" placeholder="Specify..." />
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1otheriamount'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">18</TableCell>
                            <TableCell class="font-semibold">
                                Total Ordinary Allowable Itemized Deductions (Sum of Items 1 to 17i)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule1totalordinaryallowableitemizeddeductions'])"
                                    class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">
                SCHEDULE 2 - SPECIAL ALLOWABLE ITEMIZED DEDUCTIONS
            </p>

            <div class="overflow-x-auto rounded-lg border">
                <Table class="min-w-[900px]">
                    <TableHeader>
                        <TableRow class="bg-slate-50">
                            <TableHead class="w-[80px]">Item</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead>Legal Basis</TableHead>
                            <TableHead class="w-[260px] text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <TableRow v-for="item in [1, 2, 3, 4]" :key="item">
                            <TableCell>{{ item }}</TableCell>
                            <TableCell><Input /></TableCell>
                            <TableCell><Input /></TableCell>
                            <TableCell>
                                <Input :model-value="value([`schedule2item${item}amount`])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">5</TableCell>
                            <TableCell colspan="2" class="font-semibold">
                                Total Special Allowable Itemized Deductions (Sum of Items 1 to 4)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule2totalspecialallowableitemizeddeductions'])"
                                    class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">
                SCHEDULE 3 - RECONCILIATION OF NET INCOME PER BOOKS AGAINST TAXABLE INCOME
            </p>

            <div class="overflow-x-auto rounded-lg border">
                <Table class="min-w-[900px]">
                    <TableHeader>
                        <TableRow class="bg-slate-50">
                            <TableHead class="w-[80px]">Item</TableHead>
                            <TableHead>Description</TableHead>
                            <TableHead class="w-[260px] text-right">Amount</TableHead>
                        </TableRow>
                    </TableHeader>

                    <TableBody>
                        <TableRow>
                            <TableCell>1</TableCell>
                            <TableCell>Net Income / (Loss) per Books</TableCell>
                            <TableCell><Input :model-value="value(['schedule3netincomelossperbooks'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell colspan="3" class="bg-blue-50 font-semibold text-[#2563EB]">
                                Add: Non-Deductible Expenses / Other Income
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>2</TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule3addnondeductible1description'])" />
                            </TableCell>
                            <TableCell><Input :model-value="value(['schedule3addnondeductible1amount'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>3</TableCell>
                            <TableCell><Input /></TableCell>
                            <TableCell><Input :model-value="value(['schedule3addnondeductible2amount'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">4</TableCell>
                            <TableCell class="font-semibold">Total (Sum of Items 1 to 3)</TableCell>
                            <TableCell><Input :model-value="value(['schedule3totalitems1to3'])"
                                    class="text-right font-semibold" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell colspan="3" class="bg-blue-50 font-semibold text-[#2563EB]">
                                Less: A) Non-Taxable Income and Income Subjected to Final Tax
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>5</TableCell>
                            <TableCell><Input /></TableCell>
                            <TableCell><Input
                                    :model-value="value(['schedule3lessnontaxableincomesubjectfinaltaxamount'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>6</TableCell>
                            <TableCell><Input /></TableCell>
                            <TableCell><Input
                                    :model-value="value(['schedule3lessnontaxableincomesubjectfinaltaxotheramount'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell colspan="3" class="bg-blue-50 font-semibold text-[#2563EB]">
                                B) Special Deductions
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>7</TableCell>
                            <TableCell><Input /></TableCell>
                            <TableCell><Input :model-value="value(['schedule3specialdeductionsamount'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>8</TableCell>
                            <TableCell><Input /></TableCell>
                            <TableCell><Input :model-value="value(['schedule3specialdeductionsotheramount'])"
                                    class="text-right" /></TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">9</TableCell>
                            <TableCell class="font-semibold">Total (Sum of Items 5 to 8)</TableCell>
                            <TableCell><Input :model-value="value(['schedule3totalitems5to8'])"
                                    class="text-right font-semibold" /></TableCell>
                        </TableRow>

                        <TableRow class="bg-blue-50">
                            <TableCell class="font-semibold">10</TableCell>
                            <TableCell class="font-semibold">
                                Net Taxable Income / (Loss) (Item 4 Less Item 9)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['schedule3nettaxableincomeloss'])"
                                    class="text-right font-bold text-[#2563EB]" />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>
    </div>
</template>