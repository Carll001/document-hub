<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Files,
    Mail,
    ShieldCheck,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import documentGeneratorRoutes from '@/routes/document-generator';
import generatedFilesRoutes from '@/routes/generated-files';
import { dashboard, login } from '@/routes';
import type { Auth } from '@/types';

withDefaults(
    defineProps<{
        canRegister: boolean;
        signatureEnabled: boolean;
    }>(),
    {
        canRegister: true,
        signatureEnabled: true,
    },
);

const page = usePage<{ auth: Auth }>();
const auth = computed(() => page.props.auth);

const primaryHref = computed(() => {
    const user = auth.value.user;

    if (!user) {
        return login();
    }

    return user.canAccessUserManagement ? '/users' : dashboard();
});

const primaryLabel = computed(() => {
    const user = auth.value.user;

    if (!user) {
        return 'Log in';
    }

    return user.canAccessUserManagement ? 'Open users' : 'Open dashboard';
});

const documentGeneratorHref = computed(() =>
    auth.value.user ? documentGeneratorRoutes.index().url : login(),
);

const generatedFilesHref = computed(() =>
    auth.value.user ? generatedFilesRoutes.index().url : login(),
);

const workflowSteps = [
    'Sync incoming emails into one workspace.',
    'Merge PDFs with a shared confirmation template.',
    'Keep staff access managed by superadmin accounts.',
];

const featureCards = [
    {
        title: 'Email Sync',
        description:
            'Collect mailbox activity in one place so staff can work from the same saved message history.',
        icon: Mail,
    },
    {
        title: 'Doc Merge',
        description:
            'Generate merged PDFs quickly, including bulk workflows for repeated document sets.',
        icon: Files,
    },
    {
        title: 'Document Generator',
        description:
            'Generate document rows from Excel + DOCX templates with one shared process.',
        icon: Files,
    },
    {
        title: 'Completed Files',
        description:
            'Track signed outputs in one place and open completed documents quickly.',
        icon: Files,
    },
    {
        title: 'User Control',
        description:
            'Superadmins create and manage staff accounts without exposing public registration.',
        icon: Users,
    },
];

const roleCards = [
    {
        title: 'Superadmin',
        description:
            'Creates staff accounts, controls access, and oversees the shared workspace.',
        icon: ShieldCheck,
    },
    {
        title: 'Staff',
        description:
            'Uses Email Sync and Doc Merge tools to process day-to-day documents faster.',
        icon: Users,
    },
];

function scrollToFeatures(): void {
    if (typeof document === 'undefined') {
        return;
    }

    document.getElementById('features')?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
}
</script>

