export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';
export type AppShellVariant = AppVariant;
export type FlashToastType = 'success' | 'error' | 'info' | 'warning';

export type FlashToast = {
    id: string;
    type: FlashToastType;
    title: string;
    message?: string;
};
