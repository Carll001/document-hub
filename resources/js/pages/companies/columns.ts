import type { ColumnDef } from '@tanstack/vue-table';
import { h, type ComputedRef, type Ref } from 'vue';
import { ArrowUpDown, Eye, MoreHorizontal, Pencil, Trash2 } from 'lucide-vue-next';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export type CompanyRow = {
    id: number;
    name: string;
    tin: string;
    address: string;
    created_at: string;
};

type CreateCompanyColumnsOptions = {
    allVisibleSelected: ComputedRef<boolean>;
    paginatedRows: ComputedRef<CompanyRow[]>;
    selectedIds: Ref<number[]>;
    formatDate: (value: string) => string;
    onView: (row: CompanyRow) => void;
    onEdit: (row: CompanyRow) => void;
    onDelete: (row: CompanyRow) => void;
};

export function createCompanyColumns(options: CreateCompanyColumnsOptions): ColumnDef<CompanyRow>[] {
    return [
        {
            id: 'select',
            header: () =>
                h(Checkbox, {
                    modelValue: options.allVisibleSelected.value,
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') => {
                        if (value === 'indeterminate') {
                            return;
                        }
                        if (value) {
                            const visibleIds = options.paginatedRows.value.map((row) => row.id);
                            options.selectedIds.value = Array.from(
                                new Set([...options.selectedIds.value, ...visibleIds]),
                            );
                            return;
                        }
                        const visibleSet = new Set(options.paginatedRows.value.map((row) => row.id));
                        options.selectedIds.value = options.selectedIds.value.filter(
                            (id) => !visibleSet.has(id),
                        );
                    },
                    'aria-label': 'Select all visible companies',
                }),
            cell: ({ row }) =>
                h(Checkbox, {
                    modelValue: options.selectedIds.value.includes(row.original.id),
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') => {
                        if (value === 'indeterminate') {
                            return;
                        }
                        if (value) {
                            options.selectedIds.value = Array.from(
                                new Set([...options.selectedIds.value, row.original.id]),
                            );
                            return;
                        }
                        options.selectedIds.value = options.selectedIds.value.filter(
                            (id) => id !== row.original.id,
                        );
                    },
                    'aria-label': `Select ${row.original.name}`,
                }),
        },
        {
            accessorKey: 'name',
            header: () =>
                h('div', { class: 'flex items-center gap-2' }, [
                    h('span', 'Company'),
                    h(ArrowUpDown, { class: 'size-3.5 text-slate-400' }),
                ]),
            enableSorting: true,
            cell: ({ row }) => {
                const company = row.original;
                const initials = company.name
                    .split(' ')
                    .map((part) => part[0])
                    .join('')
                    .slice(0, 2)
                    .toUpperCase();

                return h('div', { class: 'flex items-center gap-3' }, [
                    h(Avatar, { class: 'size-8 border border-blue-100' }, () =>
                        h(
                            AvatarFallback,
                            { class: 'bg-blue-100 text-xs font-semibold text-blue-700' },
                            () => initials,
                        ),
                    ),
                    h('span', { class: 'font-semibold text-slate-900' }, company.name),
                ]);
            },
        },
        {
            accessorKey: 'tin',
            header: () =>
                h('div', { class: 'flex items-center gap-2' }, [
                    h('span', 'TIN'),
                    h(ArrowUpDown, { class: 'size-3.5 text-slate-400' }),
                ]),
            enableSorting: true,
        },
        {
            accessorKey: 'created_at',
            header: () =>
                h('div', { class: 'flex items-center gap-2' }, [
                    h('span', 'Date Added'),
                    h(ArrowUpDown, { class: 'size-3.5 text-slate-400' }),
                ]),
            enableSorting: true,
            cell: ({ row }) => options.formatDate(row.original.created_at),
        },
        {
            id: 'actions',
            header: 'Actions',
            cell: ({ row }) =>
                h(DropdownMenu, {}, () => [
                    h(DropdownMenuTrigger, { asChild: true }, () =>
                        h(
                            Button,
                            { type: 'button', variant: 'ghost', size: 'sm' },
                            () => h(MoreHorizontal, { class: 'size-4' }),
                        ),
                    ),
                    h(DropdownMenuContent, { align: 'end' }, () => [
                        h(
                            DropdownMenuItem,
                            { onSelect: () => options.onView(row.original) },
                            () => [
                                h(Eye, { class: 'size-4' }),
                                h('span', 'View'),
                            ],
                        ),
                        h(
                            DropdownMenuItem,
                            { onSelect: () => options.onEdit(row.original) },
                            () => [
                                h(Pencil, { class: 'size-4' }),
                                h('span', 'Edit'),
                            ],
                        ),
                        h(
                            DropdownMenuItem,
                            {
                                variant: 'destructive',
                                onSelect: () => options.onDelete(row.original),
                            },
                            () => [
                                h(Trash2, { class: 'size-4' }),
                                h('span', 'Delete'),
                            ],
                        ),
                    ]),
                ]),
        },
    ];
}
