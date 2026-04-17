<script setup lang="ts">
import { LoaderCircle, Search } from 'lucide-vue-next';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import type { ManagedUser } from '@/components/user-components/types';

const props = defineProps<{
    users: ManagedUser[];
    totalUsers: number;
    searchTerm: string;
    selectAllState: boolean | 'indeterminate';
    hasSelectableUsers: boolean;
    hasMoreUsers: boolean;
    isDeleteProcessing: boolean;
    isLoadingMore: boolean;
    isUserSelected: (user: ManagedUser) => boolean;
    loadMoreError: string | null;
    formatDateTime: (value: string | null) => string;
}>();

const emit = defineEmits<{
    edit: [user: ManagedUser];
    delete: [user: ManagedUser];
    loadMore: [];
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
                        <TableCell>{{ index + 1 }}</TableCell>
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

                    <TableEmpty v-if="props.users.length === 0" :colspan="6">
                        {{
                            props.totalUsers === 0
                                ? 'No users found.'
                                : 'No users match your search.'
                        }}
                    </TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Alert v-if="props.loadMoreError" variant="destructive">
            <AlertTitle>Load more failed</AlertTitle>
            <AlertDescription>
                {{ props.loadMoreError }}
            </AlertDescription>
        </Alert>

        <Button
            v-if="props.hasMoreUsers"
            type="button"
            variant="outline"
            size="sm"
            class="w-full rounded-full text-xs"
            :disabled="props.isLoadingMore"
            @click="emit('loadMore')"
        >
            <LoaderCircle
                v-if="props.isLoadingMore"
                class="mr-2 size-4 animate-spin"
            />
            {{
                props.isLoadingMore ? 'Loading more users...' : 'Load more'
            }}
        </Button>
    </CardContent>
</template>
