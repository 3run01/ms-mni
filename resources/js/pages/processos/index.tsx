import { Head, Link, router } from '@inertiajs/react';
import { Check, ChevronDown, ChevronsUpDown, X } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
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
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { formatData, formatMoeda } from '@/lib/format';
import {
    type BreadcrumbItem,
    type ClasseOption,
    type Paginated,
    type ProcessoFiltros,
    type ProcessoListItem,
    type TribunalOption,
} from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Processos', href: '/processos' }];

const statusBadgeClass: Record<string, string> = {
    'Peticionado': 'border-transparent bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    'Processando envio': 'border-transparent bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-200',
    'Pendente de envio': 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    'Arquivado': 'border-transparent bg-muted text-muted-foreground',
};

interface Props {
    processos: Paginated<ProcessoListItem>;
    filtros: ProcessoFiltros;
    tribunais: TribunalOption[];
    classes: ClasseOption[];
    statusOptions: string[];
    niveisSigilo: Record<string, string>;
    errors: Record<string, string>;
}

function aplicarFiltros(filtros: ProcessoFiltros) {
    const params = Object.fromEntries(
        Object.entries(filtros).filter(([, v]) => v !== undefined && v !== null && v !== ''),
    );
    router.get('/processos', params, {
        preserveState: true,
        preserveScroll: true,
        only: ['processos', 'filtros', 'errors'],
    });
}

