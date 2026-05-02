import type { ColumnDef } from '@tanstack/vue-table'
import { h, type ComputedRef, type Ref } from 'vue'
import { ArrowUpDown, Eye, MoreHorizontal, Trash2 } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

export type TemplateRow = {
    id: number
    name: string
    form: string
    description: string
    status: string
    last_modified: string | null
}

type CreateTemplateColumnsOptions = {
    allVisibleSelected: ComputedRef<boolean>
    paginatedRows: ComputedRef<TemplateRow[]>
    selectedIds: Ref<number[]>
    formatDate: (value: string | null) => string
    onView: (row: TemplateRow) => void
    onDelete: (row: TemplateRow) => void
}

export function createTemplateColumns(options: CreateTemplateColumnsOptions): ColumnDef<TemplateRow>[] {
    return [
        {
            id: 'select',
            header: () =>
                h(Checkbox, {
                    modelValue: options.allVisibleSelected.value,
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') => {
                        if (value === 'indeterminate') return
                        if (value) {
                            const visibleIds = options.paginatedRows.value.map((row) => row.id)
                            options.selectedIds.value = Array.from(new Set([...options.selectedIds.value, ...visibleIds]))
                            return
                        }
                        const visibleSet = new Set(options.paginatedRows.value.map((row) => row.id))
                        options.selectedIds.value = options.selectedIds.value.filter((id) => !visibleSet.has(id))
                    },
                    'aria-label': 'Select all visible templates',
                }),
            cell: ({ row }) =>
                h(Checkbox, {
                    modelValue: options.selectedIds.value.includes(row.original.id),
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') => {
                        if (value === 'indeterminate') return
                        if (value) {
                            options.selectedIds.value = Array.from(new Set([...options.selectedIds.value, row.original.id]))
                            return
                        }
                        options.selectedIds.value = options.selectedIds.value.filter((id) => id !== row.original.id)
                    },
                    'aria-label': `Select ${row.original.name}`,
                }),
        },
        {
            accessorKey: 'template_name',
            header: () =>
                h('div', { class: 'flex items-center gap-2' }, [
                    h('span', 'Template Name'),
                    h(ArrowUpDown, { class: 'size-3.5 text-slate-400' }),
                ]),
            enableSorting: true,
            cell: ({ row }) => h('span', { class: 'font-medium text-slate-900' }, row.original.name),
        },
        {
            accessorKey: 'form',
            header: 'Form',
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700' },
                    row.original.form,
                ),
        },
        {
            accessorKey: 'description',
            header: 'Description',
            cell: ({ row }) => h('span', { class: 'text-slate-600' }, row.original.description),
        },
        {
            accessorKey: 'updated_at',
            header: () =>
                h('div', { class: 'flex items-center gap-2' }, [
                    h('span', 'Last Modified'),
                    h(ArrowUpDown, { class: 'size-3.5 text-slate-400' }),
                ]),
            enableSorting: true,
            cell: ({ row }) => options.formatDate(row.original.last_modified),
        },
        {
            accessorKey: 'status',
            header: 'Status',
            cell: ({ row }) =>
                h(
                    'span',
                    { class: 'inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700' },
                    row.original.status,
                ),
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
                            () => [h(Eye, { class: 'size-4' }), h('span', 'View')],
                        ),
                        h(
                            DropdownMenuItem,
                            { variant: 'destructive', onSelect: () => options.onDelete(row.original) },
                            () => [h(Trash2, { class: 'size-4' }), h('span', 'Delete')],
                        ),
                    ]),
                ]),
        },
    ]
}

