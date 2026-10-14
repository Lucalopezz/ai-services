<?php

namespace App\Ai\Agents;

use App\Models\AiPrompt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\AgentInput;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\StructuredAgentResponse;
use RuntimeException;
use Laravel\Ai\Attributes\Strict;

/**
 * Formata referências com o prompt existente e uma saída estruturada de quatro listas.
 *
 * A norma é fornecida pelo chamador; o texto a revisar é enviado a prompt().
 * O agente executa chamadas independentes, sem contexto de conversa ou tools.
 */
#[Strict]
class BibliografiaAgent implements Agent, HasStructuredOutput
{
    /**
     * Preserva o método original do SDK com outro nome para que
     * a implementação personalizada de prompt() possa reutilizá-lo.
     *
     * O alias é privado para impedir chamadas externas que
     * contornem a validação das listas.
     */
    use Promptable {
        prompt as private promptViaSdk;
    }

    /**
     * Define a norma usada na interpolação do prompt original.
     *
     * @param string $standard Norma bibliográfica previamente validada pelo chamador.
     * @return void
     */
    public function __construct(private string $standard) {}

    /**
     * Obtém as regras de conteúdo bibliográfico mantidas em AiPrompt.
     * Os campos e seus tipos são enviados pelo SDK a partir de schema(); aqui
     * permanecem as regras bibliográficas e o significado de cada informação.
     *
     * @return string Instruções com a norma selecionada interpolada, sem estrutura JSON.
     */
    public function instructions(): string
    {
        // Centraliza as regras em AiPrompt e interpola a norma selecionada. O SDK usa
        // schema() para montar response_format, sem exigir um exemplo JSON nas instruções.
        return AiPrompt::systemPrompt('bibliografia', ['standard' => $this->standard]);
    }

    /**
     * Define o contrato de saída enviado pelo SDK, preservando as quatro listas atuais.
     * Este contrato continua obrigatório após retirar o bloco de formato do prompt;
     * a correspondência entre as listas permanece validada pelo agente.
     *
     * @param JsonSchema $schema Construtor de tipos JSON Schema fornecido pelo SDK.
     * @return array<string, Type> Campos obrigatórios e tipos da resposta de bibliografia.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'referencias' => $schema->array()->items($schema->string())->required(),
            'explicacoes' => $schema->array()->items($schema->string())->required(),
            'busca_dedalus' => $schema->array()->items(
                $schema->object([
                    'autor' => $schema->string()->required(),
                    'titulo' => $schema->string()->required(),
                ]),
            )->required(),
            'confiancas' => $schema->array()->items(
                $schema->integer()->min(0)->max(100),
            )->required(),
        ];
    }

    /**
     * Executa a chamada síncrona do SDK e confere a correspondência entre as listas.
     *
     * @param AgentInput|UserMessage|Decisions|string $prompt Entrada aceita pela interface
     *     do SDK; neste fluxo, contém o texto das referências como string.
     * @param array $attachments Anexos previstos na interface do SDK; não usados neste fluxo.
     * @param Lab|array|string|null $provider Provider opcional; null usa config('ai.default').
     * @param string|null $model Modelo opcional; null usa o padrão do provider selecionado.
     * @param int|null $timeout Timeout opcional em segundos; null usa o padrão do SDK.
     * @return StructuredAgentResponse Dados estruturados e metadados da execução.
     * @throws RuntimeException Quando a resposta não for estruturada ou as listas não corresponderem.
     * @throws \Laravel\Ai\Exceptions\AiException Quando o SDK falhar ao executar o provider.
     */
    public function prompt(
        AgentInput|UserMessage|Decisions|string $prompt,
        array $attachments = [],
        Lab|array|string|null $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): StructuredAgentResponse {
        // Delega a comunicação ao SDK, mantendo sua assinatura e opções de execução.
        $response = $this->promptViaSdk($prompt, $attachments, $provider, $model, $timeout);

        if (! $response instanceof StructuredAgentResponse) {
            throw new RuntimeException('A resposta de bibliografia não contém dados estruturados.');
        }

        // O JSON Schema valida os tipos, mas não garante que as quatro
        // listas possuam o mesmo tamanho. Essa verificação é feita aqui.
        $this->validateCorrespondence($response);

        return $response;
    }

    /**
     * Confere que as quatro listas permitem associar um item por referência.
     *
     * @param StructuredAgentResponse $response Resposta estruturada a ser verificada.
     * @return void
     * @throws RuntimeException Quando uma lista estiver ausente, inválida ou tiver tamanho diferente.
     */
    private function validateCorrespondence(StructuredAgentResponse $response): void
    {
        $result = $response->toArray();
        $referenceCount = null;

        // JSON Schema define cada lista, mas não compara o tamanho de campos diferentes.
        foreach (['referencias', 'explicacoes', 'busca_dedalus', 'confiancas'] as $field) {
            $items = $result[$field] ?? null;

            if (! is_array($items) || ! array_is_list($items)) {
                throw new RuntimeException("A resposta de bibliografia deve conter a lista '{$field}'.");
            }

            $referenceCount ??= count($items);

            if (count($items) !== $referenceCount) {
                throw new RuntimeException(
                    "A lista '{$field}' deve conter um item para cada referência.",
                );
            }
        }
    }
}
