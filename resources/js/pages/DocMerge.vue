<script setup lang="ts">
import {
    FlexRender,
    createColumnHelper,
    getCoreRowModel,
    useVueTable,
} from '@tanstack/vue-table';
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowUp,
    Download,
    FileText,
    LoaderCircle,
    Search,
    Trash2,
    Upload,
} from 'lucide-vue-next';
import { computed, h, ref, toRefs, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import docMerge from '@/routes/doc-merge';
import type { BreadcrumbItem } from '@/types';

type FlashState = {
    success?: string | null;
    error?: string | null;
};

type MergedPdfRecord = {
    id: number;
    fileName: string;
    fileSize: number;
    sourceCount: number;
    sourceFileNames: string[];
    createdAt: string | null;
    downloadUrl: string;
};

const props = defineProps<{
    flash: FlashState;
    mergedPdfs: MergedPdfRecord[];
}>();
const { flash, mergedPdfs } = toRefs(props);

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Doc Merge',
        href: docMerge.index(),
    },
];

const fileInput = ref<HTMLInputElement | null>(null);
const isMergeDialogOpen = ref(false);
const mergedFileSearch = ref('');

const form = useForm<{
    files: File[];
    outputName: string;
}>({
    files: [],
    outputName: defaultOutputName(),
});

const selectedFiles = computed(() => form.files);
const canSubmit = computed(() => form.files.length >= 2 && !form.processing);
const filteredMergedPdfs = computed(() => {
    const query = mergedFileSearch.value.trim().toLowerCase();

    if (query === '') {
        return mergedPdfs.value;
    }

    return mergedPdfs.value.filter((mergedPdf) =>
        [
            mergedPdf.fileName,
            ...mergedPdf.sourceFileNames,
            formatDateTime(mergedPdf.createdAt),
        ]
            .join(' ')
            .toLowerCase()
            .includes(query),
    );
});
const fileFieldError = computed(() => {
    const directError = form.errors.files;

    if (directError) {
        return directError;
    }

    const nestedEntry = Object.entries(form.errors).find(([key]) =>
        key.startsWith('files.'),
    );

    return nestedEntry?.[1] ?? null;
});

