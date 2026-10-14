import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEvent } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export interface UsuarioFormValues {
    id: number;
    name: string;
    email: string;
}

export default function UsuarioForm({ usuario }: { usuario?: UsuarioFormValues }) {
    const { data, setData, post, put, processing, errors } = useForm({
        name: usuario?.name ?? '',
        email: usuario?.email ?? '',
        password: '',
        password_confirmation: '',
    });

    function submit(e: FormEvent) {
        e.preventDefault();

        if (usuario) {
            put(`/usuarios/${usuario.id}`);
        } else {
            post('/usuarios');
        }
    }

    return (
        <form onSubmit={submit} className="flex max-w-lg flex-col gap-4">
            <div className="space-y-2">
                <Label htmlFor="name">Nome *</Label>
                <Input
                    id="name"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    autoComplete="off"
                    autoFocus
                    aria-invalid={!!errors.name}
                />
                <InputError message={errors.name} />
            </div>

            <div className="space-y-2">
                <Label htmlFor="email">E-mail *</Label>
                <Input
                    id="email"
                    type="email"
                    value={data.email}
                    onChange={(e) => setData('email', e.target.value)}
                    autoComplete="off"
                    aria-invalid={!!errors.email}
                />
                <InputError message={errors.email} />
            </div>

            <div className="space-y-2">
                <Label htmlFor="password">{usuario ? 'Nova senha' : 'Senha *'}</Label>
                <Input
                    id="password"
                    type="password"
                    value={data.password}
                    onChange={(e) => setData('password', e.target.value)}
                    autoComplete="new-password"
                    aria-invalid={!!errors.password}
                />
                <InputError message={errors.password} />
                <p className="text-sm text-muted-foreground">
                    {usuario
                        ? 'Em branco, mantém a senha atual. Mínimo de 8 caracteres.'
                        : 'Mínimo de 8 caracteres.'}
                </p>
            </div>

            <div className="space-y-2">
                <Label htmlFor="password_confirmation">
                    {usuario ? 'Confirmar nova senha' : 'Confirmar senha *'}
                </Label>
                <Input
                    id="password_confirmation"
                    type="password"
                    value={data.password_confirmation}
                    onChange={(e) => setData('password_confirmation', e.target.value)}
                    autoComplete="new-password"
                />
            </div>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Salvar
                </Button>
                <Button variant="ghost" asChild>
                    <Link href="/usuarios">Cancelar</Link>
                </Button>
            </div>
        </form>
    );
}
