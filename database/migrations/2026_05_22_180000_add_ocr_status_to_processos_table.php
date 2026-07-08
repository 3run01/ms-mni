<?php

use Illuminate\Database\Migrations\Migration;

/**
 * NO-OP: a coluna `ocr_status` foi removida pela migration
 * `2026_07_07_000000_drop_ocr_and_samia_columns` (funcionalidade OCR removida).
 *
 * Esta migration virou no-op para não recriar a coluna em ambientes onde
 * ainda não tinha rodado (ex.: quando a migration de drop foi aplicada fora
 * de ordem, via --path, antes desta chegar a rodar). É seguro em qualquer
 * ambiente: onde já rodou, fica registrada e nunca roda de novo; onde está
 * pendente, é registrada como no-op; em instalações novas, os guards
 * `hasColumn` da migration de drop já lidam com a ausência da coluna.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Intencionalmente vazio — ver docblock acima.
    }

    public function down(): void
    {
        // Intencionalmente vazio — ver docblock acima.
    }
};
