import { Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEvent, type ReactNode } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

const SEM_TIPO = '__none__';

export interface TribunalFormValues {
    id: number;
    nome: string;
    tipo: string | null;
    versao_mni: string | null;
    ativo: boolean | null;
    url_webservice_mni: string;
    url_webservice_mni_consultar_processo: string | null;
    url_webservice_mni_complementar: string;
    url_consulta_pje: string | null;
    url_webservice_mni_criminal: string | null;
    url_recuperar_senha_tribunal: string | null;
    codigo_peticao_inicial: string | null;
    codigo_peticao_avulsa: string | null;
    codigo_certidao_inicio_fim: string | null;
    codigo_seeu: string | null;
    usar_codigo_documento_padrao: string | null;
    enviar_dados_criminais: boolean | null;
}

function Secao({ titulo, children }: { titulo: string; children: ReactNode }) {
    return (
        <section className="space-y-4">
            <h2 className="border-b pb-2 text-lg font-semibold">{titulo}</h2>
            <div className="grid gap-4 md:grid-cols-2">{children}</div>
        </section>
    );
}

export default function TribunalForm({
    tipos,
    tribunal,
}: {
    tipos: string[];
    tribunal?: TribunalFormValues;
}) {
    const { data, setData, post, put, processing, errors } = useForm({
        nome: tribunal?.nome ?? '',
        tipo: tribunal?.tipo ?? null,
        versao_mni: tribunal?.versao_mni ?? '',
        ativo: Boolean(tribunal?.ativo ?? true),
        url_webservice_mni: tribunal?.url_webservice_mni ?? '',
        url_webservice_mni_consultar_processo: tribunal?.url_webservice_mni_consultar_processo ?? '',
        url_webservice_mni_complementar: tribunal?.url_webservice_mni_complementar ?? '',
        url_consulta_pje: tribunal?.url_consulta_pje ?? '',
        url_webservice_mni_criminal: tribunal?.url_webservice_mni_criminal ?? '',
        url_recuperar_senha_tribunal: tribunal?.url_recuperar_senha_tribunal ?? '',
        codigo_peticao_inicial: tribunal?.codigo_peticao_inicial ?? '',
        codigo_peticao_avulsa: tribunal?.codigo_peticao_avulsa ?? '',
        codigo_certidao_inicio_fim: tribunal?.codigo_certidao_inicio_fim ?? '',
        codigo_seeu: tribunal?.codigo_seeu ?? '',
        usar_codigo_documento_padrao: tribunal?.usar_codigo_documento_padrao ?? '',
        enviar_dados_criminais: Boolean(tribunal?.enviar_dados_criminais ?? false),
    });

    function submit(e: FormEvent) {
        e.preventDefault();

        if (tribunal) {
            put(`/tribunais/${tribunal.id}`);
        } else {
            post('/tribunais');
        }
    }

    const camposUrl = [
        { key: 'url_webservice_mni', label: 'URL webservice MNI *' },
        { key: 'url_webservice_mni_complementar', label: 'URL webservice MNI complementar *' },
        { key: 'url_webservice_mni_consultar_processo', label: 'URL consultar processo' },
        { key: 'url_consulta_pje', label: 'URL consulta PJe' },
        { key: 'url_webservice_mni_criminal', label: 'URL webservice MNI criminal' },
        { key: 'url_recuperar_senha_tribunal', label: 'URL recuperar senha' },
    ] as const;

    const camposCodigo = [
        { key: 'codigo_peticao_inicial', label: 'Código petição inicial' },
        { key: 'codigo_peticao_avulsa', label: 'Código petição avulsa' },
        { key: 'codigo_certidao_inicio_fim', label: 'Código certidão início/fim' },
        { key: 'codigo_seeu', label: 'Código SEEU' },
        { key: 'usar_codigo_documento_padrao', label: 'Código documento padrão' },
    ] as const;

    return (
        <form onSubmit={submit} className="flex max-w-4xl flex-col gap-8">
            <Secao titulo="Identificação">
                <div className="space-y-2 md:col-span-2">
                    <Label htmlFor="nome">Nome *</Label>
                    <Input
                        id="nome"
                        value={data.nome}
                        onChange={(e) => setData('nome', e.target.value)}
                        aria-invalid={!!errors.nome}
                    />
                    <InputError message={errors.nome} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="tipo">Tipo</Label>
                    <Select
                        value={data.tipo ?? SEM_TIPO}
                        onValueChange={(value) => setData('tipo', value === SEM_TIPO ? null : value)}
                    >
                        <SelectTrigger id="tipo" aria-invalid={!!errors.tipo}>
                            <SelectValue placeholder="Selecione o tipo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value={SEM_TIPO}>Nenhum</SelectItem>
                            {tipos.map((tipo) => (
                                <SelectItem key={tipo} value={tipo}>
                                    {tipo}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors.tipo} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="versao_mni">Versão MNI</Label>
                    <Input
                        id="versao_mni"
                        value={data.versao_mni ?? ''}
                        onChange={(e) => setData('versao_mni', e.target.value)}
                        placeholder="2.2.2"
                        aria-invalid={!!errors.versao_mni}
                    />
                    <InputError message={errors.versao_mni} />
                </div>

                <div className="flex items-center gap-2">
                    <Checkbox
                        id="ativo"
                        checked={data.ativo}
                        onCheckedChange={(checked) => setData('ativo', checked === true)}
                    />
                    <Label htmlFor="ativo" className="font-normal">
                        Ativo
                    </Label>
                </div>
            </Secao>

            <Secao titulo="URLs MNI">
                {camposUrl.map(({ key, label }) => (
                    <div key={key} className="space-y-2">
                        <Label htmlFor={key}>{label}</Label>
                        <Input
                            id={key}
                            type="url"
                            value={data[key] ?? ''}
                            onChange={(e) => setData(key, e.target.value)}
                            aria-invalid={!!errors[key]}
                        />
                        <InputError message={errors[key]} />
                    </div>
                ))}
            </Secao>

            <Secao titulo="Códigos">
                {camposCodigo.map(({ key, label }) => (
                    <div key={key} className="space-y-2">
                        <Label htmlFor={key}>{label}</Label>
                        <Input
                            id={key}
                            value={data[key] ?? ''}
                            onChange={(e) => setData(key, e.target.value)}
                            aria-invalid={!!errors[key]}
                        />
                        <InputError message={errors[key]} />
                    </div>
                ))}
            </Secao>

            <Secao titulo="Flags">
                <div className="flex items-center gap-2 md:col-span-2">
                    <Checkbox
                        id="enviar_dados_criminais"
                        checked={data.enviar_dados_criminais}
                        onCheckedChange={(checked) => setData('enviar_dados_criminais', checked === true)}
                    />
                    <Label htmlFor="enviar_dados_criminais" className="font-normal">
                        Enviar dados criminais
                    </Label>
                </div>
            </Secao>

            <div className="flex items-center gap-3">
                <Button type="submit" disabled={processing}>
                    {processing && <LoaderCircle className="size-4 animate-spin" />}
                    Salvar
                </Button>
                <Button variant="ghost" asChild>
                    <Link href="/tribunais">Cancelar</Link>
                </Button>
            </div>
        </form>
    );
}
