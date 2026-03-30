import { computed, ref, watch, type Ref } from 'vue';
import type { ManagedUser } from '@/components/user-components/types';

export function useUserSelection(storedUsers: Ref<ManagedUser[]>) {
    const searchTerm = ref('');
    const selectedUserIds = ref<number[]>([]);

    const filteredUsers = computed(() => {
        const query = searchTerm.value.trim().toLowerCase();

        if (query === '') {
            return storedUsers.value;
        }

        return storedUsers.value.filter((user) =>
            [user.name, user.email, user.roleLabel]
                .join(' ')
                .toLowerCase()
                .includes(query),
        );
    });

    const selectedUserIdSet = computed(() => new Set(selectedUserIds.value));
    const selectedUsers = computed(() =>
        storedUsers.value.filter((user) => selectedUserIdSet.value.has(user.id)),
    );
    const visibleSelectableUserIds = computed(() =>
        filteredUsers.value
            .filter((user) => user.deleteUrl !== null)
            .map((user) => user.id),
    );
    const visibleSelectedUserCount = computed(
        () =>
            visibleSelectableUserIds.value.filter((id) =>
                selectedUserIdSet.value.has(id),
            ).length,
    );
    const selectAllUsersState = computed<boolean | 'indeterminate'>(() => {
        if (visibleSelectedUserCount.value === 0) {
            return false;
        }

        if (
            visibleSelectedUserCount.value === visibleSelectableUserIds.value.length
        ) {
            return true;
        }

        return 'indeterminate';
    });

    watch(
        () => storedUsers.value.map((user) => user.id),
        (userIds) => {
            const availableUserIds = new Set(userIds);

            selectedUserIds.value = selectedUserIds.value.filter((id) =>
                availableUserIds.has(id),
            );
        },
        { immediate: true },
    );

    function isUserSelected(user: ManagedUser): boolean {
        return selectedUserIdSet.value.has(user.id);
    }

    function toggleUserSelection(
        user: ManagedUser,
        checked: boolean | 'indeterminate',
    ): void {
        if (!user.deleteUrl) {
            return;
        }

        if (checked === true) {
            selectedUserIds.value = Array.from(
                new Set([...selectedUserIds.value, user.id]),
            );

            return;
        }

        selectedUserIds.value = selectedUserIds.value.filter(
            (id) => id !== user.id,
        );
    }

    function toggleAllVisibleUsers(checked: boolean | 'indeterminate'): void {
        if (checked === true) {
            selectedUserIds.value = Array.from(
                new Set([
                    ...selectedUserIds.value,
                    ...visibleSelectableUserIds.value,
                ]),
            );

            return;
        }

        selectedUserIds.value = selectedUserIds.value.filter(
            (id) => !visibleSelectableUserIds.value.includes(id),
        );
    }

    return {
        filteredUsers,
        isUserSelected,
        searchTerm,
        selectedUserIds,
        selectedUsers,
        selectAllUsersState,
        toggleAllVisibleUsers,
        toggleUserSelection,
        visibleSelectableUserIds,
    };
}
