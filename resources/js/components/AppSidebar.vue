<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    Files,
    FileStack,
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
import { companyName, dashboard } from '@/routes';
import aliases from '@/routes/aliases';
import documentGenerator from '@/routes/afs-filing';
import client from '@/routes/client';
import companies from '@/routes/companies';
import form1702ex from '@/routes/forms/form1702ex';
import mailboxAccounts from '@/routes/mailbox-accounts';
import template from '@/routes/template';
import users from '@/routes/users';
import type { Auth, NavItem } from '@/types';
import filing from '@/routes/filing';

const page = usePage<{ auth: Auth }>();
const auth = computed(() => page.props.auth);
const homeHref = computed(() =>
    auth.value.user?.canAccessUserManagement
        ? users.index()
        : auth.value.user?.canAccessClientPortal
            ? client.files()
            : dashboard(),
);
const mainNavItems = computed<NavItem[]>(() => {
    if (auth.value.user?.canAccessUserManagement) {
        return [
            {
                title: 'Users',
                href: users.index(),
                icon: Users,
            },
            {
                title: 'Mailbox Accounts',
                href: mailboxAccounts.index(),
                icon: Mail,
            },
            {
                title: 'Aliases',
                href: aliases.edit(),
                icon: FileStack,
            },
        ];
    }

    if (auth.value.user?.canAccessClientPortal) {
        return [
            {
                title: 'My Files',
                href: client.files(),
                icon: FileSpreadsheet,
            },
        ];
    }

    return [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];
});

const companyNavItems = computed<NavItem[]>(() => {
    if (auth.value.user?.canAccessUserManagement || auth.value.user?.canAccessClientPortal) {
        return [];
    }

    return [
        {
            title: 'Companies',
            href: companies.index(),
            icon: BriefcaseBusiness,
        },
    ];
});

const filingNavItems = computed<NavItem[]>(() => {
    if (auth.value.user?.canAccessUserManagement || auth.value.user?.canAccessClientPortal) {
        return [];
    }

    return [
        {
            title: 'Generate Filing',
            href: filing.index(),
            icon: FileSpreadsheet,
        },
        {
            title: 'My Filings',
            href: form1702ex.index(),
            icon: Files,
        },
        {
            title: 'Templates',
            href: template.index(),
            icon: FileStack,
        },
    ];
});

const documentHubNavItems = computed<NavItem[]>(() => {
    if (auth.value.user?.canAccessUserManagement || auth.value.user?.canAccessClientPortal) {
        return [];
    }

    return [
        {
            title: 'AFS',
            href: documentGenerator.index(),
            icon: FileStack,
        },
        {
            title: '1702',
            href: form1702ex.index(),
            icon: FileSpreadsheet,
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
            <NavMain :items="mainNavItems" :label="auth.user?.canAccessUserManagement ? 'Admin' : 'Dashboard'" />
            <NavMain v-if="companyNavItems.length > 0" :items="companyNavItems" label="Company" />
            <NavMain v-if="filingNavItems.length > 0" :items="filingNavItems" label="Filing" />
            <NavMain v-if="documentHubNavItems.length > 0" :items="documentHubNavItems" label="Document Hub" />
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
