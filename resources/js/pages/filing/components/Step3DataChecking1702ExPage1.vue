<script setup lang="ts">
import { Input } from '@/components/ui/input'

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
</script>

<template>
    <div class="space-y-3">
        <section class="rounded-xl border border-slate-200 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">FILING INFORMATION</p>
            <div class="grid gap-3 md:grid-cols-4">
                <div>
                    <p class="mb-1 text-xs text-slate-600">1. For</p>
                    <div class="flex items-center gap-3 text-sm">
                        <input type="radio" name="filing_for" class="accent-[#2563EB]"
                            :checked="props.pickDataValue(props.activeCompany, ['filingperiod']) === 'CALENDAR'" />
                        Calendar
                        <input type="radio" name="filing_for" class="accent-[#2563EB]"
                            :checked="props.pickDataValue(props.activeCompany, ['filingperiod']) === 'FISCAL'" />
                        Fiscal
                    </div>
                    <div class="mt-2">
                        <p class="mb-1 text-xs text-slate-600">2. Year Ended (MM/20YY)</p>
                        <div class="grid grid-cols-2 gap-2">
                            <Input
                                :model-value="props.pickDataValue(props.activeCompany, ['yearmonth']).split('/')[1] ?? ''" />
                            <Input
                                :model-value="props.pickDataValue(props.activeCompany, ['yearmonth']).split('/')[0] ?? ''" />
                        </div>
                    </div>
                </div>
                <div>
                    <p class="mb-1 text-xs text-slate-600">3. Amended Return?</p>
                    <div class="flex items-center gap-3 text-sm">
                        <input type="radio" name="amended_return" class="accent-[#2563EB]"
                            :checked="props.pickDataValue(props.activeCompany, ['amendedreturn']) === 'YES'" />
                        Yes
                        <input type="radio" name="amended_return" class="accent-[#2563EB]"
                            :checked="props.pickDataValue(props.activeCompany, ['amendedreturn']) === 'NO'" />
                        No
                    </div>
                </div>
                <div>
                    <p class="mb-1 text-xs text-slate-600">4. Short Period Return?</p>
                    <div class="flex items-center gap-3 text-sm">
                        <input type="radio" name="short_period_return" class="accent-[#2563EB]"
                            :checked="props.pickDataValue(props.activeCompany, ['shortperiodreturn']).toUpperCase() === 'YES'" />
                        Yes
                        <input type="radio" name="short_period_return" class="accent-[#2563EB]"
                            :checked="props.pickDataValue(props.activeCompany, ['shortperiodreturn']).toUpperCase() === 'NO'" />
                        No
                    </div>
                </div>
                <div>
                    <p class="mb-1 text-xs text-slate-600">5. Alphanumeric Tax Code (ATC)</p>
                    <div class="space-y-1 text-sm">
                        <p>
                            <input type="radio" name="atc" class="accent-[#2563EB]"
                                :checked="props.pickDataValue(props.activeCompany, ['atc']) === 'IC 011'" />
                            IC 011 - Exempt Corporation on Exempt Activities
                        </p>
                        <p>
                            <input type="radio" name="atc" class="accent-[#2563EB]"
                                :checked="props.pickDataValue(props.activeCompany, ['atc']) === 'IC 021'" />
                            IC 021 - General Professional Partnership
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-slate-200 p-3">
            <p class="mb-2 text-xs font-semibold tracking-wide text-[#2563EB]">BACKGROUND INFORMATION</p>
            <div class="grid gap-2 sm:grid-cols-4">
                <div class="sm:col-span-2">
                    <p class="mb-1 text-xs text-slate-600">6. Taxpayer Identification Number (TIN)</p>
                    <Input :model-value="props.activeCompany.tin" />
                </div>
                <div class="sm:col-span-2">
                    <p class="mb-1 text-xs text-slate-600">7. RDO Code</p>
                    <Input
                        :model-value="props.pickDataValue(props.activeCompany, ['rdo_code', 'rdo', 'rdocode']) || null" />
                </div>
                <div class="sm:col-span-4">
                    <p class="mb-1 text-xs text-slate-600">8. Registered Name</p>
                    <Input :model-value="props.activeCompany.name" />
                </div>
                <div class="sm:col-span-3">
                    <p class="mb-1 text-xs text-slate-600">9. Registered Address</p>
                    <textarea
                        class="min-h-[66px] w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm">{{ props.activeCompany.address }}</textarea>
                </div>
                <div>
                    <p class="mb-1 text-xs text-slate-600">9A. Zip Code</p>
                    <Input
                        :model-value="props.pickDataValue(props.activeCompany, ['zip_code', 'zipcode', 'zip']) || null" />
                </div>
                <div class="sm:col-span-2">
                    <p class="mb-1 text-xs text-slate-600">10. Date of Incorporation</p>
                    <div class="grid grid-cols-3 gap-2">
                        <Input :model-value="props.pickDataValue(props.activeCompany, ['incorporationdatemonth'])" />
                        <Input :model-value="props.pickDataValue(props.activeCompany, ['incorporationdateday'])" />
                        <Input :model-value="props.pickDataValue(props.activeCompany, ['incorporationdateyear'])" />
                    </div>
                </div>
                <div class="sm:col-span-2">
                    <p class="mb-1 text-xs text-slate-600">11. Contact Number</p>
                    <Input
                        :model-value="props.pickDataValue(props.activeCompany, ['contact_number', 'contact', 'phone', 'contactnumber']) || null" />
                </div>
                <div class="sm:col-span-4 sm:col-start-1">
                    <p class="mb-1 text-xs text-slate-600">12. Email Address</p>
                    <Input
                        :model-value="props.pickDataValue(props.activeCompany, ['email_address', 'email', 'emailaddress']) || null" />
                </div>
            </div>
        </section>
    </div>
</template>
