import type { ColumnDef } from '@tanstack/vue-table';
import { AlertCircle, ArrowUpDown, Download, Eye, FileText, MoreHorizontal, Pencil, PenLine, Trash2, Upload } from 'lucide-vue-next';
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
    const normalizeKey = (value: string): string =>
        value.toLowerCase().replace(/[^a-z0-9]/g, '');

    const fieldValue = (row: UnifiedItem, aliases: string[]): string => {
        const normalizedAliases = aliases.map(normalizeKey);
        const entry = Object.entries(row.row_data ?? {}).find(([key]) =>
            normalizedAliases.includes(normalizeKey(key)),
        );
        const raw = entry?.[1];

        if (typeof raw !== 'string') {
            return '-';
        }

        const trimmed = raw.trim();
        return trimmed === '' ? '-' : trimmed;
    };

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
            id: 'total_current_assets',
            header: 'Total Current Assets',
            enableSorting: false,
            cell: ({ row }) => fieldValue(row.original, ['total_current_assets', 'total current assets', 'total_assets', 'total assets']),
        },
        {
            id: 'inventory',
            header: 'Inventory',
            enableSorting: false,
            cell: ({ row }) => fieldValue(row.original, ['inventory']),
        },
        {
            id: 'gross_profit',
            header: 'Gross Profit',
            enableSorting: false,
            cell: ({ row }) => fieldValue(row.original, ['gross_profit', 'gross profit']),
        },
        {
            id: 'cogs',
            header: 'COGS',
            enableSorting: false,
            cell: ({ row }) => fieldValue(row.original, ['cogs', 'cost_of_goods_sold', 'cost of goods sold']),
        },
        {
            id: 'net_income',
            header: 'Net Income',
            enableSorting: false,
            cell: ({ row }) => fieldValue(row.original, ['net_income', 'net income']),
        },
        {
            id: 'cash',
            header: 'Cash',
            enableSorting: false,
            cell: ({ row }) => fieldValue(row.original, ['cash', 'cash_on_hand', 'cash on hand']),
        },
        {
            id: 'pt_payable',
            header: 'PT Payable',
            enableSorting: false,
            cell: ({ row }) => fieldValue(row.original, ['pt_payable', 'pt payable', 'payables', 'accounts_payable', 'accounts payable']),
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
            id: 'actions',
            header: 'Actions',
            enableSorting: false,
            cell: ({ row }) => {
                const parsed = params.parseErrorDetails(row.original);
                const hasError = parsed.missingData.length > 0
                    || parsed.errors.length > 0
                    || Boolean(row.original.error_message?.trim())
                    || Boolean(parsed.message);

                return h(DropdownMenu, {}, {
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
                                hasError
                                    ? h(DropdownMenuItem, { onSelect: () => { params.openErrorDialog(row.original); } }, {
                                        default: () => [h(AlertCircle, { class: 'size-4' }), 'Error Details'],
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
                });
            },
        },
    ]);
}
