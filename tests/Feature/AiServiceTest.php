<?php

namespace Tests\Feature;

use App\Ai\Agents\BibliografiaAgent;
use App\Ai\Agents\EmentaAgent;
use App\Models\User;
use App\Services\AiService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

use function Laravel\Ai\agent;

/**
 * Verifica execução pelo SDK, isolamento e expiração do cache e rejeição de respostas inválidas.
 *
 * As respostas HTTP são simuladas e o cache usa memória isolada do ambiente real.
 */
class AiServiceTest extends TestCase
{
    /**
     * Configura o provider de teste e um cache sem acesso ao armazenamento da aplicação.
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
            'aiService.cacheRetention' => 60,
            'cache.default' => 'array',
            'cache.stores.array.serialize' => true,
        ]);
        // APP_URL local pode incluir o caminho do Apache; o servidor de teste usa a raiz.
        URL::forceRootUrl('http://localhost');
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    /**
     * Confere uso normalizado e a preservação da data de geração em outro serviço.
     *
     * @return void
     */
    public function test_cache_preserves_results_and_statistics_across_service_instances(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertSame(60, $options['timeout']);

            return Http::response(self::httpResult(self::bibliographyResult()));
        });
        $service = new AiService;
        $createdAt = now()->toIso8601String();

        $this->assertNull($service->statistics());
        $this->assertSame(self::bibliographyResult(), $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A'));
        $statistics = $service->statistics();
        $this->assertSame(['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30], $statistics['usage']);
        $this->assertSame('litellm', $statistics['provider']);
        $this->assertSame('backend-model', $statistics['model']);
        $this->assertSame('provider-response-id', $statistics['id']);
        $this->assertSame('stop', $statistics['finish_reason']);
        $this->assertFalse($statistics['cached']);
        $this->assertSame($createdAt, $statistics['cached_at']);
        $this->assertGreaterThanOrEqual(0, $statistics['duration']);

        $this->travel(30)->seconds();
        $anotherService = new AiService;
        $this->assertSame(self::bibliographyResult(), $anotherService->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A'));
        $this->assertTrue($anotherService->statistics()['cached']);
        $this->assertSame($createdAt, $anotherService->statistics()['cached_at']);
        $this->assertSame($statistics['usage'], $anotherService->statistics()['usage']);
        Http::assertSentCount(1);
    }

    /**
     * Confere que a retenção configurada determina quando o resultado será gerado novamente.
     *
     * @return void
     */
    public function test_cache_expires_after_the_configured_retention(): void
    {
        Http::fake(['*' => Http::response(self::httpResult(self::bibliographyResult()))]);
        $service = new AiService;
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');

        $this->travel(61)->seconds();
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');

        $this->assertFalse($service->statistics()['cached']);
        $this->assertSame(now()->toIso8601String(), $service->statistics()['cached_at']);
        Http::assertSentCount(2);
    }

    /**
     * Confere que desabilitar o cache também impede ler um resultado já armazenado.
     *
     * @return void
     */
    public function test_zero_retention_bypasses_existing_cache_and_does_not_write(): void
    {
        Http::fake(['*' => Http::response(self::httpResult(self::bibliographyResult()))]);
        (new AiService)->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        config(['aiService.cacheRetention' => 0]);
        $service = new AiService;

        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        $this->assertFalse($service->statistics()['cached']);
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto B');
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto B');

        $this->assertFalse($service->statistics()['cached']);
        Http::assertSentCount(4);
    }

    /**
     * Confere a separação por norma, entrada e classe do agente.
     *
     * @return void
     */
    public function test_cache_separates_standards_inputs_and_agents(): void
    {
        Http::fake(function (Request $request) {
            $result = str_contains($request['messages'][0]['content'], 'Academic and Curriculum Reviewer')
                ? self::ementaResult()
                : self::bibliographyResult();

            return Http::response(self::httpResult($result));
        });
        $service = new AiService;
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        $service->submit(new BibliografiaAgent('IEEE Citation Style'), 'Texto A');
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto B');
        $this->assertSame(self::ementaResult(), $service->submit(new EmentaAgent, 'Texto A'));
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');

        $this->assertTrue($service->statistics()['cached']);
        Http::assertSentCount(4);
    }

    /**
     * Confere a separação por conexão e modelo, mesmo mantendo o nome do provider.
     *
     * @param string $field Caminho da opção de configuração a alterar.
     * @param string $value Novo valor de configuração do provider de teste.
     * @return void
     */
    #[DataProvider('providerChanges')]
    public function test_cache_separates_provider_configuration(string $field, string $value): void
    {
        Http::fake(['*' => Http::response(self::httpResult(self::bibliographyResult()))]);
        $service = new AiService;
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');

        config(["ai.providers.litellm.{$field}" => $value]);
        // O SDK mantém instâncias de providers; recarregue após a mudança de configuração do teste.
        Ai::forgetInstance('litellm');
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        $this->assertFalse($service->statistics()['cached']);
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        $this->assertTrue($service->statistics()['cached']);
        Http::assertSentCount(2);

        [$request] = Http::recorded()->last();
        if ($field === 'models.text.default') {
            $this->assertSame($value, $request['model']);
        } elseif ($field === 'url') {
            $this->assertSame($value.'/chat/completions', $request->url());
        } else {
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.$value));
        }
    }

    /**
     * Lista mudanças que devem invalidar a reutilização de um resultado.
     *
     * @return array<string, array{string, string}> Campo e novo valor de configuração.
     */
    public static function providerChanges(): array
    {
        return [
            'model' => ['models.text.default', 'another-model'],
            'URL' => ['url', 'https://another-gateway.example.com/v1'],
            'credential' => ['key', 'another-test-key'],
        ];
    }

    /**
     * Confere que o serviço respeita ai.default e preserva os caches por nome de provider.
     *
     * @return void
     */
    public function test_service_uses_the_selected_default_provider(): void
    {
        Http::fake(['*' => Http::response(self::httpResult(self::bibliographyResult()))]);
        $service = new AiService;
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        config([
            'ai.default' => 'alternate',
            'ai.providers.alternate' => config('ai.providers.litellm'),
        ]);
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        $this->assertSame('alternate', $service->statistics()['provider']);
        $this->assertFalse($service->statistics()['cached']);

        config(['ai.default' => 'litellm']);
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        $this->assertTrue($service->statistics()['cached']);
        $this->assertSame('litellm', $service->statistics()['provider']);
        Http::assertSentCount(2);
    }

    /**
     * Confere que o conteúdo das instruções e do schema participa da chave de cache.
     *
     * @return void
     */
    public function test_cache_changes_with_instructions_and_schema(): void
    {
        Http::fake(['*' => Http::response(self::httpResult(self::ementaResult()))]);
        // A variação existe somente neste agente de teste; os prompts reais são preservados.
        $agent = new class extends EmentaAgent {
            public bool $changeInstructions = false;
            public bool $changeSchema = false;

            /** @return string Instruções com uma variação exclusiva do teste de cache. */
            public function instructions(): string
            {
                return parent::instructions().($this->changeInstructions ? '\nContexto de teste.' : '');
            }

            /**
             * Acrescenta um campo opcional para simular a evolução do contrato no teste.
             *
             * @param JsonSchema $schema Construtor de tipos fornecido pelo SDK.
             * @return array<string, \Illuminate\JsonSchema\Types\Type> Schema original ou variante de teste.
             */
            public function schema(JsonSchema $schema): array
            {
                $fields = parent::schema($schema);
                if ($this->changeSchema) {
                    $fields['teste'] = $schema->string();
                }

                return $fields;
            }
        };
        $service = new AiService;
        $service->submit($agent, 'Texto A');
        $agent->changeInstructions = true;
        $service->submit($agent, 'Texto A');
        $this->assertFalse($service->statistics()['cached']);
        $agent->changeSchema = true;
        $service->submit($agent, 'Texto A');
        $this->assertFalse($service->statistics()['cached']);
        $service->submit($agent, 'Texto A');
        $this->assertTrue($service->statistics()['cached']);
        Http::assertSentCount(3);
    }

    /**
     * Confere que uma resposta inválida não é armazenada e uma repetição pode se recuperar.
     *
     * @param string $kind Nome do agente a executar no caso de teste.
     * @param array<string, mixed> $invalid Resultado com uma violação do contrato.
     * @param string $field Campo que deve aparecer na mensagem de erro.
     * @return void
     */
    #[DataProvider('invalidResults')]
    public function test_invalid_responses_are_not_cached(string $kind, array $invalid, string $field): void
    {
        $agent = $kind === 'bibliografia' ? new BibliografiaAgent('APA 7th Edition') : new EmentaAgent;
        $valid = $kind === 'bibliografia' ? self::bibliographyResult() : self::ementaResult();
        Http::fake(['*' => Http::sequence()->push(self::httpResult($invalid))->push(self::httpResult($valid))]);
        $service = new AiService;

        try {
            $service->submit($agent, 'Texto A');
            $this->fail('A resposta inválida deveria ter sido rejeitada.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($field, $exception->getMessage());
        }
        $this->assertNull($service->statistics());
        $this->assertSame($valid, $service->submit($agent, 'Texto A'));
        $this->assertFalse($service->statistics()['cached']);
        $this->assertSame($valid, $service->submit($agent, 'Texto A'));
        $this->assertTrue($service->statistics()['cached']);
        Http::assertSentCount(2);
    }

    /**
     * Prepara violações de campos aninhados, tipos, limites e correspondência das listas.
     *
     * @return array<string, array{string, array<string, mixed>, string}> Agente, resultado e campo inválido.
     */
    public static function invalidResults(): array
    {
        $bibliography = self::bibliographyResult();
        $ementa = self::ementaResult();
        $missingEnglish = $ementa;
        unset($missingEnglish['texto_revisado']['ementa']['en']);

        return [
            'unmatched lists' => ['bibliografia', array_replace($bibliography, ['explicacoes' => []]), 'explicacoes'],
            'confidence too high' => ['bibliografia', array_replace($bibliography, ['confiancas' => [101]]), 'confiancas.0'],
            'confidence too low' => ['bibliografia', array_replace($bibliography, ['confiancas' => [-1]]), 'confiancas.0'],
            'confidence string' => ['bibliografia', array_replace($bibliography, ['confiancas' => ['90']]), 'confiancas.0'],
            'reference type' => ['bibliografia', array_replace($bibliography, ['referencias' => [42]]), 'referencias.0'],
            'dedalus title missing' => ['bibliografia', array_replace($bibliography, ['busca_dedalus' => [['autor' => 'Autor']]]), 'busca_dedalus.0.titulo'],
            'score too high' => ['ementa', array_replace_recursive($ementa, ['score' => ['valor' => 101]]), 'score.valor'],
            'score fractional' => ['ementa', array_replace_recursive($ementa, ['score' => ['valor' => 90.5]]), 'score.valor'],
            'unknown English status' => ['ementa', array_replace_recursive($ementa, ['explicacoes' => ['en' => ['status' => 'outro']]]), 'explicacoes.en.status'],
            'English text missing' => ['ementa', $missingEnglish, 'texto_revisado.ementa.en'],
            'unexpected field' => ['ementa', [...$ementa, 'extra' => 'fora do contrato'], 'resposta'],
        ];
    }

    /**
     * Confere rejeição de JSON inválido ou término incompleto, sem reaproveitar o resultado.
     *
     * @param string $content Conteúdo textual simulado pelo provider.
     * @param string $finishReason Motivo de término da resposta simulada.
     * @return void
     */
    #[DataProvider('invalidCompletions')]
    public function test_invalid_json_and_incomplete_responses_are_not_cached(string $content, string $finishReason): void
    {
        $invalid = self::httpResult(self::ementaResult());
        $invalid['choices'][0]['message']['content'] = $content;
        $invalid['choices'][0]['finish_reason'] = $finishReason;
        Http::fake(['*' => Http::sequence()->push($invalid)->push(self::httpResult(self::ementaResult()))]);
        $service = new AiService;

        try {
            $service->submit(new EmentaAgent, 'Texto A');
            $this->fail('A resposta inválida deveria ter sido rejeitada.');
        } catch (RuntimeException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertNull($service->statistics());
        $this->assertSame(self::ementaResult(), $service->submit(new EmentaAgent, 'Texto A'));
        $this->assertFalse($service->statistics()['cached']);
        Http::assertSentCount(2);
    }

    /**
     * Fornece texto que não é JSON e respostas interrompidas antes da conclusão normal.
     *
     * @return array<string, array{string, string}> Conteúdo e motivo de término simulados.
     */
    public static function invalidCompletions(): array
    {
        return [
            'invalid JSON' => ['Resposta sem JSON.', 'stop'],
            'truncated output' => [json_encode(self::ementaResult()), 'length'],
            'filtered output' => [json_encode(self::ementaResult()), 'content_filter'],
        ];
    }

    /**
     * Confere propagação da falha do provider e limpeza das estatísticas anteriores.
     *
     * @return void
     */
    public function test_provider_failures_clear_previous_statistics_and_are_not_cached(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(self::httpResult(self::ementaResult()))
            ->pushStatus(503)
            ->push(self::httpResult(self::ementaResult()))]);
        $service = new AiService;
        $service->submit(new EmentaAgent, 'Texto A');
        $this->assertNotNull($service->statistics());

        try {
            $service->submit(new EmentaAgent, 'Texto B');
            $this->fail('A falha do provider deveria ter sido propagada.');
        } catch (ProviderOverloadedException $exception) {
            $this->assertNotEmpty($exception->getMessage());
        }
        $this->assertNull($service->statistics());
        $this->assertSame(self::ementaResult(), $service->submit(new EmentaAgent, 'Texto B'));
        $this->assertFalse($service->statistics()['cached']);
        Http::assertSentCount(3);
    }

    /**
     * Confere que agentes sem schema são rejeitados antes de chamar o provider.
     *
     * @return void
     */
    public function test_service_rejects_agents_without_structured_output(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('agente com saída estruturada');

        (new AiService)->submit(agent(instructions: 'Teste.'), 'Texto A');
    }

    /**
     * Confere o uso dos fakes nativos, inclusive quando não há resposta HTTP bruta.
     *
     * @return void
     */
    public function test_service_supports_sdk_fakes_without_raw_http_response(): void
    {
        EmentaAgent::fake([self::ementaResult()]);
        $service = new AiService;

        $this->assertSame(self::ementaResult(), $service->submit(new EmentaAgent, 'Texto A'));
        $this->assertNotEmpty($service->statistics()['id']);
        $this->assertSame('stop', $service->statistics()['finish_reason']);
        $service->submit(new EmentaAgent, 'Texto A');

        $this->assertTrue($service->statistics()['cached']);
        EmentaAgent::assertPromptedTimes(1);
        Http::assertNothingSent();
    }

    /**
     * Confere que as duas chamadas ajustadas no controller preservam seus contratos JSON.
     *
     * @return void
     */
    public function test_controllers_use_the_new_service_signature(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push(self::httpResult(self::bibliographyResult()))
            ->push(self::httpResult(self::ementaResult()))]);
        // Usuário em memória evita criar registros no banco do ambiente local.
        $this->actingAs((new User)->forceFill(['id' => 1, 'name' => 'Teste', 'email' => 'teste@example.com']));

        $this->postJson(route('bibliografia.bibliografia'), ['message' => 'Referência A.', 'standard' => 'APA 7th Edition'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.respostas', self::bibliographyResult()['referencias'])
            ->assertJsonPath('data.estatisticas.usage.total_tokens', 30);
        $this->postJson(route('bibliografia.ementa'), ['mensagem' => 'Ementa: introdução à programação.'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.resposta', self::ementaResult()['texto_revisado'])
            ->assertJsonPath('data.estatisticas.finish_reason', 'stop');
        Http::assertSentCount(2);
    }

    /**
     * Monta o envelope Chat Completions com metadados e uso para testar a conversão do SDK.
     *
     * @param array<string, mixed> $result Dados estruturados a incluir no conteúdo da resposta.
     * @return array<string, mixed> Payload HTTP simulado do provider.
     */
    private static function httpResult(array $result): array
    {
        return [
            'id' => 'provider-response-id',
            'model' => 'backend-model',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => json_encode($result)],
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ];
    }

    /**
     * Fornece uma referência com os quatro itens correspondentes para os testes do serviço.
     *
     * @return array<string, mixed> Resposta de bibliografia válida segundo o schema do agente.
     */
    private static function bibliographyResult(): array
    {
        return [
            'referencias' => ['Referência A.'],
            'explicacoes' => ['Pontuação revisada.'],
            'busca_dedalus' => [['autor' => 'Autor A', 'titulo' => 'Título A']],
            'confiancas' => [90],
        ];
    }

    /**
     * Fornece uma revisão bilíngue com todos os campos obrigatórios do agente de ementa.
     *
     * @return array<string, mixed> Resposta de ementa válida segundo o schema do agente.
     */
    private static function ementaResult(): array
    {
        return [
            'score' => ['valor' => 90, 'justificativa' => 'Boa qualidade.'],
            'explicacoes' => [
                'pt' => [
                    'gramatica_acentuacao_pontuacao' => 'Pontuação revisada.',
                    'redacao_academica_clareza_pedagogica' => 'Texto claro.',
                    'terminologia_tecnica' => 'Terminologia consistente.',
                ],
                'en' => [
                    'status' => 'ausente',
                    'gramatica_acentuacao_pontuacao' => 'Tradução criada.',
                    'redacao_academica_clareza_pedagogica' => 'Texto claro.',
                    'terminologia_tecnica' => 'Terminologia consistente.',
                ],
            ],
            'texto_revisado' => [
                'ementa' => ['pt' => 'Introdução à programação.', 'en' => 'Introduction to programming.'],
                'objetivos' => ['pt' => 'Desenvolver algoritmos.', 'en' => 'Develop algorithms.'],
                'conteudo_programatico' => ['pt' => 'Variáveis.', 'en' => 'Variables.'],
            ],
        ];
    }
}
