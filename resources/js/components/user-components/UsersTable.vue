<script setup lang="ts">
import { Search } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { ManagedUser } from '@/components/user-components/types';

const props = defineProps<{
    users: ManagedUser[];
    rowsPerPage: number;
    searchTerm: string;
    selectAllState: boolean | 'indeterminate';
    hasSelectableUsers: boolean;
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    isDeleteProcessing: boolean;
    isUserSelected: (user: ManagedUser) => boolean;
    formatDateTime: (value: string | null) => string;
}>();

const emit = defineEmits<{
    edit: [user: ManagedUser];
    delete: [user: ManagedUser];
    pageChange: [page: number];
    perPageChange: [value: number];
    toggleAll: [checked: boolean | 'indeterminate'];
    toggleUser: [payload: { user: ManagedUser; checked: boolean | 'indeterminate' }];
    'update:searchTerm': [value: string];
}>();
</script>

<template>
    <CardContent class="space-y-4">
        <div class="relative">
            <Search
                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
            <Input
                :model-value="props.searchTerm"
                type="search"
                placeholder="Search users"
                class="pl-10"
                @update:model-value="emit('update:searchTerm', String($event))"
            />
        </div>

        <div class="overflow-hidden rounded-2xl border bg-background">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead class="w-[1%]">
                            <Checkbox
                                :key="`select-all-users-${props.selectAllState}`"
                                :model-value="props.selectAllState"
                                :disabled="props.hasSelectableUsers === false"
                                aria-label="Select all users"
                                @update:model-value="emit('toggleAll', $event)"
                            />
                        </TableHead>
                        <TableHead>#</TableHead>
                        <TableHead>Name</TableHead>
                        <TableHead>Email</TableHead>
                        <TableHead>Role</TableHead>
                        <TableHead>Created</TableHead>
                        <TableHead class="w-[1%] text-right">Actions</TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <TableRow v-for="(user, index) in props.users" :key="user.id">
                        <TableCell>
                            <Checkbox
                                :model-value="props.isUserSelected(user)"
                                :disabled="user.deleteUrl === null"
                                :aria-label="`Select ${user.name}`"
                                @update:model-value="
                                    emit('toggleUser', { user, checked: $event })
                                "
                            />
                        </TableCell>
                        <TableCell>
                            {{ ((props.pagination.currentPage - 1) * props.pagination.perPage) + index + 1 }}
                        </TableCell>
                        <TableCell>
                            <div class="space-y-1">
                                <p class="font-medium text-foreground">
                                    {{ user.name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Updated
                                    {{ props.formatDateTime(user.updatedAt) }}
                                </p>
                            </div>
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground">
                            {{ user.email }}
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground">
                            {{ user.roleLabel }}
                        </TableCell>
                        <TableCell class="text-sm text-muted-foreground">
                            {{ props.formatDateTime(user.createdAt) }}
                        </TableCell>
                        <TableCell>
                            <div class="flex justify-end gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    :disabled="user.canEdit === false"
                                    @click="emit('edit', user)"
                                >
                                    Edit
                                </Button>
                                <Button
                                    type="button"
                                    variant="destructive"
                                    size="sm"
                                    :disabled="
                                        user.deleteUrl === null ||
                                        props.isDeleteProcessing
                                    "
                                    @click="emit('delete', user)"
                                >
                                    Delete
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>

                    <TableEmpty v-if="props.users.length === 0" :colspan="7">
                        {{
                            props.pagination.total === 0
                                ? 'No users found.'
                                : 'No users match your search.'
                        }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>
        <div
            v-if="props.pagination.total > 0"
            class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
        >
            <p class="text-sm text-muted-foreground">
                Showing {{ props.pagination.from ?? 0 }} to {{ props.pagination.to ?? 0 }} of {{ props.pagination.total }} users
            </p>
            <div class="flex items-center gap-2">
                <span class="text-sm text-muted-foreground">Rows per page</span>
                <Select
                    :model-value="String(props.rowsPerPage)"
                    @update:model-value="emit('perPageChange', Number.parseInt(String($event), 10))"
                >
                    <SelectTrigger class="w-[96px]">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="10">10</SelectItem>
                        <SelectItem value="25">25</SelectItem>
                        <SelectItem value="50">50</SelectItem>
                        <SelectItem value="100">100</SelectItem>
                    </SelectContent>
                </Select>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="props.pagination.currentPage <= 1"
                    @click="emit('pageChange', props.pagination.currentPage - 1)"
                >
                    Previous
                </Button>
                <span class="text-sm">
                    Page {{ props.pagination.currentPage }} / {{ props.pagination.lastPage }}
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="props.pagination.currentPage >= props.pagination.lastPage"
                    @click="emit('pageChange', props.pagination.currentPage + 1)"
                >
                    Next
                </Button>
            </div>
        </div>
    </CardContent>
</template>
