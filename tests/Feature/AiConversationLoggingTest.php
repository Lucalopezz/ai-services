<?php

namespace Tests\Feature;

use App\Ai\Agents\BibliografiaAgent;
use App\Ai\Agents\EmentaAgent;
use App\Models\AiPrompt;
use App\Services\AiService;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * Verifica registros reais em arquivo com HTTP simulado, sem serviços pagos.
 *
 * Cada teste usa um arquivo temporário próprio e cache em memória.
 */
class AiConversationLoggingTest extends TestCase
{
    /** @var string Arquivo temporário exclusivo do canal de IA deste teste. */
    private string $logPath;

    /** @var list<array{event: string, data: array<string, mixed>}> Eventos efetivamente escritos pelo canal. */
    private array $loggedRecords = [];

    /**
     * Configura o log em arquivo e um gateway HTTP inteiramente simulado.
     * Isola o armazenamento e observa os eventos emitidos depois da escrita para
     * verificar os dados originais separadamente da apresentação legível no arquivo.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Confere primeiro o destino configurado pela aplicação e depois troca somente
        // o caminho do teste, para não acrescentar registros aos logs reais de desenvolvimento.
        $this->assertSame(storage_path('logs/ai-conversations.log'), config('logging.channels.ai_conversations.path'));
        $this->logPath = tempnam(sys_get_temp_dir(), 'ai-conversation-test-');
        config([
            'ai.default' => 'litellm',
            'ai.providers.litellm' => [
                'driver' => 'openai-compatible',
                'url' => 'https://litellm.example.com/v1',
                'key' => 'secret-not-for-logs',
                'models' => ['text' => ['default' => 'sdk-test-model']],
            ],
            'aiService.cacheRetention' => 60,
            'cache.default' => 'array',
            'logging.ai_conversations_enabled' => true,
            'logging.channels.ai_conversations.path' => $this->logPath,
        ]);
        // Uma simulação incompleta deve falhar localmente, sem alcançar o gateway real.
        Http::preventStrayRequests();
        // MessageLogged só é emitido após escrever. Assim, a inspeção do contexto não
        // depende de reconstruir arrays a partir dos blocos de texto produzidos pelo formatter.
        Event::listen(MessageLogged::class, [$this, 'recordLoggedEvent']);
    }

    /**
     * Libera o canal e exclui somente o arquivo criado por este teste.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        // Descarta o canal antes de remover o arquivo exclusivo deste teste; nenhum
        // teste seguinte deve reutilizar a instância apontando para o caminho anterior.
        Log::forgetChannel('ai_conversations');
        unlink($this->logPath);
        parent::tearDown();
    }

    /**
     * Confere mensagens, schema HTTP real, retorno bruto e resultado recebido no arquivo.
     *
     * @return void
     */
    public function test_file_records_the_actual_request_and_response_without_headers(): void
    {
        $body = self::httpResult();
        Http::fake(['*' => Http::response($body)]);
        $service = new AiService;

        $this->assertSame(self::bibliographyResult(), $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Referência original.'));

        // Os quatro eventos distinguem a entrada lógica da aplicação, o envio efetivo,
        // o retorno antes da validação e o resultado que o serviço aceitou.
        $records = $this->records();
        $this->assertSame(['ai.request', 'ai.http.request', 'ai.http.response', 'ai.result'], array_column($records, 'event'));
        // Compara o payload registrado ao pedido capturado pelo HTTP fake, em vez
        // de inferir como o SDK deveria montar mensagens ou response_format.
        [$request] = Http::recorded()->first();
        $this->assertSame($request->data(), $records[1]['data']['payload']);
        $this->assertSame([
            ['role' => 'system', 'content' => AiPrompt::systemPrompt('bibliografia', ['standard' => 'APA 7th Edition'])],
            ['role' => 'user', 'content' => 'Referência original.'],
        ], $records[1]['data']['payload']['messages']);
        $this->assertSame('json_schema', $records[1]['data']['payload']['response_format']['type']);
        $this->assertSame(200, $records[2]['data']['status']);
        $this->assertSame($body, json_decode($records[2]['data']['body'], true));
        $this->assertSame(self::bibliographyResult(), $records[3]['data']['result']);
        $this->assertSame($service->statistics(), $records[3]['data']['statistics']);
        $this->assertCount(1, array_unique(array_column(array_column($records, 'data'), 'request_id')));
        $this->assertStringNotContainsString('secret-not-for-logs', file_get_contents($this->logPath));
        $this->assertStringNotContainsString('Authorization', file_get_contents($this->logPath));

        // Além do contexto correto, verifica a saída real do formatter: cabeçalho,
        // prompts em várias linhas, schema indentado e JSON interno sem escapes duplicados.
        $log = file_get_contents($this->logPath);
        $this->assertStringContainsString("INFO: ai.request\nrequest_id: ", $log);
        $this->assertStringContainsString("agent: App\\Ai\\Agents\\BibliografiaAgent\n", $log);
        $this->assertStringContainsString("            role: system\n            content:\n                You are", $log);
        $this->assertStringContainsString("schema:\n    {\n", $log);
        $this->assertStringContainsString("body:\n    id: test-response-id\n", $log);
        $this->assertStringContainsString("                        [0]: Referência revisada.", $log);
        $this->assertStringNotContainsString('\\"referencias\\"', $log);

        // A captura encerrada com sucesso também exclui chamadas HTTP de outros serviços.
        Http::get('https://unrelated.example.com');
        $this->assertSame($records, $this->records());
    }

    /**
     * Confere que o formatter preserva todos os níveis do schema e resultado de ementa.
     *
     * @return void
     */
    public function test_file_preserves_the_nested_course_description_payload(): void
    {
        // A ementa exercita mais níveis de aninhamento que a bibliografia. Verificar
        // seus campos profundos detecta cortes causados pelos limites do Monolog.
        $analysis = [
            'gramatica_acentuacao_pontuacao' => 'Pontuação revisada.',
            'redacao_academica_clareza_pedagogica' => 'Texto claro.',
            'terminologia_tecnica' => 'Terminologia consistente.',
        ];
        $bilingual = ['pt' => 'Texto revisado.', 'en' => 'Revised text.'];
        $result = [
            'score' => ['valor' => 90, 'justificativa' => 'Boa qualidade.'],
            'explicacoes' => ['pt' => $analysis, 'en' => ['status' => 'ausente', ...$analysis]],
            'texto_revisado' => [
                'ementa' => $bilingual,
                'objetivos' => $bilingual,
                'conteudo_programatico' => $bilingual,
            ],
        ];
        $body = self::httpResult();
        $body['choices'][0]['message']['content'] = json_encode($result);
        Http::fake(['*' => Http::response($body)]);

        (new AiService)->submit(new EmentaAgent, 'Ementa: programação.');

        [$request] = Http::recorded()->first();
        $records = $this->records();
        $this->assertSame($request->data(), $records[1]['data']['payload']);
        $this->assertSame($request['response_format']['json_schema']['schema'], $records[0]['data']['schema']);
        $this->assertSame($result, $records[3]['data']['result']);
        // O arquivo deve conter os campos mais profundos do schema, sem truncamento do Monolog.
        $this->assertStringContainsString('"conteudo_programatico": {', file_get_contents($this->logPath));
        $this->assertStringContainsString("            en: Revised text.\n", file_get_contents($this->logPath));
    }

    /**
     * Confere que um acerto de cache tem identificador próprio e não inventa tráfego HTTP.
     *
     * @return void
     */
    public function test_cache_hit_records_the_reused_result_without_another_http_request(): void
    {
        Http::fake(['*' => Http::response(self::httpResult())]);
        (new AiService)->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        // Uma segunda instância deve ler o mesmo registro do cache, mas abrir uma
        // identificação de log própria, pois se trata de outra execução do serviço.
        $service = new AiService;
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');

        $records = $this->records();
        $this->assertSame([
            'ai.request', 'ai.http.request', 'ai.http.response', 'ai.result', 'ai.request', 'ai.cache_hit',
        ], array_column($records, 'event'));
        $this->assertNotSame($records[0]['data']['request_id'], $records[4]['data']['request_id']);
        $this->assertSame($records[4]['data']['request_id'], $records[5]['data']['request_id']);
        $this->assertSame(self::bibliographyResult(), $records[5]['data']['result']);
        $this->assertTrue($records[5]['data']['statistics']['cached']);
        Http::assertSentCount(1);
    }

    /**
     * Confere que desligar a opção impede registrar tanto sucesso quanto falha.
     *
     * @return void
     */
    public function test_disabled_logging_does_not_write_conversation_content(): void
    {
        // Exercita sucesso e falha com a mesma opção desligada: nenhum caminho
        // deve registrar conteúdo, mesmo que os listeners HTTP continuem disponíveis.
        config(['logging.ai_conversations_enabled' => false]);
        Http::fake(['*' => Http::sequence()->push(self::httpResult())->pushStatus(503)]);
        $service = new AiService;
        $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');

        try {
            $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto B');
            $this->fail('A falha HTTP deveria ser propagada.');
        } catch (\Laravel\Ai\Exceptions\ProviderOverloadedException) {
            $this->assertSame('', file_get_contents($this->logPath));
        }
    }

    /**
     * Confere a captura de respostas rejeitadas e a limpeza do contexto após a falha.
     *
     * @param array<string, mixed>|string $body Resposta bruta simulada, válida ou inválida.
     * @param int $status Status HTTP simulado.
     * @return void
     */
    #[DataProvider('failedResponses')]
    public function test_failed_responses_are_logged_before_rejection_and_context_is_cleared(array|string $body, int $status): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        $service = new AiService;
        $failure = null;

        try {
            $service->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        // A falha continua visível ao chamador. O corpo deve ter sido registrado pelo
        // evento HTTP antes de o SDK, o serviço ou o agente rejeitar a resposta.
        $this->assertNotNull($failure);
        $this->assertNull($service->statistics());
        $records = $this->records();
        $this->assertSame(['ai.request', 'ai.http.request', 'ai.http.response', 'ai.error'], array_column($records, 'event'));
        $this->assertSame($status, $records[2]['data']['status']);
        $this->assertSame($failure::class, $records[3]['data']['exception']);
        $this->assertSame($failure->getMessage(), $records[3]['data']['message']);
        if (is_string($body)) {
            $this->assertSame($body, $records[2]['data']['body']);
            $this->assertStringContainsString('body: '.$body, file_get_contents($this->logPath));
        } else {
            $this->assertSame($body, json_decode($records[2]['data']['body'], true));
        }

        // O cliente HTTP fora do serviço não deve herdar a captura encerrada com erro.
        Http::get('https://unrelated.example.com');
        $this->assertSame($records, $this->records());
    }

    /**
     * Fornece falhas do provider, JSON inválido, interrupção e listas sem correspondência.
     *
     * @return array<string, array{array<string, mixed>|string, int}> Corpo e status HTTP simulados.
     */
    public static function failedResponses(): array
    {
        // Cobre rejeições em etapas diferentes: transporte HTTP, leitura de JSON,
        // término da geração e correspondência das listas exigida pelo agente.
        $invalidJson = self::httpResult();
        $invalidJson['choices'][0]['message']['content'] = 'Resposta sem JSON.';
        $incomplete = self::httpResult();
        $incomplete['choices'][0]['finish_reason'] = 'length';
        $unmatchedLists = self::httpResult();
        $unmatchedLists['choices'][0]['message']['content'] = json_encode([...self::bibliographyResult(), 'explicacoes' => []]);

        return [
            'provider failure' => [['error' => ['message' => 'Serviço indisponível.']], 503],
            'malformed HTTP body' => ['Resposta HTTP sem JSON.', 200],
            'invalid completion JSON' => [$invalidJson, 200],
            'incomplete completion' => [$incomplete, 200],
            'unmatched bibliography lists' => [$unmatchedLists, 200],
        ];
    }

    /**
     * Confere registro de falha de conexão e recuperação da execução seguinte.
     *
     * @return void
     */
    public function test_connection_failure_is_logged_and_the_next_call_has_a_new_context(): void
    {
        // O primeiro pedido falha sem resposta HTTP; o seguinte responde normalmente.
        // A sequência permite conferir que a captura anterior foi encerrada no finally.
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('Conexão de teste indisponível.')
            ->push(self::httpResult())]);
        $failure = null;
        try {
            (new AiService)->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure);
        $records = $this->records();
        $this->assertSame(['ai.request', 'ai.http.request', 'ai.http.connection_failed', 'ai.error'], array_column($records, 'event'));

