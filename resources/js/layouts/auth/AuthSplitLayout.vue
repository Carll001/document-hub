<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed, useSlots } from 'vue';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

const page = usePage();
const name = page.props.name;
const slots = useSlots();
const hasPanel = computed(() => slots.panel !== undefined);

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        v-if="hasPanel"
        class="min-h-dvh bg-[radial-gradient(circle_at_top_left,_rgba(15,23,42,0.06),_transparent_26%),linear-gradient(180deg,_hsl(0_0%_100%),_hsl(0_0%_98%))]"
    >
        <div
            class="grid min-h-dvh lg:grid-cols-[minmax(0,1.08fr)_minmax(420px,0.92fr)]"
        >
            <div
                class="relative overflow-hidden border-b border-black/10 bg-[linear-gradient(160deg,_rgba(15,23,42,0.98),_rgba(30,41,59,0.96))] text-white lg:border-r lg:border-b-0"
            >
                <div
                    class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,_rgba(255,255,255,0.12),_transparent_24%),radial-gradient(circle_at_bottom_left,_rgba(255,255,255,0.08),_transparent_28%)]"
                />
                <div
                    class="relative flex h-full flex-col px-6 py-8 sm:px-8 sm:py-10 lg:px-10 lg:py-12"
                >
                    <slot name="panel" />
                </div>
            </div>

            <div
                class="flex items-center justify-center px-4 py-8 sm:px-6 lg:px-10 lg:py-12"
            >
                <div class="w-full max-w-md space-y-8">
                    <div class="space-y-3 text-left">
                        <p
                            class="text-xs font-semibold tracking-[0.22em] text-muted-foreground uppercase"
                        >
                            Secure Staff Login
                        </p>
                        <h1
                            v-if="title"
                            class="text-3xl font-semibold tracking-tight text-balance text-foreground"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="max-w-sm text-sm leading-6 text-muted-foreground sm:text-base"
                        >
                            {{ description }}
                        </p>
                    </div>

                    <slot />
                </div>
            </div>
        </div>
    </div>

    <div
        v-else
        class="relative grid h-dvh flex-col items-center justify-center px-8 sm:px-0 lg:max-w-none lg:grid-cols-2 lg:px-0"
    >
        <div
            class="relative hidden h-full flex-col bg-muted p-10 text-white lg:flex dark:border-r"
        >
            <div class="absolute inset-0 bg-zinc-900" />
            <Link
                :href="home()"
                class="relative z-20 flex items-center text-lg font-medium"
            >
                <AppLogoIcon class="mr-2 size-8 fill-current text-white" />
                {{ name }}
            </Link>
        </div>
        <div class="lg:p-8">
            <div
                class="mx-auto flex w-full flex-col justify-center space-y-6 sm:w-[350px]"
            >
                <div class="flex flex-col space-y-2 text-center">
                    <h1 class="text-xl font-medium tracking-tight" v-if="title">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground" v-if="description">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
