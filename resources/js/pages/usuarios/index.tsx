import { Head, Link, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedProps, type UsuarioListItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Usuários', href: '/usuarios' }];

export default function UsuariosIndex({ usuarios }: { usuarios: UsuarioListItem[] }) {
    const { auth, flash } = usePage<SharedProps>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Usuários" />

            <div className="flex flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Usuários</h1>
                    <Button asChild>
                        <Link href="/usuarios/criar" prefetch>
                            <Plus /> Novo usuário
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
                                <TableHead>E-mail</TableHead>
                                <TableHead>Criado em</TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {usuarios.map((usuario) => (
                                <TableRow key={usuario.id}>
                                    <TableCell className="font-medium">
                                        {usuario.name}
                                        {usuario.id === auth.user?.id && (
                                            <Badge variant="secondary" className="ml-2">
                                                Você
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell>{usuario.email}</TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {new Date(usuario.created_at).toLocaleString('pt-BR', {
                                            dateStyle: 'short',
                                            timeStyle: 'short',
                                        })}
                                    </TableCell>
                                    <TableCell>
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/usuarios/${usuario.id}/editar`} prefetch>
                                                Editar
                                            </Link>
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                            {usuarios.length === 0 && (
                                <TableRow>
                                    <TableCell colSpan={4} className="text-center text-muted-foreground">
                                        Nenhum usuário cadastrado.
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
