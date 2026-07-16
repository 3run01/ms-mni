import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Copy, Plus } from 'lucide-react';
import { useState } from 'react';

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
import { type ApiTokenListItem, type BreadcrumbItem, type SharedProps } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tokens de API', href: '/tokens' }];

function formatarData(valor: string | null): string {
    if (!valor) {
        return '—';
    }
    return new Date(valor).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

export default function TokensIndex({ tokens }: { tokens: ApiTokenListItem[] }) {
    const { flash } = usePage<SharedProps>().props;
    const [copiado, setCopiado] = useState(false);

    function toggleAtivo(id: number) {
        router.patch(`/tokens/${id}/ativo`, {}, { preserveScroll: true });
    }

    function revogar(token: ApiTokenListItem) {
        if (confirm(`Revogar o token "${token.name}"? Os sistemas que o utilizam perderão acesso imediatamente.`)) {
            router.delete(`/tokens/${token.id}`, { preserveScroll: true });
        }
    }

    async function copiarToken() {
        if (flash.token) {
            await navigator.clipboard.writeText(flash.token);
            setCopiado(true);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tokens de API" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Tokens de API</h1>
                    <Button asChild>
                        <Link href="/tokens/criar" prefetch>
                            <Plus /> Novo token
                        </Link>
                    </Button>
                </div>

                {flash.token && (
                    <div className="flex flex-col gap-2 rounded-md border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100">
                        <p className="font-semibold">
                            Copie o token agora — ele não será mostrado novamente.
                        </p>
                        <div className="flex items-center gap-2">
                            <code className="flex-1 overflow-x-auto rounded bg-amber-100 px-2 py-1 font-mono dark:bg-amber-900">
                                {flash.token}
                            </code>
                            <Button variant="outline" size="sm" onClick={copiarToken}>
                                {copiado ? <Check /> : <Copy />}
                                {copiado ? 'Copiado' : 'Copiar'}
                            </Button>
                        </div>
                    </div>
                )}

                {flash.success && !flash.token && (
                    <div className="rounded-md border border-green-200 bg-green-50 p-3 text-sm text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200">
                        {flash.success}
                    </div>
                )}

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nome</TableHead>
                                <TableHead>Ativo</TableHead>
                                <TableHead>Expira em</TableHead>
                                <TableHead>Último uso</TableHead>
                                <TableHead>Criado em</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {tokens.map((token) => (
                                <TableRow key={token.id}>
                                    <TableCell className="font-medium">{token.name}</TableCell>
                                    <TableCell>
                                        <Switch
                                            checked={Boolean(token.ativo)}
                                            onCheckedChange={() => toggleAtivo(token.id)}
                                            aria-label={`Ativar/desativar ${token.name}`}
                                        />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {token.expires_at ? formatarData(token.expires_at) : 'Nunca'}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatarData(token.last_used_at)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatarData(token.created_at)}
                                    </TableCell>
                                    <TableCell>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="text-destructive hover:text-destructive"
                                            onClick={() => revogar(token)}
                                        >
                                            Revogar
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {tokens.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={6} className="text-center text-muted-foreground">
                                        Nenhum token cadastrado.
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
