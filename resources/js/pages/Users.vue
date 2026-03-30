<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { UserCog } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CreateUserDialog from '@/components/user-components/CreateUserDialog.vue';
import DeleteUsersDialog from '@/components/user-components/DeleteUsersDialog.vue';
import EditUserDialog from '@/components/user-components/EditUserDialog.vue';
import type {
    FlashState,
    ManagedUser,
    UsersPayload,
} from '@/components/user-components/types';
import { useUserSelection } from '@/components/user-components/useUserSelection';
import UsersTable from '@/components/user-components/UsersTable.vue';
import UsersToolbar from '@/components/user-components/UsersToolbar.vue';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    flash: FlashState;
    storeUrl: string;
    usersPageUrl: string;
    bulkDestroyUrl: string;
    users: ManagedUser[];
    hasMoreUsers: boolean;
    nextUsersCursor: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users',
        href: '/users',
    },
];

const storedUsers = ref<ManagedUser[]>([...props.users]);
const hasMoreUsers = ref(props.hasMoreUsers);
const nextUsersCursor = ref(props.nextUsersCursor);
const isLoadingMore = ref(false);
const loadMoreError = ref<string | null>(null);
const isCreateDialogOpen = ref(false);
const isEditDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);
const selectedUser = ref<ManagedUser | null>(null);
const usersForDeletion = ref<ManagedUser[]>([]);

const createForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const editForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const deleteForm = useForm<{
    user_ids: number[];
}>({
    user_ids: [],
});

const {
    filteredUsers,
    isUserSelected,
    searchTerm,
    selectedUserIds,
    selectedUsers,
    selectAllUsersState,
    toggleAllVisibleUsers,
    toggleUserSelection,
    visibleSelectableUserIds,
} = useUserSelection(storedUsers);

const canSubmitCreate = computed(
    () =>
        createForm.name.trim() !== '' &&
        createForm.email.trim() !== '' &&
        createForm.password.trim() !== '' &&
        createForm.password_confirmation.trim() !== '' &&
        !createForm.processing,
);
const canSubmitEdit = computed(
    () =>
        selectedUser.value !== null &&
        editForm.name.trim() !== '' &&
        editForm.email.trim() !== '' &&
        !editForm.processing,
);
const canBulkDeleteUsers = computed(
    () => selectedUsers.value.length > 0 && !deleteForm.processing,
);
const canConfirmDelete = computed(
    () => usersForDeletion.value.length > 0 && !deleteForm.processing,
);
const deleteDialogTitle = computed(() =>
    usersForDeletion.value.length === 1 ? 'Delete user' : 'Delete users',
);
const deleteDialogDescription = computed(() => {
    if (usersForDeletion.value.length === 1) {
        return `Delete ${usersForDeletion.value[0]?.name ?? 'this user'}? This also removes the user's related records.`;
    }

    return `Delete ${usersForDeletion.value.length} selected users? This also removes each user's related records.`;
});

watch(
    () => [props.users, props.hasMoreUsers, props.nextUsersCursor] as const,
    ([users, hasMore, cursor]) => {
        storedUsers.value = [...users];
        hasMoreUsers.value = hasMore;
        nextUsersCursor.value = cursor;
        loadMoreError.value = null;
    },
    { deep: true },
);

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            toast.success(success);
            return;
        }

        if (error) {
            toast.error(error);
        }
    },
    { immediate: true },
);

