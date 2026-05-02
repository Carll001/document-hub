<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Plus, Search } from 'lucide-vue-next'

import AppLayout from '@/layouts/AppLayout.vue'
import { Button } from '@/components/ui/button'
import { Card, CardContent } from '@/components/ui/card'
import { DataTable } from '@/components/ui/data-table'
import { Input } from '@/components/ui/input'
import type { BreadcrumbItem } from '@/types'
import template from '@/routes/template'
import CreateTemplateDialog from '@/pages/template/Create.vue'
import { createTemplateColumns, type TemplateRow } from '@/pages/template/columns'

type PaginationMeta = {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number
    to: number
}

const props = defineProps<{
    templates: {
        data: TemplateRow[]
        pagination: PaginationMeta
    }
    filters: {
        search: string
        sort: 'template_name' | 'updated_at'
        direction: 'asc' | 'desc'
        perPage: 10 | 25 | 50 | 100
    }
    routes: {
        index: string
        store: string
        bulkDelete: string
    }
    flash?: {
        success?: string | null
        error?: string | null
    }
}>()

const showCreate = ref(false)
const search = ref(props.filters.search ?? '')
const sortBy = ref<'template_name' | 'updated_at'>(props.filters.sort ?? 'updated_at')
const sortDirection = ref<'asc' | 'desc'>(props.filters.direction ?? 'desc')
const selectedIds = ref<number[]>([])
const form = useForm({
    name: '',
    filing_type: 'afs',
    template_file: null as File | null,
})

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Templates',
        href: template.index(),
    },
]

const rows = computed(() => props.templates.data)
const tableMeta = computed(() => props.templates.pagination)
const allVisibleSelected = computed(
    () => rows.value.length > 0 && rows.value.every((row) => selectedIds.value.includes(row.id)),
)
const columns = createTemplateColumns({
    allVisibleSelected,
    paginatedRows: rows,
    selectedIds,
    formatDate,
    onView: (row) => {
        window.open(`/template/${row.id}/data`, '_blank', 'noopener,noreferrer')
    },
    onDelete: (row) => {
        router.delete(`/template/${row.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                selectedIds.value = selectedIds.value.filter((id) => id !== row.id)
            },
        })
    },
})

function submit(payload: { name: string; filing_type: 'afs' | '1702ex'; template_file: File | null }) {
    form.name = payload.name
    form.filing_type = payload.filing_type
    form.template_file = payload.template_file

    form.post(props.routes.store, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            showCreate.value = false
            form.reset()
            form.clearErrors()
            form.filing_type = 'afs'
        },
    })
}

function refreshTable(overrides: Partial<{ page: number; perPage: number; sort: string; direction: string; search: string }>): void {
    router.get(
        props.routes.index,
        {
            page: overrides.page ?? props.templates.pagination.current_page,
            perPage: overrides.perPage ?? props.templates.pagination.per_page,
            sort: overrides.sort ?? sortBy.value,
            direction: overrides.direction ?? sortDirection.value,
            search: overrides.search ?? search.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    )
}

function pageChange(page: number): void {
    refreshTable({ page })
}

function perPageChange(value: number): void {
    refreshTable({ page: 1, perPage: value })
}

function sortChange(column: string, direction: 'asc' | 'desc'): void {
    if (column === 'template_name' || column === 'updated_at') {
        sortBy.value = column
        sortDirection.value = direction
        refreshTable({ page: 1, sort: column, direction })
    }
}

function searchTemplates(): void {
    refreshTable({ page: 1, search: search.value })
}

function deleteSelected(): void {
    if (selectedIds.value.length === 0) return

    router.delete(props.routes.bulkDelete, {
        data: {
            ids: selectedIds.value,
        },
        preserveScroll: true,
        onSuccess: () => {
            selectedIds.value = []
        },
    })
}

function formatDate(value: string | null): string {
    if (!value) {
        return '-'
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value))
}
</script>

<template>
    <Head title="Templates" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">Templates</h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Manage filing templates used in Generate Filing.
                    </p>
                </div>
                <Button class="gap-2 bg-[#2563EB] hover:bg-[#1D4ED8]" @click="showCreate = !showCreate">
                    <Plus class="size-4" />
                    {{ showCreate ? 'Close' : 'New Template' }}
                </Button>
            </div>

            <div v-if="props.flash?.success" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-700">
                {{ props.flash.success }}
            </div>
            <div v-if="props.flash?.error" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-700">
                {{ props.flash.error }}
            </div>

            <CreateTemplateDialog
                :open="showCreate"
                :processing="form.processing"
                :errors="{
                    name: form.errors.name,
                    filing_type: form.errors.filing_type,
                    template_file: form.errors.template_file,
                }"
                @update:open="showCreate = $event"
                @submit="submit"
            />

            <Card class="rounded-2xl border border-slate-200">
                <CardContent class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="relative max-w-md">
                            <Search class="pointer-events-none absolute left-3 top-2.5 size-4 text-slate-400" />
                            <Input v-model="search" class="pl-9" placeholder="Search templates..." @keyup.enter="searchTemplates" />
                        </div>
                        <Button
                            v-if="selectedIds.length > 0"
                            variant="destructive"
                            @click="deleteSelected"
                        >
                            Delete Selected ({{ selectedIds.length }})
                        </Button>
                    </div>

                    <DataTable
                        :columns="columns"
                        :data="rows"
                        :meta="tableMeta"
                        :sort-by="sortBy"
                        :sort-direction="sortDirection"
                        empty-message="No templates found"
                        @page-change="pageChange"
                        @per-page-change="perPageChange"
                        @sort-change="sortChange"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
