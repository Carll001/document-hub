<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Mail, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableEmpty,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type MailboxAccount = {
    id: number;
    displayName: string;
    username: string;
    host: string;
    port: number;
    encryption: string;
    mailbox: string;
    validateCertificate: boolean;
    isActive: boolean;
    createdAt: string | null;
    updatedAt: string | null;
    syncedEmailCount: number;
    updateUrl: string;
    deleteUrl: string;
};

const props = defineProps<{
    flash: {
        success?: string | null;
        error?: string | null;
    };
    storeUrl: string;
    bulkDestroyUrl: string;
    accounts: MailboxAccount[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Mailbox Accounts',
        href: '/mailbox-accounts',
    },
];

const ENCRYPTION_OPTIONS = [
    { label: 'SSL', value: 'ssl' },
    { label: 'TLS', value: 'tls' },
    { label: 'None', value: 'none' },
];

const searchTerm = ref('');
const isCreateDialogOpen = ref(false);
const isEditDialogOpen = ref(false);
const isDeleteDialogOpen = ref(false);
const selectedAccount = ref<MailboxAccount | null>(null);
const selectedAccountIds = ref<number[]>([]);
const accountsForDeletion = ref<MailboxAccount[]>([]);

const createForm = useForm({
    display_name: '',
    username: '',
    password: '',
    host: 'imap.gmail.com',
    port: 993,
    encryption: 'ssl',
    mailbox: 'INBOX',
    validate_certificate: true,
    is_active: true,
});

const editForm = useForm({
    display_name: '',
    username: '',
    password: '',
    host: '',
    port: 993,
    encryption: 'ssl',
    mailbox: 'INBOX',
    validate_certificate: true,
    is_active: true,
});

const deleteForm = useForm<{
    account_ids: number[];
}>({
    account_ids: [],
});

const filteredAccounts = computed(() => {
    const query = searchTerm.value.trim().toLowerCase();

    if (query === '') {
        return props.accounts;
    }

    return props.accounts.filter((account) =>
        [
            account.displayName,
            account.username,
            account.host,
            account.mailbox,
            account.encryption,
        ]
            .join(' ')
            .toLowerCase()
            .includes(query),
    );
});

const selectedSet = computed(() => new Set(selectedAccountIds.value));
const allVisibleSelected = computed(
    () =>
        filteredAccounts.value.length > 0
        && filteredAccounts.value.every((account) => selectedSet.value.has(account.id)),
);
const someVisibleSelected = computed(
    () => filteredAccounts.value.some((account) => selectedSet.value.has(account.id)),
);
const selectAllState = computed<boolean | 'indeterminate'>(() => {
    if (allVisibleSelected.value) {
        return true;
    }

    if (someVisibleSelected.value) {
        return 'indeterminate';
    }

    return false;
});
const canBulkDelete = computed(
    () => selectedAccountIds.value.length > 0 && !deleteForm.processing,
);

