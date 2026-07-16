<?php

ini_set('memory_limit', '2048M');


Schedule::command('mni:consultar-orgao')->dailyAt('04:00');
Schedule::command('cnj:consultar-classes')->dailyAt('04:00');
Schedule::command('cnj:consultar-assuntos')->sundays()->at('05:00');
Schedule::command('mni:consultar-tipos-documentos')->dailyAt('3:00');
