export type FormType = 'afs' | '1702ex' | '2551q';

export type AliasRegistry = {
    global: Record<string, string[]>;
    perForm: Partial<Record<FormType, Record<string, string[]>>>;
};

const DEFAULT_ALIAS_REGISTRY: AliasRegistry = {
    global: {
        tin: [
            'tin',
            'tax identification number',
            'taxpayer tin',
        ],
    },
    perForm: {
        afs: {
            tin: ['company tin', 'company_tin', 'companytin'],
        },
        '1702ex': {
            tin: [],
        },
        '2551q': {
            tin: [],
        },
    },
};

let runtimeAliasRegistry: AliasRegistry | null = null;

const normalizeRowDataKey = (value: string): string =>
    value.toLowerCase().replace(/[^a-z0-9]/g, '');

const normalizeTinDigits = (value: string): string =>
    value.replace(/\D+/g, '').trim();

const aliasesFor = (
    canonicalKey: string,
    formType: FormType,
): string[] => {
    const registry = runtimeAliasRegistry ?? DEFAULT_ALIAS_REGISTRY;
    const globalAliases = registry.global[canonicalKey] ?? [];
    const formAliases = registry.perForm[formType]?.[canonicalKey] ?? [];

    return Array.from(new Set([...formAliases, ...globalAliases]));
};

export const setAliasRegistry = (registry: AliasRegistry | null): void => {
    runtimeAliasRegistry = registry;
};

export const resolveAliasedField = (
    rowData: Record<string, string>,
    canonicalKey: string,
    formType: FormType,
): string | null => {
    const normalizedAliases = aliasesFor(canonicalKey, formType).map((alias) =>
        normalizeRowDataKey(alias),
    );

    const entry = Object.entries(rowData).find(([fieldKey]) =>
        normalizedAliases.includes(normalizeRowDataKey(fieldKey)),
    );

    if (!entry) {
        return null;
    }

    const value = entry[1]?.trim();
    return value && value !== '' ? value : null;
};

export const resolveTin = (
    rowData: Record<string, string>,
    formType: FormType,
): string | null => {
    const value = resolveAliasedField(rowData, 'tin', formType);

    if (!value) {
        return null;
    }

    const normalized = normalizeTinDigits(value);
    return normalized !== '' ? normalized : null;
};
