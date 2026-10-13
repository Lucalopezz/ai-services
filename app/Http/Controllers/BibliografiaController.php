<?php

namespace App\Http\Controllers;

use App\Ai\Agents\BibliografiaAgent;
use App\Ai\Agents\EmentaAgent;
use App\Models\AiPrompt;
use App\Services\AiService;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use JsonException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use RuntimeException;
use Throwable;

/**
 * Valida os formulários de bibliografia e ementa e apresenta os resultados web ou JSON.
 */
class BibliografiaController extends Controller
{

    /**
     * Recebe o serviço compartilhado de execução dos agentes.
     *
     * @param AiService $aiService Serviço de IA com cache e estatísticas.
     * @return void
     */
    public function __construct(private AiService $aiService) {}

    /**
     * Processa a lista de referências bibliográficas utilizando o serviço de IA.
     *
     * Este método aceita requisições convencionais (Web) e requisições via API (JSON),
     * retornando uma View renderizada ou um payload JSON estruturado com base no
     * cabeçalho `Accept` fornecido na requisição.
     *
     * @param  \Illuminate\Http\Request  $request Requisição com 'message' e 'standard' no POST.
     * @return \Illuminate\Contracts\View\View|JsonResponse|RedirectResponse Formulário, resultado ou erro de processamento.
     *
     * @throws \Illuminate\Validation\ValidationException Se a validação dos dados de entrada falhar.
     */
    public function bibliografia(Request $request)
    {
        $standards = AiPrompt::getBibliografiaStandards();
        if ($request->isMethod('get')) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Envie uma requisição POST com os campos "message" e "standard" para processar.',
                ], 200);
            }

            return view(
                'bibliografia.index',
                [
                    'standards' => $standards,
                ]
            );
        }

        $request->validate([
            'message' => ['required', 'string', 'max:10000'],
            'standard' => ['required', 'string', Rule::in($standards)],
        ]);

        $referencias = $request->input('message');
        $standard = $request->input('standard');

        $prompt = AiPrompt::systemPrompt('bibliografia', ['standard' => $standard]);
        try {
            // O agente fornece o prompt original; o serviço devolve o array validado.
            $resultado = $this->aiService->submit(new BibliografiaAgent($standard), $referencias);
        } catch (AiException|RequestException|ConnectionException|RuntimeException|InvalidArgumentException|JsonException $exception) {
            return $this->aiFailureResponse($request, $exception);
        }

        $data = [
            'referencias'   => $referencias,
            'respostas'     => $resultado['referencias'] ?? [],
            'explicacoes'   => $resultado['explicacoes'] ?? [],
            'confiancas'    => $resultado['confiancas'] ?? [],
            'estatisticas'  => $this->aiService->statistics(),
            'prompt'        => $prompt,
            'standards'      => $standards,
            'standard'      => $standard,
        ];

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'data' => $data,], 200);
        }

        return view('bibliografia.index', $data);
    }


    /**
     * Valida e revisa a ementa, apresentando os dados estruturados em uma view ou JSON.
     *
     * @param Request $request Requisição com o campo 'mensagem' no POST.
     * @return \Illuminate\Contracts\View\View|JsonResponse|RedirectResponse Formulário, revisão ou erro de processamento.
     * @throws \Illuminate\Validation\ValidationException Quando a entrada não for válida.
     */
    public function ementa(Request $request)
    {
        if ($request->isMethod('get')) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'mensagem' => 'Envie uma requisição POST com o campo "mensagem" para processar.',
                ], 200);
            }

            $standards = AiPrompt::getBibliografiaStandards();

            return view('ementa.index', compact('standards'));
        }

        $request->validate([
            'mensagem' => ['required', 'string', 'max:10000'],
        ]);

        $mensagem = $request->input('mensagem');

        $prompt = AiPrompt::systemPrompt('ementa');
        try {
            // A entrada permanece separada das instruções preservadas pelo agente.
            $resultado = $this->aiService->submit(new EmentaAgent, $mensagem);
        } catch (AiException|RequestException|ConnectionException|RuntimeException|InvalidArgumentException|JsonException $exception) {
            return $this->aiFailureResponse($request, $exception);
        }

        $data = [
            'mensagem'   => $mensagem,
            'resposta'     => $resultado['texto_revisado'] ?? [],
            'explicacoes'   => $resultado['explicacoes'] ?? [],
            // Mantém a chave pública existente, usando o campo efetivo do schema.
            'analiseIngles'   => $resultado['explicacoes']['en'],
            'score'    => $resultado['score'] ?? [],
            'estatisticas'  => $this->aiService->statistics(),
            'prompt'        => $prompt,
        ];

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'data' => $data,], 200);
        }

        return view('ementa.index', $data);
    }

    /**
     * Converte uma falha da execução de IA em uma mensagem web ou JSON apropriada.
     *
     * @param Request $request Requisição original usada para escolher o formato e preservar a entrada.
     * @param Throwable $exception Falha do provider, configuração ou validação da resposta de IA.
     * @return JsonResponse|RedirectResponse Erro JSON ou retorno ao formulário com mensagem e entrada.
     */
    private function aiFailureResponse(Request $request, Throwable $exception): JsonResponse|RedirectResponse
    {
        // As mensagens do provider podem conter detalhes da conexão. A resposta
        // pública e o registro usam mensagens próprias e somente a classe do erro.
        Log::warning('Falha no processamento de IA.', [
            'exception_type' => $exception::class,
            'route' => $request->route()->getName(),
        ]);

        [$status, $message] = match (true) {
            $this->isTimeout($exception) => [504, 'O serviço de IA não respondeu a tempo. Tente novamente.'],
            $exception instanceof RateLimitedException => [429, 'O serviço de IA atingiu o limite de solicitações. Aguarde e tente novamente.'],
            $exception instanceof ProviderConnectionException,
            $exception instanceof ConnectionException => [503, 'Não foi possível conectar ao serviço de IA. Tente novamente mais tarde.'],
            $exception instanceof ProviderOverloadedException,
            $exception instanceof InsufficientCreditsException,
            $exception instanceof InvalidArgumentException,
            $exception instanceof JsonException => [503, 'O serviço de IA está indisponível no momento. Tente novamente mais tarde.'],
            $exception instanceof RuntimeException => [502, 'A IA retornou uma resposta inválida ou incompleta. Tente novamente.'],
            default => [502, 'Não foi possível processar a solicitação no serviço de IA. Tente novamente.'],
        };

        if ($request->wantsJson()) {
            return response()->json(['success' => false, 'message' => $message], $status);
        }

        // Os alertas do tema exibem o erro; somente os campos dos formulários
        // são preservados na sessão, sem reaproveitar tokens ou outros parâmetros.
        return redirect()->route($request->route()->getName())
            ->withInput($request->only(['message', 'standard', 'mensagem']))
            ->withErrors(['ai' => $message]);
    }

    /**
     * Identifica timeout nas causas originais mantidas pelo SDK e pelo cliente HTTP.
     *
     * @param Throwable $exception Falha que pode envolver um timeout de conexão ou resposta.
     * @return bool True quando houver timeout cURL ou status HTTP 408/504 na cadeia de causas.
     */
    private function isTimeout(Throwable $exception): bool
    {
        // O SDK envolve os erros HTTP; percorra as causas sem analisar ou expor seu texto.
        do {
            if ($exception instanceof ConnectException && ($exception->getHandlerContext()['errno'] ?? null) === 28) {
                return true;
            }
            if ($exception instanceof RequestException && in_array($exception->response->status(), [408, 504], true)) {
                return true;
            }
        } while ($exception = $exception->getPrevious());

        return false;
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
