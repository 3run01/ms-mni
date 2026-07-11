import { Link } from '@inertiajs/react';
import { Activity, FileText, KeyRound, Landmark, LayoutGrid, LayoutList } from 'lucide-react';

import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
    },
    {
        title: 'Tribunais',
        href: '/tribunais',
        icon: Landmark,
    },
    {
        title: 'Tokens de API',
        href: '/tokens',
        icon: KeyRound,
    },
];

const monitoringNavItems: NavItem[] = [
    { title: 'Laravel Pulse', href: '/pulse', icon: Activity, external: true },
    { title: 'Horizon', href: '/horizon', icon: LayoutList, external: true },
    { title: 'Log Viewer', href: '/logs', icon: FileText, external: true },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain label="Platform" items={mainNavItems} />
                <NavMain label="Monitoramento" items={monitoringNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
