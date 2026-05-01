import type { ColumnDef } from '@tanstack/vue-table';
import { Download, Eye, MoreHorizontal, Trash2 } from 'lucide-vue-next';
import { computed, h, type Ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import documentGeneratorRoutes from '@/routes/afs-filing';
import type { UnifiedItem } from '@/components/afs-components/types';
import { statusBadgeClass, statusBadgeVariant } from '@/components/afs-components/utils';

type UseAfsCompletedColumnsParams = {
    currentPage: Ref<number>;
    perPage: Ref<number>;
    itemsCount: Ref<number>;
    deletingItems: Ref<boolean>;
    selectedItemIds: Ref<number[]>;
    selectAllState: Ref<boolean | 'indeterminate'>;
    toggleAllVisibleRows: (checked: boolean | 'indeterminate') => void;
    toggleItemSelection: (itemId: number, checked: boolean | 'indeterminate') => void;
    requestDeleteRow: (itemId: number) => void;
};

export function useAfsCompletedColumns(params: UseAfsCompletedColumnsParams) {
    const formatDateTime = (value: string | null): string => {
        if (!value) {
            return '-';
        }

        const parsed = new Date(value);
        if (Number.isNaN(parsed.getTime())) {
            return value;
        }

        return parsed.toLocaleString();
    };

    return computed<ColumnDef<UnifiedItem>[]>(() => [
        {
            id: 'selection',
            header: () =>
                h(Checkbox, {
                    modelValue: params.selectAllState.value,
                    disabled: params.itemsCount.value === 0 || params.deletingItems.value,
                    'aria-label': 'Select visible completed rows',
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') => params.toggleAllVisibleRows(value),
                }),
            enableSorting: false,
            cell: ({ row }) =>
                h(Checkbox, {
                    modelValue: params.selectedItemIds.value.includes(row.original.id),
                    disabled: params.deletingItems.value,
                    'aria-label': `Select completed row ${row.original.row_number}`,
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') =>
                        params.toggleItemSelection(row.original.id, value),
                }),
        },
        {
            id: 'index',
            header: '#',
            enableSorting: false,
            cell: ({ row }) =>
                String((params.currentPage.value - 1) * params.perPage.value + row.index + 1),
        },
        { id: 'tin', header: 'TIN', enableSorting: false, cell: ({ row }) => row.original.tin?.trim() ? row.original.tin : '-' },
        { id: 'company', accessorKey: 'company', header: 'Company', enableSorting: false, cell: ({ row }) => row.original.company || '-' },
        { id: 'created_at', accessorKey: 'created_at', header: 'Uploaded', enableSorting: true, cell: ({ row }) => formatDateTime(row.original.created_at) },
        {
            id: 'status',
            accessorKey: 'status',
            header: 'Status',
            enableSorting: true,
            cell: ({ row }) =>
                h(
                    Badge,
                    {
                        variant: statusBadgeVariant(row.original.status),
                        class: statusBadgeClass(row.original.status),
                    },
                    () => (row.original.signature_applied ? 'Signed' : row.original.status === 'pdf_done' ? 'Generated' : row.original.status),
                ),
        },
        {
            id: 'actions',
            header: 'Actions',
            enableSorting: false,
            cell: ({ row }) =>
                h(DropdownMenu, {}, {
                    default: () => [
                        h(DropdownMenuTrigger, { asChild: true }, {
                            default: () =>
                                h(Button, { variant: 'ghost', size: 'icon-sm', 'aria-label': 'Completed row actions' }, {
                                    default: () => h(MoreHorizontal, { class: 'size-4' }),
                                }),
                        }),
                        h(DropdownMenuContent, { align: 'end', class: 'w-44' }, {
                            default: () => [
                                h(DropdownMenuItem, { asChild: true }, {
                                    default: () =>
                                        h('a', {
                                            href: `${documentGeneratorRoutes.items.download.url({ item: row.original.id, type: 'pdf' })}?inline=1`,
                                            target: '_blank',
                                            rel: 'noopener noreferrer',
                                            class: 'flex w-full items-center gap-2',
                                        }, [h(Eye, { class: 'size-4' }), 'Preview']),
                                }),
                                h(DropdownMenuItem, { asChild: true }, {
                                    default: () =>
                                        h('a', {
                                            href: documentGeneratorRoutes.items.download.url({ item: row.original.id, type: 'pdf' }),
                                            class: 'flex w-full items-center gap-2',
                                        }, [h(Download, { class: 'size-4' }), 'Download']),
                                }),
                                h(DropdownMenuItem, {
                                    disabled: params.deletingItems.value,
                                    class: 'text-destructive focus:text-destructive',
                                    onSelect: () => params.requestDeleteRow(row.original.id),
                                }, { default: () => [h(Trash2, { class: 'size-4' }), 'Delete'] }),
                            ],
                        }),
                    ],
                }),
        },
    ]);
}

