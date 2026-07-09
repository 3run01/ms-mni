import { Head, useForm } from '@inertiajs/react';
import { LockKeyhole } from 'lucide-react';
import { type FormEvent } from 'react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    function submit(e: FormEvent) {
        e.preventDefault();
        post('/login');
    }

    const errorMessages = Object.values(errors);

    return (
        <AuthLayout>
            <Head title="Login" />

            <Card>
                <CardHeader className="text-center">
                    <CardTitle className="text-2xl">Acesse sua conta</CardTitle>
                    <CardDescription>Sistema de Integração MNI</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-2">
                            <Label htmlFor="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                autoComplete="email"
                                required
                                placeholder="Endereço de email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                aria-invalid={!!errors.email}
                            />
                            {errors.email && <p className="text-sm text-destructive">{errors.email}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="password">Senha</Label>
                            <Input
                                id="password"
                                type="password"
                                autoComplete="current-password"
                                required
                                placeholder="Senha"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                aria-invalid={!!errors.password}
                            />
                            {errors.password && <p className="text-sm text-destructive">{errors.password}</p>}
                        </div>

                        <div className="flex items-center gap-2">
                            <Checkbox
                                id="remember"
                                checked={data.remember}
                                onCheckedChange={(checked) => setData('remember', checked === true)}
                            />
                            <Label htmlFor="remember" className="font-normal">
                                Lembrar de mim
                            </Label>
                        </div>

                        <Button type="submit" className="w-full" disabled={processing}>
                            <LockKeyhole /> Entrar
                        </Button>

                        {errorMessages.length > 0 && (
                            <div className="rounded-md bg-red-50 p-4">
                                <h3 className="text-sm font-medium text-red-800">Erro na autenticação</h3>
                                <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-red-700">
                                    {errorMessages.map((message) => (
                                        <li key={message}>{message}</li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </form>
                </CardContent>
            </Card>
        </AuthLayout>
    );
}
