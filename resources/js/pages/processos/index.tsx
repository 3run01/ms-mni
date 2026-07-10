import { Head, usePage } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedProps } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Processos', href: '/processos' }];

interface ProcessoListItem {
    id: number;
    numero_processo: string;
    tribunal: string | null;
    classe: string | null;
    status: string;
    valor_causa: number;
    created_at: string;
}

interface Props {
    processos: {
        data: ProcessoListItem[];
        total: number;
        current_page: number;
        links: any[];
    };
    filtros: {
        busca?: string;
    };
}

export default function ProcessosIndex({ processos, filtros }: Props) {
    const { flash } = usePage<SharedProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Processos" />
            <div className="flex flex-col gap-4 p-4">
                {/* TODO: Implement processos listing UI */}
            </div>
        </AppLayout>
    );
}
