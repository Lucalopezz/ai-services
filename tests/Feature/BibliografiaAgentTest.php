<?php

namespace Tests\Feature;

use App\Ai\Agents\BibliografiaAgent;
use App\Models\AiPrompt;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Verifica o contrato de bibliografia, a preservação do prompt e a correspondência das listas.
 *
 * As chamadas são simuladas para não utilizar credenciais ou serviços pagos.
 */
class BibliografiaAgentTest extends TestCase
{
    /**
     * Configura um gateway de teste e bloqueia requisições HTTP não simuladas.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.default' => 'litellm',
            'ai.providers.litellm' => [
                'driver' => 'openai-compatible',
                'url' => 'https://litellm.example.com/v1',
                'key' => 'sdk-test-key',
                'models' => ['text' => ['default' => 'sdk-test-model']],
            ],
        ]);
        Http::preventStrayRequests();
    }

    /**
     * Confere o envio do prompt original e do schema pelo driver real, com HTTP simulado.
     *
     * @param string $standard Norma bibliográfica suportada pela aplicação.
     * @return void
     */
    #[DataProvider('bibliographyStandards')]
    public function test_agent_sends_the_original_prompt_and_structured_schema(string $standard): void
    {
        $result = self::bibliographyResult();
        Http::fake([
            '*' => Http::response([
                'model' => 'sdk-test-model',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => json_encode($result),
                    ],
                ]],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
            ]),
        ]);

        $agent = new BibliografiaAgent($standard);
        $input = "Referência A.\nReferência B.";
        $originalPrompt = AiPrompt::systemPrompt('bibliografia', ['standard' => $standard]);
        $response = $agent->prompt($input);

        $this->assertSame($originalPrompt, $agent->instructions());
        $this->assertInstanceOf(StructuredAgentResponse::class, $response);
        $this->assertSame($result, $response->toArray());
        $this->assertNull($response->conversationId);
        $this->assertSame(30, $response->usage->totalTokens());
        Http::assertSentCount(1);

        [$request] = Http::recorded()->first();
        $this->assertSame('https://litellm.example.com/v1/chat/completions', $request->url());
        $this->assertSame('sdk-test-model', $request['model']);
        // O conteúdo do usuário permanece separado das instruções originais.
        $this->assertSame([
            ['role' => 'system', 'content' => $originalPrompt],
            ['role' => 'user', 'content' => $input],
        ], $request['messages']);
        $this->assertArrayNotHasKey('tools', $request->data());
        $this->assertSame('json_schema', $request['response_format']['type']);

        $schema = $request['response_format']['json_schema']['schema'];
        $this->assertSame(['referencias', 'explicacoes', 'busca_dedalus', 'confiancas'], $schema['required']);
        $this->assertFalse($schema['additionalProperties']);

        foreach (['referencias', 'explicacoes', 'busca_dedalus', 'confiancas'] as $field) {
            $this->assertSame('array', $schema['properties'][$field]['type']);
        }
        foreach (['referencias', 'explicacoes'] as $field) {
            $this->assertSame('string', $schema['properties'][$field]['items']['type']);
        }

        $dedalus = $schema['properties']['busca_dedalus']['items'];
        $this->assertSame('object', $dedalus['type']);
        $this->assertSame(['autor', 'titulo'], $dedalus['required']);
        $this->assertFalse($dedalus['additionalProperties']);
        $this->assertSame('string', $dedalus['properties']['autor']['type']);
        $this->assertSame('string', $dedalus['properties']['titulo']['type']);

        $confidence = $schema['properties']['confiancas']['items'];
        $this->assertSame('integer', $confidence['type']);
        $this->assertSame(0, $confidence['minimum']);
        $this->assertSame(100, $confidence['maximum']);
    }

    /**
     * Lista as normas atuais para conferir a interpolação sem reescrever o prompt.
     *
     * @return array<string, array{string}> Casos de teste identificados pela norma.
     */
    public static function bibliographyStandards(): array
    {
        $cases = [];
        foreach (AiPrompt::getBibliografiaStandards() as $standard) {
            $cases[$standard] = [$standard];
        }

        return $cases;
    }

    /**
     * Confere que respostas incoerentes são rejeitadas antes de serem consumidas.
     *
     * @param array<string, mixed> $result Resultado simulado com uma lista inválida.
     * @param string $field Nome da lista que deve ser identificada no erro.
     * @return void
     */
    #[DataProvider('invalidResults')]
    public function test_agent_rejects_invalid_or_unmatched_lists(array $result, string $field): void
    {
        BibliografiaAgent::fake([$result]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("'{$field}'");

        (new BibliografiaAgent('APA 7th Edition'))->prompt('Referências para revisar.');
    }

    /**
     * Prepara falhas de presença, formato e tamanho das listas de resposta.
     *
     * @return array<string, array{array<string, mixed>, string}> Resultado e campo inválido.
     */
    public static function invalidResults(): array
    {
        $result = self::bibliographyResult();
        $missingReferences = $result;
        unset($missingReferences['referencias']);

        return [
            'explanation missing an item' => [array_replace($result, ['explicacoes' => ['Uma explicação.']]), 'explicacoes'],
            'explanation with an extra item' => [array_replace($result, ['explicacoes' => ['A', 'B', 'C']]), 'explicacoes'],
            'dedalus missing an item' => [array_replace($result, ['busca_dedalus' => []]), 'busca_dedalus'],
            'confidence missing an item' => [array_replace($result, ['confiancas' => [50]]), 'confiancas'],
            'references absent' => [$missingReferences, 'referencias'],
            'confidence is not an array' => [array_replace($result, ['confiancas' => 50]), 'confiancas'],
            'explanation is not a list' => [array_replace($result, ['explicacoes' => ['a' => 'A', 'b' => 'B']]), 'explicacoes'],
        ];
    }

    /**
     * Fornece duas referências com itens correspondentes e os extremos de confiança.
     *
     * @return array{referencias: list<string>, explicacoes: list<string>,
     *     busca_dedalus: list<array{autor: string, titulo: string}>, confiancas: list<int>}
     *     Resultado válido segundo o contrato de bibliografia.
     */
    private static function bibliographyResult(): array
    {
        return [
            'referencias' => ['Referência A.', 'Referência B.'],
            'explicacoes' => ['Dados incompletos.', 'Nenhuma alteração necessária.'],
            'busca_dedalus' => [
                ['autor' => 'Autor A', 'titulo' => 'Título A'],
                ['autor' => 'Autor B', 'titulo' => 'Título B'],
            ],
            'confiancas' => [0, 100],
        ];
    }
}
