<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifica a proteção dos POSTs e os links de logs após a atualização do framework.
 *
 * Usa usuário em memória e arquivos temporários, sem banco ou chamadas de IA.
 */
class Laravel13CompatibilityTest extends TestCase
{
    /** @var string|null Diretório temporário de logs removido ao encerrar cada teste. */
    private ?string $logDirectory = null;

    /**
     * Isola os menus e o HTTP para testar as rotas sem serviços externos.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        // Os menus reais podem consultar permissões no banco do ambiente local.
        View::share('menu', []);
        View::share('right_menu', []);
        URL::forceRootUrl('http://localhost');
        Http::preventStrayRequests();
        $this->actingAs((new User)->forceFill(['id' => 1, 'name' => 'Teste', 'email' => 'teste@example.com']));
    }

    /**
     * Remove apenas os arquivos temporários criados pelo teste e encerra a aplicação.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->logDirectory !== null) {
            File::deleteDirectory($this->logDirectory);
        }

        parent::tearDown();
    }

    /**
     * Exercita o middleware real antes da validação de entrada dos serviços de IA.
     *
     * @param string $route Nome da rota autenticada a testar.
     * @param string|null $origin Cabeçalho Sec-Fetch-Site, quando enviado pelo navegador.
     * @param string|null $token Token recebido no cabeçalho X-CSRF-TOKEN.
     * @param int $status Status esperado: bloqueio CSRF ou validação de entrada.
     * @return void
     */
    #[DataProvider('forgeryRequests')]
    public function test_ai_posts_verify_origin_or_csrf_token(string $route, ?string $origin, ?string $token, int $status): void
    {
        // Laravel ignora CSRF no ambiente testing; esta instância precisa executar a proteção.
        $this->app['env'] = 'local';
        $headers = array_filter(['Sec-Fetch-Site' => $origin, 'X-CSRF-TOKEN' => $token]);

        $this->withSession(['_token' => 'valid-test-token'])
            ->postJson(route($route), [], $headers)
            ->assertStatus($status);

        Http::assertNothingSent();
    }

    /**
     * Fornece origens e tokens aceitos e rejeitados nos dois endpoints.
     *
     * @return array<string, array{string, string|null, string|null, int}> Rota, origem, token e status.
     */
    public static function forgeryRequests(): array
    {
        $cases = [];
        foreach (['bibliografia.bibliografia', 'bibliografia.ementa'] as $route) {
            $cases[$route.' missing token'] = [$route, null, null, 419];
            $cases[$route.' invalid token'] = [$route, null, 'invalid-token', 419];
            $cases[$route.' valid token'] = [$route, null, 'valid-test-token', 422];
            $cases[$route.' same origin'] = [$route, 'same-origin', null, 422];
            $cases[$route.' cross site'] = [$route, 'cross-site', null, 419];
        }

        return $cases;
    }

    /**
     * Confere seleção, download e restrição de acesso dos logs pelo controller real.
     *
     * @return void
     */
    public function test_log_links_select_and_download_the_requested_file(): void
    {
        $this->logDirectory = sys_get_temp_dir().'/ai-services-logs-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->logDirectory);
        File::put($this->logDirectory.'/first.log', "[2026-10-13 12:00:00] local.INFO: First file\n");
        File::put($this->logDirectory.'/second.log', "[2026-10-13 12:00:00] local.INFO: Second file\n");
        config(['logviewer.storage_path' => $this->logDirectory]);
        // O hook do Spatie consulta permissões antes do Gate; substitua apenas essa consulta.
        $user = Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('checkPermissionTo')->andReturn(false);
        $this->actingAs($user);
        Gate::define('admin', fn (User $user): bool => true);

        $page = $this->get(route('laravel-tools.logs'))->assertOk();
        $fileLink = $this->linkQuery($page->getContent(), 'l');
        $filename = Crypt::decrypt($fileLink['l']);
        $this->assertNotSame($page->viewData('current_file'), $filename);
        // O controller do pacote captura o request no construtor; renove-o entre requisições.
        $route = $this->app['router']->getRoutes()->getByName('laravel-tools.logs');
        $route->flushController();
        $selected = $this->get(route('laravel-tools.logs', $fileLink))->assertOk();
        $selected->assertViewHas('current_file', $filename);
        $downloadLink = $this->linkQuery($selected->getContent(), 'dl');

        $route->flushController();
        $this->get(route('laravel-tools.logs', $downloadLink))->assertOk()->assertDownload($filename);
        // As ações de limpeza e exclusão devem usar o mesmo contrato sem alterar os arquivos.
        foreach (['clean', 'del'] as $action) {
            $query = $this->linkQuery($selected->getContent(), $action);
            $this->assertSame($filename, Crypt::decrypt($query[$action]));
        }

        // A atualização do visualizador mantém a autorização administrativa da rota.
        Gate::define('admin', fn (User $user): bool => false);
        $route->flushController();
        $this->getJson(route('laravel-tools.logs'))->assertForbidden();
    }

    /**
     * Extrai a query do último link da ação para reenviá-la ao controller do pacote.
     *
     * @param string $html HTML da página ou do parcial de logs.
     * @param string $parameter Primeiro parâmetro da ação procurada.
     * @return array<string, string> Parâmetros decodificados do link encontrado.
     */
    private function linkQuery(string $html, string $parameter): array
    {
        $this->assertGreaterThan(0, preg_match_all('/href="([^"]*\?'.$parameter.'=[^"]+)"/', $html, $matches));
        // O último arquivo difere da seleção automática inicial e verifica a troca efetiva.
        $url = html_entity_decode(end($matches[1]), ENT_QUOTES, 'UTF-8');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }
}
