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

export interface TribunalListItem {
    id: number;
    nome: string;
    tipo: string | null;
    versao_mni: string | null;
    ativo: boolean | null;
}

export interface SharedProps {
    app: { name: string };
    auth: { user: User | null };
    sidebarOpen: boolean;
    flash: { success: string | null };
    [key: string]: unknown;
}
