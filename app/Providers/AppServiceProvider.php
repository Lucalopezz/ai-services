<?php

namespace App\Providers;

use App\Services\AiConversationLogger;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Registra serviços da aplicação e a observação do tráfego HTTP das chamadas de IA.
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Compartilha o contexto do log de IA dentro da mesma requisição ou job.
     * O serviço e os listeners HTTP precisam resolver a mesma instância para
     * que os eventos encontrem o request_id da execução em andamento.
     *
     * @return void
     */
    public function register(): void
    {
        // scoped reaproveita a instância nesta requisição ou job e a descarta quando
        // o ciclo termina, evitando carregar o contexto para o próximo ciclo do Laravel.
        $this->app->scoped(AiConversationLogger::class);
    }

    /**
     * Conecta os eventos HTTP ao logger, que captura apenas execuções de AiService.
     * O registro dos listeners é permanente; o contexto ativo no logger determina
     * se cada evento pertence ao intervalo de uma chamada síncrona da aplicação.
     *
     * @return void
     */
    public function boot(): void
    {
        // Registra a classe, sem criar uma instância manual. O dispatcher resolve o logger
        // pelo container, compartilhando o contexto que AiService abriu com start().
        // Esses três eventos cobrem envio, retorno HTTP e falha sem resposta do servidor.
        foreach ([RequestSending::class, ResponseReceived::class, ConnectionFailed::class] as $event) {
            Event::listen($event, [AiConversationLogger::class, 'handle']);
        }
    }
}
