<?php

ini_set('memory_limit', '2048M');


Schedule::command('mni:consultar-orgao')->dailyAt('04:00');
Schedule::command('cnj:consultar-classes')->dailyAt('04:00');
Schedule::command('cnj:consultar-assuntos')->sundays()->at('05:00');
Schedule::command('mni:consultar-tipos-documentos')->dailyAt('3:00');

Schedule::command('monitoramentos:despachar')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('monitoramentos:limpar-execucoes')->dailyAt('04:30');

// Acompanhamento de reserva do OCR (RF-13, RF-18): a cada minuto, para que o
// fim por P (25 min sem progresso) chegue antes dos 30 min sem aviso com que
// o Sistema trata o processamento como `falhou`, e a consulta antes do
// reenvio saia no minuto em que a idade passa de A. Sem sobreposição: uma
// rodada lenta (consultas ao workflow no fim do prazo) não dobra as consultas.
Schedule::command('ocr:acompanhar')->everyMinute()->withoutOverlapping()->runInBackground();
