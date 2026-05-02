<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ArrowRight, Building2, Download, Files, FileSpreadsheet, Plus, Upload } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { DataTable } from '@/components/ui/data-table';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';
import { createCompanyColumns, type CompanyRow } from '@/pages/companies/columns';
import CompaniesImport from '@/pages/companies/Import.vue';

type PaginationMeta = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
};

type PageProps = {
    flash?: {
        success?: string | null;
        error?: string | null;
    };
    routes: {
        index: string;
        create: string;
        import: string;
        store: string;
    };
    companies: {
        data: CompanyRow[];
        pagination: PaginationMeta;
    };
    stats: {
        totalCompanies: number;
        recentlyAdded: number;
        importedCompanies: number;
        addedThisMonth: number;
    };
    filters: {
        search: string;
        sort: 'name' | 'tin' | 'created_at';
        direction: 'asc' | 'desc';
        perPage: 10 | 25 | 50 | 100;
    };
};

const props = defineProps<PageProps>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Companies', href: props.routes.index },
];

const search = ref(props.filters.search ?? '');
const sortBy = ref<'name' | 'tin' | 'created_at'>(props.filters.sort ?? 'created_at');
const sortDirection = ref<'asc' | 'desc'>(props.filters.direction ?? 'desc');
const selectedIds = ref<number[]>([]);
const importDialogOpen = ref(false);
let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null;

const tableMeta = computed(() => props.companies.pagination);
const rows = computed(() => props.companies.data);

const allVisibleSelected = computed(
    () => rows.value.length > 0 && rows.value.every((row) => selectedIds.value.includes(row.id)),
);

const columns = createCompanyColumns({
    allVisibleSelected,
    paginatedRows: rows,
    selectedIds,
    formatDate,
    onView: (row) => {
        window.open(`/companies/${row.id}/data`, '_blank', 'noopener,noreferrer');
    },
    onEdit: (row) => {
        router.visit(`/companies/${row.id}/edit`);
    },
    onDelete: (row) => {
        router.delete(`/companies/${row.id}`, {
            preserveScroll: true,
        });
    },
});

const importForm = useForm<{
    spreadsheet: File | null;
    overwrite_existing: boolean;
}>({
    spreadsheet: null,
    overwrite_existing: false,
});

function formatDate(value: string): string {
    const date = new Date(value);
    return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(date);
}

function refreshTable(overrides: Partial<{ page: number; perPage: number; sort: string; direction: string; search: string }>): void {
    router.get(
        props.routes.index,
        {
            page: overrides.page ?? props.companies.pagination.current_page,
            perPage: overrides.perPage ?? props.companies.pagination.per_page,
            sort: overrides.sort ?? sortBy.value,
            direction: overrides.direction ?? sortDirection.value,
            search: overrides.search ?? search.value,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: () => {
                window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' });
            },
        },
    );
}

function pageChange(page: number): void {
    refreshTable({ page });
}

function perPageChange(value: number): void {
    refreshTable({ page: 1, perPage: value });
}

function sortChange(column: string, direction: 'asc' | 'desc'): void {
    if (column === 'name' || column === 'tin' || column === 'created_at') {
        sortBy.value = column;
        sortDirection.value = direction;
        refreshTable({ page: 1, sort: column, direction });
    }
}

function submitImport(payload: { file: File | null; overwriteExisting: boolean }): void {
    importForm.spreadsheet = payload.file;
    importForm.overwrite_existing = payload.overwriteExisting;
    importForm.post(props.routes.import, {
        preserveScroll: true,
        onSuccess: () => {
            importDialogOpen.value = false;
            importForm.reset();
        },
    });
}

watch(search, (value, previousValue) => {
    if (value === previousValue) {
        return;
    }

    if (searchDebounceTimer !== null) {
        clearTimeout(searchDebounceTimer);
    }

    searchDebounceTimer = setTimeout(() => {
        refreshTable({ page: 1, search: value });
    }, 350);
});

onBeforeUnmount(() => {
    if (searchDebounceTimer !== null) {
        clearTimeout(searchDebounceTimer);
    }
});
</script>

