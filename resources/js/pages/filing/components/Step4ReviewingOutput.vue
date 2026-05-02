<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Files, FileSpreadsheet, RefreshCw } from 'lucide-vue-next'
import { router } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { DataTable } from '@/components/ui/data-table'
import { createCompanyReviewColumns, type CompanyReviewRow } from '@/pages/filing/columns'

const props = defineProps<{
    selectedCompanyIds: number[]
    filingType: 'afs' | '1702ex' | null
    routes: {
        afsOutputs: string
    }
}>()
const rows = ref<CompanyReviewRow[]>([])
const loading = ref(false)

async function refreshRows(): Promise<void> {
    if (props.filingType !== 'afs') {
        rows.value = []
        return
    }

    loading.value = true

    try {
        const query = new URLSearchParams()
        props.selectedCompanyIds.forEach((id) => query.append('companyId[]', String(id)))
        const response = await fetch(`${props.routes.afsOutputs}?${query.toString()}`, {
            headers: {
                Accept: 'application/json',
            },
        })

        if (!response.ok) {
            throw new Error(`Failed to refresh outputs (${response.status}).`)
        }

        const payload = (await response.json()) as {
            data?: CompanyReviewRow[]
        }

        rows.value = Array.isArray(payload.data) ? payload.data : []
    } finally {
        loading.value = false
    }
}

function previewRow(row: CompanyReviewRow): void {
    window.open(`/filing/afs/outputs/${row.id}/preview`, '_blank', 'noopener,noreferrer')
}

function downloadRow(row: CompanyReviewRow): void {
    window.open(`/filing/afs/outputs/${row.id}/download`, '_blank', 'noopener,noreferrer')
}

async function deleteRow(row: CompanyReviewRow): Promise<void> {
    await fetch(`/filing/afs/outputs/${row.id}`, {
        method: 'DELETE',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
        },
    })

    void refreshRows()
}

async function regenerateRow(row: CompanyReviewRow): Promise<void> {
    await fetch(`/filing/afs/outputs/${row.id}/regenerate`, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
        },
    })

    void refreshRows()
}

function signRow(row: CompanyReviewRow): void {
    window.alert(`Sign action is available for AFS row ${row.id}. Wiring to signature flow is next.`)
}

function addTemporaryReceiptRow(row: CompanyReviewRow): void {
    window.alert(`Add temporary receipt is available for 1702EX row ${row.id}. Wiring is next.`)
}

function goToMyFilings(): void {
    router.get('/filing/my-filings')
}

function generateNew(): void {
    router.get('/filing', { step: 1 })
}

const meta = computed(() => {
    const total = rows.value.length

    return {
        current_page: 1,
        last_page: 1,
        per_page: total > 0 ? total : 10,
        total,
        from: total > 0 ? 1 : 0,
        to: total,
    }
})

const columns = createCompanyReviewColumns({
    onPreview: previewRow,
    onDownload: downloadRow,
    onDelete: deleteRow,
    onRegenerate: regenerateRow,
    onSign: signRow,
    onAddTemporaryReceipt: addTemporaryReceiptRow,
    onStatusClick: () => {},
})

onMounted(() => {
    void refreshRows()
})

</script>

<template>
    <Card class="rounded-3xl border border-slate-200 bg-white shadow-sm">
        <CardHeader>
            <div class="flex justify-between items-center">
                <div>
                    <CardTitle>Step 4: Reviewing Output</CardTitle>
                    <p class="text-sm text-muted-foreground">
                        Review generated AFS files and use actions per company output.
                    </p>
                </div>
                <div class="flex items-center justify-end gap-2">
                    <Button variant="outline" :disabled="loading" @click="refreshRows">
                        <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" />
                    </Button>
                </div>
            </div>
        </CardHeader>

        <CardContent class="space-y-4">

            <DataTable :columns="columns" :data="rows" :meta="meta"
                :empty-message="props.filingType === 'afs' ? 'No outputs yet. Click Refresh after queueing generation.' : 'Step 4 output review is currently available for AFS only.'" />
        </CardContent>
    </Card>
    <div class="flex items-center justify-end gap-2">
        <Button variant="outline" @click="generateNew">
            <FileSpreadsheet /> Generate New
        </Button>
        <Button class="bg-[#2563EB] hover:bg-[#1D4ED8]" @click="goToMyFilings">
            <Files /> Go to My Filings
        </Button>
    </div>
</template>
