import type { ColumnDef } from '@tanstack/vue-table'
import { h, type Ref } from 'vue'
import { ArrowUpDown } from 'lucide-vue-next'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Checkbox } from '@/components/ui/checkbox'

export type CompanyOptionRow = {
    id: number
    name: string
    tin: string
    created_at: string
}

type Options = {
    selectedCompanyIds: Ref<number[]>
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