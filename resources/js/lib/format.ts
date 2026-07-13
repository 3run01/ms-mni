export function formatMoeda(valor: string | number | null): string {
    if (valor === null || valor === '' || valor === undefined) return '—';
    const numero = typeof valor === 'string' ? parseFloat(valor) : valor;
    if (Number.isNaN(numero)) return '—';
    return numero.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

export function formatData(iso: string | null): string {
    if (!iso) return '—';
    const data = new Date(iso);
    if (Number.isNaN(data.getTime())) return '—';
    return data.toLocaleDateString('pt-BR');
}

export function formatDataHora(iso: string | null): string {
    if (!iso) return '—';
    const data = new Date(iso);
    if (Number.isNaN(data.getTime())) return '—';
    return data.toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}

export function formatBytes(bytes: number | null): string {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

export function formatCpfCnpj(valor: string | null): string {
    if (!valor) return '—';
    const digitos = valor.replace(/\D/g, '');
    if (digitos.length === 11) {
        return digitos.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    }
    if (digitos.length === 14) {
        return digitos.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, '$1.$2.$3/$4-$5');
    }
    return valor;
}

// justica_gratuita/pedido_liminar são varchar com semântica frouxa no banco
export function flagAtiva(valor: string | null): boolean {
    if (!valor) return false;
    return !['', '0', 'false', 'n', 'nao', 'não'].includes(valor.trim().toLowerCase());
}
