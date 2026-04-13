<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BriefcaseBusiness, FolderOpen } from 'lucide-vue-next';
import { watch } from 'vue';
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

const props = defineProps<{
    flash: {
        success?: string | null;
        error?: string | null;
    };
    clients: ClientListItem[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Clients',
        href: '/clients',
    },
];

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
