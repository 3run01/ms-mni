import { Head } from '@inertiajs/react';

import UsuarioForm from '@/components/usuario-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Usuários', href: '/usuarios' },
    { title: 'Novo usuário', href: '/usuarios/criar' },
];

export default function UsuariosCreate() {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo usuário" />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-bold">Novo usuário</h1>
                <UsuarioForm />
            </div>
        </AppLayout>
    );
}
