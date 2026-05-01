import type { ColumnDef } from '@tanstack/vue-table';
import { ArrowUpDown, Download, Eye, FileText, MoreHorizontal, Pencil, PenLine, Trash2, Upload } from 'lucide-vue-next';
import { computed, h, type Ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { UnifiedItem } from '@/components/afs-components/types';
import { statusBadgeClass, statusBadgeVariant } from '@/components/afs-components/utils';
import documentGeneratorRoutes from '@/routes/afs-filing';

type ParsedErrorDetails = {
    missingData: string[];
    errors: string[];
    message: string | null;
};

type UseAfsIndexColumnsParams = {
    selectAllState: Ref<boolean | 'indeterminate'>;
    itemsCount: Ref<number>;
    deletingItems: Ref<boolean>;
    selectedItemIds: Ref<number[]>;
    currentPage: Ref<number>;
    perPage: Ref<number>;
    signatureEnabled: boolean;
    queuingSignatures: Ref<boolean>;
    toggleAllVisibleRows: (checked: boolean | 'indeterminate') => void;
    toggleItemSelection: (itemId: number, checked: boolean | 'indeterminate') => void;
    extractTin: (item: UnifiedItem) => string;
    formatDateTime: (value: string | null) => string;
    displayStatus: (item: UnifiedItem) => string;
    parseErrorDetails: (item: UnifiedItem) => ParsedErrorDetails;
    openErrorDialog: (item: UnifiedItem) => void;
    canEditItem: (item: UnifiedItem) => boolean;
    openEditDialog: (item: UnifiedItem) => void;
    isItemSigning: (itemId: number) => boolean;
    isPreparingSignature: (itemId: number) => boolean;
    applySignatureToItem: (item: UnifiedItem) => void;
    retryItem: (item: UnifiedItem) => void;
    canDeleteItem: (item: UnifiedItem) => boolean;
    confirmDelete: (ids: number[]) => void;
};

export function useAfsIndexColumns(params: UseAfsIndexColumnsParams) {
    return computed<ColumnDef<UnifiedItem>[]>(() => [
        {
            id: 'selection',
            header: () =>
                h(Checkbox, {
                    modelValue: params.selectAllState.value,
                    disabled: params.itemsCount.value === 0 || params.deletingItems.value,
                    'aria-label': 'Select visible rows',
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') => params.toggleAllVisibleRows(value),
                }),
            enableSorting: false,
            cell: ({ row }) =>
                h(Checkbox, {
                    modelValue: params.selectedItemIds.value.includes(row.original.id),
                    disabled: params.deletingItems.value,
                    'aria-label': `Select row ${row.original.row_number}`,
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') => params.toggleItemSelection(row.original.id, value),
                }),
        },
        {
            id: 'index',
            header: '#',
            enableSorting: false,
            cell: ({ row }) =>
                String((params.currentPage.value - 1) * params.perPage.value + row.index + 1),
        },
        {
            id: 'tin',
            header: 'TIN',
            enableSorting: false,
            cell: ({ row }) => params.extractTin(row.original),
        },
        {
            id: 'company',
            accessorKey: 'company',
            header: 'Company',
            enableSorting: false,
            cell: ({ row }) => row.original.company || '-',
        },
        {
            id: 'created_at',
            accessorKey: 'created_at',
            header: () =>
                h('span', { class: 'inline-flex items-center gap-1' }, [
                    'Uploaded',
                    h(ArrowUpDown, { class: 'size-4 text-muted-foreground' }),
                ]),
            enableSorting: true,
            cell: ({ row }) => params.formatDateTime(row.original.created_at),
        },
        {
            id: 'status',
            accessorKey: 'status',
            header: () =>
                h('span', { class: 'inline-flex items-center gap-1' }, [
                    'Status',
                    h(ArrowUpDown, { class: 'size-4 text-muted-foreground' }),
                ]),
            enableSorting: true,
            cell: ({ row }) =>
                h('div', { class: 'flex items-center gap-2' }, [
                    h(
                        Badge,
                        {
                            variant: statusBadgeVariant(row.original.status),
                            class: statusBadgeClass(row.original.status),
                        },
                        () => params.displayStatus(row.original),
                    ),
                ]),
        },
        {
            id: 'error_message',
            accessorKey: 'error_message',
            header: 'Error',
            enableSorting: false,
            cell: ({ row }) => {
                const parsed = params.parseErrorDetails(row.original);
                const hasDetails = parsed.missingData.length > 0 || parsed.errors.length > 0;

                if (hasDetails || row.original.error_message) {
                    return h(
                        Badge,
                        {
                            variant: 'destructive',
                            class: 'cursor-pointer',
                            onClick: () => params.openErrorDialog(row.original),
                        },
                        () => 'Error',
                    );
                }

                return '-';
            },
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
                                h(Button, { variant: 'ghost', size: 'icon-sm', 'aria-label': 'Row actions' }, {
                                    default: () => h(MoreHorizontal, { class: 'size-4' }),
                                }),
                        }),
                        h(DropdownMenuContent, { align: 'end', class: 'w-44' }, {
                            default: () => [
                                h(DropdownMenuItem, {
                                    disabled: !params.canEditItem(row.original),
                                    onSelect: () => {
                                        if (!params.canEditItem(row.original)) return;
                                        params.openEditDialog(row.original);
                                    },
                                }, { default: () => [h(Pencil, { class: 'size-4' }), 'Edit'] }),
                                h(DropdownMenuSub, {}, {
                                    default: () => [
                                        h(DropdownMenuSubTrigger, {
                                            disabled: !row.original.docx_available && !row.original.pdf_available,
                                        }, {
                                            default: () => [h(Download, { class: 'mr-2 size-4' }), 'Download as'],
                                        }),
                                        h(DropdownMenuSubContent, { class: 'w-40' }, {
                                            default: () => [
                                                row.original.docx_available
                                                    ? h(DropdownMenuItem, { asChild: true }, {
                                                        default: () => h('a', {
                                                            href: documentGeneratorRoutes.items.download.url({ item: row.original.id, type: 'docx' }),
                                                            class: 'flex w-full items-center gap-2',
                                                        }, [h(FileText, { class: 'mr-2 size-4' }), 'DOCX']),
                                                    })
                                                    : h(DropdownMenuItem, { disabled: true }, { default: () => [h(FileText, { class: 'mr-2 size-4' }), 'DOCX'] }),
                                                row.original.pdf_available
                                                    ? h(DropdownMenuItem, { asChild: true }, {
                                                        default: () => h('a', {
                                                            href: documentGeneratorRoutes.items.download.url({ item: row.original.id, type: 'pdf' }),
                                                            class: 'flex w-full items-center gap-2',
                                                        }, [h(FileText, { class: 'mr-2 size-4' }), 'PDF']),
                                                    })
                                                    : h(DropdownMenuItem, { disabled: true }, { default: () => [h(FileText, { class: 'mr-2 size-4' }), 'PDF'] }),
                                            ],
                                        }),
                                    ],
                                }),
                                row.original.pdf_available
                                    ? h(DropdownMenuItem, { asChild: true }, {
                                        default: () => h('a', {
                                            href: `${documentGeneratorRoutes.items.download.url({ item: row.original.id, type: 'pdf' })}?inline=1`,
                                            target: '_blank',
                                            rel: 'noopener noreferrer',
                                            class: 'flex w-full items-center gap-2',
                                        }, [h(Eye, { class: 'size-4' }), 'Preview PDF']),
                                    })
                                    : h(DropdownMenuItem, { disabled: true }, { default: () => [h(Eye, { class: 'size-4' }), 'Preview PDF'] }),
                                params.signatureEnabled
                                    ? h(DropdownMenuItem, {
                                        disabled: !row.original.pdf_available
                                            || row.original.signature_applied
                                            || params.isItemSigning(row.original.id)
                                            || params.queuingSignatures.value,
                                        onSelect: () => { params.applySignatureToItem(row.original); },
                                    }, {
                                        default: () => [
                                            h(PenLine, { class: 'size-4' }),
                                            row.original.signature_applied
                                                ? 'Signed'
                                                : params.isPreparingSignature(row.original.id)
                                                    ? 'Preparing for signing...'
                                                    : params.isItemSigning(row.original.id)
                                                        ? 'Queuing for signing...'
                                                        : row.original.status === 'signing'
                                                            ? 'Signing...'
                                                            : 'Add Signature',
                                        ],
                                    })
                                    : null,
                                row.original.status === 'failed'
                                    ? h(DropdownMenuItem, { onSelect: () => { params.retryItem(row.original); } }, {
                                        default: () => [h(Upload, { class: 'size-4' }), 'Retry'],
                                    })
                                    : null,
                                h(DropdownMenuItem, {
                                    disabled: !params.canDeleteItem(row.original) || params.deletingItems.value,
                                    variant: 'destructive',
                                    onSelect: () => { params.confirmDelete([row.original.id]); },
                                }, { default: () => [h(Trash2, { class: 'size-4' }), 'Delete'] }),
                            ],
                        }),
                    ],
                }),
        },
    ]);
}

