import type { ColumnDef } from '@tanstack/vue-table'
import { h, type Ref } from 'vue'
import { ArrowUpDown, Download, Eye, MoreHorizontal, RefreshCw, Signature, Trash2, Upload } from 'lucide-vue-next'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'

export type CompanyOptionRow = {
    id: number
    name: string
    tin: string
    created_at: string
}

export type CompanyReviewRow = {
    id: number
    company_id: number
    name: string
    tin: string
    form_type: 'afs' | '1702ex'
    status: string
    file_path: string | null
    pdf_available: boolean
    error_message: string | null
    updated_at: string | null
}

type Options = {
    selectedCompanyIds: Ref<number[]>
}

type ReviewOptions = {
    onPreview: (row: CompanyReviewRow) => void
    onDownload: (row: CompanyReviewRow) => void
    onDelete: (row: CompanyReviewRow) => void
    onRegenerate: (row: CompanyReviewRow) => void
    onSign: (row: CompanyReviewRow) => void
    onAddTemporaryReceipt: (row: CompanyReviewRow) => void
    onStatusClick?: (row: CompanyReviewRow) => void
}

export function createCompanySelectColumns(
    options: Options,
): ColumnDef<CompanyOptionRow>[] {
    return [
        {
            id: 'select',
            header: '',
            cell: ({ row }) =>
                h(Checkbox, {
                    modelValue: options.selectedCompanyIds.value.includes(row.original.id),
                    'onUpdate:modelValue': (value: boolean | 'indeterminate') => {
                        if (value === 'indeterminate') return

                        if (value) {
                            options.selectedCompanyIds.value = Array.from(
                                new Set([...options.selectedCompanyIds.value, row.original.id]),
                            )
                            return
                        }

                        options.selectedCompanyIds.value = options.selectedCompanyIds.value.filter(
                            (id) => id !== row.original.id,
                        )
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
                const company = row.original
                const initials = company.name
                    .split(' ')
                    .map((part) => part[0])
                    .join('')
                    .slice(0, 2)
                    .toUpperCase()

                return h('div', { class: 'flex items-center gap-3' }, [
                    h(Avatar, { class: 'size-8 border border-blue-100' }, () =>
                        h(
                            AvatarFallback,
                            {
                                class: 'bg-blue-100 text-xs font-semibold text-blue-700',
                            },
                            () => initials,
                        ),
                    ),
                    h('span', { class: 'font-semibold text-slate-900' }, company.name),
                ])
            },
        },

        {
            accessorKey: 'tin',
            header: 'TIN',
            enableSorting: true,
        },

        {
            accessorKey: 'created_at',
            header: 'Date Added',
            enableSorting: true,
            cell: ({ row }) =>
                new Date(row.original.created_at).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric',
                }),
        },
    ]
}

export function createCompanyReviewColumns(options: ReviewOptions): ColumnDef<CompanyReviewRow>[] {
    return [
        {
            accessorKey: 'name',
            header: () =>
                h('div', { class: 'flex items-center gap-2' }, [
                    h('span', 'Company'),
                    h(ArrowUpDown, { class: 'size-3.5 text-slate-400' }),
                ]),
            enableSorting: true,
            cell: ({ row }) => {
                const company = row.original
                const initials = company.name
                    .split(' ')
                    .map((part) => part[0])
                    .join('')
                    .slice(0, 2)
                    .toUpperCase()

                return h('div', { class: 'flex items-center gap-3' }, [
                    h(Avatar, { class: 'size-8 border border-blue-100' }, () =>
                        h(
                            AvatarFallback,
                            {
                                class: 'bg-blue-100 text-xs font-semibold text-blue-700',
                            },
                            () => initials,
                        ),
                    ),
                    h('span', { class: 'font-semibold text-slate-900' }, company.name),
                ])
            },
        },
        {
            accessorKey: 'tin',
            header: 'TIN',
            enableSorting: true,
        },
        {
            accessorKey: 'form_type',
            header: 'Form Type',
            enableSorting: true,
            cell: ({ row }) => (row.original.form_type === '1702ex' ? '1702EX' : 'AFS'),
        },
        {
            accessorKey: 'status',
            header: 'Status',
            enableSorting: true,
            cell: ({ row }) => {
                const status = (row.original.status || 'pending').toLowerCase()
                const isFailed = status === 'failed'
                const isGenerated = status === 'generated'

                const statusClass = isGenerated
                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                    : isFailed
                        ? 'border-red-200 bg-red-50 text-red-700'
                        : 'border-amber-200 bg-amber-50 text-amber-700'

                return h(
                    'button',
                    {
                        type: 'button',
                        class: `inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-medium ${statusClass} ${isFailed ? 'cursor-pointer underline underline-offset-2' : 'cursor-default'}`,
                        onClick: () => {
                            if (isFailed && typeof options.onStatusClick === 'function') {
                                options.onStatusClick(row.original)
                            }
                        },
                    },
                    row.original.status || 'Pending',
                )
            },
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
                            {
                                disabled: !row.original.pdf_available || row.original.status !== 'generated',
                                onSelect: () => options.onPreview(row.original),
                            },
                            () => [
                                h(Eye, { class: 'size-4' }),
                                h('span', 'Preview'),
                            ],
                        ),
                        h(
                            DropdownMenuItem,
                            {
                                disabled: row.original.status === 'processing' || row.original.status === 'deleting',
                                onSelect: () => options.onRegenerate(row.original),
                            },
                            () => [
                                h(RefreshCw, { class: 'size-4' }),
                                h('span', 'Regenerate'),
                            ],
                        ),
                        ...(row.original.form_type === 'afs'
                            ? [
                                h(
                                    DropdownMenuItem,
                                    {
                                        disabled: !row.original.pdf_available || row.original.status !== 'generated',
                                        onSelect: () => options.onSign(row.original),
                                    },
                                    () => [
                                        h(Signature, { class: 'size-4' }),
                                        h('span', 'Sign'),
                                    ],
                                ),
                            ]
                            : []),
                        ...(row.original.form_type === '1702ex'
                            ? [
                                h(
                                    DropdownMenuItem,
                                    {
                                        disabled: row.original.status !== 'generated',
                                        onSelect: () => options.onAddTemporaryReceipt(row.original),
                                    },
                                    () => [
                                        h(Upload, { class: 'size-4' }),
                                        h('span', 'Add temporary receipt'),
                                    ],
                                ),
                            ]
                            : []),
                        h(
                            DropdownMenuItem,
                            {
                                disabled: !row.original.pdf_available || row.original.status !== 'generated',
                                onSelect: () => options.onDownload(row.original),
                            },
                            () => [
                                h(Download, { class: 'size-4' }),
                                h('span', 'Download'),
                            ],
                        ),
                        h(
                            DropdownMenuItem,
                            {
                                variant: 'destructive',
                                disabled: row.original.status === 'processing' || row.original.status === 'deleting',
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
    ]
}
