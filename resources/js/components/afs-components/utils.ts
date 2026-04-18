import { resolveAliasedField, resolveTin } from '@/lib/form-field-aliases';

export const statusBadgeVariant = (status: string): 'default' | 'secondary' | 'destructive' | 'outline' => {
    if (status === 'failed') {
        return 'destructive';
    }

    if (status === 'pdf_done' || status === 'completed') {
        return 'default';
    }

    if (status === 'processing' || status === 'docx_done') {
        return 'secondary';
    }

    return 'outline';
};

export const statusBadgeClass = (status: string): string | undefined => {
    if (status === 'queued') {
        return 'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300';
    }

    if (status === 'processing' || status === 'docx_done') {
        return 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-300';
    }

    if (status === 'pdf_done' || status === 'completed') {
        return 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300';
    }

    return undefined;
};

export const getAliasedRowDataValue = (
    rowData: Record<string, string>,
    key: 'tin',
): string | null => resolveAliasedField(rowData, key, 'afs');

export const getTinFromRowData = (rowData: Record<string, string>): string | null => {
    return resolveTin(rowData, 'afs');
};

export const csrfToken = () => {
    const xsrfCookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='));

    if (!xsrfCookie) {
        return '';
    }

    return decodeURIComponent(xsrfCookie.split('=')[1] ?? '');
};

export const getApi = async <T>(url: string): Promise<T> => {
    const response = await fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

export const sendJson = async <T>(url: string, method: 'PUT', payload: unknown): Promise<T> => {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (response.status === 422) {
        const errorPayload = (await response.json()) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        const validationError = new Error(errorPayload.message ?? 'Validation failed.');
        Object.assign(validationError, { validationErrors: errorPayload.errors ?? {} });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

export const sendDelete = async (url: string): Promise<void> => {
    const response = await fetch(url, {
        method: 'DELETE',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
    });

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }
};

export const sendPostJson = async <T>(url: string, payload: unknown): Promise<T> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (response.status === 422) {
        const errorPayload = (await response.json()) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        const validationError = new Error(errorPayload.message ?? 'Validation failed.');
        Object.assign(validationError, { validationErrors: errorPayload.errors ?? {} });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};

export const sendPostFormData = async <T>(url: string, payload: FormData): Promise<T> => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: payload,
    });

    if (response.status === 422) {
        const errorPayload = (await response.json()) as {
            errors?: Record<string, string[]>;
            message?: string;
        };
        const validationError = new Error(errorPayload.message ?? 'Validation failed.');
        Object.assign(validationError, { validationErrors: errorPayload.errors ?? {} });
        throw validationError;
    }

    if (!response.ok) {
        throw new Error(`Request failed with status ${response.status}`);
    }

    return (await response.json()) as T;
};
