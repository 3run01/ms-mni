import { type LucideIcon } from 'lucide-react';

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    external?: boolean;
}

export interface SharedProps {
    app: { name: string };
    auth: { user: User | null };
    sidebarOpen: boolean;
    [key: string]: unknown;
}
