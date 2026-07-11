import { Head } from '@inertiajs/react';

import TribunalForm from '@/components/tribunal-form';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tribunais', href: '/tribunais' },
    { title: 'Novo tribunal', href: '/tribunais/criar' },
];

export default function TribunaisCreate({ tipos }: { tipos: string[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo tribunal" />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-bold">Novo tribunal</h1>
                <TribunalForm tipos={tipos} />
            </div>
        </AppLayout>
    );
}
