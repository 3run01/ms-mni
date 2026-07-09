import { Head } from '@inertiajs/react';
import { BarChart3, FileText, ScrollText } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';

const cards = [
    {
        title: 'Consulta de Processos',
        description: 'Consulte processos judiciais via API',
        icon: FileText,
        container: 'border-blue-200 bg-blue-50',
        iconColor: 'text-blue-600',
        titleColor: 'text-blue-900',
        descriptionColor: 'text-blue-700',
    },
    {
        title: 'Monitoramento',
        description: 'Acompanhe métricas do sistema',
        icon: BarChart3,
        container: 'border-green-200 bg-green-50',
        iconColor: 'text-green-600',
        titleColor: 'text-green-900',
        descriptionColor: 'text-green-700',
    },
    {
        title: 'Logs do Sistema',
        description: 'Visualize logs de aplicação',
        icon: ScrollText,
        container: 'border-yellow-200 bg-yellow-50',
        iconColor: 'text-yellow-600',
        titleColor: 'text-yellow-900',
        descriptionColor: 'text-yellow-700',
    },
];

export default function Dashboard() {
    return (
        <AppLayout>
            <Head title="Dashboard" />

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white p-6 shadow-sm sm:rounded-lg">
                        <h1 className="mb-6 text-2xl font-bold">Dashboard - SIM-MNI</h1>

                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                            {cards.map(
                                ({ title, description, icon: Icon, container, iconColor, titleColor, descriptionColor }) => (
                                    <div
                                        key={title}
                                        className={`flex items-center gap-4 rounded-lg border p-6 ${container}`}
                                    >
                                        <Icon className={`size-8 shrink-0 ${iconColor}`} />
                                        <div>
                                            <h3 className={`text-lg font-medium ${titleColor}`}>{title}</h3>
                                            <p className={descriptionColor}>{description}</p>
                                        </div>
                                    </div>
                                ),
                            )}
                        </div>

                        <div className="mt-8">
                            <h2 className="mb-4 text-xl font-semibold">Bem-vindo ao SIM-MNI</h2>
                            <p className="text-gray-600">
                                Sistema de Integração MNI para consulta e monitoramento de processos judiciais. Use o
                                menu acima para navegar pelas funcionalidades disponíveis.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