function ClasseCombobox({
    classes,
    value,
    onChange,
}: {
    classes: ClasseOption[];
    value: string | undefined;
    onChange: (codigo: string | undefined) => void;
}) {
    const [open, setOpen] = useState(false);
    const [termo, setTermo] = useState('');

    const selecionada = classes.find((c) => c.codigo === value);
    const filtradas = classes
        .filter((c) => c.descricao.toLowerCase().includes(termo.toLowerCase()))
        .slice(0, 50);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="outline" role="combobox" aria-expanded={open} className="w-full justify-between font-normal">
                    <span className="truncate">{selecionada ? selecionada.descricao : 'Todas as classes'}</span>
                    <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-(--radix-popover-trigger-width) p-2" align="start">
                <Input
                    placeholder="Buscar classe..."
                    value={termo}
                    onChange={(e) => setTermo(e.target.value)}
                    className="mb-2"
                    autoFocus
                />
                <div className="max-h-60 overflow-y-auto">
                    {filtradas.map((classe) => (
                        <button
                            key={classe.codigo}
                            type="button"
                            className="flex w-full items-center gap-2 rounded-sm px-2 py-1.5 text-left text-sm hover:bg-accent"
                            onClick={() => {
                                onChange(classe.codigo === value ? undefined : classe.codigo);
                                setOpen(false);
                            }}
                        >
                            <Check className={cn('size-4 shrink-0', classe.codigo === value ? 'opacity-100' : 'opacity-0')} />
                            <span className="truncate">{classe.descricao}</span>
                        </button>
                    ))}
                    {filtradas.length === 0 && (
                        <p className="px-2 py-1.5 text-sm text-muted-foreground">Nenhuma classe encontrada.</p>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

export default function ProcessosIndex({
    processos,
    filtros,
    tribunais,
    classes,
    statusOptions,
    niveisSigilo,
    errors,
}: Props) {
    const [busca, setBusca] = useState(filtros.busca ?? '');
    const [orgaoJulgador, setOrgaoJulgador] = useState(filtros.orgao_julgador ?? '');
    const primeiraRenderizacao = useRef(true);
    const filtrosRef = useRef(filtros);
    filtrosRef.current = filtros;
    const [maisFiltros, setMaisFiltros] = useState(
        Boolean(filtros.data_inicio || filtros.data_fim || filtros.orgao_julgador || filtros.nivel_sigilo !== undefined && filtros.nivel_sigilo !== ''),
    );

    const temFiltroAtivo = Object.values(filtros).some((v) => v !== undefined && v !== null && v !== '');

    const mudarFiltro = useCallback(
        (mudanca: Partial<ProcessoFiltros>) => {
            aplicarFiltros({ ...filtrosRef.current, busca: busca || undefined, ...mudanca });
        },
        [busca],
    );

    // debounce de 400ms na busca por número
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
            <Head title="Processos" />

            <div className="flex flex-col gap-4 p-4">
                <h1 className="text-2xl font-bold">Processos</h1>

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
                            <Label>Classe CNJ</Label>
                            <ClasseCombobox
                                classes={classes}
                                value={filtros.classe_codigo}
                                onChange={(codigo) => mudarFiltro({ classe_codigo: codigo })}
                            />
                        </div>
                    </div>

                    <Collapsible open={maisFiltros} onOpenChange={setMaisFiltros}>
                        <div className="flex items-center justify-between">
                            <CollapsibleTrigger asChild>
                                <Button variant="ghost" size="sm">
                                    <ChevronDown className={cn('size-4 transition-transform', maisFiltros && 'rotate-180')} />
                                    Mais filtros
                                </Button>
                            </CollapsibleTrigger>
                            {temFiltroAtivo && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => {
                                        setBusca('');
                                        setOrgaoJulgador('');
                                        aplicarFiltros({});
                                    }}
                                >
                                    <X className="size-4" /> Limpar filtros
                                </Button>
                            )}
                        </div>
                        <CollapsibleContent>
                            <div className="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="data_inicio">Criado a partir de</Label>
                                    <Input
                                        id="data_inicio"
                                        type="date"
                                        value={filtros.data_inicio ?? ''}
                                        onChange={(e) => mudarFiltro({ data_inicio: e.target.value || undefined })}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="data_fim">Criado até</Label>
                                    <Input
                                        id="data_fim"
                                        type="date"
                                        value={filtros.data_fim ?? ''}
                                        onChange={(e) => mudarFiltro({ data_fim: e.target.value || undefined })}
                                    />
                                    {errors.data_fim && <p className="text-sm text-destructive">{errors.data_fim}</p>}
                                </div>
                                <div className="grid gap-1.5">
                                    <Label htmlFor="orgao_julgador">Órgão julgador</Label>
                                    <Input
                                        id="orgao_julgador"
                                        placeholder="Nome do órgão..."
                                        value={orgaoJulgador}
                                        onChange={(e) => setOrgaoJulgador(e.target.value)}
                                        onBlur={(e) => mudarFiltro({ orgao_julgador: e.target.value || undefined })}
                                        onKeyDown={(e) => {
                                            if (e.key === 'Enter') {
                                                mudarFiltro({ orgao_julgador: e.currentTarget.value || undefined });
                                            }
                                        }}
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Nível de sigilo</Label>
                                    <Select
                                        value={String(filtros.nivel_sigilo ?? 'todos')}
                                        onValueChange={(v) => mudarFiltro({ nivel_sigilo: v === 'todos' ? undefined : v })}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Todos" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="todos">Todos</SelectItem>
                                            {Object.entries(niveisSigilo).map(([valor, label]) => (
                                                <SelectItem key={valor} value={valor}>
                                                    {label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Número do processo</TableHead>
                                <TableHead>Tribunal</TableHead>
                                <TableHead>Classe</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Valor da causa</TableHead>
                                <TableHead>Criado em</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {processos.data.map((processo) => (
                                <TableRow
                                    key={processo.id}
                                    className="cursor-pointer"
                                    onClick={() => router.visit(`/processos/${processo.id}`)}
                                >
                                    <TableCell className="font-medium">
                                        <Link
                                            href={`/processos/${processo.id}`}
                                            className="hover:underline"
                                            onClick={(e) => e.stopPropagation()}
                                        >
                                            {processo.numero_processo ?? '—'}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">{processo.tribunal ?? '—'}</TableCell>
                                    <TableCell className="text-muted-foreground">{processo.classe ?? '—'}</TableCell>
                                    <TableCell>
                                        <Badge variant="outline" className={statusBadgeClass[processo.status] ?? ''}>
                                            {processo.status}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>{formatMoeda(processo.valor_causa)}</TableCell>
                                    <TableCell className="text-muted-foreground">{formatData(processo.created_at)}</TableCell>
                                </TableRow>
                            ))}
                            {processos.data.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-center text-muted-foreground">
                                        Nenhum processo encontrado.
                                        {temFiltroAtivo && ' Tente limpar os filtros.'}
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>

                <Pagination paginator={processos} />
            </div>
        </AppLayout>
    );
}