        $this->assertSame(self::bibliographyResult(), (new AiService)->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A'));
        $records = $this->records();
        $this->assertNotSame($records[0]['data']['request_id'], $records[4]['data']['request_id']);
        $this->assertSame('ai.result', $records[7]['event']);
    }

    /**
     * Confere que fakes nativos do SDK geram registros de aplicação sem tráfego HTTP.
     *
     * @return void
     */
    public function test_sdk_fake_records_the_result_without_fabricating_http_events(): void
    {
        // O fake nativo retorna dados do agente sem passar pelo cliente HTTP. O log
        // deve representar esse fluxo com entrada e resultado, sem inventar envio ou retorno HTTP.
        BibliografiaAgent::fake([self::bibliographyResult()]);
        (new AiService)->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A');

        $this->assertSame(['ai.request', 'ai.result'], array_column($this->records(), 'event'));
        Http::assertNothingSent();
    }

    /**
     * Confere que o log usa linhas reais, preservando barras, aspas e escapes literais.
     *
     * @return void
     */
    public function test_multiline_content_is_readable_without_unescaping_literal_characters(): void
    {
        BibliografiaAgent::fake([self::bibliographyResult()]);
        // Mistura finais de linha reais com uma sequência literal de barra+n e um caminho.
        // Isso detecta substituições indiscriminadas que tornariam legível o log alterando o texto.
        $input = "Primeira linha.\r\nSegunda linha.\nLiteral \\n e C:\\arquivo\\teste.txt \"citação\".";
        (new AiService)->submit(new BibliografiaAgent('APA 7th Edition'), $input);

        $this->assertSame($input, $this->records()[0]['data']['messages'][1]['content']);
        $this->assertStringContainsString(
            "        content:\n            Primeira linha.\n            Segunda linha.\n            Literal \\n e C:\\arquivo\\teste.txt \"citação\".\n",
            file_get_contents($this->logPath),
        );
    }

