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

export interface ApiTokenListItem {
    id: number;
    name: string;
    ativo: boolean;
    expires_at: string | null;
    last_used_at: string | null;
    created_at: string;
}

export interface SharedProps {
    app: { name: string };
    auth: { user: User | null };
    sidebarOpen: boolean;
    flash: { success: string | null; token: string | null };
    [key: string]: unknown;
}
