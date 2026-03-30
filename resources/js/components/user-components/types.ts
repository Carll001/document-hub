export type FlashState = {
    success?: string | null;
    error?: string | null;
};

export type ManagedUser = {
    id: number;
    name: string;
    email: string;
    role: 'staff' | 'superadmin';
    roleLabel: string;
    createdAt: string | null;
    updatedAt: string | null;
    canEdit: boolean;
    updateUrl: string | null;
    deleteUrl: string | null;
};

export type UsersPayload = {
    users: ManagedUser[];
    hasMoreUsers: boolean;
    nextUsersCursor: string | null;
};

export type UserDialogFormState = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
    processing: boolean;
    errors: Partial<
        Record<'name' | 'email' | 'password' | 'password_confirmation', string>
    >;
};
