<?php

namespace App\Services;

use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Cache;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\ObjectSchema;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Throwable;

/**
 * Executa agentes estruturados pelo SDK e compartilha cache, estatísticas e log opcional.
 *
 * As instruções e schemas pertencem aos agentes. A configuração do provider
 * vem de config/ai.php; a retenção do cache permanece em config/aiService.php.
 */
class AiService
{
    private int $cacheRetention;

    /** @var array<string, mixed>|null Estatísticas da última execução bem-sucedida. */
    private ?array $statistics = null;

    /**
     * Carrega a retenção do cache, sem exigir uma conexão ao provider na construção.
     *
     * @return void
     */
    public function __construct()
    {
        $this->cacheRetention = (int) config('aiService.cacheRetention', 604800);
    }

    /**
     * Executa um agente síncrono, confere o término e reaproveita resultados em cache.
     * O provider recebe o schema do agente; os campos retornados não são revalidados aqui.
     * Quando habilitado, registra entrada, tráfego HTTP, saída e falhas no canal da IA.
     * A captura do log acompanha o processamento do cache e da geração. Os eventos
     * HTTP registram o tráfego do SDK, e o serviço registra o resultado recebido
     * ou a exceção, encerrando o contexto antes de devolver o controle ao chamador.
     *
     * @param Agent $agent Agente que também implementa HasStructuredOutput.
     * @param string $input Texto fornecido pelo usuário, separado das instruções.
     * @return array<string, mixed> Dados estruturados devolvidos pelo agente ou recuperados do cache.
     * @throws RuntimeException Quando faltar saída estruturada, o término for incompleto ou o agente rejeitar a resposta.
     * @throws \JsonException Quando as entradas da chave de cache não puderem ser serializadas.
     * @throws \Laravel\Ai\Exceptions\AiException Em caso de falha de execução pelo SDK.
     * @throws \Illuminate\Http\Client\RequestException Em caso de erro HTTP do provider.
     */
    public function submit(Agent $agent, string $input): array
    {
        // Uma falha não deve deixar estatísticas de uma chamada anterior disponíveis.
        $this->statistics = null;
        $startTime = microtime(true);

        if (! $agent instanceof HasStructuredOutput) {
            throw new RuntimeException('O serviço de IA exige um agente com saída estruturada.');
        }

        $provider = Ai::getDefaultInstance();
        $model = Ai::textProvider($provider)->defaultTextModel();
        $schema = (new ObjectSchema($agent->schema(new JsonSchemaTypeFactory)))->toSchema();
        // Usa o mesmo texto nas instruções da chave de cache e no registro do pedido
        // da aplicação, para permitir relacionar um resultado ao prompt que o originou.
        $instructions = (string) $agent->instructions();

        // A versão separa o novo array normalizado das respostas HTTP antigas.
        // A configuração da conexão também participa do hash: mudanças de URL,
        // credencial ou modelo não reutilizam resultados de outro destino.
        $cacheKey = 'ai:submit:v2:'.hash('sha256', json_encode([
            'agent' => $agent::class,
            'instructions' => $instructions,
            'schema' => $schema,
            'provider' => $provider,
            'provider_config' => config("ai.providers.{$provider}"),
            'model' => $model,
            'input' => $input,
        ], JSON_THROW_ON_ERROR));

        $wasCached = true;
        $generate = function () use ($agent, $input, $provider, $model, &$wasCached): array {
            $wasCached = false;
            $response = $agent->prompt($input, provider: $provider, model: $model, timeout: 60);

            if (! $response instanceof StructuredAgentResponse) {
                throw new RuntimeException('A resposta da IA não contém dados estruturados.');
            }

            $finishReason = $response->steps->last()?->finishReason;
            if ($finishReason !== FinishReason::Stop) {
                throw new RuntimeException('Resposta da IA não foi concluída normalmente.');
            }

            // Confia nos campos retornados pelo agente, sem repetir a validação do schema.
            return [
                'result' => $response->toArray(),
                'statistics' => [
                    'usage' => [
                        'prompt_tokens' => $response->usage->inputTokens,
                        'completion_tokens' => $response->usage->outputTokens,
                        'total_tokens' => $response->usage->totalTokens(),
                    ],
                    'provider' => $response->meta->provider ?: $provider,
                    'model' => $response->meta->model ?: $model,
                    'id' => $response->raw?->json('id') ?? $response->invocationId,
                    'finish_reason' => $finishReason->value,
                    // Guardar a data junto com o resultado preserva-a nos acertos de cache.
                    'cached_at' => now()->toIso8601String(),
                ],
            ];
        };

        // Resolve o logger pelo container para compartilhar a mesma instância com os
        // listeners HTTP. O contexto fica ativo durante cache e execução do agente.
        // As mensagens abaixo descrevem a entrada da aplicação; os listeners registram
        // separadamente o payload real construído pelo SDK, com suas opções e schema.
        $logger = app(AiConversationLogger::class);
        $logger->start([
            'agent' => $agent::class,
            'provider' => $provider,
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $instructions],
                ['role' => 'user', 'content' => $input],
            ],
            'schema' => $schema,
        ]);

        try {
            // Com cache ativo, remember() só executa $generate quando não encontra o registro.
            // Nesse caso os listeners capturam o HTTP; no acerto, o logger registra ai.cache_hit.
            // Retenção zero ou negativa ignora o cache e permite testar uma nova resposta sempre.
            $record = $this->cacheRetention > 0
                ? Cache::remember($cacheKey, now()->addSeconds($this->cacheRetention), $generate)
                : $generate();

            // Preserva tokens e cached_at do registro original. Duração e cached descrevem
            // esta execução, pois uma leitura do cache tem tempo e origem diferentes da geração.
            $this->statistics = [
                ...$record['statistics'],
                'duration' => round(microtime(true) - $startTime, 2),
                'cached' => $wasCached,
            ];

            // Registra somente o resultado aceito pelo serviço, junto das estatísticas
            // disponibilizadas ao controller; respostas rejeitadas seguem para o catch.
            $logger->result($record['result'], $this->statistics);

            return $record['result'];
        } catch (Throwable $exception) {
            // Acrescenta o diagnóstico ao arquivo e propaga a mesma exceção para preservar
            // o tratamento de erros e os status HTTP já definidos pelo controller.
            $logger->error($exception);

            throw $exception;
        } finally {
            // finally também roda após return e throw. Limpar o contexto impede que uma
            // requisição HTTP posterior seja registrada como parte desta conversa com a IA.
            $logger->finish();
        }
    }

    /**
     * Retorna uso, identificação, duração e estado do cache da última chamada bem-sucedida.
     *
     * @return array<string, mixed>|null Estatísticas normalizadas, ou null se a última chamada não tiver sucesso ou ainda não houver execução.
     */
    public function statistics(): ?array
    {
        return $this->statistics;
    }
}
