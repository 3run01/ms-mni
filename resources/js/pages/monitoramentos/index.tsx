import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, Radar, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import { formatDataHora } from '@/lib/format';
import {
    type BreadcrumbItem,
    type MonitoramentoFiltros,
    type MonitoramentoListItem,
    type Paginated,
    type TokenOption,
    type TribunalOption,
} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Monitoramentos', href: '/monitoramentos' }];

const statusBadgeClass: Record<string, string> = {
    ativo: 'border-transparent bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    pausado: 'border-transparent bg-muted text-muted-foreground',
    suspenso: 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};

interface Props {
    monitoramentos: Paginated<MonitoramentoListItem>;
    filtros: MonitoramentoFiltros;
    tribunais: TribunalOption[];
    tokens: TokenOption[];
    statusOptions: string[];
    resumo: { ativos: number; pausados: number; suspensos: number };
}

function aplicarFiltros(filtros: MonitoramentoFiltros) {
    const params = Object.fromEntries(
        Object.entries(filtros).filter(([, v]) => v !== undefined && v !== null && v !== ''),
    );
    router.get('/monitoramentos', params, {
        preserveState: true,
        preserveScroll: true,
        only: ['monitoramentos', 'filtros', 'resumo', 'errors'],
    });
}

function formatarNumeroProcesso(numero: string): string {
    const d = numero.replace(/\D/g, '');
    if (d.length !== 20) return numero;
    return `${d.slice(0, 7)}-${d.slice(7, 9)}.${d.slice(9, 13)}.${d.slice(13, 14)}.${d.slice(14, 16)}.${d.slice(16)}`;
}

function formatarIntervalo(horas: number): string {
    if (horas % 24 === 0) {
        const dias = horas / 24;
        return dias === 1 ? '1 dia' : `${dias} dias`;
    }
    return horas === 1 ? '1 hora' : `${horas} horas`;
}

function CardResumo({ titulo, valor, destaque }: { titulo: string; valor: number; destaque?: boolean }) {
    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium text-muted-foreground">{titulo}</CardTitle>
            </CardHeader>
            <CardContent>
                <p className={`text-2xl font-bold ${destaque && valor > 0 ? 'text-red-600 dark:text-red-400' : ''}`}>
                    {valor}
                </p>
            </CardContent>
        </Card>
    );
}

function UltimoResultado({ item }: { item: MonitoramentoListItem }) {
    const ultima = item.ultima_execucao;

    if (!ultima) {
        return <span className="text-muted-foreground">Aguardando 1ª execução</span>;
    }

    if (ultima.status === 'falha') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="inline-flex items-center gap-1 text-red-600 dark:text-red-400">
                        <AlertTriangle className="size-4 shrink-0" />
                        Falhou
                    </span>
                </TooltipTrigger>
                <TooltipContent className="max-w-md">
                    {ultima.erro_resumo ?? 'Sem detalhes do erro.'}
                </TooltipContent>
            </Tooltip>
        );
    }

    if (!ultima.houve_alteracao) {
        return <span className="text-muted-foreground">Sem novidade</span>;
    }

    return (
        <span className="text-foreground">
            {ultima.movimentos_novos > 0 && `${ultima.movimentos_novos} mov.`}
            {ultima.movimentos_novos > 0 && ultima.documentos_novos > 0 && ' · '}
            {ultima.documentos_novos > 0 && `${ultima.documentos_novos} doc.`}
        </span>
    );
}