<template>
    <Head title="Companies" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 bg-slate-50 p-4 md:p-6">
            <div class="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
                <div class="space-y-1 px-1">
                    <h1 class="text-3xl font-semibold tracking-tight text-slate-900">
                        Companies
                    </h1>
                    <p class="text-sm text-slate-600">
                        Manage your company records for filing.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="outline" @click="router.visit('/filing')">
                        <Files class="mr-2 size-4" />
                        Generate Filing
                    </Button>
                    <Button variant="outline" @click="importDialogOpen = true">
                        <Upload class="mr-2 size-4" />
                        Import Companies
                    </Button>
                    <Button class="bg-[#2563EB] hover:bg-[#1D4ED8]" @click="router.visit(props.routes.create)">
                        <Plus class="mr-2 size-4" />
                        Add Company
                    </Button>
                </div>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <Card class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <CardContent class="flex items-center gap-4">
                        <div class="flex size-12 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                            <Building2 class="size-5" />
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-xs font-medium text-slate-500">Total Companies</p>
                            <p class="text-4xl leading-none font-semibold text-slate-900">
                                {{ stats.totalCompanies }}
                            </p>
                            <p class="text-xs text-slate-500">All company records</p>
                        </div>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <CardContent class="flex items-center gap-4">
                        <div class="flex size-12 items-center justify-center rounded-full bg-amber-100 text-amber-600">
                            <FileSpreadsheet class="size-5" />
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-xs font-medium text-slate-500">Recently Added</p>
                            <p class="text-4xl leading-none font-semibold text-slate-900">
                                {{ stats.recentlyAdded }}
                            </p>
                            <p class="text-xs text-slate-500">In the last 30 days</p>
                        </div>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <CardContent class="flex items-center gap-4">
                        <div class="flex size-12 items-center justify-center rounded-full bg-indigo-100 text-indigo-600">
                            <FileSpreadsheet class="size-5" />
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-xs font-medium text-slate-500">Added This Month</p>
                            <p class="text-4xl leading-none font-semibold text-slate-900">
                                {{ stats.addedThisMonth }}
                            </p>
                            <p class="text-xs text-slate-500">Current calendar month</p>
                        </div>
                    </CardContent>
                </Card>
                <Card class="rounded-2xl border border-slate-200 bg-white shadow-sm">
                    <CardContent class="flex items-center gap-4">
                        <div class="flex size-12 items-center justify-center rounded-full bg-blue-100 text-blue-600">
                            <Upload class="size-5" />
                        </div>
                        <div class="space-y-0.5">
                            <p class="text-xs font-medium text-slate-500">Imported Companies</p>
                            <p class="text-4xl leading-none font-semibold text-slate-900">
                                {{ stats.importedCompanies }}
                            </p>
                            <p class="text-xs text-slate-500">From imported files</p>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <Card class="rounded-3xl border border-slate-200 bg-white shadow-sm">
                <CardContent class="space-y-4">
                    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div class="w-full max-w-2xl">
                            <Input
                                v-model="search"
                                placeholder="Search company name or TIN..."
                                class="w-full"
                            />
                        </div>
                        <div class="flex items-center gap-2">
                            <Button variant="outline">
                                <Download class="mr-2 size-4" />
                                Export
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="tableMeta.total === 0"
                        class="rounded-2xl border border-dashed border-slate-200 bg-slate-50 p-10 text-center"
                    >
                        <div
                            class="mx-auto mb-3 flex size-12 items-center justify-center rounded-full bg-blue-100 text-blue-700"
                        >
                            <Building2 class="size-6" />
                        </div>
                        <p class="text-base font-medium text-slate-900">No companies yet</p>
                        <p class="mt-1 text-sm text-slate-600">
                            Add or import companies to start generating filings.
                        </p>
                        <Button class="mt-4 bg-[#2563EB] hover:bg-[#1D4ED8]" @click="router.visit(props.routes.create)">
                            Add your first company
                        </Button>
                    </div>

                    <DataTable
                        v-else
                        :columns="columns"
                        :data="rows"
                        :meta="tableMeta"
                        :sort-by="sortBy"
                        :sort-direction="sortDirection"
                        empty-message="No companies yet"
                        @page-change="pageChange"
                        @per-page-change="perPageChange"
                        @sort-change="sortChange"
                    />
                </CardContent>
            </Card>
        </div>
    </AppLayout>

    <CompaniesImport
        v-model:open="importDialogOpen"
        :processing="importForm.processing"
        :error="importForm.errors.spreadsheet"
        @submit="submitImport"
    />
</template>
