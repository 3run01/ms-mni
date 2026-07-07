<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="{{ public_path() . '/css/relatorio.css' }}">

    {{-- <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/4.0.0-alpha.6/css/bootstrap.min.css" integrity="sha384-rwoIResjU2yc3z8GV/NPeZWAv56rSmLldC3R/AZzGRnGxQQKnKkoFVhFQhNUwEyJ" crossorigin="anonymous"> --}}
    <style>
        /* thead {
            display: ;
        } */

        tr {
            page-break-inside: Avoid
        }

        /* body {
            font-size: 12px;
        }, */
        /* *{
            margin-left: 0;
            margin-right: 0;
        } */
    </style>
</head>

<body>
    <div class="container">
        {{-- <p style='margin-top: 0px; margin-bottom: 0px; text-align: center;'>
            <img alt=''
                src="{{ generateBase64Image('https://urano2.mpap.mp.br:8443/upload/ck/ckfinder/userfiles/images/logo.jpg') }}"
                style='width: 132px; height: 57px;' />
        </p>
        <p style='text-align: center; margin-top: 0px; margin-bottom: 0px;'>
            <span style='font-size: 14px;'><span
                    style='font-family: Arial, Helvetica, sans-serif;'><strong>Minist&eacute;rio
                        P&uacute;blico</strong></span></span>
        </p>

        <p style='text-align: center; margin-top: 0px; margin-bottom: 0px;'>
            <span style='font-size: 9px;'><span style='font-family: Arial, Helvetica, sans-serif;'>d o &nbsp;E s t a d o
                    &nbsp;d o &nbsp;A m a p &aacute;</span></span>
        </p>
        <p style='text-align: center; margin-top: 0px; margin-bottom: 0px;'>
            &nbsp;</p> --}}

        {{-- <table class="table">
            <tbody>
                @if (!empty($empresa->logo) && is_file(\Storage::disk('public')->path('imagens/logo/' . $empresa->logo)))
                    <td>
                        @if (is_file(\Storage::disk('public')->path('imagens/logo/' . $empresa->logo)))
                            <img width="170" height="100"
                                src="{{ generateBase64Image(\Storage::disk('public')->path('imagens/logo/' . $empresa->logo)) }}"
                                alt="image">
                        @endif
                    </td>
                @endif
                <td align="right">
                    {{ $empresa->nome_fantasia }} - {{ $empresa->cnpj }} <br />
                    {{ $empresa->telefone }} {{ $empresa->celular1 }} {{ $empresa->celular2 }}<br />
                    {{ $empresa->email }}<br />
                    {{ $empresa->logradouro }}, {{ $empresa->numero }} - {{ $empresa->bairro }} <br />
                    {{ $empresa->cidade }}/{{ $empresa->uf }} - {{ $empresa->cep }}
                </td>
            </tbody>
        </table> --}}

        <p align="center" style="font-size: 20px"><strong>@yield('title')</strong></p>
        @yield('content')
    </div>
</body>
