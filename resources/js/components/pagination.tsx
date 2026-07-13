import { Link } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { type Paginated } from '@/types';

// O projeto não tem lang/ publicado, então o Laravel devolve as chaves de
// tradução cruas nos links de anterior/próximo — traduz aqui no cliente.
function rotuloLink(label: string): string {
    if (label === 'pagination.previous') return '‹ Anterior';
    if (label === 'pagination.next') return 'Próximo ›';
    return label;
}

export function Pagination({ paginator }: { paginator: Paginated<unknown> }) {
    if (paginator.total === 0) return null;

    return (
        <div className="flex flex-col items-center justify-between gap-2 sm:flex-row">
            <p className="text-sm text-muted-foreground">
                {paginator.from ?? 0}–{paginator.to ?? 0} de {paginator.total}
            </p>
            <div className="flex flex-wrap gap-1">
                {paginator.links.map((link, i) => (
                    <Button
                        key={i}
                        variant={link.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={!link.url}
                        asChild={Boolean(link.url)}
                    >
                        {link.url ? (
                            <Link href={link.url} preserveScroll preserveState only={['processos']}>
                                <span dangerouslySetInnerHTML={{ __html: rotuloLink(link.label) }} />
                            </Link>
                        ) : (
                            <span dangerouslySetInnerHTML={{ __html: rotuloLink(link.label) }} />
                        )}
                    </Button>
                ))}
            </div>
        </div>
    );
}
