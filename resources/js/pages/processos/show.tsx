import { Deferred, Head } from '@inertiajs/react';
import { Copy, FileText } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { flagAtiva, formatBytes, formatCpfCnpj, formatData, formatDataHora, formatMoeda } from '@/lib/format';
import {
    type BreadcrumbItem,
    type DocumentoItem,
    type MovimentoItem,
    type ParteItem,
    type ProcessoDetalhe,
} from '@/types';

const statusBadgeClass: Record<string, string> = {
    'Peticionado': 'border-transparent bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200',
    'Processando envio': 'border-transparent bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-200',
    'Pendente de envio': 'border-transparent bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    'Arquivado': 'border-transparent bg-muted text-muted-foreground',
};

interface Props {
    processo: ProcessoDetalhe;
    movimentos?: MovimentoItem[];
    documentos?: DocumentoItem[];
}

function CampoInfo({ rotulo, valor }: { rotulo: string; valor: string | null | undefined }) {
    return (
        <div>
            <dt className="text-sm text-muted-foreground">{rotulo}</dt>
            <dd className="text-sm font-medium">{valor || '—'}</dd>
        </div>
    );
}

function ListaSkeleton() {
    return (
        <div className="flex flex-col gap-2 p-4">
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
            <Skeleton className="h-10 w-full" />
        </div>
    );
}

