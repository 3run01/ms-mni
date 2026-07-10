import { Head } from '@inertiajs/react';

import TribunalForm, { type TribunalFormValues } from '@/components/tribunal-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

export default function TribunaisEdit({
    tipos,
    tribunal,
}: {
    tipos: string[];
    tribunal: TribunalFormValues;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tribunais', href: '/tribunais' },
        { title: tribunal.nome, href: `/tribunais/${tribunal.id}/editar` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Editar ${tribunal.nome}`} />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-bold">Editar tribunal</h1>
                <TribunalForm tipos={tipos} tribunal={tribunal} />
            </div>
        </AppLayout>
    );
}
