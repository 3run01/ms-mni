import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

interface Representante {
    id: number;
    nome: string;
    numero_documento_principal?: string;
    inscricao?: string;
    tipo: string;
}

interface Parte {
    id: number;
    polo: string;
    nome: string;
    cpf_cnpj: string;
    endereco: string;
    representantes: Representante[];
}

interface Assunto {
    nome: string;
    assunto_codigo: string;
    principal: boolean;
}

interface ProcessoData {
    id: number;
    numero_processo: string;
    status: string;
    tribunal?: string;
    classe?: string;
    orgao_julgador?: string;
    instancia_orgao_julgador?: string;
    valor_causa?: number;
    nivel_sigilo: string;
    justica_gratuita?: boolean;
    pedido_liminar?: boolean;
    motivo_segredo_justica?: string;
    created_at?: string;
    assuntos: Assunto[];
    prioridades: string[];
    partes: Parte[];
}

interface Props {
    processo: ProcessoData;
    movimentos?: any[];
    documentos?: any[];
}

export default function ProcessosShow({ processo, movimentos, documentos }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Processos', href: '/processos' },
        { title: processo.numero_processo },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Processo ${processo.numero_processo}`} />
            <div className="container mx-auto p-4">
                <h1 className="text-2xl font-bold">{processo.numero_processo}</h1>
                {/* Task 6 will implement the full UI */}
            </div>
        </AppLayout>
    );
}
