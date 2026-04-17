<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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
import type { AliasRegistry } from '@/lib/form-field-aliases';
import { setAliasRegistry } from '@/lib/form-field-aliases';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type AliasFormType = 'global' | 'afs' | '1702ex' | '2551q';

type EditableAliasEntry = {
    id: string;
    formType: AliasFormType;
    canonicalKey: string;
    aliasesText: string;
    editing: boolean;
};

const props = defineProps<{
    registry: AliasRegistry;
    status?: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Alias settings',
        href: '/settings/aliases',
    },
];

const form = useForm({});
const hasSuccess = computed(() => props.status === 'Alias registry updated.');

const formTypeTabs: { label: string; value: AliasFormType }[] = [
    { label: 'Global', value: 'global' },
    { label: 'AFS', value: 'afs' },
    { label: '1702EX', value: '1702ex' },
    { label: '2551Q', value: '2551q' },
];
const activeFormType = ref<AliasFormType>('global');

const nextId = ref(1);
const makeId = (): string => {
    const id = `alias-entry-${nextId.value}`;
    nextId.value += 1;
    return id;
};

const registryToEntries = (registry: AliasRegistry): EditableAliasEntry[] => {
    const entries: EditableAliasEntry[] = [];

    Object.entries(registry.global ?? {}).forEach(([canonicalKey, aliases]) => {
        entries.push({
            id: makeId(),
            formType: 'global',
            canonicalKey,
            aliasesText: (aliases ?? []).join(', '),
            editing: false,
        });
    });

    (['afs', '1702ex', '2551q'] as const).forEach((formType) => {
        Object.entries(registry.perForm?.[formType] ?? {}).forEach(
            ([canonicalKey, aliases]) => {
                entries.push({
                    id: makeId(),
                    formType,
                    canonicalKey,
                    aliasesText: (aliases ?? []).join(', '),
                    editing: false,
                });
            },
        );
    });

    return entries;
};

const entries = ref<EditableAliasEntry[]>(registryToEntries(props.registry));

const visibleEntries = computed(() =>
    entries.value.filter((entry) => entry.formType === activeFormType.value),
);

const normalizeCanonicalKey = (value: string): string =>
    value
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '_')
        .replace(/[^a-z0-9_]/g, '');

const parseAliases = (value: string): string[] =>
    Array.from(
        new Set(
            value
                .split(/[\n,]+/)
                .map((alias) => alias.trim())
                .filter((alias) => alias !== ''),
        ),
    );

const toRegistry = (allEntries: EditableAliasEntry[]): AliasRegistry => {
    const registry: AliasRegistry = {
        global: {},
        perForm: {
            afs: {},
            '1702ex': {},
            '2551q': {},
        },
    };

    allEntries.forEach((entry) => {
        const canonicalKey = normalizeCanonicalKey(entry.canonicalKey);
        if (canonicalKey === '') {
            return;
        }

        const aliases = parseAliases(entry.aliasesText);

        if (entry.formType === 'global') {
            registry.global[canonicalKey] = aliases;
            return;
        }

        registry.perForm[entry.formType] ??= {};
        registry.perForm[entry.formType]![canonicalKey] = aliases;
    });

    return registry;
};

const addEntry = () => {
    entries.value.push({
        id: makeId(),
        formType: activeFormType.value,
        canonicalKey: '',
        aliasesText: '',
        editing: true,
    });
};

const editEntry = (id: string) => {
    const target = entries.value.find((entry) => entry.id === id);
    if (target) {
        target.editing = true;
    }
};

const doneEntry = (id: string) => {
    const target = entries.value.find((entry) => entry.id === id);
    if (target) {
        target.canonicalKey = normalizeCanonicalKey(target.canonicalKey);
        target.editing = false;
    }
};

const deleteEntry = (id: string) => {
    entries.value = entries.value.filter((entry) => entry.id !== id);
};

