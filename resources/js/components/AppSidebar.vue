<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    Files,
    FileSpreadsheet,
    LayoutGrid,
    Mail,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import docMerge from '@/routes/doc-merge';
import emailSync from '@/routes/email-sync';
import forms from '@/routes/forms';
import type { Auth, NavItem } from '@/types';

const page = usePage<{ auth: Auth }>();
const auth = computed(() => page.props.auth);
const homeHref = computed(() =>
    auth.value.user?.canAccessUserManagement ? '/users' : dashboard(),
);
const mainNavItems = computed<NavItem[]>(() => {
    if (auth.value.user?.canAccessUserManagement) {
        return [
            {
                title: 'Users',
                href: '/users',
                icon: Users,
            },
            {
                title: 'Mailbox Accounts',
                href: '/mailbox-accounts',
                icon: Mail,
            },
        ];
    }

    return [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        {
            title: 'Email Sync',
            href: emailSync.index(),
            icon: Mail,
        },
        {
            title: 'Clients',
            href: '/clients',
            icon: BriefcaseBusiness,
        },
        {
            title: 'Company Name',
            href: forms.form1702ex.index(),
            icon: FileSpreadsheet,
        },
        {
            title: 'Doc Merge',
            href: docMerge.index(),
            icon: Files,
        },
    ];
});

const footerNavItems: NavItem[] = [
    
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="homeHref">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