function formatDateTime(value: string | null): string {
    if (!value) {
        return 'Unknown date';
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return 'Unknown date';
    }

    return new Intl.DateTimeFormat('en-US', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function openCreateDialog(): void {
    createForm.reset();
    createForm.clearErrors();
    isCreateDialogOpen.value = true;
}

function handleCreateDialogOpenChange(open: boolean): void {
    if (createForm.processing) {
        return;
    }

    isCreateDialogOpen.value = open;

    if (!open) {
        createForm.reset();
        createForm.clearErrors();
    }
}

function openEditDialog(user: ManagedUser): void {
    if (!user.canEdit || !user.updateUrl) {
        return;
    }

    selectedUser.value = user;
    editForm.name = user.name;
    editForm.email = user.email;
    editForm.password = '';
    editForm.password_confirmation = '';
    editForm.clearErrors();
    isEditDialogOpen.value = true;
}

function handleEditDialogOpenChange(open: boolean): void {
    if (editForm.processing) {
        return;
    }

    isEditDialogOpen.value = open;

    if (!open) {
        selectedUser.value = null;
        editForm.reset();
        editForm.clearErrors();
    }
}

function openDeleteDialogForUser(user: ManagedUser): void {
    if (!user.deleteUrl) {
        return;
    }

    usersForDeletion.value = [user];
    deleteForm.clearErrors();
    isDeleteDialogOpen.value = true;
}

function openDeleteDialogForSelection(): void {
    const deletableUsers = selectedUsers.value.filter(
        (user) => user.deleteUrl !== null,
    );

    if (deletableUsers.length === 0) {
        return;
    }

    usersForDeletion.value = deletableUsers;
    deleteForm.clearErrors();
    isDeleteDialogOpen.value = true;
}

function resetDeleteForm(): void {
    usersForDeletion.value = [];
    deleteForm.reset();
    deleteForm.clearErrors();
}

function handleDeleteDialogOpenChange(open: boolean): void {
    if (deleteForm.processing) {
        return;
    }

    isDeleteDialogOpen.value = open;

    if (!open) {
        resetDeleteForm();
    }
}

function submitCreate(): void {
    createForm
        .transform((data) => ({
            name: data.name.trim(),
            email: data.email.trim(),
            password: data.password,
            password_confirmation: data.password_confirmation,
        }))
        .post(props.storeUrl, {
            preserveScroll: true,
            onSuccess: () => {
                isCreateDialogOpen.value = false;
            },
        });
}

function submitEdit(): void {
    if (!selectedUser.value?.updateUrl) {
        return;
    }

    editForm
        .transform((data) => ({
            name: data.name.trim(),
            email: data.email.trim(),
            password: data.password.trim() === '' ? null : data.password,
            password_confirmation:
                data.password.trim() === '' ? null : data.password_confirmation,
        }))
        .put(selectedUser.value.updateUrl, {
            preserveScroll: true,
            onSuccess: () => {
                isEditDialogOpen.value = false;
            },
        });
}

function submitDelete(): void {
    const deletableUsers = usersForDeletion.value.filter(
        (user) => user.deleteUrl !== null,
    );
    const deletedUserIds = deletableUsers.map((user) => user.id);

    if (deletableUsers.length === 0) {
        return;
    }

    const handleSuccess = (page: { props: unknown }): void => {
        const success = (page.props as { flash?: FlashState }).flash?.success;

        if (!success) {
            return;
        }

        selectedUserIds.value = selectedUserIds.value.filter(
            (id) => !deletedUserIds.includes(id),
        );
        isDeleteDialogOpen.value = false;
        resetDeleteForm();
    };

    if (deletableUsers.length === 1 && deletableUsers[0]?.deleteUrl) {
        deleteForm.delete(deletableUsers[0].deleteUrl, {
            preserveScroll: true,
            onSuccess: handleSuccess,
        });

        return;
    }

    deleteForm.user_ids = deletedUserIds;
    deleteForm.delete(props.bulkDestroyUrl, {
        preserveScroll: true,
        onSuccess: handleSuccess,
    });
}

async function loadMoreUsers(): Promise<void> {
    if (!nextUsersCursor.value || isLoadingMore.value) {
        return;
    }

    isLoadingMore.value = true;
    loadMoreError.value = null;

    try {
        const response = await fetch(
            `${props.usersPageUrl}?cursor=${encodeURIComponent(nextUsersCursor.value)}`,
            {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );

        if (!response.ok) {
            throw new Error('Unable to load more users right now.');
        }

        const payload = (await response.json()) as UsersPayload;
        const seenIds = new Set(storedUsers.value.map((user) => user.id));

        storedUsers.value = [
            ...storedUsers.value,
            ...payload.users.filter((user) => !seenIds.has(user.id)),
        ];
        hasMoreUsers.value = payload.hasMoreUsers;
        nextUsersCursor.value = payload.nextUsersCursor;
    } catch (error) {
        loadMoreError.value =
            error instanceof Error
                ? error.message
                : 'Unable to load more users right now.';
    } finally {
        isLoadingMore.value = false;
    }
}
</script>

<template>
    <Head title="Users" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <template #subheader>
            <UsersToolbar
                :can-bulk-delete="canBulkDeleteUsers"
                :selected-count="selectedUserIds.length"
                @create-user="openCreateDialog"
                @delete-selected="openDeleteDialogForSelection"
            />
        </template>

        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardHeader class="space-y-1">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="rounded-2xl bg-muted p-3">
                                <UserCog class="size-6 text-foreground" />
                            </div>
                            <div class="space-y-1">
                                <CardTitle class="text-xl">Users</CardTitle>
                                <CardDescription>
                                    Create and update user accounts. New users are
                                    created as staff by default.
                                </CardDescription>
                            </div>
                        </div>
                        <div
                            class="rounded-full border px-3 py-1 text-sm text-muted-foreground"
                        >
                            {{ storedUsers.length }} users loaded
                        </div>
                    </div>
                </CardHeader>

                <UsersTable
                    :format-date-time="formatDateTime"
                    :has-more-users="hasMoreUsers"
                    :has-selectable-users="visibleSelectableUserIds.length > 0"
                    :is-delete-processing="deleteForm.processing"
                    :is-loading-more="isLoadingMore"
                    :is-user-selected="isUserSelected"
                    :load-more-error="loadMoreError"
                    :search-term="searchTerm"
                    :select-all-state="selectAllUsersState"
                    :total-users="storedUsers.length"
                    :users="filteredUsers"
                    @delete="openDeleteDialogForUser"
                    @edit="openEditDialog"
                    @load-more="loadMoreUsers"
                    @toggle-all="toggleAllVisibleUsers"
                    @toggle-user="toggleUserSelection($event.user, $event.checked)"
                    @update:search-term="searchTerm = $event"
                />
            </Card>
        </div>

        <CreateUserDialog
            :can-submit="canSubmitCreate"
            :form="createForm"
            :open="isCreateDialogOpen"
            @submit="submitCreate"
            @update:open="handleCreateDialogOpenChange"
        />

        <EditUserDialog
            :can-submit="canSubmitEdit"
            :form="editForm"
            :open="isEditDialogOpen"
            @submit="submitEdit"
            @update:open="handleEditDialogOpenChange"
        />

        <DeleteUsersDialog
            :can-confirm="canConfirmDelete"
            :description="deleteDialogDescription"
            :open="isDeleteDialogOpen"
            :processing="deleteForm.processing"
            :title="deleteDialogTitle"
            @submit="submitDelete"
            @update:open="handleDeleteDialogOpenChange"
        />
    </AppLayout>
</template>
