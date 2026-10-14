<?php

namespace App\Services;

use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Registra o conteúdo das chamadas síncronas de AiService no canal de arquivo da IA.
 *
 * O contexto existe entre start() e finish(), para excluir outras chamadas HTTP.
 * Cabeçalhos e configuração de credenciais não são incluídos nos registros.
 * O serviço informa início, sucesso e falha; os eventos HTTP fornecem o tráfego
 * real do SDK. Esta classe decide o que registrar, e o formatter do canal
 * decide como apresentar os dados no arquivo.
 */
class AiConversationLogger
{
    /** @var array<string, mixed>|null Identificação da execução síncrona em andamento. */
    private ?array $context = null;

    /**
     * Abre o contexto da chamada e registra a entrada quando a opção estiver habilitada.
     * A entrada representa o pedido da aplicação; os eventos HTTP registram depois
     * o payload efetivamente montado pelo SDK, incluindo suas opções de execução.
     *
     * @param array<string, mixed> $context Agente, provider, modelo, mensagens e schema solicitado.
     * @return void
     */
    public function start(array $context): void
    {
        // Descarta qualquer identificação anterior antes de conferir a configuração.
        // Com o log desligado, os eventos HTTP encontrarão null e serão ignorados.
        $this->context = null;
        if (! config('logging.ai_conversations_enabled', false)) {
            return;
        }

        // O UUID relaciona todos os registros de um submit, inclusive em acertos de cache.
        // Guarda apenas a identificação compartilhada: mensagens e schema pertencem
        // ao evento inicial e não precisam ser repetidos em cada resposta HTTP.
        $this->context = [
            'request_id' => (string) Str::uuid(),
            'agent' => $context['agent'],
            'provider' => $context['provider'],
            'model' => $context['model'],
        ];

        $this->write('ai.request', $context);
    }

    /**
     * Registra o resultado recebido, distinguindo a geração do reaproveitamento de cache.
     * Nos acertos de cache, os tokens e cached_at continuam sendo os da geração original.
     *
     * @param array<string, mixed> $result Dados estruturados devolvidos pelo agente ou pelo cache.
     * @param array<string, mixed> $statistics Estatísticas da chamada, incluindo o campo cached.
     * @return void
     */
    public function result(array $result, array $statistics): void
    {
        // A ausência de eventos HTTP no cache é esperada: ai.cache_hit mostra que
        // o resultado veio do armazenamento local, sem uma nova chamada ao provider.
        $this->write($statistics['cached'] ? 'ai.cache_hit' : 'ai.result', [
            'result' => $result,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Registra uma falha sem assumir a responsabilidade de propagá-la ao chamador.
     * A mensagem interna fica neste canal de diagnóstico; o controller continua
     * responsável por escolher a mensagem e o status apresentados ao usuário.
     *
     * @param Throwable $exception Falha de cache, execução ou validação capturada pelo serviço.
     * @return void
     */
    public function error(Throwable $exception): void
    {
        // Inclui a classe para distinguir falhas de transporte, SDK e validação,
        // mesmo quando mensagens de erro diferentes parecem semelhantes.
        $this->write('ai.error', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ], 'error');
    }

    /**
     * Encerra a captura para excluir o tráfego HTTP posterior à chamada síncrona.
     * AiService chama este método em finally, tanto no sucesso quanto nas exceções.
     *
     * @return void
     */
    public function finish(): void
    {
        // O listener HTTP continua registrado na aplicação; null desativa a captura
        // sem remover listeners nem interferir em outros serviços que usam Http.
        $this->context = null;
    }

    /**
     * Captura o pedido real e a resposta bruta antes da interpretação pelo SDK.
     * Os eventos são emitidos pelo cliente HTTP do Laravel, inclusive com HTTP fake;
     * fakes nativos do agente não geram tráfego e produzem somente registros do serviço.
     *
     * @param RequestSending|ResponseReceived|ConnectionFailed $event Evento do cliente HTTP do Laravel.
     * @return void
     */
    public function handle(RequestSending|ResponseReceived|ConnectionFailed $event): void
    {
        if ($this->context === null) {
            // Estes listeners recebem eventos de todo o cliente HTTP. O contexto
            // limita o registro ao intervalo da chamada síncrona de AiService.
            return;
        }

        // Seleciona os campos explicitamente, sem serializar o objeto Request inteiro:
        // isso exclui os cabeçalhos que carregam a autenticação do gateway.
        $request = ['method' => $event->request->method(), 'url' => $event->request->url()];

        if ($event instanceof RequestSending) {
            // data() inclui as mensagens e o response_format realmente montados pelo SDK.
            $this->write('ai.http.request', [...$request, 'payload' => $event->request->data()]);
        } elseif ($event instanceof ResponseReceived) {
            // O evento ocorre antes de o SDK interpretar o corpo ou lançar uma exceção.
            // body() preserva JSON inválido, recusas e erros HTTP que não chegarão a result().
            $this->write('ai.http.response', [
                ...$request,
                'status' => $event->response->status(),
                'body' => $event->response->body(),
            ]);
        } else {
            // Na falha de conexão não existe ResponseReceived: registra a exceção
            // do transporte e deixa a falha do SDK aparecer também em ai.error.
            $this->write('ai.http.connection_failed', [
                ...$request,
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ], 'error');
        }
    }

    /**
     * Escreve um evento correlacionado sem permitir que uma falha do log interrompa a IA.
     * O canal dedicado mantém os registros em storage, independentemente do canal padrão.
     *
     * @param string $event Nome da etapa da conversa.
     * @param array<string, mixed> $data Conteúdo e metadados específicos da etapa.
     * @param string $level Nível do registro no canal dedicado.
     * @return void
     */
    private function write(string $event, array $data, string $level = 'info'): void
    {
        // Sem um contexto ativo, a opção está desligada ou a chamada já terminou.
        if ($this->context === null) {
            return;
        }

        try {
            // A identificação ativa prevalece sobre os dados do evento para manter
            // o mesmo request_id, agente e destino em toda a execução.
            Log::channel('ai_conversations')->log($level, $event, [...$data, ...$this->context]);
        } catch (Throwable) {
            // Um arquivo sem permissão de escrita não deve transformar uma resposta válida
            // em erro nem substituir a exceção original do provider por uma falha do log.
        }
    }
}
