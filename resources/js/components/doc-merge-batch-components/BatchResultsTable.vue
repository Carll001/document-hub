<script setup lang="ts">
import {
    Download,
    Eye,
    Mail,
    MoreHorizontal,
    Search,
    Trash2,
} from 'lucide-vue-next';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    BatchMergedOutput,
    BatchMergeHistoryRecord,
    BatchResultsPaginationState,
} from '@/components/doc-merge-batch-components/types';
import {
    formatDateTime,
    formatTinDigitsOnly,
    isMergedRecord,
    mergeHistoryRecordKey,
} from '@/components/doc-merge-batch-components/utils';

const props = defineProps<{
    canBulkDelete: boolean;
    isBatchBusy: boolean;
    isRecordSelected: (record: BatchMergeHistoryRecord) => boolean;
    pageUrl: string;
    pagination: BatchResultsPaginationState;
    results: BatchMergeHistoryRecord[];
    search: string;
    selectedCount: number;
    selectAllState: boolean | 'indeterminate';
    totalResults: number;
}>();
const paginationControls = ref<HTMLElement | null>(null);

const emit = defineEmits<{
    bulkDownloadSelected: [];
    bulkSendEmailSelected: [];
    deleteRecord: [record: BatchMergeHistoryRecord];
    openBulkDelete: [];
    openFailure: [record: BatchMergeHistoryRecord];
    openPreview: [record: BatchMergeHistoryRecord];
    openSendEmail: [record: BatchMergeHistoryRecord];
    toggleAll: [checked: boolean | 'indeterminate'];
    toggleRecord: [payload: { record: BatchMergeHistoryRecord; checked: boolean | 'indeterminate' }];
    'update:search': [value: string];
}>();

function visitPage(page: number): void {
    if (page === props.pagination.currentPage) {
        return;
    }

    router.get(
        props.pageUrl,
        { page },
        {
            preserveScroll: true,
            preserveState: true,
        },
    );
}

const selectedMergedCount = computed(() =>
    props.results.filter((record): record is BatchMergedOutput =>
        isMergedRecord(record) && props.isRecordSelected(record),
    ).length,
);

const canBulkDownloadSelected = computed(() => selectedMergedCount.value > 0);
const canBulkSendEmailSelected = computed(() => selectedMergedCount.value > 0 && !props.isBatchBusy);
</script>

