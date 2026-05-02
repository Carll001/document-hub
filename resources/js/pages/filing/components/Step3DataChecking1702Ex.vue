<script setup lang="ts">
import { ArrowLeft, Check, Circle, FileText } from 'lucide-vue-next'
import { computed, ref } from 'vue'

import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import Step3DataChecking1702ExPage1 from '@/pages/filing/components/Step3DataChecking1702ExPage1.vue'
import Step3DataChecking1702ExPage2 from '@/pages/filing/components/Step3DataChecking1702ExPage2.vue'
import Step3DataChecking1702ExPage3 from '@/pages/filing/components/Step3DataChecking1702ExPage3.vue'

type CompanyData = {
    id: number
    name: string
    tin: string
    address: string
    data: Record<string, string>
}

const emit = defineEmits<{
    backToFilingType: []
    generateFiling: []
}>()
const props = defineProps<{
    selectedCompanies: CompanyData[]
}>()

const activeCompanyId = ref<number | null>(props.selectedCompanies[0]?.id ?? null)
const currentPage = ref<1 | 2 | 3>(1)
const activeCompany = computed(() =>
    props.selectedCompanies.find((company) => company.id === activeCompanyId.value) ?? null,
)

const pageSubtitle = computed(() => {
    if (currentPage.value === 2) return 'Step 3 of 4: Computation of Tax'
    if (currentPage.value === 3) return 'Step 3 of 4: Review & Edit 1702-EX (Page 3)'
    return 'Step 3 of 4: Review & Edit 1702-EX (Page 1)'
})

function pickDataValue(company: CompanyData, aliases: string[]): string {
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
                    <CardTitle class="text-xl">{{ currentPage === 2 ? 'Review & Edit 1702-EX (Page 2)' : 'Generate Filing' }}</CardTitle>
                    <p class="mt-1 text-sm font-medium text-slate-700">{{ pageSubtitle }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ currentPage === 2
                            ? 'Review and edit computed values before generating the filing.'
                            : 'Review and edit the information below. You can modify any field before generating the filing.' }}
                    </p>
                </div>
            </div>

            <div class="rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm text-blue-700">
                {{ currentPage === 2
                    ? 'Review and edit computed values before generating the filing.'
                    : 'You can edit any field before generating the filing.' }}
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

            <Step3DataChecking1702ExPage1
                v-if="activeCompany && currentPage === 1"
                :active-company="activeCompany"
                :pick-data-value="pickDataValue"
            />

            <Step3DataChecking1702ExPage2
                v-if="activeCompany && currentPage === 2"
                :active-company="activeCompany"
                :pick-data-value="pickDataValue"
            />

            <Step3DataChecking1702ExPage3
                v-if="activeCompany && currentPage === 3"
                :active-company="activeCompany"
                :pick-data-value="pickDataValue"
            />

            <div class="flex items-center justify-between border-t pt-4">
                <Button variant="outline" class="gap-2" @click="emit('backToFilingType')">
                    <ArrowLeft class="size-4" />
                    Back
                </Button>
                <div class="flex items-center gap-2">
                    <Button variant="outline" size="sm" :class="currentPage === 1 && 'border-[#2563EB] text-[#2563EB]'" @click="currentPage = 1">Page 1</Button>
                    <Button variant="outline" size="sm" :class="currentPage === 2 && 'border-[#2563EB] text-[#2563EB]'" @click="currentPage = 2">Page 2</Button>
                    <Button variant="outline" size="sm" :class="currentPage === 3 && 'border-[#2563EB] text-[#2563EB]'" @click="currentPage = 3">Page 3</Button>
                    <Button variant="outline">Save as Draft</Button>
                    <Button class="gap-2 bg-[#2563EB] hover:bg-[#1D4ED8]" @click="emit('generateFiling')">
                        <FileText class="size-4" />
                        Generate Filing
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
