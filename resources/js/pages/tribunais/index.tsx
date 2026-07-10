import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedProps, type TribunalListItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tribunais', href: '/tribunais' }];

export default function TribunaisIndex({ tribunais }: { tribunais: TribunalListItem[] }) {
    const { flash } = usePage<SharedProps>().props;

    function toggleAtivo(id: number) {
        router.patch(`/tribunais/${id}/ativo`, {}, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tribunais" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Tribunais</h1>
                    <Button asChild>
                        <Link href="/tribunais/criar" prefetch>
                            <Plus /> Novo tribunal
                        </Link>
                    </Button>
                </div>

                {flash.success && (
                    <div className="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nome</TableHead>
                                <TableHead>Tipo</TableHead>
                                <TableHead>Versão MNI</TableHead>
                                <TableHead>Ativo</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tribunais.map((tribunal) => (
                                <TableRow key={tribunal.id}>
                                    <TableCell className="font-medium">{tribunal.nome}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {tribunal.tipo ?? '—'}
                                    </TableCell>
                                    <TableCell>{tribunal.versao_mni ?? '—'}</TableCell>
                                    <TableCell>
                                        <Switch
                                            checked={Boolean(tribunal.ativo)}
                                            onCheckedChange={() => toggleAtivo(tribunal.id)}
                                            aria-label={`Ativar/desativar ${tribunal.nome}`}
                                        />
                                    </TableCell>
                                    <TableCell>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/tribunais/${tribunal.id}/editar`} prefetch>
                                                Editar
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {tribunais.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-center text-muted-foreground">
                                        Nenhum tribunal cadastrado.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
