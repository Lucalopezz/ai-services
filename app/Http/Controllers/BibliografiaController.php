<?php

namespace App\Http\Controllers;

use App\Ai\Agents\BibliografiaAgent;
use App\Ai\Agents\EmentaAgent;
use App\Models\AiPrompt;
use App\Services\AiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\JsonResponse Retorna View para requisições web ou JsonResponse para APIs.
     *
     * @throws \Illuminate\Validation\ValidationException Se a validação dos dados de entrada falhar.
     * @throws \RuntimeException Quando a resposta do agente não respeitar seu contrato.
     * @throws \Laravel\Ai\Exceptions\AiException Em caso de falha do provider pelo SDK.
     */
    public function bibliografia(Request $request)
    {
        $standards = AiPrompt::getBibliografiaStandards();
        if ($request->isMethod('get')) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Envie uma requisição POST com o campo "referencias" para processar.',
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
            'standard' => ['required','string',Rule::in($standards)
            ],
        ]);

        $referencias = $request->input('message');
        $standard = $request->input('standard');

        $prompt = AiPrompt::systemPrompt('bibliografia', ['standard' => $standard]);
        // O serviço retorna os dados estruturados; o agente fornece o prompt original ao SDK.
        $resultado = $this->aiService->submit(new BibliografiaAgent($standard), $referencias);

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
     * @return \Illuminate\Contracts\View\View|\Illuminate\Http\JsonResponse Formulário ou resultado da revisão.
     * @throws \Illuminate\Validation\ValidationException Quando a entrada não for válida.
     * @throws \RuntimeException Quando a resposta do agente não respeitar seu contrato.
     * @throws \Laravel\Ai\Exceptions\AiException Em caso de falha do provider pelo SDK.
     */
    public function ementa(Request $request)
    {
        if ($request->isMethod('get')) {
            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'mensagem' => 'Envie uma requisição POST com o campo "referencias" para processar.',
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
        // A entrada permanece separada das instruções preservadas pelo EmentaAgent.
        $resultado = $this->aiService->submit(new EmentaAgent, $mensagem);


        // dd($resultado);

        $data = [
            'mensagem'   => $mensagem,
            'resposta'     => $resultado['texto_revisado'] ?? [],
            'explicacoes'   => $resultado['explicacoes'] ?? [],
            'analiseIngles'   => $resultado['analise_ingles'] ?? [],
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
