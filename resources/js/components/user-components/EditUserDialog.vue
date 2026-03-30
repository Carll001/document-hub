<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
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
import type { UserDialogFormState } from '@/components/user-components/types';

const props = defineProps<{
    canSubmit: boolean;
    form: UserDialogFormState;
    open: boolean;
}>();

const emit = defineEmits<{
    submit: [];
    'update:open': [open: boolean];
}>();

const form = props.form;
</script>

<template>
    <Dialog :open="props.open" @update:open="emit('update:open', $event)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader class="space-y-3">
                <DialogTitle>Edit user</DialogTitle>
                <DialogDescription>
                    Update user account details and optionally set a new
                    password.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="emit('submit')">
                <div class="space-y-2">
                    <Label for="editUserName">Name</Label>
                    <Input
                        id="editUserName"
                        v-model="form.name"
                        placeholder="Enter user name"
                    />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="space-y-2">
                    <Label for="editUserEmail">Email</Label>
                    <Input
                        id="editUserEmail"
                        v-model="form.email"
                        type="email"
                        placeholder="user@example.com"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="space-y-2">
                    <Label for="editUserPassword">
                        New password
                        <span class="text-muted-foreground">(optional)</span>
                    </Label>
                    <PasswordInput
                        id="editUserPassword"
                        v-model="form.password"
                        autocomplete="new-password"
                        placeholder="Enter a new password"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="space-y-2">
                    <Label for="editUserPasswordConfirmation">
                        Confirm new password
                        <span class="text-muted-foreground">(optional)</span>
                    </Label>
                    <PasswordInput
                        id="editUserPasswordConfirmation"
                        v-model="form.password_confirmation"
                        autocomplete="new-password"
                        placeholder="Confirm the new password"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="form.processing"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        class="gap-2"
                        :disabled="props.canSubmit === false"
                    >
                        Save changes
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
