export type UserRole = 'staff' | 'superadmin';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    role: UserRole;
    canAccessDocMerge: boolean;
    canAccessEmailSync: boolean;
    canAccessUserManagement: boolean;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
