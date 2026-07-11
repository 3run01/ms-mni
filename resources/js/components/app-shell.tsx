import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';

import { SidebarProvider } from '@/components/ui/sidebar';
import { type SharedProps } from '@/types';

export function AppShell({ children }: { children: ReactNode }) {
    const isOpen = usePage<SharedProps>().props.sidebarOpen;

    return <SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>;
}
