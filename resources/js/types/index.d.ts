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

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

export interface ProcessoListItem {
    id: number;
    numero_processo: string | null;
    tribunal: string | null;
    classe: string | null;
    status: string;
    valor_causa: string | null;
    created_at: string;
}

export interface ProcessoFiltros {
    busca?: string;
    tribunal_id?: number | string;
    status?: string;
    data_inicio?: string;
    data_fim?: string;
    classe_codigo?: string;
    orgao_julgador?: string;
    nivel_sigilo?: number | string;
}

export interface TribunalOption {
    id: number;
    nome: string;
}

export interface ClasseOption {
    codigo: string;
    descricao: string;
}

export interface RepresentanteItem {
    id: number;
    nome: string | null;
    numero_documento_principal: string | null;
    inscricao: string | null;
    tipo: string | null;
}

export interface ParteItem {
    id: number;
    polo: string | null;
    nome: string | null;
    cpf_cnpj: string | null;
    endereco: string;
    representantes: RepresentanteItem[];
}

export interface ProcessoDetalhe {
    id: number;
    numero_processo: string | null;
    status: string;
    tribunal: string | null;
    classe: string | null;
    orgao_julgador: string | null;
    instancia_orgao_julgador: string | null;
    valor_causa: string | null;
    nivel_sigilo: string | null;
    justica_gratuita: string | null;
    pedido_liminar: string | null;
    motivo_segredo_justica: string | null;
    created_at: string;
    assuntos: { nome: string | null; assunto_codigo: string | null; principal: boolean }[];
    prioridades: string[];
    partes: ParteItem[];
}

export interface MovimentoItem {
    id: number;
    codigo_nacional: number | string | null;
    complemento: string | null;
    data_hora: string | null;
    tem_documento: boolean;
}

export interface DocumentoItem {
    id: number;
    descricao: string | null;
    tipo_documento: number | string | null;
    mimetype: string | null;
    file_size: number | null;
    nivel_sigilo: string | null;
    data_juntada: string | null;
    data_hora: string | null;
    status: string | null;
}
