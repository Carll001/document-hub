import { toast } from 'vue-sonner';
import type { ExternalToast } from 'vue-sonner';
import type { FlashToast, FlashToastType } from '@/types/ui';

const toastHandlers = {
    success: toast.success,
    error: toast.error,
    info: toast.info,
    warning: toast.warning,
} as const;

const createToastId = () =>
    globalThis.crypto?.randomUUID?.()
    ?? `toast-${Date.now()}-${Math.random().toString(36).slice(2)}`;

export const createToast = (
    type: FlashToastType,
    title: string,
    message?: string,
): FlashToast => ({
    id: createToastId(),
    type,
    title,
    ...(message ? { message } : {}),
});

export const showToast = (
    toastPayload: FlashToast,
    options: ExternalToast = {},
) => {
    const { id, message, title, type } = toastPayload;

    return toastHandlers[type](title, {
        ...options,
        id,
        description: message ?? options.description,
    });
};
