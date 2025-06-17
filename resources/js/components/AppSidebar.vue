<script setup lang="ts">
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarRail } from '@/components/ui/sidebar';
import type { NavItem } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { BarChart3, FileText, Gauge, Home, Settings, Users, Zap, Target } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth?.user);


// Navigation items
const data: { navMain: NavItem[] } = {
    navMain: [
        {
            title: 'Dashboard',
            href: '/dashboard',
            icon: Home,
        },
        {
            title: 'Reports',
            icon: BarChart3,
            items: [
                {
                    title: 'Project Time',
                    href: '/reports/project-time',
                    icon: FileText,
                },
                {
                    title: 'User Time per Project',
                    href: '/reports/user-time-per-project',
                    icon: Users,
                },
                {
                    title: 'Project Trend',
                    href: '/reports/project-trend',
                    icon: Gauge,
                },
            ],
        },
        {
            title: 'Initiatives',
            href: '/initiatives',
            icon: Target,
        },
        {
            title: 'Admin',
            icon: Zap,
            items: [
                {
                    title: 'JIRA Sync',
                    href: '/admin/jira/sync',
                    icon: Zap,
                },
                {
                    title: 'JIRA Issues',
                    href: '/admin/jira/issues',
                    icon: FileText,
                },
                {
                    title: 'Manage Initiatives',
                    href: '/admin/initiatives',
                    icon: Target,
                },
            ],
        },
        {
            title: 'Settings',
            icon: Settings,
            items: [
                {
                    title: 'Profile',
                    href: '/settings/profile',
                    icon: Users,
                },
                {
                    title: 'Password',
                    href: '/settings/password',
                    icon: Settings,
                },
                {
                    title: 'JIRA',
                    href: '/settings/jira',
                    icon: Zap,
                },
                {
                    title: 'Appearance',
                    href: '/settings/appearance',
                    icon: Settings,
                },
            ],
        },
    ],
};

</script>

<template>
    <Sidebar collapsible="icon">
        <SidebarHeader>
            <AppLogo />
        </SidebarHeader>
        <SidebarContent>
            <NavMain :items="data.navMain" />
        </SidebarContent>
        <SidebarFooter>
            <NavUser v-if="user" :user="user" />
            <NavFooter />
        </SidebarFooter>
        <SidebarRail />
    </Sidebar>
</template>
