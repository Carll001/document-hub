<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3'
import { computed, onMounted, ref } from 'vue'
import { RefreshCw } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import type { BreadcrumbItem } from '@/types'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { DataTable } from '@/components/ui/data-table'
import { Input } from '@/components/ui/input'
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog'
import { createCompanyReviewColumns, type CompanyReviewRow } from '@/pages/filing/columns'

const props = defineProps<{
    routes: {
        index: string
        myFilings: string
        outputs: string
    }
}>()

const rows = ref<CompanyReviewRow[]>([])
const loading = ref(false)
const search = ref('')
const showFailedDialog = ref(false)
const failedDialogRow = ref<CompanyReviewRow | null>(null)

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Completed Files',
        href: props.routes.index,
    },
]

async function refreshRows(): Promise<void> {
    loading.value = true

    try {
        const query = new URLSearchParams()
        query.set('formType', 'afs')
        query.set('status', 'signed')
        if (search.value.trim() !== '') {
            query.set('search', search.value.trim())
        }

        const response = await fetch(`${props.routes.outputs}?${query.toString()}`, {
            headers: {
                Accept: 'application/json',
            },
        })

        if (!response.ok) {
            throw new Error(`Failed to load completed files (${response.status}).`)
        }

        const payload = (await response.json()) as { data?: CompanyReviewRow[] }
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

function signRow(_row: CompanyReviewRow): void {}

function addTemporaryReceiptRow(_row: CompanyReviewRow): void {}

function openFailedStatusDialog(row: CompanyReviewRow): void {
    if (row.status.toLowerCase() !== 'failed') return

    failedDialogRow.value = row
    showFailedDialog.value = true
}

const columns = createCompanyReviewColumns({
    onPreview: previewRow,
    onDownload: downloadRow,
    onDelete: deleteRow,
    onRegenerate: regenerateRow,
    onSign: signRow,
    onAddTemporaryReceipt: addTemporaryReceiptRow,
    onStatusClick: openFailedStatusDialog,
})

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

onMounted(() => {
    void refreshRows()
})
</script>

<template>
    <Head title="Completed Files" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <AlertDialog :open="showFailedDialog" @update:open="showFailedDialog = $event">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Generation failed</AlertDialogTitle>
                    <AlertDialogDescription>
                        Review the failure details below before regenerating.
                    </AlertDialogDescription>
                </AlertDialogHeader>

                <div class="max-h-64 space-y-2 overflow-y-auto text-sm text-slate-700">
                    <p>
                        <span class="font-semibold">{{ failedDialogRow?.name }}</span> ({{ failedDialogRow?.tin }})
                    </p>
                    <p>{{ failedDialogRow?.error_message || 'No detailed error message was saved.' }}</p>
                </div>

                <AlertDialogFooter>
                    <AlertDialogAction @click="showFailedDialog = false">
                        Close
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <CardHeader class="flex flex-row items-center justify-between gap-3">
                    <div>
                        <CardTitle>Completed Files</CardTitle>
                        <p class="text-sm text-muted-foreground">
                            Signed AFS files ready for preview and download.
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="props.routes.myFilings">Back to My Filings</Link>
                        </Button>
                        <Button variant="outline" :disabled="loading" @click="refreshRows">
                            <RefreshCw class="size-4" :class="{ 'animate-spin': loading }" />
                        </Button>
                    </div>
                </CardHeader>

                <CardContent class="space-y-4">
                    <Input
                        v-model="search"
                        placeholder="Search company or TIN"
                        @keyup.enter="refreshRows"
                    />

                    <DataTable
                        :columns="columns"
                        :data="rows"
                        :meta="meta"
                        empty-message="No signed AFS files yet."
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