<template>
    <CardHeader class="space-y-1">
        <CardTitle class="text-xl">Merge table</CardTitle>
        <CardDescription>
            Review merged outputs and failed results inside this batch.
        </CardDescription>
    </CardHeader>

    <CardContent class="space-y-4">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <Input
                    :model-value="props.search"
                    type="search"
                    placeholder="Search this batch merge table"
                    class="pl-10"
                    @update:model-value="emit('update:search', String($event))"
                />
            </div>

            <div class="flex flex-wrap gap-2 self-end md:self-auto">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2"
                    :disabled="!canBulkDownloadSelected"
                    @click="emit('bulkDownloadSelected')"
                >
                    <Download class="size-4" />
                    {{
                        selectedMergedCount > 0
                            ? `Download selected (${selectedMergedCount})`
                            : 'Download selected'
                    }}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-2"
                    :disabled="!canBulkSendEmailSelected"
                    @click="emit('bulkSendEmailSelected')"
                >
                    <Mail class="size-4" />
                    {{
                        selectedMergedCount > 0
                            ? `Send email selected (${selectedMergedCount})`
                            : 'Send email selected'
                    }}
                </Button>
                <Button
                    type="button"
                    variant="destructive"
                    size="sm"
                    class="gap-2"
                    :disabled="!props.canBulkDelete"
                    @click="emit('openBulkDelete')"
                >
                    <Trash2 class="size-4" />
                    {{
                        props.selectedCount > 0
                            ? `Delete selected (${props.selectedCount})`
                            : 'Delete selected'
                    }}
                </Button>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-background">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[1%]">
                            <Checkbox
                                :checked="props.selectAllState"
                                :disabled="props.results.length === 0"
                                aria-label="Select all merge results"
                                @update:checked="emit('toggleAll', $event)"
                            />
                        </TableHead>
                        <TableHead class="w-[1%]">#</TableHead>
                        <TableHead>Result</TableHead>
                        <TableHead>TIN</TableHead>
                        <TableHead>Uploaded at</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead class="w-[1%] text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <template v-if="props.results.length > 0">
                        <TableRow
                            v-for="(record, index) in props.results"
                            :key="mergeHistoryRecordKey(record)"
                        >
                            <TableCell>
                                <Checkbox
                                    :checked="props.isRecordSelected(record)"
                                    :aria-label="`Select ${record.fileName}`"
                                    @update:checked="
                                        emit('toggleRecord', { record, checked: $event })
                                    "
                                />
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ index + 1 }}
                            </TableCell>
                            <TableCell>
                                <div class="min-w-0 space-y-1">
                                    <p class="max-w-[16rem] truncate font-medium text-foreground">
                                        {{ record.fileName }}
                                    </p>
                                    <p
                                        v-if="isMergedRecord(record)"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ record.sourceCount }} source{{
                                            record.sourceCount === 1 ? ' file' : ' files'
                                        }}
                                    </p>
                                    <template v-else>
                                        <p class="text-xs text-muted-foreground">
                                            Matched PDF: {{ record.groupLabel }}
                                        </p>
                                        <p class="max-w-[18rem] text-xs text-destructive">
                                            {{ record.errorMessage }}
                                        </p>
                                    </template>
                                </div>
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{
                                    isMergedRecord(record) && record.tinNumber
                                        ? formatTinDigitsOnly(record.tinNumber)
                                        : '-'
                                }}
                            </TableCell>
                            <TableCell class="text-sm text-muted-foreground">
                                {{ formatDateTime(record.createdAt) }}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    v-if="isMergedRecord(record)"
                                    variant="outline"
                                    class="border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300"
                                >
                                    Success
                                </Badge>
                                <Badge v-else variant="destructive">
                                    Failed
                                </Badge>
                            </TableCell>
                            <TableCell>
                                <div class="flex justify-end">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon-sm"
                                            >
                                                <MoreHorizontal class="size-4" />
                                                <span class="sr-only">Open actions</span>
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent
                                            align="end"
                                            class="w-48 rounded-lg"
                                        >
                                            <template v-if="isMergedRecord(record)">
                                                <DropdownMenuItem
                                                    @select="emit('openPreview', record)"
                                                >
                                                    <Eye class="size-4" />
                                                    Preview
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="props.isBatchBusy"
                                                    @select="emit('openSendEmail', record)"
                                                >
                                                    <Mail class="size-4" />
                                                    Send to email
                                                </DropdownMenuItem>
                                                <DropdownMenuItem :as-child="true">
                                                    <a
                                                        :href="record.downloadUrl ?? undefined"
                                                        class="flex w-full items-center gap-2"
                                                    >
                                                        <Download class="size-4" />
                                                        Download
                                                    </a>
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="props.isBatchBusy"
                                                    variant="destructive"
                                                    @select="emit('deleteRecord', record)"
                                                >
                                                    <Trash2 class="size-4" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </template>
                                            <template v-else>
                                                <DropdownMenuItem
                                                    @select="emit('openFailure', record)"
                                                >
                                                    <Eye class="size-4" />
                                                    View error
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    :disabled="props.isBatchBusy"
                                                    variant="destructive"
                                                    @select="emit('deleteRecord', record)"
                                                >
                                                    <Trash2 class="size-4" />
                                                    Delete
                                                </DropdownMenuItem>
                                            </template>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </div>
                            </TableCell>
                        </TableRow>
                    </template>

                    <TableEmpty v-else :colspan="7">
                        {{
                            props.totalResults === 0
                                ? 'No merge results in this batch yet.'
                                : 'No batch results match your search.'
                        }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <div
            v-if="props.pagination.lastPage > 1"
            ref="paginationControls"
            class="flex items-center justify-center gap-2 pt-2"
        >
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="props.pagination.currentPage <= 1"
                @click="visitPage(props.pagination.currentPage - 1)"
            >
                Previous
            </Button>
            <span class="text-sm">Page {{ props.pagination.currentPage }} / {{ props.pagination.lastPage }}</span>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="
                    props.pagination.currentPage >= props.pagination.lastPage
                "
                @click="visitPage(props.pagination.currentPage + 1)"
            >
                Next
            </Button>
        </div>
    </CardContent>
</template>