watch(
    () => [flash.value.success, flash.value.error] as const,
    ([success, error]) => {
        if (success) {
            resetMergeForm();
            isMergeDialogOpen.value = false;
            toast.success(success);

            return;
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

const columnHelper = createColumnHelper<MergedPdfRecord>();

const mergedPdfColumns = [
    columnHelper.accessor('fileName', {
        header: 'Merged file',
        cell: ({ row }) =>
            h('div', { class: 'min-w-0 space-y-1' }, [
                h(
                    'p',
                    {
                        class: 'max-w-[16rem] truncate font-medium text-foreground',
                    },
                    row.original.fileName,
                ),
                h(
                    'p',
                    { class: 'text-xs text-muted-foreground' },
                    `${row.original.sourceCount} ${row.original.sourceCount === 1 ? 'source file' : 'source files'}`,
                ),
            ]),
    }),
    columnHelper.display({
        id: 'sources',
        header: 'Sources',
        cell: ({ row }) => {
            const previewNames = row.original.sourceFileNames.slice(0, 2);
            const remainingCount =
                row.original.sourceFileNames.length - previewNames.length;

            return h('div', { class: 'flex max-w-[18rem] flex-wrap gap-1.5' }, [
                ...previewNames.map((sourceFileName) =>
                    h(
                        Badge,
                        {
                            variant: 'secondary',
                            class: 'max-w-full truncate',
                        },
                        () => sourceFileName,
                    ),
                ),
                ...(remainingCount > 0
                    ? [
                          h(
                              Badge,
                              {
                                  variant: 'outline',
                              },
                              () => `+${remainingCount} more`,
                          ),
                      ]
                    : []),
            ]);
        },
    }),
    columnHelper.accessor('fileSize', {
        header: 'Size',
        cell: ({ getValue }) =>
            h(
                'span',
                { class: 'text-sm text-muted-foreground' },
                formatFileSize(getValue()),
            ),
    }),
    columnHelper.accessor('createdAt', {
        header: 'Saved',
        cell: ({ getValue }) =>
            h(
                'span',
                { class: 'text-sm text-muted-foreground' },
                formatDateTime(getValue()),
            ),
    }),
    columnHelper.display({
        id: 'actions',
        header: '',
        cell: ({ row }) =>
            h(
                Button,
                {
                    asChild: true,
                    size: 'sm',
                    variant: 'outline',
                    class: 'gap-2',
                },
                () =>
                    h(
                        'a',
                        {
                            href: row.original.downloadUrl,
                        },
                        [
                            h(Download, { class: 'size-4' }),
                            h('span', 'Download'),
                        ],
                    ),
            ),
    }),
];

const mergedPdfTable = useVueTable({
    get data() {
        return filteredMergedPdfs.value;
    },
    columns: mergedPdfColumns,
    getCoreRowModel: getCoreRowModel(),
});

function mergedPdfColumnClass(columnId: string): string | undefined {
    return columnId === 'actions' ? 'w-[1%] text-right' : undefined;
}

function handleMergeDialogOpenChange(open: boolean): void {
    if (form.processing) {
        return;
    }

    if (!open) {
        form.clearErrors();
    }

    isMergeDialogOpen.value = open;
}

function defaultOutputName(): string {
    const date = new Date();
    const parts = [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('');
    const time = [
        String(date.getHours()).padStart(2, '0'),
        String(date.getMinutes()).padStart(2, '0'),
        String(date.getSeconds()).padStart(2, '0'),
    ].join('');

    return `merged-document-${parts}-${time}.pdf`;
}

function handleFileSelection(event: Event): void {
    const input = event.target as HTMLInputElement;
    const incomingFiles = Array.from(input.files ?? []).filter((file) =>
        isPdfFile(file),
    );

    form.files = [...form.files, ...incomingFiles];
    form.clearErrors('files');
    input.value = '';
}

function isPdfFile(file: File): boolean {
    return (
        file.type === 'application/pdf' ||
        file.name.toLowerCase().endsWith('.pdf')
    );
}

function moveFile(index: number, direction: -1 | 1): void {
    const targetIndex = index + direction;

    if (targetIndex < 0 || targetIndex >= form.files.length) {
        return;
    }

    const reordered = [...form.files];
    const [file] = reordered.splice(index, 1);

    reordered.splice(targetIndex, 0, file);
    form.files = reordered;
}

function removeFile(index: number): void {
    form.files = form.files.filter((_, fileIndex) => fileIndex !== index);
}

function openPicker(): void {
    fileInput.value?.click();
}

function resetMergeForm(): void {
    form.files = [];
    form.outputName = defaultOutputName();
    form.clearErrors();

    if (fileInput.value) {
        fileInput.value.value = '';
    }
}

function submit(): void {
    form.post(docMerge.store.url(), {
        forceFormData: true,
        preserveScroll: true,
    });
}

function formatFileSize(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 B';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex++;
    }

    return `${value >= 10 || unitIndex === 0 ? value.toFixed(0) : value.toFixed(1)} ${units[unitIndex]}`;
}

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Unknown date';
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
</script>

<template>
    <Head title="Doc Merge" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <div class="flex items-center justify-end">
                <Button
                    type="button"
                    size="sm"
                    class="gap-2 text-xs"
                    @click="handleMergeDialogOpenChange(true)"
                >
                    <FileText class="size-4" />
                    Merge PDFs
                </Button>
            </div>
        </template>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardHeader class="space-y-1">
                    <CardTitle class="text-xl">Saved merged PDFs</CardTitle>
                    <CardDescription>
                        Your merged files stay here for download after each
                        successful merge.
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-4">
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        />
                        <Input
                            v-model="mergedFileSearch"
                            type="search"
                            placeholder="Search merged files"
                            class="pl-10"
                        />
                    </div>

                    <div
                        class="overflow-hidden rounded-2xl border bg-background"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow
                                    v-for="headerGroup in mergedPdfTable.getHeaderGroups()"
                                    :key="headerGroup.id"
                                >
                                    <TableHead
                                        v-for="header in headerGroup.headers"
                                        :key="header.id"
                                        :class="
                                            mergedPdfColumnClass(
                                                header.column.id,
                                            )
                                        "
                                    >
                                        <template v-if="!header.isPlaceholder">
                                            <FlexRender
                                                :render="
                                                    header.column.columnDef
                                                        .header
                                                "
                                                :props="header.getContext()"
                                            />
                                        </template>
                                    </TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                <template
                                    v-if="
                                        mergedPdfTable.getRowModel().rows
                                            .length > 0
                                    "
                                >
                                    <TableRow
                                        v-for="row in mergedPdfTable.getRowModel()
                                            .rows"
                                        :key="row.id"
                                    >
                                        <TableCell
                                            v-for="cell in row.getVisibleCells()"
                                            :key="cell.id"
                                            :class="
                                                mergedPdfColumnClass(
                                                    cell.column.id,
                                                )
                                            "
                                        >
                                            <FlexRender
                                                :render="
                                                    cell.column.columnDef.cell
                                                "
                                                :props="cell.getContext()"
                                            />
                                        </TableCell>
                                    </TableRow>
                                </template>

                                <TableEmpty
                                    v-else
                                    :colspan="mergedPdfColumns.length"
                                >
                                    {{
                                        mergedPdfs.length === 0
                                            ? 'No merged PDFs saved yet.'
                                            : 'No merged PDFs match your search.'
                                    }}
                                </TableEmpty>
                            </TableBody>
                        </Table>
                    </div>
                </CardContent>
            </Card>

            <Dialog
                :open="isMergeDialogOpen"
                @update:open="handleMergeDialogOpenChange"
            >
                <DialogContent class="sm:max-w-3xl">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Merge PDF files</DialogTitle>
                        <DialogDescription>
                            Upload at least two PDFs, arrange them in order, and
                            save the merged result to your account.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="max-h-[70vh] space-y-6 overflow-y-auto pr-1">
                        <input
                            ref="fileInput"
                            type="file"
                            accept=".pdf,application/pdf"
                            multiple
                            class="hidden"
                            @change="handleFileSelection"
                        />

                        <div class="flex justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="gap-2"
                                @click="openPicker"
                            >
                                <Upload class="size-4" />
                                Add PDFs
                            </Button>
                        </div>

                        <div class="space-y-2">
                            <Label for="outputName">Output filename</Label>
                            <Input
                                id="outputName"
                                v-model="form.outputName"
                                type="text"
                                placeholder="merged-document.pdf"
                            />
                            <InputError :message="form.errors.outputName" />
                        </div>

                        <div class="space-y-3">
                            <div
                                class="flex items-center justify-between gap-3"
                            >
                                <div>
                                    <p class="text-sm font-medium">
                                        Selected files
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Top to bottom is the final merge order.
                                    </p>
                                </div>

                                <Badge variant="outline">
                                    {{ selectedFiles.length }} files
                                </Badge>
                            </div>

                            <div
                                v-if="selectedFiles.length === 0"
                                class="rounded-2xl border border-dashed px-4 py-8 text-center text-sm text-muted-foreground"
                            >
                                No PDFs selected yet.
                            </div>

                            <div v-else class="space-y-3">
                                <Card
                                    v-for="(file, index) in selectedFiles"
                                    :key="`${file.name}-${file.size}-${index}`"
                                    class="rounded-2xl"
                                >
                                    <CardHeader
                                        class="flex flex-col gap-3 sm:flex-row sm:items-start"
                                    >
                                        <div class="min-w-0 flex-1 space-y-1">
                                            <CardTitle
                                                class="truncate text-base"
                                            >
                                                {{ file.name }}
                                            </CardTitle>
                                            <CardDescription>
                                                {{ formatFileSize(file.size) }}
                                            </CardDescription>
                                        </div>

                                        <div class="flex items-center gap-2">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon-sm"
                                                :disabled="index === 0"
                                                @click="moveFile(index, -1)"
                                            >
                                                <ArrowUp class="size-4" />
                                                <span class="sr-only"
                                                    >Move up</span
                                                >
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon-sm"
                                                :disabled="
                                                    index ===
                                                    selectedFiles.length - 1
                                                "
                                                @click="moveFile(index, 1)"
                                            >
                                                <ArrowDown class="size-4" />
                                                <span class="sr-only"
                                                    >Move down</span
                                                >
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                                @click="removeFile(index)"
                                            >
                                                <Trash2 class="size-4" />
                                                <span class="sr-only"
                                                    >Remove file</span
                                                >
                                            </Button>
                                        </div>
                                    </CardHeader>
                                </Card>
                            </div>

                            <InputError
                                :message="fileFieldError ?? undefined"
                            />
                        </div>
                    </div>

                    <DialogFooter class="gap-2">
                        <div class="mr-auto text-xs text-muted-foreground">
                            <p>
                                PDF-only for now. Unsupported or locked PDFs
                                will be rejected during merge.
                            </p>
                            <p v-if="form.progress" class="mt-1">
                                Uploading {{ form.progress.percentage }}%
                            </p>
                        </div>

                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="form.processing"
                            @click="handleMergeDialogOpenChange(false)"
                        >
                            Cancel
                        </Button>

                        <Button
                            type="button"
                            class="gap-2"
                            :disabled="!canSubmit"
                            @click="submit"
                        >
                            <LoaderCircle
                                v-if="form.processing"
                                class="size-4 animate-spin"
                            />
                            <FileText v-else class="size-4" />
                            {{
                                form.processing
                                    ? 'Merging PDFs...'
                                    : 'Merge and save'
                            }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
