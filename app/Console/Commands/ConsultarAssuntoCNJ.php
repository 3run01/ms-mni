<?php

namespace App\Console\Commands;

use App\Models\AssuntoCNJ;
use Illuminate\Console\Command;

class ConsultarAssuntoCNJ extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cnj:consultar-assuntos';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consultar assuntos cnj e gravar na tabela cnj.assuntos';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        function cadastrar($itens)
        {
            foreach ($itens as $item) {
                $create = AssuntoCNJ::updateOrCreate(
                    [
                        'codigo' => $item->seq_elemento,
                    ],
                    [
                        'descricao' => $item->dsc_elemento,
                        'codigo_pai' => $item->seq_elemento_pai,
                        'tem_filhos' => $item->temFilhos == '1' ? true : false,
                        'situacao' => $item->situacao,
                    ]
                );
            }
        }

        function consultarFilho($seqItem, $tipoItem)
        {
            $itens = consultar($seqItem, $tipoItem);

            if (is_array($itens) || is_object($itens)) {
                foreach ($itens as $item) {
                    if (!empty($item->temFilhos) && $item->temFilhos == "1") {
                        consultarFilho($item->seq_elemento, $tipoItem);
                    }
                }
            } else {
                echo "Nenhum item encontrado para o seqItem: $seqItem";
            }
        }


        function consultar($seqItem = '', $tipoItem)
        {
            // URL do serviço SOAP
            $wsdl = "https://www.cnj.jus.br/sgt/sgt_ws.php?wsdl";

            $opts = [
                'http' => [
                    'user_agent' => 'PHPSoapClient'
                ]
            ];

            $context = stream_context_create($opts);

            // Opções do cliente SOAP
            $options = [
                'stream_context' => $context,
                'cache_wsdl' => WSDL_CACHE_NONE,
                'encoding' => 'UTF-8',
            ];

            try {
                // Criação do cliente SOAP
                $client = new \SoapClient($wsdl, $options);

                // Parâmetros para a requisição SOAP
                $params = [
                    'seqItem' => $seqItem,
                    'tipoItem' => $tipoItem
                ];

                // Fazendo a requisição SOAP
                $response = $client->__soapCall('getArrayFilhosItemPublicoWS', $params);

                cadastrar($response);

                return $response;
            } catch (\SoapFault $e) {
                // Tratamento de erros
                echo "Erro na requisição SOAP: " . $e->getMessage();
                return null;
            }
        }

        $tipoItem = "A";
        consultarFilho('', $tipoItem);

        $this->info('Assuntos CNJ consultados com sucesso.');
    }
}