const submit = () => {
    const registry = toRegistry(entries.value);
    const payloadEntries = entries.value.map((entry) => ({
        form_type: entry.formType,
        canonical_key: normalizeCanonicalKey(entry.canonicalKey),
        aliases: parseAliases(entry.aliasesText),
    }));

    form
        .transform(() => ({
            entries: payloadEntries,
        }))
        .put('/settings/aliases', {
            preserveScroll: true,
            onSuccess: () => {
                setAliasRegistry(registry);
            },
        });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Alias settings" />

        <div class="px-4 py-6">
            <Heading
                title="Aliases"
                description="Manage canonical fields and their aliases per form type."
            />

            <div class="mt-6 flex flex-col lg:flex-row lg:space-x-12">
                <aside class="w-full max-w-xl lg:w-48">
                    <nav class="flex flex-col space-y-1 space-x-0" aria-label="Alias form types">
                        <Button
                            v-for="tab in formTypeTabs"
                            :key="tab.value"
                            type="button"
                            variant="ghost"
                            :class="[
                                'w-full justify-start',
                                { 'bg-muted': activeFormType === tab.value },
                            ]"
                            @click="activeFormType = tab.value"
                        >
                            {{ tab.label }}
                        </Button>
                    </nav>
                </aside>

                <div class="mt-6 flex-1 md:max-w-3xl lg:mt-0">
                    <section class="space-y-6">
                        <Heading
                            variant="small"
                            title="Alias mappings"
                            description="Add canonical keys like `tin` or `company_name`, then edit aliases inline."
                        />

                        <div
                            v-if="hasSuccess"
                            class="rounded-xl border border-emerald-200/70 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
                        >
                            Alias registry updated.
                        </div>

                        <form class="space-y-4" @submit.prevent="submit">
                            <div class="rounded-xl border border-border/60">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead class="w-12">#</TableHead>
                                            <TableHead class="w-[24%]">Canonical key</TableHead>
                                            <TableHead>Aliases</TableHead>
                                            <TableHead class="w-[1%] text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow
                                            v-for="(entry, index) in visibleEntries"
                                            :key="entry.id"
                                        >
                                            <TableCell class="text-muted-foreground">
                                                {{ index + 1 }}
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    v-if="entry.editing"
                                                    v-model="entry.canonicalKey"
                                                    placeholder="company_name"
                                                />
                                                <span v-else class="font-mono text-xs">
                                                    {{ entry.canonicalKey }}
                                                </span>
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    v-if="entry.editing"
                                                    v-model="entry.aliasesText"
                                                    placeholder="company name, company_name"
                                                />
                                                <span v-else class="text-sm text-muted-foreground">
                                                    {{ entry.aliasesText || '-' }}
                                                </span>
                                            </TableCell>
                                            <TableCell class="text-right">
                                                <div class="flex justify-end gap-2">
                                                    <Button
                                                        v-if="entry.editing"
                                                        type="button"
                                                        variant="ghost"
                                                        @click="doneEntry(entry.id)"
                                                    >
                                                        Done
                                                    </Button>
                                                    <Button
                                                        v-else
                                                        type="button"
                                                        variant="ghost"
                                                        @click="editEntry(entry.id)"
                                                    >
                                                        Edit
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="icon"
                                                        @click="deleteEntry(entry.id)"
                                                    >
                                                        <Trash2 class="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                        <TableEmpty v-if="visibleEntries.length === 0" :colspan="4">
                                            No mappings yet for this form type.
                                        </TableEmpty>
                                    </TableBody>
                                </Table>
                            </div>

                            <InputError :message="form.errors.entries" />

                            <div class="flex flex-wrap items-center gap-3">
                                <Button type="button" variant="outline" class="gap-2" @click="addEntry">
                                    <Plus class="h-4 w-4" />
                                    Add mapping
                                </Button>
                                <Button type="submit" :disabled="form.processing">
                                    Save aliases
                                </Button>
                                <p v-if="form.recentlySuccessful" class="text-sm text-muted-foreground">
                                    Saved.
                                </p>
                            </div>
                        </form>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