    /**
     * Confere que falhas na escrita do log não interrompem a execução da IA.
     *
     * @return void
     */
    public function test_log_write_failure_does_not_change_the_service_result(): void
    {
        // Simula a indisponibilidade do canal sem depender das permissões do sistema.
        // O serviço ainda precisa devolver o resultado válido do agente.
        Log::shouldReceive('forgetChannel')->with('ai_conversations')->once();
        Log::shouldReceive('channel')->with('ai_conversations')->andThrow(new \RuntimeException('Log indisponível.'));
        BibliografiaAgent::fake([self::bibliographyResult()]);

        $this->assertSame(self::bibliographyResult(), (new AiService)->submit(new BibliografiaAgent('APA 7th Edition'), 'Texto A'));
    }

    /**
     * Captura o contexto original após a gravação, independente da apresentação em texto.
     *
     * @param MessageLogged $event Evento emitido pelo Laravel após escrever o registro.
     * @return void
     */
    public function recordLoggedEvent(MessageLogged $event): void
    {
        // Filtra pelos nomes ai.* para não misturar logs gerais do Laravel com a sequência
        // de eventos emitida pelo logger desta implementação.
        if (str_starts_with($event->message, 'ai.')) {
            $this->loggedRecords[] = ['event' => $event->message, 'data' => $event->context];
        }
    }

    /**
     * Obtém os eventos emitidos após a gravação do log, preservando seus dados originais.
     *
     * @return list<array{event: string, data: array<string, mixed>}> Eventos na ordem de gravação.
     */
    private function records(): array
    {
        return $this->loggedRecords;
    }

    /**
     * Monta o envelope HTTP esperado pelo driver compatível com OpenAI.
     *
     * @return array<string, mixed> Resposta simulada com texto estruturado e tokens.
     */
    private static function httpResult(): array
    {
        return [
            'id' => 'test-response-id',
            'model' => 'backend-model',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => json_encode(self::bibliographyResult())],
            ]],
            'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10],
        ];
    }

    /**
     * Fornece dados bibliográficos válidos com um item correspondente em cada lista.
     *
     * @return array<string, mixed> Resultado válido do agente de bibliografia.
     */
    private static function bibliographyResult(): array
    {
        return [
            'referencias' => ['Referência revisada.'],
            'explicacoes' => ['Pontuação revisada.'],
            'busca_dedalus' => [['autor' => 'Autor', 'titulo' => 'Título']],
            'confiancas' => [90],
        ];
    }
}
