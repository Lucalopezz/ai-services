@extends('layouts.app')

@section('content')
  <div class="card">
    <div class="card-header card-header-sticky">
      <div class="h4 mb-0">
        Revisor de ementas e conteúdo pedagógico
        @include('ementa.partials.ajuda', ['button' => 1])
      </div>
      @include('ementa.partials.ajuda')

    </div>

    <div class="card-body">

      @include('ementa.partials.form')

      @if ($resposta ?? null)
        <hr>
        <h5 class="mb-3" id="resultados">
          Resultados
          @include('bibliografia.partials.estatisticas')
        </h5>

        <style>
          #explicacao,
          .explicacao {
            height: auto !important;
            /* Permite que o elemento cresça livremente */
            max-height: none !important;
            /* Impede travamento de altura máxima */
            min-height: fit-content;
            /* Ajusta a altura mínima ao conteúdo interno */
            overflow-y: visible !important;
            /* Remove barras de rolagem internas (scrollbars) */
          }
        </style>

        <div class="row mb-4">
          <div class="col">
            <label class="ml-2 font-weight-bold" for="explicacao">Explicações e qualidade da entrada</label>
            <div id="explicacao" class="form-control explicacao">

              <div class="mb-4 pb-2 border-bottom">
                <div class="font-weight-bold text-primary">Score: {{ $score['valor'] }}/100</div>
                <div>{{ $score['justificativa'] }}</div>
              </div>

              <div class="d-flex align-items-center justify-content-start gap-2">
                <span class="font-weight-bold text-primary mb-0">Português</span> /
                <span class="font-weight-bold text-primary">Inglês</span>
                @include('ementa.partials.ingles-presente-badge')
              </div>

              <div class="explicacao-item mb-2" data-index="1">
                <div class="font-weight-bold">Gramática, Acentuação e Pontuação</div>
                <div>
                  <span class="badge badge-primary">PT</span>
                  {{ $explicacoes['pt']['gramatica_acentuacao_pontuacao'] }}
                </div>
                <div>
                  <span class="badge badge-primary">EN</span>
                  {{ $explicacoes['en']['gramatica_acentuacao_pontuacao'] }}
                </div>
              </div>

              <div class="explicacao-item mb-2" data-index="2">
                <div class="font-weight-bold">Redação Acadêmica e Clareza Pedagógica</div>
                <div>
                  <span class="badge badge-primary">PT</span>
                  {{ $explicacoes['pt']['redacao_academica_clareza_pedagogica'] }}
                </div>
                <div>
                  <span class="badge badge-primary">EN</span>
                  {{ $explicacoes['en']['redacao_academica_clareza_pedagogica'] }}
                </div>
              </div>

              <div class="explicacao-item mb-2" data-index="3">
                <div class="font-weight-bold">Terminologia Técnica e Vocabulário</div>
                <div>
                  <span class="badge badge-primary">PT</span>
                  {{ $explicacoes['pt']['terminologia_tecnica'] }}
                </div>
                <div>
                  <span class="badge badge-primary">EN</span>
                  {{ $explicacoes['en']['terminologia_tecnica'] }}
                </div>
              </div>
            </div>

          </div>
        </div>
    </div>

    <div class="row">
      <div class="col-md-6">
        <label class="ml-2 font-weight-bold">Alterações</label>
        <div id="referencias-diff" class="form-control diff-container"></div>
      </div>

      <div class="col-md-6">
        <div class="form-group">
          <div>
            <label class="mx-2 font-weight-bold" for="resposta">Texto corrigido</label>
            <span class="pb-2">@include('ementa.partials.copiar-btn')</span>
          </div>

          <div id="resposta" class="form-control resposta-editavel usptheme-contador" contenteditable="true">
            <div class="">
              <b>Ementa</b>
              {{-- Mantém as quebras de linha e apresenta o conteúdo como texto. --}}
              <div class="mb-2">{!! nl2br(e($resposta['ementa']['pt'])) !!}</div>
              <div class="font-italic mb-2" lang="en" spellcheck="true">{!! nl2br(e($resposta['ementa']['en'])) !!}</div>
            </div>
            <div class="">
              <br>
              <b>Objetivos</b>
              <div class="mb-2">{!! nl2br(e($resposta['objetivos']['pt'])) !!}</div>
              <div class="font-italic mb-2" lang="en" spellcheck="true">{!! nl2br(e($resposta['objetivos']['en'])) !!}</div>
            </div>
            <div class="">
              <br>
              <b>Conteúdo Programático</b>
              <div class="mb-2">{!! nl2br(e($resposta['conteudo_programatico']['pt'])) !!}</div>
              <div class="font-italic mb-2" lang="en" spellcheck="true">{!! nl2br(e($resposta['conteudo_programatico']['en'])) !!}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  @else
    <div id="error-bibliografia" class="alert alert-info">
      @foreach ($explicacoes ?? [] as $index => $texto)
        <div class="explicacao-item" data-index="{{ $index }}">{{ $index }}: {{ $texto }}
        </div>
      @endforeach
    </div>
    @endif

    {{-- @if ($estatisticas ?? [])
        <hr>
        <div class="small text-muted">
          <pre>Estatísticas:
            {{ print_r($estatisticas, true) }}
            Prompt:
            {{ $prompt ?? '' }}
        </pre>
        </div>
      @endif --}}

  </div>
@endsection

@push('scripts')
  @include('partials.height-sync')
  @include('partials.diff-viewer')
  @include('partials.cross-highlight')

  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const original = document.getElementById('mensagem');
      const resposta = document.getElementById('resposta');
      const diff = document.getElementById('referencias-diff');
      // O destaque cruzado usa o painel de explicações da revisão.
      const explicacao = document.getElementById('explicacao');

      if (!original || !resposta || !diff) return;

      // 1. Sincroniza apenas as alturas de 'diff' e 'resposta' (explicacao removida do array)
      UIHeightSync.iniciar([diff, resposta]);

      // 2. Inicia os destaques ao passar o mouse (continua utilizando a explicacao normalmente)
      if (explicacao) {
        UICrossHighlight.iniciar(resposta, explicacao);
      }

      // 3. Atualiza o Diff passando os elementos diretamente
      const executarDiff = () => {
        UIDiffViewer.atualizar(original, diff, resposta);
      };

      resposta.addEventListener('input', executarDiff);

      // Execução inicial
      executarDiff();
    });
  </script>
@endpush
