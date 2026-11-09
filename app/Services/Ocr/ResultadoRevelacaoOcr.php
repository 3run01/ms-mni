<?php

namespace App\Services\Ocr;

/**
 * O que o download do OCR faz depois de revelar o mimetype de um documento
 * que o tribunal não informou na lista (RF-10 a RF-12; DP-06, passo 2):
 *
 * - `Enviar`: o revelado está na lista legível. O documento vai ao envio no
 *   mesmo processamento; a revelação não é progresso e não avisa (RF-10);
 * - `Ignorado`: o revelado é conhecido e fora da lista. O documento sai de
 *   `total` e conta em `ignorados`, com aviso, e o processamento termina
 *   `concluido` se nada mais resta (RF-11);
 * - `SemMimetype`: o tribunal também não informou o mimetype no download. O
 *   documento vai a `com_erro` neste processamento, com aviso (RF-12);
 * - `JaRevelado`: o documento já tinha mimetype não vazio quando a trava
 *   chegou (revelado por uma execução anterior do job ou regravado pela
 *   lista). Nada muda e nada é avisado: quem chama relê o documento e segue
 *   pelo mimetype gravado (RNF-05).
 */
enum ResultadoRevelacaoOcr: string
{
    case Enviar = 'enviar';
    case Ignorado = 'ignorado';
    case SemMimetype = 'sem_mimetype';
    case JaRevelado = 'ja_revelado';
}