function GrupoPartes({ titulo, partes }: { titulo: string; partes: ParteItem[] }) {
    if (partes.length === 0) return null;

    return (
        <div className="flex flex-col gap-2">
            <h3 className="text-sm font-semibold text-muted-foreground uppercase">{titulo}</h3>
            {partes.map((parte) => (
                <Card key={parte.id}>
                    <CardContent className="flex flex-col gap-2 pt-4">
                        <div>
                            <p className="font-medium">{parte.nome ?? '—'}</p>
                            <p className="text-sm text-muted-foreground">
                                {formatCpfCnpj(parte.cpf_cnpj)}
                                {parte.endereco && ` · ${parte.endereco}`}
                            </p>
                        </div>
                        {parte.representantes.length > 0 && (
                            <div className="border-l-2 pl-3">
                                <p className="text-xs font-semibold text-muted-foreground uppercase">Representantes</p>
                                {parte.representantes.map((rep) => (
                                    <p key={rep.id} className="text-sm">
                                        {rep.nome ?? '—'}
                                        <span className="text-muted-foreground">
                                            {rep.tipo && ` · ${rep.tipo}`}
                                            {rep.inscricao && ` · ${rep.inscricao}`}
                                        </span>
                                    </p>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            ))}
        </div>
    );
}

export default function ProcessoShow({ processo, movimentos, documentos }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Processos', href: '/processos' },
        { title: processo.numero_processo ?? String(processo.id), href: `/processos/${processo.id}` },
    ];

    const partesAtivas = processo.partes.filter((p) => p.polo === 'Ativo');
    const partesPassivas = processo.partes.filter((p) => p.polo === 'Passivo');
    const demaisPartes = processo.partes.filter((p) => p.polo !== 'Ativo' && p.polo !== 'Passivo');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Processo ${processo.numero_processo ?? processo.id}`} />

            <div className="flex flex-col gap-4 p-4">
                <Card>
                    <CardHeader>
                        <div className="flex flex-wrap items-center gap-2">
                            <CardTitle className="text-xl">{processo.numero_processo ?? '—'}</CardTitle>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Copiar número do processo"
                                onClick={() => navigator.clipboard.writeText(processo.numero_processo ?? '')}
                            >
                                <Copy className="size-4" />
                            </Button>
                            <Badge variant="outline" className={statusBadgeClass[processo.status] ?? ''}>
                                {processo.status}
                            </Badge>
                            {flagAtiva(processo.justica_gratuita) && <Badge variant="secondary">Justiça gratuita</Badge>}
                            {flagAtiva(processo.pedido_liminar) && <Badge variant="secondary">Pedido liminar</Badge>}
                        </div>
                    </CardHeader>
                    <CardContent>
                        <dl className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <CampoInfo rotulo="Tribunal" valor={processo.tribunal} />
                            <CampoInfo rotulo="Classe CNJ" valor={processo.classe} />
                            <CampoInfo
                                rotulo="Órgão julgador"
                                valor={
                                    processo.orgao_julgador
                                        ? `${processo.orgao_julgador}${processo.instancia_orgao_julgador ? ` (${processo.instancia_orgao_julgador})` : ''}`
                                        : null
                                }
                            />
                            <CampoInfo rotulo="Valor da causa" valor={formatMoeda(processo.valor_causa)} />
                            <CampoInfo rotulo="Nível de sigilo" valor={processo.nivel_sigilo} />
                            <CampoInfo rotulo="Criado em" valor={formatData(processo.created_at)} />
                            {processo.motivo_segredo_justica && (
                                <CampoInfo rotulo="Motivo do segredo de justiça" valor={processo.motivo_segredo_justica} />
                            )}
                        </dl>
                        {(processo.assuntos.length > 0 || processo.prioridades.length > 0) && (
                            <div className="mt-4 flex flex-wrap gap-1.5">
                                {processo.assuntos.map((assunto, i) => (
                                    <Badge key={i} variant={assunto.principal ? 'default' : 'secondary'}>
                                        {assunto.nome ?? assunto.assunto_codigo}
                                    </Badge>
                                ))}
                                {processo.prioridades.map((prioridade, i) => (
                                    <Badge key={`p-${i}`} variant="outline">
                                        {prioridade}
                                    </Badge>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Tabs defaultValue="partes">
                    <TabsList>
                        <TabsTrigger value="partes">Partes ({processo.partes.length})</TabsTrigger>
                        <TabsTrigger value="movimentos">Movimentos</TabsTrigger>
                        <TabsTrigger value="documentos">Documentos</TabsTrigger>
                    </TabsList>

                    <TabsContent value="partes" className="flex flex-col gap-4">
                        {processo.partes.length === 0 && (
                            <p className="p-4 text-center text-muted-foreground">Nenhuma parte cadastrada.</p>
                        )}
                        <GrupoPartes titulo="Polo ativo" partes={partesAtivas} />
                        <GrupoPartes titulo="Polo passivo" partes={partesPassivas} />
                        <GrupoPartes titulo="Outras partes" partes={demaisPartes} />
                    </TabsContent>

                    <TabsContent value="movimentos">
                        <Deferred data="movimentos" fallback={<ListaSkeleton />}>
                            <div className="rounded-xl border">
                                {(movimentos ?? []).length === 0 ? (
                                    <p className="p-4 text-center text-muted-foreground">Nenhum movimento registrado.</p>
                                ) : (
                                    <ul className="divide-y">
                                        {(movimentos ?? []).map((mov) => (
                                            <li key={mov.id} className="flex flex-col gap-1 p-3">
                                                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                                                    <span>{formatDataHora(mov.data_hora)}</span>
                                                    {mov.codigo_nacional != null && <span>· Código {mov.codigo_nacional}</span>}
                                                    {mov.tem_documento && (
                                                        <span className="flex items-center gap-1">
                                                            · <FileText className="size-3.5" /> documento vinculado
                                                        </span>
                                                    )}
                                                </div>
                                                <p className="text-sm">{mov.complemento ?? '—'}</p>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </div>
                        </Deferred>
                    </TabsContent>

                    <TabsContent value="documentos">
                        <Deferred data="documentos" fallback={<ListaSkeleton />}>
                            <div className="rounded-xl border">
                                {(documentos ?? []).length === 0 ? (
                                    <p className="p-4 text-center text-muted-foreground">Nenhum documento registrado.</p>
                                ) : (
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Descrição</TableHead>
                                                <TableHead>Tipo</TableHead>
                                                <TableHead>Formato</TableHead>
                                                <TableHead>Tamanho</TableHead>
                                                <TableHead>Sigilo</TableHead>
                                                <TableHead>Juntada</TableHead>
                                                <TableHead>Status</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {(documentos ?? []).map((doc) => (
                                                <TableRow key={doc.id}>
                                                    <TableCell className="font-medium">{doc.descricao ?? '—'}</TableCell>
                                                    <TableCell className="text-muted-foreground">{doc.tipo_documento ?? '—'}</TableCell>
                                                    <TableCell className="text-muted-foreground">{doc.mimetype ?? '—'}</TableCell>
                                                    <TableCell>{formatBytes(doc.file_size)}</TableCell>
                                                    <TableCell className="text-muted-foreground">{doc.nivel_sigilo ?? '—'}</TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {formatDataHora(doc.data_juntada ?? doc.data_hora)}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">{doc.status ?? '—'}</TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                )}
                            </div>
                        </Deferred>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
