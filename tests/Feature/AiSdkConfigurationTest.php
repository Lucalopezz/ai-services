<?php

namespace Tests\Feature;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Responses\StructuredAgentResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

use function Laravel\Ai\agent;

class AiSdkConfigurationTest extends TestCase
{
    #[DataProvider('litellmUrls')]
    public function test_sdk_uses_litellm_configuration_for_structured_output(string $url, string $endpoint): void
    {
        config(['ai' => $this->configurationFor($url)]);

        Http::preventStrayRequests();
        Http::fake([
            '*' => Http::response([
                'model' => 'sdk-test-model',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => '{"status":"ok"}',
                    ],
                ]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ]),
        ]);

        $response = agent(
            instructions: 'Responda conforme o schema informado.',
            schema: fn (JsonSchema $schema) => [
                'status' => $schema->string()->enum(['ok'])->required(),
            ],
        )->prompt('Teste de conectividade.', timeout: 60);

        $this->assertInstanceOf(StructuredAgentResponse::class, $response);
        $this->assertSame(['status' => 'ok'], $response->toArray());
        $this->assertSame(15, $response->usage->totalTokens());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) =>
            $request->method() === 'POST'
            && $request->url() === $endpoint
            && $request->hasHeader('Authorization', 'Bearer sdk-test-key')
            && $request['model'] === 'sdk-test-model'
            && $request['response_format']['type'] === 'json_schema'
            && $request['response_format']['json_schema']['schema']['required'] === ['status']
        );
    }

    public static function litellmUrls(): array
    {
        return [
            'root' => ['https://litellm.example.com', 'https://litellm.example.com/v1/chat/completions'],
            'trailing slash' => ['https://litellm.example.com/', 'https://litellm.example.com/v1/chat/completions'],
            'versioned base' => ['https://litellm.example.com/v1', 'https://litellm.example.com/v1/chat/completions'],
            'versioned base with slash' => ['https://litellm.example.com/v1/', 'https://litellm.example.com/v1/chat/completions'],
            'gateway with path' => ['https://litellm.example.com/gateway', 'https://litellm.example.com/gateway/v1/chat/completions'],
        ];
    }

    public function test_missing_litellm_url_is_not_replaced_with_a_relative_endpoint(): void
    {
        $configuration = $this->configurationFor('');

        $this->assertNull($configuration['providers']['litellm']['url']);
    }

    private function configurationFor(string $url): array
    {
        $environment = Env::getRepository();
        $original = [];

        foreach ([
            'AI_PROVIDER' => null,
            'LITELLM_URL' => $url,
            'LITELLM_API_KEY' => 'sdk-test-key',
            'LITELLM_MODEL' => 'sdk-test-model',
        ] as $name => $value) {
            $original[$name] = $environment->get($name);
            $value === null ? $environment->clear($name) : $environment->set($name, $value);
        }

        try {
            return require config_path('ai.php');
        } finally {
            foreach ($original as $name => $value) {
                $value === null ? $environment->clear($name) : $environment->set($name, $value);
            }
        }
    }
}
