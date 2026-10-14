import { Head } from '@inertiajs/react';

import UsuarioForm, { type UsuarioFormValues } from '@/components/usuario-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

export default function UsuariosEdit({ usuario }: { usuario: UsuarioFormValues }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Usuários', href: '/usuarios' },
        { title: usuario.name, href: `/usuarios/${usuario.id}/editar` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar ${usuario.name}`} />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-bold">Editar usuário</h1>
                <UsuarioForm usuario={usuario} />
            </div>
        </AppLayout>
    );
}
