<?php

namespace Tests\Feature;

use App\Ai\Agents\EmentaAgent;
use App\Models\AiPrompt;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifica o prompt original e o contrato aninhado enviado pelo agente de ementa.
 *
 * O driver do SDK usa HTTP simulado para validar a integração sem chamadas pagas.
 */
class EmentaAgentTest extends TestCase
{
    /**
     * Configura um gateway de teste e impede requisições HTTP não simuladas.
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
     * Confere a preservação do prompt e os dados estruturados para entradas com e sem inglês.
     *
     * @param string $input Texto fornecido pelo usuário com os idiomas disponíveis.
     * @param string $status Estado do inglês esperado na resposta simulada.
     * @param int $score Nota da resposta simulada, incluindo os limites do contrato.
     * @return void
     */
    #[DataProvider('courseDescriptions')]
    public function test_agent_preserves_prompt_and_returns_bilingual_output(string $input, string $status, int $score): void
    {
        $result = self::ementaResult($status, $score);
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

        $agent = new EmentaAgent;
        $originalPrompt = AiPrompt::systemPrompt('ementa');
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
        // O exemplo de JSON do prompt permanece nas instruções, separado da entrada.
        $this->assertSame([
            ['role' => 'system', 'content' => $originalPrompt],
            ['role' => 'user', 'content' => $input],
        ], $request['messages']);
        $this->assertArrayNotHasKey('tools', $request->data());
        $this->assertSame('json_schema', $request['response_format']['type']);
    }

    /**
     * Confere tipos, limites, enum e obrigatoriedade dos campos aninhados enviados ao provider.
     *
     * @return void
     */
    public function test_schema_requires_the_existing_course_description_structure(): void
    {
        Http::fake([
            '*' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => json_encode(self::ementaResult('ausente', 80))],
                ]],
            ]),
        ]);

        (new EmentaAgent)->prompt('Ementa: introdução à programação.');
        [$request] = Http::recorded()->first();
        $schema = $request['response_format']['json_schema']['schema'];
        $this->assertRequiredObject($schema, ['score', 'explicacoes', 'texto_revisado']);

        $score = $schema['properties']['score'];
        $this->assertRequiredObject($score, ['valor', 'justificativa']);
        $this->assertSame('integer', $score['properties']['valor']['type']);
        $this->assertSame(0, $score['properties']['valor']['minimum']);
        $this->assertSame(100, $score['properties']['valor']['maximum']);
        $this->assertSame('string', $score['properties']['justificativa']['type']);

        $explanations = $schema['properties']['explicacoes'];
        $this->assertRequiredObject($explanations, ['pt', 'en']);
        $analysisFields = [
            'gramatica_acentuacao_pontuacao',
            'redacao_academica_clareza_pedagogica',
            'terminologia_tecnica',
        ];
        foreach (['pt', 'en'] as $language) {
            $analysis = $explanations['properties'][$language];
            $required = $language === 'en' ? ['status', ...$analysisFields] : $analysisFields;
            $this->assertRequiredObject($analysis, $required);
            foreach ($required as $field) {
                $this->assertSame('string', $analysis['properties'][$field]['type']);
            }
        }
        $this->assertSame(
            ['presente', 'parcial', 'ausente'],
            $explanations['properties']['en']['properties']['status']['enum'],
        );

        $revisedText = $schema['properties']['texto_revisado'];
        $sections = ['ementa', 'objetivos', 'conteudo_programatico'];
        $this->assertRequiredObject($revisedText, $sections);
        foreach ($sections as $section) {
            $texts = $revisedText['properties'][$section];
            $this->assertRequiredObject($texts, ['pt', 'en']);
            $this->assertSame('string', $texts['properties']['pt']['type']);
            $this->assertSame('string', $texts['properties']['en']['type']);
        }
    }

    /**
     * Confere também o uso dos fakes nativos do SDK com o agente dedicado.
     *
     * @return void
     */
    public function test_agent_supports_structured_sdk_fakes(): void
    {
        $result = self::ementaResult('presente', 90);
        EmentaAgent::fake([$result]);

        $response = (new EmentaAgent)->prompt('Texto acadêmico para revisão.');

        $this->assertInstanceOf(StructuredAgentResponse::class, $response);
        $this->assertSame($result, $response->toArray());
        EmentaAgent::assertPrompted('Texto acadêmico para revisão.');
    }

    /**
     * Fornece os três estados do inglês e as notas extremas esperadas no contrato.
     *
     * @return array<string, array{string, string, int}> Entrada, status e nota simulados.
     */
    public static function courseDescriptions(): array
    {
        return [
            'English absent' => ['Ementa: introdução à programação. Objetivos: desenvolver algoritmos.', 'ausente', 0],
            'English partial' => ["Ementa: introdução à programação.\nCourse Description: introduction.", 'parcial', 50],
            'English present' => ["Ementa: introdução à programação.\nCourse Description: introduction to programming.", 'presente', 100],
        ];
    }

    /**
     * Confere que um objeto exige todos e somente os campos do contrato atual.
     *
     * @param array<string, mixed> $schema Schema serializado de um objeto da resposta.
     * @param list<string> $fields Nomes dos campos obrigatórios esperados.
     * @return void
     */
    private function assertRequiredObject(array $schema, array $fields): void
    {
        $this->assertSame('object', $schema['type']);
        $this->assertSame($fields, array_keys($schema['properties']));
        $this->assertSame($fields, $schema['required']);
        $this->assertFalse($schema['additionalProperties']);
    }

    /**
     * Monta uma resposta simulada com análises e textos revisados nos dois idiomas.
     *
     * @param string $status Estado do inglês declarado pelo resultado simulado.
     * @param int $score Nota acadêmica declarada pelo resultado simulado.
     * @return array<string, mixed> Resultado de ementa com todos os campos do contrato atual.
     */
    private static function ementaResult(string $status, int $score): array
    {
        return [
            'score' => ['valor' => $score, 'justificativa' => 'Revisão acadêmica concluída.'],
            'explicacoes' => [
                'pt' => [
                    'gramatica_acentuacao_pontuacao' => 'Pontuação revisada.',
                    'redacao_academica_clareza_pedagogica' => 'Objetivos esclarecidos.',
                    'terminologia_tecnica' => 'Terminologia consistente.',
                ],
                'en' => [
                    'status' => $status,
                    'gramatica_acentuacao_pontuacao' => 'Grammar reviewed.',
                    'redacao_academica_clareza_pedagogica' => 'Learning objectives clarified.',
                    'terminologia_tecnica' => 'Consistent terminology.',
                ],
            ],
            'texto_revisado' => [
                'ementa' => ['pt' => 'Introdução à programação.', 'en' => 'Introduction to programming.'],
                'objetivos' => ['pt' => 'Desenvolver algoritmos.', 'en' => 'Develop algorithms.'],
                'conteudo_programatico' => ['pt' => 'Variáveis e estruturas de controle.', 'en' => 'Variables and control structures.'],
            ],
        ];
    }
}
