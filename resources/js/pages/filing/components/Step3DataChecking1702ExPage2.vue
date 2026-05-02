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
                PART IV - COMPUTATION OF TAX
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
                            <TableCell>28</TableCell>
                            <TableCell>Sales / Receipts / Revenues / Fees</TableCell>
                            <TableCell>
                                <Input :model-value="value(['salesreceiptsrevenuesfees'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>29</TableCell>
                            <TableCell>Less: Sales Returns, Allowances and Discounts</TableCell>
                            <TableCell>
                                <Input :model-value="value(['salesreturnsallowancesdiscounts'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">30</TableCell>
                            <TableCell class="font-semibold">
                                Net Sales / Receipts / Revenues / Fees (Item 28 less Item 29)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['netsalesreceiptsrevenuesfees'])" class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>31</TableCell>
                            <TableCell>Less: Cost of Sales / Services</TableCell>
                            <TableCell>
                                <Input :model-value="value(['costofsalesservices'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">32</TableCell>
                            <TableCell class="font-semibold">
                                Gross Income from Operation (Item 30 Less Item 31)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['grossincomefromoperation'])" class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>33</TableCell>
                            <TableCell>Add: Other Income</TableCell>
                            <TableCell>
                                <Input :model-value="value(['otherincome'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">34</TableCell>
                            <TableCell class="font-semibold">
                                Total Gross Income (Sum of Items 32 and 33)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['totalgrossincome'])" class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell colspan="3" class="bg-blue-50 font-semibold text-[#2563EB]">
                                Less: Deductions Allowable under Existing Law
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell colspan="3" class="bg-slate-50 font-semibold">
                                A. Itemized Deduction
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>35</TableCell>
                            <TableCell>
                                Ordinary Allowable Itemized Deductions (From Part VI Schedule I Item 18)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['ordinaryallowableitemizeddeductions'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>36</TableCell>
                            <TableCell>
                                Special Allowable Itemized Deductions (From Part VI Schedule II Item 5)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['specialallowableitemizeddeductions'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">37</TableCell>
                            <TableCell class="font-semibold">
                                Total Itemized Deductions (Sum of Items 35 and 36)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['totalitemizeddeductions'])" class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell colspan="3" class="bg-slate-50 font-semibold">
                                B. Optional Standard Deduction (OSD)
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>38</TableCell>
                            <TableCell>
                                OSD (40% of Item 34) (applicable to GPP per RA No. 10963)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['osd'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">39</TableCell>
                            <TableCell class="font-semibold">
                                Net Taxable Income / (Loss)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['nettaxableincomeloss'])" class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>40</TableCell>
                            <TableCell>Tax Rate %</TableCell>
                            <TableCell>
                                <Input :model-value="value(['taxrate'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">41</TableCell>
                            <TableCell class="font-semibold">
                                Tax Due (Item 39 x Item 40) (To Part II Item 18)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['taxdue'])" class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">
                TAX CREDITS / PAYMENTS
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
                            <TableCell>42</TableCell>
                            <TableCell>Prior Year's Excess Credits</TableCell>
                            <TableCell>
                                <Input :model-value="value(['prioryearsexcesscredits'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>43</TableCell>
                            <TableCell>Income Tax Payment from Previous Quarter/s</TableCell>
                            <TableCell>
                                <Input :model-value="value(['incometaxpaymentpreviousquarters'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>44</TableCell>
                            <TableCell>
                                Creditable Tax Withheld from Previous Quarter/s per BIR Form No. 2307
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['creditabletaxwithheldpreviousquarters'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>45</TableCell>
                            <TableCell>
                                Creditable Tax Withheld per BIR Form No. 2307 for the 4th Quarter
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['creditabletaxwithheldfourthquarter'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>46</TableCell>
                            <TableCell>Foreign Tax Credits, if applicable</TableCell>
                            <TableCell>
                                <Input :model-value="value(['foreigntaxcredits'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>47</TableCell>
                            <TableCell>Tax Paid in Return Previously Filed, if this is an Amended Return</TableCell>
                            <TableCell>
                                <Input :model-value="value(['taxpaidreturnpreviouslyfiled'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>48</TableCell>
                            <TableCell>Other Tax Credits / Payments</TableCell>
                            <TableCell>
                                <Input :model-value="value(['othertaxcreditspayments1amount'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow>
                            <TableCell>49</TableCell>
                            <TableCell>Other Tax Credits / Payments</TableCell>
                            <TableCell>
                                <Input :model-value="value(['othertaxcreditspayments2amount'])" class="text-right" />
                            </TableCell>
                        </TableRow>

                        <TableRow class="bg-slate-50">
                            <TableCell class="font-semibold">50</TableCell>
                            <TableCell class="font-semibold">
                                Total Tax Credits / Payments (Sum of Items 42 to 49) (To Part II Item 19)
                            </TableCell>
                            <TableCell>
                                <Input :model-value="value(['taxcredits'])" class="text-right font-semibold" />
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <section class="rounded-xl border border-blue-200 bg-blue-50 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">
                TOTAL OVERPAYMENT
            </p>

            <div class="grid grid-cols-1 gap-3 md:grid-cols-[1fr_260px] md:items-center">
                <p class="font-semibold text-slate-800">
                    51 Total (Overpayment) (Item 41 Less Item 50) (To Part II Item 20)
                </p>
                <Input
                    :model-value="value(['overpayment'])"
                    class="text-right text-lg font-bold text-[#2563EB]"
                />
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">
                PART V - TAX RELIEF AVAILMENT
            </p>

            <div class="grid gap-2 sm:grid-cols-3">
                <div>
                    <p class="mb-1 text-xs text-slate-600">
                        52 Regular Income Tax Otherwise Due (Item 39 of Part IV x Applicable Income Tax Rate)
                    </p>
                    <Input :model-value="value(['regularincometaxotherwisedue'])" class="text-right" />
                </div>

                <div>
                    <p class="mb-1 text-xs text-slate-600">
                        53 Special Allowable Itemized Deductions (Item 36 of Part IV x Applicable Income Tax Rate)
                    </p>
                    <Input :model-value="value(['specialallowableitemizeddeductionstaxrelief'])" class="text-right" />
                </div>

                <div>
                    <p class="mb-1 text-xs text-slate-600">
                        54 Total Tax Relief Availment (Sum of Items 52 and 53)
                    </p>
                    <Input :model-value="value(['totaltaxreliefavailment'])" class="text-right font-semibold" />
                </div>
            </div>
        </section>
    </div>
</template>