<template>
    <Head title="Document Hub" />

    <div
        class="min-h-screen bg-[radial-gradient(circle_at_top_left,_rgba(0,0,0,0.08),_transparent_24%),linear-gradient(180deg,_hsl(0_0%_100%),_hsl(0_0%_98%))] text-foreground dark:bg-[radial-gradient(circle_at_top_left,_rgba(255,255,255,0.08),_transparent_24%),linear-gradient(180deg,_hsl(0_0%_6%),_hsl(0_0%_4%))]"
    >
        <div class="mx-auto flex min-h-screen w-full max-w-7xl flex-col px-4 py-6 md:px-6 lg:px-8">
            <header
                class="flex flex-wrap items-center justify-between gap-4 border-b border-border/70 pb-6"
            >
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-11 items-center justify-center rounded-2xl bg-primary text-primary-foreground shadow-sm"
                    >
                        <AppLogoIcon class-name="size-6" />
                    </div>
                    <div class="space-y-1">
                        <p class="text-sm font-semibold tracking-[0.2em] uppercase">
                            Document Hub
                        </p>
                        <p class="text-sm text-muted-foreground">
                            Shared email sync and document merge workspace
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-if="$page.props.auth.user"
                        as-child
                        variant="outline"
                        size="sm"
                        class="rounded-full"
                    >
                        <Link :href="primaryHref">
                            {{ primaryLabel }}
                        </Link>
                    </Button>
                    <template v-else>
                        <Button as-child variant="ghost" size="sm" class="rounded-full">
                            <Link :href="login()">Log in</Link>
                        </Button>
                        <Button
                            v-if="canRegister"
                            as-child
                            variant="outline"
                            size="sm"
                            class="rounded-full"
                        >
                            <Link href="/register">Register</Link>
                        </Button>
                    </template>
                </div>
            </header>

            <main class="flex flex-1 flex-col justify-center py-10 md:py-14">
                <section class="grid gap-8 lg:grid-cols-[minmax(0,1.15fr)_minmax(320px,0.85fr)] lg:items-center">
                    <div class="space-y-6">
                        <Badge
                            variant="outline"
                            class="rounded-full px-4 py-1 text-[11px] tracking-[0.22em] uppercase"
                        >
                            Internal workflow platform
                        </Badge>

                        <div class="space-y-4">
                            <h1
                                class="max-w-3xl text-4xl font-semibold tracking-tight text-balance sm:text-5xl lg:text-6xl"
                            >
                                Stop juggling inboxes, PDFs, and user access in separate places.
                            </h1>
                            <p
                                class="max-w-2xl text-base leading-8 text-muted-foreground sm:text-lg"
                            >
                                Document Hub brings email sync, shared merge templates, and
                                staff account management into one clean workspace for your team.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-3">
                            <Button as-child size="lg" class="gap-2 rounded-full px-6">
                                <Link :href="primaryHref">
                                    {{ primaryLabel }}
                                    <ArrowRight class="size-4" />
                                </Link>
                            </Button>
                            <Button
                                variant="secondary"
                                size="lg"
                                class="gap-2 rounded-full px-6"
                                type="button"
                                @click="scrollToFeatures"
                            >
                                See features
                            </Button>
                        </div>

                        <div class="flex flex-wrap gap-2 pt-2">
                            <Badge variant="secondary" class="rounded-full px-3 py-1">
                                Shared templates
                            </Badge>
                            <Badge variant="secondary" class="rounded-full px-3 py-1">
                                Bulk PDF merge
                            </Badge>
                            <Badge variant="secondary" class="rounded-full px-3 py-1">
                                {{ signatureEnabled ? 'Signature tools enabled' : 'Signature tools disabled' }}
                            </Badge>
                            <Badge variant="secondary" class="rounded-full px-3 py-1">
                                Staff-only accounts
                            </Badge>
                        </div>
                    </div>

                    <Card
                        class="relative overflow-hidden rounded-[2rem] border-0 bg-[linear-gradient(145deg,_rgba(0,0,0,0.95),_rgba(36,36,36,0.92))] text-white shadow-2xl"
                    >
                        <div
                            class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(255,255,255,0.18),_transparent_28%),radial-gradient(circle_at_bottom_left,_rgba(255,255,255,0.12),_transparent_24%)]"
                        />
                        <CardContent class="relative space-y-6 p-6 sm:p-8">
                            <div class="flex items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm tracking-[0.2em] uppercase text-white/60">
                                        Workspace flow
                                    </p>
                                    <p class="mt-2 text-2xl font-semibold">
                                        One place to move work forward
                                    </p>
                                </div>
                                <div
                                    class="flex size-12 items-center justify-center rounded-2xl bg-white/10 backdrop-blur"
                                >
                                    <Files class="size-6" />
                                </div>
                            </div>

                            <div class="grid gap-3">
                                <div
                                    v-for="step in workflowSteps"
                                    :key="step"
                                    class="flex items-start gap-3 rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm"
                                >
                                    <CheckCircle2 class="mt-0.5 size-5 shrink-0 text-white/80" />
                                    <p class="text-sm leading-7 text-white/80">
                                        {{ step }}
                                    </p>
                                </div>
                            </div>

                            <div class="grid gap-3 sm:grid-cols-2">
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                    <p class="text-xs tracking-[0.18em] uppercase text-white/60">
                                        Access model
                                    </p>
                                    <p class="mt-2 text-lg font-semibold">Superadmin + Staff</p>
                                </div>
                                <div class="rounded-2xl border border-white/10 bg-white/5 p-4">
                                    <p class="text-xs tracking-[0.18em] uppercase text-white/60">
                                        Account flow
                                    </p>
                                    <p class="mt-2 text-lg font-semibold">No public registration</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </section>

                <section id="features" class="mt-14 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <Card
                        v-for="feature in featureCards"
                        :key="feature.title"
                        class="rounded-[2rem] border-border/70 bg-card/80 shadow-sm backdrop-blur"
                    >
                        <CardHeader class="space-y-4">
                            <div
                                class="flex size-12 items-center justify-center rounded-2xl bg-primary/8 text-primary"
                            >
                                <component :is="feature.icon" class="size-5" />
                            </div>
                            <div class="space-y-2">
                                <CardTitle class="text-xl">{{ feature.title }}</CardTitle>
                                <CardDescription class="text-sm leading-7">
                                    {{ feature.description }}
                                </CardDescription>
                            </div>
                        </CardHeader>
                    </Card>
                </section>

                <section class="mt-14 grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
                    <Card class="rounded-[2rem] border-border/70">
                        <CardHeader class="space-y-2">
                            <Badge
                                variant="outline"
                                class="w-fit rounded-full px-3 py-1 text-[11px] tracking-[0.18em] uppercase"
                            >
                                Team roles
                            </Badge>
                            <CardTitle class="text-2xl">
                                Simple permissions for a focused team
                            </CardTitle>
                            <CardDescription class="text-sm leading-7">
                                The workspace stays lightweight: superadmins manage users, and
                                staff use the tools they need to get documents processed.
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="grid gap-4 sm:grid-cols-2">
                            <div
                                v-for="role in roleCards"
                                :key="role.title"
                                class="rounded-3xl border bg-muted/40 p-5"
                            >
                                <div
                                    class="flex size-11 items-center justify-center rounded-2xl bg-background text-foreground shadow-sm"
                                >
                                    <component :is="role.icon" class="size-5" />
                                </div>
                                <h2 class="mt-4 text-lg font-semibold">
                                    {{ role.title }}
                                </h2>
                                <p class="mt-2 text-sm leading-7 text-muted-foreground">
                                    {{ role.description }}
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card
                        class="rounded-[2rem] border border-border/60 bg-[linear-gradient(160deg,_rgba(0,0,0,0.06),_rgba(0,0,0,0.02))] dark:bg-[linear-gradient(160deg,_rgba(255,255,255,0.06),_rgba(255,255,255,0.02))]"
                    >
                        <CardContent class="flex h-full flex-col justify-between gap-8 p-6 sm:p-8">
                            <div class="space-y-3">
                                <p class="text-sm tracking-[0.2em] uppercase text-muted-foreground">
                                    Ready to start
                                </p>
                                <h2 class="text-3xl font-semibold tracking-tight">
                                    Open the workspace and keep the process moving.
                                </h2>
                                <p class="max-w-xl text-sm leading-7 text-muted-foreground">
                                    Sign in to manage staff accounts, review synced messages, and
                                    generate merged files from the same system.
                                </p>
                            </div>

                            <div class="flex flex-wrap gap-3">
                                <Button as-child size="lg" class="gap-2 rounded-full px-6">
                                    <Link :href="primaryHref">
                                        {{ primaryLabel }}
                                        <ArrowRight class="size-4" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="$page.props.auth.user"
                                    as-child
                                    variant="secondary"
                                    size="lg"
                                    class="gap-2 rounded-full px-6"
                                >
                                    <Link :href="documentGeneratorHref">
                                        Open document generator
                                        <ArrowRight class="size-4" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="$page.props.auth.user"
                                    as-child
                                    variant="outline"
                                    size="lg"
                                    class="gap-2 rounded-full px-6"
                                >
                                    <Link :href="generatedFilesHref">
                                        Open completed files
                                        <ArrowRight class="size-4" />
                                    </Link>
                                </Button>
                                <Button
                                    v-if="!$page.props.auth.user"
                                    as-child
                                    variant="outline"
                                    size="lg"
                                    class="rounded-full px-6"
                                >
                                    <Link :href="login()">Go to sign in</Link>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </section>
            </main>
        </div>
    </div>
</template>
