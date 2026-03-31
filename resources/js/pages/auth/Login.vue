<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Files, Mail, ShieldCheck } from 'lucide-vue-next';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthBase from '@/layouts/AuthLayout.vue';
import { home } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

const productHighlights = [
    {
        title: 'Email Sync',
        description: 'Keep synced mailbox activity in one shared workspace.',
        icon: Mail,
    },
    {
        title: 'Doc Merge',
        description: 'Create merged PDFs with shared templates and batch runs.',
        icon: Files,
    },
    {
        title: 'Superadmin + Staff',
        description: 'Keep team access managed without a public signup flow.',
        icon: ShieldCheck,
    },
];
</script>

<template>
    <AuthBase
        title="Sign in to Document Hub"
        description="Access Email Sync, Doc Merge, and your shared staff workspace."
    >
        <Head title="Log in" />

        <template #panel>
            <div class="flex h-full flex-col justify-between gap-8">
                <div class="space-y-5 sm:space-y-6">
                    <Link
                        :href="home()"
                        class="inline-flex items-center gap-3 text-sm font-semibold tracking-[0.2em] text-white/90 uppercase"
                    >
                        <span
                            class="flex size-11 items-center justify-center rounded-2xl bg-white/10 ring-1 ring-white/15 backdrop-blur-sm"
                        >
                            <Files class="size-5" />
                        </span>
                        <span>Document Hub</span>
                    </Link>

                    <div class="space-y-3">
                        <h2
                            class="max-w-xl text-3xl font-semibold tracking-tight text-balance sm:text-4xl"
                        >
                            Shared email sync and document merge in one staff
                            workspace.
                        </h2>
                        <p
                            class="max-w-lg text-sm leading-7 text-white/70 sm:text-base"
                        >
                            Document Hub helps your team manage synced emails,
                            shared merge workflows, and staff access inside one
                            clean system.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2 lg:hidden">
                        <Badge
                            v-for="highlight in productHighlights"
                            :key="highlight.title"
                            variant="secondary"
                            class="rounded-full border-0 bg-white/10 px-3 py-1 text-white hover:bg-white/10"
                        >
                            {{ highlight.title }}
                        </Badge>
                    </div>
                </div>

                <div class="hidden gap-3 lg:grid">
                    <div
                        v-for="highlight in productHighlights"
                        :key="highlight.title"
                        class="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm"
                    >
                        <div class="flex items-start gap-3">
                            <div
                                class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-white/10"
                            >
                                <component :is="highlight.icon" class="size-5" />
                            </div>
                            <div class="space-y-1">
                                <p class="font-medium text-white">
                                    {{ highlight.title }}
                                </p>
                                <p class="text-sm leading-6 text-white/70">
                                    {{ highlight.description }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <div
                v-if="status"
                class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-700"
            >
                {{ status }}
            </div>

            <div
                class="rounded-[2rem] border border-border/70 bg-background/95 p-6 shadow-[0_24px_80px_-40px_rgba(15,23,42,0.35)] sm:p-8"
            >
                <Form
                    v-bind="store.form()"
                    :reset-on-success="['password']"
                    v-slot="{ errors, processing }"
                    class="flex flex-col gap-6"
                >
                    <div class="grid gap-5">
                        <div class="grid gap-2">
                            <Label for="email">Email address</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autofocus
                                :tabindex="1"
                                autocomplete="email"
                                placeholder="email@example.com"
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div class="grid gap-2">
                            <div class="flex items-center justify-between gap-4">
                                <Label for="password">Password</Label>
                                <TextLink
                                    v-if="canResetPassword"
                                    :href="request()"
                                    class="text-sm"
                                    :tabindex="5"
                                >
                                    Forgot password?
                                </TextLink>
                            </div>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                :tabindex="2"
                                autocomplete="current-password"
                                placeholder="Password"
                            />
                            <InputError :message="errors.password" />
                        </div>

                        <div class="flex items-center justify-between">
                            <Label
                                for="remember"
                                class="flex items-center gap-3 text-sm font-normal"
                            >
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    :tabindex="3"
                                />
                                <span>Remember me</span>
                            </Label>
                        </div>

                        <Button
                            type="submit"
                            class="mt-2 h-11 w-full rounded-xl"
                            :tabindex="4"
                            :disabled="processing"
                            data-test="login-button"
                        >
                            <Spinner v-if="processing" />
                            Log in
                        </Button>
                    </div>

                    <div
                        v-if="canRegister"
                        class="text-center text-sm text-muted-foreground"
                    >
                        Don't have an account?
                        <TextLink href="/register" :tabindex="5">
                            Sign up
                        </TextLink>
                    </div>
                </Form>
            </div>
        </div>
    </AuthBase>
</template>