watch(
    () => [props.flash.success, props.flash.error] as const,
    ([success, error]) => {
        if (success) {
            toast.success(success);
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

function resetCreateForm(): void {
    createForm.reset();
    createForm.clearErrors();
    createForm.host = 'imap.gmail.com';
    createForm.port = 993;
    createForm.encryption = 'ssl';
    createForm.mailbox = 'INBOX';
    createForm.validate_certificate = true;
    createForm.is_active = true;
}

function openCreateDialog(): void {
    resetCreateForm();
    isCreateDialogOpen.value = true;
}

function openEditDialog(account: MailboxAccount): void {
    selectedAccount.value = account;
    editForm.display_name = account.displayName;
    editForm.username = account.username;
    editForm.password = '';
    editForm.host = account.host;
    editForm.port = account.port;
    editForm.encryption = account.encryption;
    editForm.mailbox = account.mailbox;
    editForm.validate_certificate = account.validateCertificate;
    editForm.is_active = account.isActive;
    editForm.clearErrors();
    isEditDialogOpen.value = true;
}

function toggleSelectAll(checked: boolean | 'indeterminate'): void {
    if (checked !== true) {
        selectedAccountIds.value = [];

        return;
    }

    selectedAccountIds.value = filteredAccounts.value.map((account) => account.id);
}

function toggleSelection(accountId: number, checked: boolean | 'indeterminate'): void {
    if (checked === true) {
        selectedAccountIds.value = Array.from(new Set([...selectedAccountIds.value, accountId]));

        return;
    }

    selectedAccountIds.value = selectedAccountIds.value.filter((id) => id !== accountId);
}

function openDeleteDialog(accounts: MailboxAccount[]): void {
    if (accounts.length === 0) {
        return;
    }

    accountsForDeletion.value = accounts;
    deleteForm.clearErrors();
    isDeleteDialogOpen.value = true;
}

function submitCreate(): void {
    createForm.post(props.storeUrl, {
        preserveScroll: true,
        onSuccess: () => {
            isCreateDialogOpen.value = false;
            resetCreateForm();
        },
    });
}

function submitEdit(): void {
    if (!selectedAccount.value) {
        return;
    }

    editForm.put(selectedAccount.value.updateUrl, {
        preserveScroll: true,
        onSuccess: () => {
            isEditDialogOpen.value = false;
            selectedAccount.value = null;
        },
    });
}

function submitDelete(): void {
    const ids = accountsForDeletion.value.map((account) => account.id);

    if (ids.length === 0) {
        return;
    }

    deleteForm.account_ids = ids;

    if (ids.length === 1 && accountsForDeletion.value[0]) {
        deleteForm.delete(accountsForDeletion.value[0].deleteUrl, {
            preserveScroll: true,
            onSuccess: () => {
                selectedAccountIds.value = selectedAccountIds.value.filter((id) => !ids.includes(id));
                isDeleteDialogOpen.value = false;
                accountsForDeletion.value = [];
            },
        });

        return;
    }

    deleteForm.delete(props.bulkDestroyUrl, {
        preserveScroll: true,
        onSuccess: () => {
            selectedAccountIds.value = selectedAccountIds.value.filter((id) => !ids.includes(id));
            isDeleteDialogOpen.value = false;
            accountsForDeletion.value = [];
        },
    });
}
</script>

<template>
    <Head title="Mailbox Accounts" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <Card class="rounded-3xl">
                <CardHeader class="space-y-1">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="rounded-2xl bg-muted p-3">
                                <Mail class="size-6 text-foreground" />
                            </div>
                            <div class="space-y-1">
                                <CardTitle class="text-xl">Mailbox Accounts</CardTitle>
                                <CardDescription>
                                    Superadmins manage shared IMAP accounts here.
                                    Staff only see the synced email workspace.
                                </CardDescription>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="!canBulkDelete"
                                @click="
                                    openDeleteDialog(
                                        props.accounts.filter((account) =>
                                            selectedSet.has(account.id),
                                        ),
                                    )
                                "
                            >
                                <Trash2 class="mr-2 size-4" />
                                Delete selected
                            </Button>
                            <Button type="button" @click="openCreateDialog">
                                <Plus class="mr-2 size-4" />
                                Add account
                            </Button>
                        </div>
                    </div>
                </CardHeader>

                <div class="px-6 pb-2">
                    <Input
                        v-model="searchTerm"
                        type="search"
                        placeholder="Search display name, email, host, or mailbox"
                        class="max-w-md rounded-2xl"
                    />
                </div>

                <div class="px-6 pb-6">
                    <div class="overflow-hidden rounded-2xl border bg-background">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead class="w-12">
                                        <Checkbox
                                            :key="`select-all-mailbox-accounts-${selectAllState}`"
                                            :model-value="selectAllState"
                                            :disabled="filteredAccounts.length === 0"
                                            aria-label="Select all mailbox accounts"
                                            @update:model-value="toggleSelectAll"
                                        />
                                    </TableHead>
                                    <TableHead>ACCOUNT</TableHead>
                                    <TableHead>HOST</TableHead>
                                    <TableHead>MAILBOX</TableHead>
                                    <TableHead>STATUS</TableHead>
                                    <TableHead>SYNCED EMAILS</TableHead>
                                    <TableHead>UPDATED</TableHead>
                                    <TableHead class="text-right">ACTIONS</TableHead>
                                </TableRow>
                            </TableHeader>

                            <TableBody>
                                <template v-if="filteredAccounts.length > 0">
                                    <TableRow
                                        v-for="account in filteredAccounts"
                                        :key="account.id"
                                    >
                                        <TableCell>
                                            <Checkbox
                                                :model-value="selectedSet.has(account.id)"
                                                :aria-label="`Select ${account.displayName}`"
                                                @update:model-value="
                                                    toggleSelection(account.id, $event)
                                                "
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <div class="space-y-1">
                                                <p class="font-medium text-foreground">
                                                    {{ account.displayName }}
                                                </p>
                                                <p class="text-sm text-muted-foreground">
                                                    {{ account.username }}
                                                </p>
                                            </div>
                                        </TableCell>
                                        <TableCell class="text-sm text-muted-foreground">
                                            {{ account.host }}:{{ account.port }}
                                            <span class="uppercase">
                                                ({{ account.encryption }})
                                            </span>
                                        </TableCell>
                                        <TableCell class="text-sm text-muted-foreground">
                                            {{ account.mailbox }}
                                        </TableCell>
                                        <TableCell>
                                            <Badge
                                                :variant="
                                                    account.isActive
                                                        ? 'secondary'
                                                        : 'outline'
                                                "
                                                class="rounded-full"
                                            >
                                                {{ account.isActive ? 'Active' : 'Inactive' }}
                                            </Badge>
                                        </TableCell>
                                        <TableCell class="text-sm text-muted-foreground">
                                            {{ account.syncedEmailCount }}
                                        </TableCell>
                                        <TableCell class="text-sm text-muted-foreground">
                                            {{ formatDateTime(account.updatedAt) }}
                                        </TableCell>
                                        <TableCell class="text-right">
                                            <div class="flex justify-end gap-2">
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    @click="openEditDialog(account)"
                                                >
                                                    Edit
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    @click="openDeleteDialog([account])"
                                                >
                                                    Delete
                                                </Button>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                </template>

                                <TableEmpty v-else :colspan="8">
                                    No mailbox accounts found.
                                </TableEmpty>
                            </TableBody>
                        </Table>
                    </div>
                </div>
            </Card>
        </div>

        <Dialog :open="isCreateDialogOpen" @update:open="isCreateDialogOpen = $event">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Add mailbox account</DialogTitle>
                    <DialogDescription>
                        Save an IMAP account that staff can sync into the shared inbox.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submitCreate">
                    <div class="space-y-2">
                        <Label for="createDisplayName">Display name</Label>
                        <Input id="createDisplayName" v-model="createForm.display_name" />
                        <InputError :message="createForm.errors.display_name" />
                    </div>

                    <div class="space-y-2">
                        <Label for="createUsername">Email / username</Label>
                        <Input id="createUsername" v-model="createForm.username" />
                        <InputError :message="createForm.errors.username" />
                    </div>

                    <div class="space-y-2">
                        <Label for="createPassword">App password</Label>
                        <Input id="createPassword" v-model="createForm.password" type="password" />
                        <InputError :message="createForm.errors.password" />
                    </div>

                    <div class="space-y-2">
                        <Label for="createHost">Host</Label>
                        <Input id="createHost" v-model="createForm.host" />
                        <InputError :message="createForm.errors.host" />
                    </div>

                    <div class="space-y-2">
                        <Label for="createPort">Port</Label>
                        <Input id="createPort" v-model="createForm.port" type="number" min="1" max="65535" />
                        <InputError :message="createForm.errors.port" />
                    </div>

                    <div class="space-y-2">
                        <Label>Encryption</Label>
                        <Select
                            :model-value="createForm.encryption"
                            @update:model-value="createForm.encryption = String($event)"
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in ENCRYPTION_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="createForm.errors.encryption" />
                    </div>

                    <div class="space-y-2 md:col-span-2">
                        <Label for="createMailbox">Mailbox / folder</Label>
                        <Input id="createMailbox" v-model="createForm.mailbox" />
                        <InputError :message="createForm.errors.mailbox" />
                    </div>

                    <div class="flex items-center gap-3">
                        <Checkbox
                            :checked="createForm.validate_certificate"
                            @update:checked="createForm.validate_certificate = $event === true"
                        />
                        <Label>Validate certificate</Label>
                    </div>

                    <div class="flex items-center gap-3">
                        <Checkbox
                            :checked="createForm.is_active"
                            @update:checked="createForm.is_active = $event === true"
                        />
                        <Label>Active</Label>
                    </div>

                    <DialogFooter class="md:col-span-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="createForm.processing"
                            @click="isCreateDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="createForm.processing">
                            Save account
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <Dialog :open="isEditDialogOpen" @update:open="isEditDialogOpen = $event">
            <DialogContent class="sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Edit mailbox account</DialogTitle>
                    <DialogDescription>
                        Leave the password blank to keep the current stored credential.
                    </DialogDescription>
                </DialogHeader>

                <form class="grid gap-4 md:grid-cols-2" @submit.prevent="submitEdit">
                    <div class="space-y-2">
                        <Label for="editDisplayName">Display name</Label>
                        <Input id="editDisplayName" v-model="editForm.display_name" />
                        <InputError :message="editForm.errors.display_name" />
                    </div>

                    <div class="space-y-2">
                        <Label for="editUsername">Email / username</Label>
                        <Input id="editUsername" v-model="editForm.username" />
                        <InputError :message="editForm.errors.username" />
                    </div>

                    <div class="space-y-2">
                        <Label for="editPassword">New app password</Label>
                        <Input id="editPassword" v-model="editForm.password" type="password" />
                        <InputError :message="editForm.errors.password" />
                    </div>

                    <div class="space-y-2">
                        <Label for="editHost">Host</Label>
                        <Input id="editHost" v-model="editForm.host" />
                        <InputError :message="editForm.errors.host" />
                    </div>

                    <div class="space-y-2">
                        <Label for="editPort">Port</Label>
                        <Input id="editPort" v-model="editForm.port" type="number" min="1" max="65535" />
                        <InputError :message="editForm.errors.port" />
                    </div>

                    <div class="space-y-2">
                        <Label>Encryption</Label>
                        <Select
                            :model-value="editForm.encryption"
                            @update:model-value="editForm.encryption = String($event)"
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="option in ENCRYPTION_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="editForm.errors.encryption" />
                    </div>

                    <div class="space-y-2 md:col-span-2">
                        <Label for="editMailbox">Mailbox / folder</Label>
                        <Input id="editMailbox" v-model="editForm.mailbox" />
                        <InputError :message="editForm.errors.mailbox" />
                    </div>

                    <div class="flex items-center gap-3">
                        <Checkbox
                            :checked="editForm.validate_certificate"
                            @update:checked="editForm.validate_certificate = $event === true"
                        />
                        <Label>Validate certificate</Label>
                    </div>

                    <div class="flex items-center gap-3">
                        <Checkbox
                            :checked="editForm.is_active"
                            @update:checked="editForm.is_active = $event === true"
                        />
                        <Label>Active</Label>
                    </div>

                    <DialogFooter class="md:col-span-2">
                        <Button
                            type="button"
                            variant="secondary"
                            :disabled="editForm.processing"
                            @click="isEditDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="editForm.processing">
                            Save changes
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <AlertDialog :open="isDeleteDialogOpen" @update:open="isDeleteDialogOpen = $event">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete mailbox account</AlertDialogTitle>
                    <AlertDialogDescription>
                        Delete {{ accountsForDeletion.length }} mailbox account{{
                            accountsForDeletion.length === 1 ? '' : 's'
                        }}? Previously synced emails stay in the shared inbox, but
                        future syncs will stop for these accounts.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel :disabled="deleteForm.processing">
                        Cancel
                    </AlertDialogCancel>
                    <AlertDialogAction
                        :disabled="deleteForm.processing"
                        @click="submitDelete"
                    >
                        Delete
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