export default function MonitoramentosIndex({
    monitoramentos,
    filtros,
    tribunais,
    tokens,
    statusOptions,
    resumo,
}: Props) {
    const [busca, setBusca] = useState(filtros.busca ?? '');
    const primeiraRenderizacao = useRef(true);
    const filtrosRef = useRef(filtros);
    filtrosRef.current = filtros;

    const temFiltroAtivo = Object.values(filtros).some((v) => v !== undefined && v !== null && v !== '');

    const mudarFiltro = useCallback(
        (mudanca: Partial<MonitoramentoFiltros>) => {
            aplicarFiltros({ ...filtrosRef.current, busca: busca || undefined, ...mudanca });
        },
        [busca],
    );

    // debounce de 400ms na busca por número, igual à listagem de processos
    useEffect(() => {
        if (primeiraRenderizacao.current) {
            primeiraRenderizacao.current = false;
            return;
        }
        const timer = setTimeout(() => {
            aplicarFiltros({ ...filtrosRef.current, busca: busca || undefined });
        }, 400);
        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [busca]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Monitoramentos" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center gap-2">
                    <Radar className="size-6" />
                    <h1 className="text-2xl font-bold">Processos monitorados</h1>
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <CardResumo titulo="Ativos" valor={resumo.ativos} />
                    <CardResumo titulo="Pausados" valor={resumo.pausados} />
                    <CardResumo titulo="Suspensos" valor={resumo.suspensos} destaque />
                </div>

                <div className="flex flex-col gap-3 rounded-xl border p-4">
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-1.5">
                            <Label htmlFor="busca">Número do processo</Label>
                            <Input
                                id="busca"
                                placeholder="Buscar por número..."
                                value={busca}
                                onChange={(e) => setBusca(e.target.value)}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Tribunal</Label>
                            <Select
                                value={String(filtros.tribunal_id ?? 'todos')}
                                onValueChange={(v) => mudarFiltro({ tribunal_id: v === 'todos' ? undefined : v })}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="todos">Todos</SelectItem>
                                    {tribunais.map((t) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.nome}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Status</Label>
                            <Select
                                value={filtros.status ?? 'todos'}
                                onValueChange={(v) => mudarFiltro({ status: v === 'todos' ? undefined : v })}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="todos">Todos</SelectItem>
                                    {statusOptions.map((s) => (
                                        <SelectItem key={s} value={s}>
                                            {s}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <Label>Token de API</Label>
                            <Select
                                value={String(filtros.api_token_id ?? 'todos')}
                                onValueChange={(v) => mudarFiltro({ api_token_id: v === 'todos' ? undefined : v })}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="todos">Todos</SelectItem>
                                    {tokens.map((t) => (
                                        <SelectItem key={t.id} value={String(t.id)}>
                                            {t.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {temFiltroAtivo && (
                        <div className="flex justify-end">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => {
                                    setBusca('');
                                    aplicarFiltros({});
                                }}
                            >
                                <X className="size-4" /> Limpar filtros
                            </Button>
                        </div>
                    )}
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Número do processo</TableHead>
                                <TableHead>Tribunal</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Frequência</TableHead>
                                <TableHead>Última execução</TableHead>
                                <TableHead>Resultado</TableHead>
                                <TableHead>Próxima execução</TableHead>
                                <TableHead>Token</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {monitoramentos.data.map((item) => (
                                <TableRow key={item.uuid}>
                                    <TableCell className="font-medium">
                                        {item.processo_id ? (
                                            <Link href={`/processos/${item.processo_id}`} className="hover:underline">
                                                {formatarNumeroProcesso(item.numero_processo)}
                                            </Link>
                                        ) : (
                                            formatarNumeroProcesso(item.numero_processo)
                                        )}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">{item.tribunal ?? '—'}</TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <Badge variant="outline" className={statusBadgeClass[item.status] ?? ''}>
                                                {item.status}
                                            </Badge>
                                            {item.falhas_consecutivas > 0 && (
                                                <Tooltip>
                                                    <TooltipTrigger asChild>
                                                        <span className="text-xs text-muted-foreground">
                                                            {item.falhas_consecutivas}×
                                                        </span>
                                                    </TooltipTrigger>
                                                    <TooltipContent>
                                                        {item.falhas_consecutivas} falha(s) consecutiva(s). Em 5, o
                                                        monitoramento é suspenso.
                                                    </TooltipContent>
                                                </Tooltip>
                                            )}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatarIntervalo(item.intervalo_horas)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDataHora(item.ultima_execucao_em)}
                                    </TableCell>
                                    <TableCell>
                                        <UltimoResultado item={item} />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {item.status === 'ativo' ? formatDataHora(item.proxima_execucao_em) : '—'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">{item.token ?? '—'}</TableCell>
                                </TableRow>
                            ))}
                            {monitoramentos.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={8} className="text-center text-muted-foreground">
                                        Nenhum processo monitorado.
                                        {temFiltroAtivo && ' Tente limpar os filtros.'}
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination paginator={monitoramentos} only={['monitoramentos']} />
            </div>
        </AppLayout>
    );
}
