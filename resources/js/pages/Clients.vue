<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BriefcaseBusiness, FolderOpen } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type ClientListItem = {
    id: string;
    name: string;
    companyCount: number;
    completed1702ExCount: number;
    showUrl: string;
};

type ClientsPagination = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
};

const props = defineProps<{
    flash: {
        success?: string | null;
        error?: string | null;
    };
    indexUrl: string;
    filters: {
        search: string;
        per_page: number;
    };
    pagination: ClientsPagination;
    clients: ClientListItem[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Clients',
        href: '/clients',
    },
];

const searchInput = ref(props.filters.search ?? '');
const searchTimeoutId = ref<number | null>(null);
const perPage = computed(() => String(props.filters.per_page ?? 25));

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            toast.success(success);
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

watch(
    () => props.filters.search,
    (value) => {
        searchInput.value = value ?? '';
    },
);

watch(searchInput, (value) => {
    if (searchTimeoutId.value !== null) {
        window.clearTimeout(searchTimeoutId.value);
    }

    searchTimeoutId.value = window.setTimeout(() => {
        visitIndex({
            page: 1,
            search: value.trim(),
            perPage: Number.parseInt(perPage.value, 10) || 25,
        });
    }, 350);
});

function visitIndex(overrides: {
    page?: number;
    perPage?: number;
    search?: string;
}): void {
    const query: Record<string, string | number | undefined> = {
        page: overrides.page ?? props.pagination.currentPage,
        per_page: overrides.perPage ?? props.filters.per_page,
        search: overrides.search ?? props.filters.search,
    };

    if (!query.search) {
        delete query.search;
    }

    router.get(props.indexUrl, query, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
        only: ['clients', 'pagination', 'filters', 'flash'],
    });
}

function onPerPageChange(value: string): void {
    const parsed = Number.parseInt(value, 10);

    if (!Number.isFinite(parsed) || parsed <= 0) {
        return;
    }

    visitIndex({
        page: 1,
        perPage: parsed,
        search: searchInput.value.trim(),
    });
}
</script>

<template>
    <Head title="Clients" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardHeader class="space-y-2">
                    <div class="flex items-center gap-3">
                        <div class="rounded-2xl bg-muted p-3">
                            <BriefcaseBusiness class="size-6 text-foreground" />
                        </div>
                        <div class="space-y-1">
                            <CardTitle class="text-xl">Clients</CardTitle>
                            <CardDescription>
                                Open a client to view its form-type folders and grouped completed files.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>

                <CardContent>
                    <div class="mb-4">
                        <Input
                            v-model="searchInput"
                            type="search"
                            placeholder="Search client name..."
                            class="w-full md:max-w-sm"
                        />
                    </div>

                    <div
                        v-if="props.clients.length > 0"
                        class="overflow-hidden rounded-2xl border"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Owner Name</TableHead>
                                    <TableHead>Companies</TableHead>
                                    <TableHead>1702-EX</TableHead>
                                    <TableHead class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                <TableRow
                                    v-for="client in props.clients"
                                    :key="client.id"
                                >
                                    <TableCell class="font-medium">
                                        <Link
                                            :href="client.showUrl"
                                            class="text-foreground transition hover:text-primary hover:underline"
                                        >
                                            {{ client.name }}
                                        </Link>
                                    </TableCell>
                                    <TableCell class="text-sm text-muted-foreground">
                                        {{ client.companyCount }}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="secondary" class="rounded-full">
                                            {{ client.completed1702ExCount }} completed
                                        </Badge>
                                    </TableCell>
                                    <TableCell class="text-right">
                                        <Button as-child size="sm" class="rounded-full">
                                            <Link :href="client.showUrl">
                                                <FolderOpen class="mr-2 size-4" />
                                                Open
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                    <div
                        v-if="props.clients.length > 0"
                        class="mt-4 flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
                    >
                        <p class="text-sm text-muted-foreground">
                            Showing {{ props.pagination.from ?? 0 }} to {{ props.pagination.to ?? 0 }} of {{ props.pagination.total }} clients
                        </p>
                        <div class="flex items-center gap-2">
                            <span class="text-sm text-muted-foreground">Rows per page</span>
                            <Select
                                :model-value="perPage"
                                @update:model-value="onPerPageChange(String($event))"
                            >
                                <SelectTrigger class="w-[96px]">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="10">10</SelectItem>
                                    <SelectItem value="25">25</SelectItem>
                                    <SelectItem value="50">50</SelectItem>
                                    <SelectItem value="100">100</SelectItem>
                                </SelectContent>
                            </Select>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="props.pagination.currentPage <= 1"
                                @click="visitIndex({ page: props.pagination.currentPage - 1, search: searchInput.trim() })"
                            >
                                Previous
                            </Button>
                            <span class="text-sm">
                                Page {{ props.pagination.currentPage }} / {{ props.pagination.lastPage }}
                            </span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="props.pagination.currentPage >= props.pagination.lastPage"
                                @click="visitIndex({ page: props.pagination.currentPage + 1, search: searchInput.trim() })"
                            >
                                Next
                            </Button>
                        </div>
                    </div>

                    <div
                        v-else
                        class="rounded-3xl border border-dashed px-6 py-12 text-center text-sm text-muted-foreground"
                    >
                        No clients are linked yet. Import a 1702-EX file with the
                        <code>client_name</code> header to start grouping companies under a client.
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
