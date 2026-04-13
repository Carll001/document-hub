<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Ellipsis, Eye, FolderKanban, Send } from 'lucide-vue-next';
import { computed, watch } from 'vue';
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
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
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

type ClientCompanyFile = {
    id: string;
    fileName: string;
    generatedAt: string | null;
    previewUrl: string | null;
    downloadUrl: string | null;
};

type ClientCompanyGroup = {
    id: string;
    name: string;
    tin: string;
    completedCount: number;
    recipientEmails: string[];
    statusLabel: string;
    statusVariant: 'secondary' | 'outline' | 'destructive';
    files: ClientCompanyFile[];
};

const props = defineProps<{
    flash: {
        success?: string | null;
        error?: string | null;
    };
    clientsUrl: string;
    client: {
        id: string;
        name: string;
        folder: {
            label: string;
            companyCount: number;
            completedCount: number;
            recipientCount: number;
            primaryRecipient: string | null;
            sendUrl: string;
            canSend: boolean;
            warning: string | null;
        };
        companies: ClientCompanyGroup[];
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Clients',
        href: props.clientsUrl,
    },
    {
        title: props.client.name,
        href: '#',
    },
];

const sendForm = useForm<Record<string, never>>({});

const companyLabel = computed(() =>
    `${props.client.folder.companyCount} compan${
        props.client.folder.companyCount === 1 ? 'y' : 'ies'
    }`,
);

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

function submitBulkSend(): void {
    sendForm.post(props.client.folder.sendUrl, {
        preserveScroll: true,
    });
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Unknown date';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Unknown date';
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}
</script>

<template>
    <Head :title="props.client.name" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardHeader class="space-y-3">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="space-y-1">
                            <CardTitle class="text-2xl">
                                {{ props.client.name }}
                            </CardTitle>
                            <CardDescription>
                                Form-type folders for this client.
                            </CardDescription>
                        </div>

                        <Button as-child variant="outline" class="rounded-full">
                            <Link :href="props.clientsUrl">Back to clients</Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <Card class="rounded-3xl border-dashed">
                        <CardHeader class="space-y-3">
                            <div class="flex flex-wrap items-start justify-between gap-4">
                                <div class="flex items-center gap-3">
                                    <div class="rounded-2xl bg-muted p-3">
                                        <FolderKanban class="size-6 text-foreground" />
                                    </div>
                                    <div class="space-y-1">
                                        <CardTitle class="text-lg">
                                            {{ props.client.folder.label }}
                                        </CardTitle>
                                        <CardDescription>
                                            {{ companyLabel }} and
                                            {{ props.client.folder.completedCount }} completed file{{
                                                props.client.folder.completedCount === 1 ? '' : 's'
                                            }}
                                        </CardDescription>
                                    </div>
                                </div>

                                <div class="flex flex-col items-start gap-2 md:items-end">
                                    <Badge variant="secondary" class="rounded-full">
                                        {{ props.client.folder.recipientCount }}
                                        recipient{{ props.client.folder.recipientCount === 1 ? '' : 's' }}
                                    </Badge>
                                    <Button
                                        type="button"
                                        class="rounded-full"
                                        :disabled="!props.client.folder.canSend || sendForm.processing"
                                        @click="submitBulkSend"
                                    >
                                        <Send class="mr-2 size-4" />
                                        {{
                                            sendForm.processing
                                                ? 'Queueing...'
                                                : 'Send bulk files'
                                        }}
                                    </Button>
                                </div>
                            </div>

                            <p
                                v-if="props.client.folder.primaryRecipient"
                                class="text-sm text-muted-foreground"
                            >
                                Recipient: {{ props.client.folder.primaryRecipient }}
                            </p>
                            <p
                                v-if="props.client.folder.warning"
                                class="text-sm text-destructive"
                            >
                                {{ props.client.folder.warning }}
                            </p>
                        </CardHeader>
                    </Card>
                </CardContent>
            </Card>

            <Card v-if="props.client.companies.length > 0" class="rounded-3xl">
                <CardHeader>
                    <CardTitle class="text-xl">Companies</CardTitle>
                    <CardDescription>
                        All companies under this client for the 1702-EX folder.
                    </CardDescription>
                </CardHeader>

                <CardContent>
                    <div class="overflow-hidden rounded-2xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Company</TableHead>
                                    <TableHead>TIN</TableHead>
                                    <TableHead>Recipient</TableHead>
                                    <TableHead>Files</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead class="text-right">Actions</TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                <TableRow
                                    v-for="company in props.client.companies"
                                    :key="company.id"
                                    class="align-top"
                                >
                                    <TableCell class="font-medium text-foreground">
                                        {{ company.name }}
                                    </TableCell>
                                    <TableCell class="text-sm text-muted-foreground">
                                        {{ company.tin || 'Unavailable' }}
                                    </TableCell>
                                    <TableCell class="text-sm text-muted-foreground">
                                        {{
                                            company.recipientEmails.length > 0
                                                ? company.recipientEmails.join(', ')
                                                : 'No saved recipient email'
                                        }}
                                    </TableCell>
                                    <TableCell class="space-y-2">
                                        <div
                                            v-for="file in company.files"
                                            :key="file.id"
                                            class="rounded-xl border px-3 py-2"
                                        >
                                            <div class="min-w-0 space-y-1">
                                                <p class="truncate text-sm font-medium text-foreground">
                                                    {{ file.fileName }}
                                                </p>
                                                <p class="text-xs text-muted-foreground">
                                                    {{ formatDateTime(file.generatedAt) }}
                                                </p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge :variant="company.statusVariant" class="rounded-full">
                                            {{ company.statusLabel }}
                                        </Badge>
                                    </TableCell>
                                    <TableCell class="space-y-2 text-right">
                                        <div
                                            v-for="file in company.files"
                                            :key="`${file.id}-action`"
                                        >
                                            <DropdownMenu v-if="file.previewUrl || file.downloadUrl">
                                                <DropdownMenuTrigger as-child>
                                                    <Button
                                                        type="button"
                                                        size="icon"
                                                        variant="outline"
                                                        class="size-9 rounded-full"
                                                    >
                                                        <Ellipsis class="size-4" />
                                                        <span class="sr-only">
                                                            Open file actions
                                                        </span>
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent align="end" class="w-40 rounded-lg">
                                                    <DropdownMenuItem
                                                        v-if="file.previewUrl"
                                                        :as-child="true"
                                                    >
                                                        <a
                                                            :href="file.previewUrl"
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            class="flex items-center gap-2"
                                                        >
                                                            <Eye class="size-4" />
                                                            Preview
                                                        </a>
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        v-if="file.downloadUrl"
                                                        :as-child="true"
                                                    >
                                                        <a :href="file.downloadUrl">
                                                            Download
                                                        </a>
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                            <span
                                                v-else
                                                class="text-xs text-muted-foreground"
                                            >
                                                No file yet
                                            </span>
                                        </div>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            <Card v-else class="rounded-3xl border-dashed">
                <CardContent class="px-6 py-12 text-center text-sm text-muted-foreground">
                    No completed 1702-EX companies are linked to this client yet.
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
