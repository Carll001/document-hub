<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, Eye } from 'lucide-vue-next';
import AppLayout from '@/layouts/AppLayout.vue';
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
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    client: {
        name: string;
    };
    rows: Array<{
        id: string;
        fileName: string;
        taxpayerName: string;
        generatedAt: string | null;
        previewUrl: string;
        downloadUrl: string;
    }>;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'My Files',
        href: '/client/files',
    },
];

function formatDate(value: string | null): string {
    if (!value) {
        return '-';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleString();
}
</script>

<template>
    <Head title="My Files" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardHeader>
                    <CardTitle class="text-2xl">My Completed Files</CardTitle>
                    <CardDescription>
                        {{ props.client.name }} completed 1702-EX files.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="overflow-hidden rounded-2xl border bg-background">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>File name</TableHead>
                                    <TableHead>Taxpayer</TableHead>
                                    <TableHead>Generated</TableHead>
                                    <TableHead class="w-[1%] text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <template v-if="props.rows.length > 0">
                                    <TableRow v-for="row in props.rows" :key="row.id">
                                        <TableCell class="font-medium text-foreground">
                                            {{ row.fileName }}
                                        </TableCell>
                                        <TableCell class="text-sm text-muted-foreground">
                                            {{ row.taxpayerName }}
                                        </TableCell>
                                        <TableCell class="text-sm text-muted-foreground">
                                            {{ formatDate(row.generatedAt) }}
                                        </TableCell>
                                        <TableCell>
                                            <div class="flex justify-end gap-2">
                                                <Button as-child type="button" variant="outline" size="sm">
                                                    <a :href="row.previewUrl" target="_blank" rel="noreferrer">
                                                        <Eye class="mr-2 size-4" />
                                                        Preview
                                                    </a>
                                                </Button>
                                                <Button as-child type="button" size="sm">
                                                    <a :href="row.downloadUrl">
                                                        <Download class="mr-2 size-4" />
                                                        Download
                                                    </a>
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </template>
                                <TableEmpty v-else :colspan="4">
                                    No completed files are available yet.
                                </TableEmpty>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>

