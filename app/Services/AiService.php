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

/**
 * Executa agentes estruturados pelo SDK e compartilha cache e estatísticas.
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
     * Executa um agente síncrono, valida sua resposta e reaproveita resultados em cache.
     *
     * @param Agent $agent Agente que também implementa HasStructuredOutput.
     * @param string $input Texto fornecido pelo usuário, separado das instruções.
     * @return array<string, mixed> Dados estruturados esperados pelo agente.
     * @throws RuntimeException Quando o agente ou a resposta não respeitar o contrato.
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

        // A versão separa o novo array normalizado das respostas HTTP antigas.
        // A configuração da conexão também participa do hash: mudanças de URL,
        // credencial ou modelo não reutilizam resultados de outro destino.
        $cacheKey = 'ai:submit:v2:'.hash('sha256', json_encode([
            'agent' => $agent::class,
            'instructions' => (string) $agent->instructions(),
            'schema' => $schema,
            'provider' => $provider,
            'provider_config' => config("ai.providers.{$provider}"),
            'model' => $model,
            'input' => $input,
        ], JSON_THROW_ON_ERROR));

        $wasCached = true;
        $generate = function () use ($agent, $input, $provider, $model, $schema, &$wasCached): array {
            $wasCached = false;
            $response = $agent->prompt($input, provider: $provider, model: $model, timeout: 60);

            if (! $response instanceof StructuredAgentResponse) {
                throw new RuntimeException('A resposta da IA não contém dados estruturados.');
            }

            $finishReason = $response->steps->last()?->finishReason;
            if ($finishReason !== FinishReason::Stop) {
                throw new RuntimeException('Resposta da IA não foi concluída normalmente.');
            }

            $result = $response->toArray();
            // O SDK decodifica JSON, mas uma resposta decodificável ainda pode
            // omitir campos ou ter tipos incorretos. Valide antes de armazenar.
            $this->validateValue($result, $schema);

            return [
                'result' => $result,
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

        // Retenção zero ou negativa desabilita tanto a leitura quanto a gravação.
        $record = $this->cacheRetention > 0
            ? Cache::remember($cacheKey, now()->addSeconds($this->cacheRetention), $generate)
            : $generate();

        $this->statistics = [
            ...$record['statistics'],
            'duration' => round(microtime(true) - $startTime, 2),
            'cached' => $wasCached,
        ];

        return $record['result'];
    }

    /**
     * Confere os tipos e restrições utilizados pelos schemas atuais, inclusive campos aninhados.
     *
     * @param mixed $value Valor decodificado da resposta a verificar.
     * @param array<string, mixed> $schema Definição serializada do campo no schema do agente.
     * @param string $path Caminho do campo usado na mensagem de erro, sem expor seu conteúdo.
     * @return void
     * @throws RuntimeException Quando o valor não corresponder ao schema ou usar um tipo não suportado.
     */
    private function validateValue(mixed $value, array $schema, string $path = 'resposta'): void
    {
        // Este fluxo usa objetos, listas, strings e inteiros. Outros tipos devem
        // ser tratados explicitamente antes de ampliar os agentes do projeto.
        $validType = match ($schema['type'] ?? null) {
            'object' => is_array($value) && ($value === [] || ! array_is_list($value)),
            'array' => is_array($value) && array_is_list($value),
            'string' => is_string($value),
            'integer' => is_int($value),
            default => false,
        };

        if (! $validType
            || (isset($schema['enum']) && ! in_array($value, $schema['enum'], true))
            || (isset($schema['minimum']) && $value < $schema['minimum'])
            || (isset($schema['maximum']) && $value > $schema['maximum'])) {
            throw new RuntimeException("O campo '{$path}' não corresponde ao schema da resposta.");
        }

        if ($schema['type'] === 'object') {
            $properties = $schema['properties'] ?? [];
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $value)) {
                    throw new RuntimeException("O campo '{$path}.{$field}' é obrigatório na resposta.");
                }
            }
            if (($schema['additionalProperties'] ?? true) === false
                && array_diff(array_keys($value), array_keys($properties)) !== []) {
                throw new RuntimeException("O campo '{$path}' contém propriedades fora do schema.");
            }
            foreach ($properties as $field => $definition) {
                if (array_key_exists($field, $value)) {
                    $this->validateValue($value[$field], $definition, "{$path}.{$field}");
                }
            }
        } elseif ($schema['type'] === 'array') {
            foreach ($value as $index => $item) {
                $this->validateValue($item, $schema['items'], "{$path}.{$index}");
            }
        }
    }

    /**
     * Retorna uso, identificação, duração e estado do cache da última chamada bem-sucedida.
     *
     * @return array<string, mixed>|null Estatísticas normalizadas, ou null antes de uma resposta válida.
     */
    public function statistics(): ?array
    {
        return $this->statistics;
    }
}
