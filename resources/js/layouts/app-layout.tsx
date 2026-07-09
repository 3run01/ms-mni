import { Link, router, usePage } from '@inertiajs/react';
import { Activity, ChevronDown, FileText, LayoutList, Menu } from 'lucide-react';
import { type PropsWithChildren } from 'react';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type SharedProps } from '@/types';

const monitoringLinks = [
    { href: '/pulse', label: 'Laravel Pulse', icon: Activity },
    { href: '/horizon', label: 'Horizon', icon: LayoutList },
    { href: '/logs', label: 'Log Viewer', icon: FileText },
];

export default function AppLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<SharedProps>().props;

    function logout() {
        router.post('/logout');
    }

    return (
        <div className="min-h-screen bg-gray-100">
            <nav className="border-b border-gray-200 bg-white shadow-sm">
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <div className="flex items-center gap-8">
                        <h1 className="text-xl font-bold text-gray-900">SIM-MNI</h1>

                        <div className="hidden items-center gap-6 sm:flex">
                            <Link
                                href="/dashboard"
                                className="text-sm font-medium text-gray-500 hover:text-gray-700"
                            >
                                Dashboard
                            </Link>

                            <DropdownMenu>
                                <DropdownMenuTrigger className="flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700">
                                    Monitoramento <ChevronDown className="size-4" />
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="start">
                                    {monitoringLinks.map(({ href, label, icon: Icon }) => (
                                        <DropdownMenuItem key={href} asChild>
                                            <a href={href} target="_blank" rel="noreferrer">
                                                <Icon /> {label}
                                            </a>
                                        </DropdownMenuItem>
                                    ))}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>

                    <div className="hidden items-center gap-3 sm:flex">
                        <span className="text-sm text-gray-700">{auth.user?.name}</span>
                        <Button variant="ghost" size="sm" onClick={logout}>
                            Sair
                        </Button>
                    </div>

                    <div className="sm:hidden">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" aria-label="Abrir menu principal">
                                    <Menu />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-56">
                                <DropdownMenuLabel>
                                    <div>{auth.user?.name}</div>
                                    <div className="text-xs font-normal text-muted-foreground">
                                        {auth.user?.email}
                                    </div>
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link href="/dashboard">Dashboard</Link>
                                </DropdownMenuItem>
                                {monitoringLinks.map(({ href, label, icon: Icon }) => (
                                    <DropdownMenuItem key={href} asChild>
                                        <a href={href} target="_blank" rel="noreferrer">
                                            <Icon /> {label}
                                        </a>
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem onSelect={logout}>Sair</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>
            </nav>

            <main>{children}</main>
        </div>
    );
}
