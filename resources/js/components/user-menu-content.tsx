import { router } from '@inertiajs/react';
import { Check, LogOut, Monitor, Moon, Sun } from 'lucide-react';

import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { type User } from '@/types';

const themes: { value: Appearance; label: string; icon: typeof Sun }[] = [
    { value: 'light', label: 'Claro', icon: Sun },
    { value: 'dark', label: 'Escuro', icon: Moon },
    { value: 'system', label: 'Sistema', icon: Monitor },
];

export function UserMenuContent({ user }: { user: User }) {
    const cleanup = useMobileNavigation();
    const { appearance, updateAppearance } = useAppearance();
    const activeTheme = themes.find((t) => t.value === appearance) ?? themes[2];
    const ActiveIcon = activeTheme.icon;

    function handleLogout() {
        cleanup();
        router.post('/logout');
    }

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuSub>
                    <DropdownMenuSubTrigger>
                        <ActiveIcon className="mr-2 size-4" />
                        Tema
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                        {themes.map(({ value, label, icon: Icon }) => (
                            <DropdownMenuItem key={value} onSelect={() => updateAppearance(value)}>
                                <Icon /> {label}
                                {appearance === value && <Check className="ml-auto size-4" />}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuSubContent>
                </DropdownMenuSub>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem onSelect={handleLogout}>
                <LogOut className="mr-2" />
                Sair
            </DropdownMenuItem>
        </>
    );
}
