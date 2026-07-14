<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>API MNI/PJe — Documentação</title>
    <meta name="robots" content="noindex" />
    <style>
        body { margin: 0; padding: 0; }
        .download-spec {
            position: fixed;
            top: 12px;
            right: 16px;
            z-index: 1000;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            font: 600 13px/1 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: #fff;
            background: #2c3e50;
            border-radius: 6px;
            text-decoration: none;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
        }
        .download-spec:hover { background: #1a252f; }
    </style>
</head>
<body>
    <a class="download-spec" href="{{ route('docs.api.spec') }}" download="openapi.yaml" title="Baixar a spec OpenAPI (importável no Postman/Insomnia)">
        ⬇ Download OpenAPI
    </a>
    <redoc spec-url="{{ route('docs.api.spec') }}"></redoc>
    <script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script>
</body>
</html>
