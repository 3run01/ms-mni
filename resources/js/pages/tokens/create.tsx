import { Head, Link, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Tokens de API', href: '/tokens' },
    { title: 'Novo token', href: '/tokens/criar' },
];

export default function TokensCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        expires_at: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/tokens');
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Novo token" />
            <div className="flex flex-col gap-6 p-4">
                <h1 className="text-2xl font-bold">Novo token</h1>

                <form onSubmit={submit} className="flex max-w-lg flex-col gap-4">
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="name">Nome</Label>
                        <Input
                            id="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            autoFocus
                        />
                        {errors.name && <p className="text-sm text-destructive">{errors.name}</p>}
                    </div>

                    <div className="flex flex-col gap-2">
                        <Label htmlFor="expires_at">Data de expiração (opcional)</Label>
                        <Input
                            id="expires_at"
                            type="date"
                            value={data.expires_at}
                            onChange={(e) => setData('expires_at', e.target.value)}
                        />
                        {errors.expires_at && (
                            <p className="text-sm text-destructive">{errors.expires_at}</p>
                        )}
                        <p className="text-sm text-muted-foreground">
                            Em branco, o token nunca expira.
                        </p>
                    </div>

                    <div className="flex gap-2">
                        <Button type="submit" disabled={processing}>
                            Gerar token
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href="/tokens">Cancelar</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
