import { Deferred, Head, router } from '@inertiajs/react';
import { AlertCircle, Clock, FileDown, FolderDown } from 'lucide-react';
import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

type PontoSerie = { dia: string; total: number };

type Metricas = {
    totais: {
        processos: number;
        documentosBaixados: number;
        documentosPendentes: number;
        documentosErro: number;
    };
    processosPorDia: PontoSerie[];
    documentosPorDia: PontoSerie[];
};

const PERIODOS = [7, 30, 90] as const;

const chartConfigProcessos = {
    total: { label: 'Processos', color: 'var(--chart-1)' },
} satisfies ChartConfig;

const chartConfigDocumentos = {
    total: { label: 'Documentos', color: 'var(--chart-2)' },
} satisfies ChartConfig;

function formatarDiaCurto(dia: string) {
    return new Date(`${dia}T00:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
}

function formatarDiaLongo(dia: string) {
    return new Date(`${dia}T00:00:00`).toLocaleDateString('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' });
}

function CardTotal({
    titulo,
    valor,
    icone: Icone,
}: {
    titulo: string;
    valor?: number;
    icone: typeof FileDown;
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">{titulo}</CardTitle>
                <Icone className="size-4 text-muted-foreground" />
            </CardHeader>
            <CardContent>
                {valor === undefined ? (
                    <Skeleton className="h-8 w-16" />
                ) : (
                    <div className="text-2xl font-semibold tabular-nums">{valor.toLocaleString('pt-BR')}</div>
                )}
            </CardContent>
        </Card>
    );
}

function GraficoSerie({
    titulo,
    serie,
    config,
    corVar,
}: {
    titulo: string;
    serie: PontoSerie[];
    config: ChartConfig;
    corVar: string;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">{titulo}</CardTitle>
            </CardHeader>
            <CardContent>
                <ChartContainer config={config} className="h-64 w-full">
                    <AreaChart data={serie} margin={{ left: 0, right: 12, top: 8 }}>
                        <CartesianGrid vertical={false} strokeDasharray="3 3" />
                        <XAxis
                            dataKey="dia"
                            tickLine={false}
                            axisLine={false}
                            tickMargin={8}
                            minTickGap={24}
                            tickFormatter={formatarDiaCurto}
                        />
                        <YAxis tickLine={false} axisLine={false} allowDecimals={false} width={36} />
                        <ChartTooltip
                            cursor={{ strokeDasharray: '3 3' }}
                            content={<ChartTooltipContent labelFormatter={(_, payload) => formatarDiaLongo(String(payload?.[0]?.payload?.dia ?? ''))} />}
                        />
                        <Area
                            dataKey="total"
                            type="linear"
                            stroke={corVar}
                            strokeWidth={2}
                            fill={corVar}
                            fillOpacity={0.15}
                            dot={false}
                        />
                    </AreaChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}

function GraficoSkeleton() {
    return (
        <Card>
            <CardHeader>
                <Skeleton className="h-5 w-56" />
            </CardHeader>
            <CardContent>
                <Skeleton className="h-64 w-full" />
            </CardContent>
        </Card>
    );
}

export default function Dashboard({ periodo, metricas }: { periodo: number; metricas?: Metricas }) {
    function trocarPeriodo(novoPeriodo: number) {
        router.get(
            '/dashboard',
            { periodo: novoPeriodo },
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold">Visão geral</h1>
                    <div className="flex gap-1 rounded-lg border p-1">
                        {PERIODOS.map((p) => (
                            <Button
                                key={p}
                                size="sm"
                                variant={p === periodo ? 'default' : 'ghost'}
                                onClick={() => trocarPeriodo(p)}
                            >
                                {p} dias
                            </Button>
                        ))}
                    </div>
                </div>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                    <CardTotal titulo="Processos no período" valor={metricas?.totais.processos} icone={FolderDown} />
                    <CardTotal titulo="Documentos baixados no período" valor={metricas?.totais.documentosBaixados} icone={FileDown} />
                    <CardTotal titulo="Documentos pendentes" valor={metricas?.totais.documentosPendentes} icone={Clock} />
                    <CardTotal titulo="Documentos com erro" valor={metricas?.totais.documentosErro} icone={AlertCircle} />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Deferred data="metricas" fallback={<><GraficoSkeleton /><GraficoSkeleton /></>}>
                        {metricas && (
                            <>
                                <GraficoSerie
                                    titulo="Processos baixados por dia"
                                    serie={metricas.processosPorDia}
                                    config={chartConfigProcessos}
                                    corVar="var(--chart-1)"
                                />
                                <GraficoSerie
                                    titulo="Documentos baixados por dia"
                                    serie={metricas.documentosPorDia}
                                    config={chartConfigDocumentos}
                                    corVar="var(--chart-2)"
                                />
                            </>
                        )}
                    </Deferred>
                </div>
            </div>
        </AppLayout>
    );
}
