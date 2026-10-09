# Plano de implementação do Laravel AI SDK

## Objetivo

Adaptar os fluxos atuais de bibliografia e ementa ao Laravel AI SDK, usando agentes próprios com instruções e saída estruturada. Preservar o LiteLLM, o cache, as estatísticas e os contratos consumidos pelas telas e pelas respostas JSON.

Cada requisição executará o agente de forma síncrona e independente.

## Restrição obrigatória — Preservar os prompts existentes

Os textos dos prompts de bibliografia e ementa em `app/Models/AiPrompt.php` devem ser preservados integralmente nesta implementação. Não reescrever, resumir, corrigir, acrescentar ou remover instruções, exemplos ou trechos que descrevem a estrutura JSON.

Os agentes devem reutilizar os prompts por meio de `AiPrompt::systemPrompt()`, mantendo `AiPrompt` como fonte dos textos. A interpolação da norma bibliográfica continua funcionando como hoje. O conteúdo do usuário é enviado separadamente a `prompt()`.

Os schemas devem refletir os formatos já descritos nos prompts. Qualquer incompatibilidade deve ser registrada e discutida antes de alterar um prompt; a migração não autoriza mudanças nesses textos.

## Escopo

Implementar:

- Dois agentes: `BibliografiaAgent` e `EmentaAgent`.
- Instruções específicas em `instructions()`.
- Saída estruturada com `HasStructuredOutput` e `schema()`.
- Configuração do provider LiteLLM em `config/ai.php`.
- Cache e estatísticas compartilhados no `AiService`.
- Tratamento de falhas e validações de negócio.

Ficam fora desta implementação: memória de conversa, tools, streaming, filas, embeddings e busca com RAG. Não há necessidade de criar tabelas de conversas para este fluxo.

## Arquitetura proposta

Fluxo atual:

```text
Controller → AiPrompt → AiService → LiteLLM
```

Fluxo proposto:

```text
Controller → AiService → BibliografiaAgent ou EmentaAgent → Laravel AI SDK → LiteLLM
```

| Parte | Responsabilidade |
| --- | --- |
| Controller | Validar a entrada, escolher o agente e montar a resposta web ou JSON. |
| Agente | Definir instruções e schema específicos do serviço. |
| Laravel AI SDK | Executar o agente, comunicar com o provider e disponibilizar a resposta estruturada e os metadados. |
| AiService | Compartilhar cache, coleta de estatísticas e validações comuns da execução. |
| Aplicação | Conferir regras de negócio e apresentar resultados ou erros. |

Os agentes implementarão apenas `Agent` e `HasStructuredOutput`, usando o trait `Promptable`. Não implementarão `Conversational` nem `HasTools`.

### Funcionamento de uma requisição

1. O controller valida o texto recebido e, no caso da bibliografia, a norma selecionada.
2. O controller cria `BibliografiaAgent($standard)` ou `EmentaAgent` e envia o agente e o texto ao `AiService`.
3. O serviço verifica o cache. Se houver um resultado válido, devolve os dados armazenados e registra o acerto nas estatísticas.
4. Se não houver resultado em cache, o serviço chama `$agent->prompt($input, ...)`. O SDK obtém as instruções por `instructions()` e a estrutura esperada por `schema()`.
5. O serviço obtém o array estruturado com `toArray()`, registra as estatísticas e armazena o resultado válido no cache.
6. O controller usa esse array para preencher os mesmos campos das views e das respostas JSON atuais.

O texto do usuário entra somente como argumento de `prompt()`. Ele não é concatenado ao prompt existente nem armazenado como histórico de conversa. O construtor de bibliografia recebe a norma para a interpolação já existente em `AiPrompt`.

### Papel do schema

Cada agente reúne o prompt que deve usar e o formato que deve retornar. `instructions()` reutiliza o texto original de `AiPrompt`; `schema()` acrescenta uma definição em código dos campos, tipos, objetos, arrays e valores permitidos já descritos nesse texto.

A descrição de JSON dentro do prompt permanece integralmente preservada, mesmo quando também representada pelo schema. Os schemas devem acompanhar os prompts existentes, sem exigir uma reescrita deles.

## Etapa 1 — Instalar e configurar o SDK

### Implementação

- Conferir os requisitos da versão estável de `laravel/ai` escolhida para instalação.
- Ajustar o requisito de PHP do projeto para uma versão compatível. A linha atual do SDK exige PHP 8.3; o projeto permite PHP 8.2 no `composer.json`.
- Instalar o pacote pelo Composer e validar a inicialização da aplicação.
- Publicar somente a configuração do SDK, usando a tag `ai-config`.
- Configurar `litellm` com o driver `openai-compatible` em `config/ai.php`.
- Reaproveitar `LITELLM_URL`, `LITELLM_API_KEY` e `LITELLM_MODEL`.
- Definir o modelo de texto em `ai.providers.litellm.models.text.default`, para que o serviço use a mesma configuração na execução dos dois agentes.
- Conferir a URL base esperada pelo driver. O serviço atual acrescenta `/v1/chat/completions` manualmente.
- Fazer uma chamada com um schema pequeno para verificar suporte a structured output no gateway e no modelo configurado.

### Critério de conclusão

Aplicação inicializando com o SDK instalado e uma resposta estruturada funcionando pelo LiteLLM. Uma chamada comum de texto não comprova suporte ao schema.

### Resultado da implementação

Etapa 1 concluída em 09/10/2026:

- Instalado `laravel/ai` 1.2.0, com requisito estável `^1.2@stable` e PHP `^8.3`.
- Mantido Laravel 12.69.3, sem atualização das demais dependências. `symfony/yaml` passou de dependência de desenvolvimento para dependência de produção do SDK, mantendo sua versão.
- Publicado somente `config/ai.php` e configurado o provider padrão `litellm`, reutilizando as variáveis existentes.
- Normalizada a URL para a base `/v1`, aceitando barra final e evitando duplicar `/v1` no SDK.
- Validada uma chamada real com um schema contendo `status` (enum `ok`) e `value` (inteiro 42). O gateway retornou os valores esperados; o SDK informou 56 tokens.
- Adicionados testes com HTTP simulado para a configuração, o envio de `json_schema` e a conversão da resposta estruturada.
- Preservados os prompts existentes. Os agentes de bibliografia e ementa continuam previstos nas etapas seguintes.

## Etapa 2 — Implementar o agente de bibliografia

### Implementação

- Criar `app/Ai/Agents/BibliografiaAgent.php`.
- Receber a norma bibliográfica no construtor, depois de validada pelo controller.
- Retornar em `instructions()` o texto de `AiPrompt::systemPrompt('bibliografia', ['standard' => $this->standard])`, sem alterar o prompt existente.
- Definir o schema com os mesmos campos utilizados no fluxo atual:

| Campo | Estrutura |
| --- | --- |
| `referencias` | Array de strings com as referências corrigidas. |
| `explicacoes` | Array de strings com os ajustes de cada referência. |
| `busca_dedalus` | Array de objetos com `autor` e `titulo`, ambos strings. |
| `confiancas` | Array de inteiros entre 0 e 100. |

- Declarar os campos obrigatórios no schema.
- Preservar inicialmente as quatro listas para manter o contrato atual.
- Conferir no código se as listas têm a mesma quantidade de elementos. A correspondência entre seus itens também é uma regra de negócio.

### Reutilização do prompt

Trecho previsto dentro de `BibliografiaAgent`, além do método `schema()`:

```php
public function __construct(private string $standard) {}

public function instructions(): string
{
    return AiPrompt::systemPrompt('bibliografia', [
        'standard' => $this->standard,
    ]);
}
```

Importar `App\Models\AiPrompt` no agente. As regras e a interpolação da norma continuam sendo fornecidas pelo método atual.

### Critério de conclusão

Bibliografia processada por um agente com saída estruturada, respeitando a norma recebida e preservando os campos esperados pela aplicação.

### Resultado da implementação

Etapa 2 concluída em 09/10/2026:

- Criado `app/Ai/Agents/BibliografiaAgent.php`, implementando `Agent` e `HasStructuredOutput` com o trait `Promptable`.
- A norma é recebida no construtor e `instructions()` retorna integralmente `AiPrompt::systemPrompt('bibliografia', ['standard' => $this->standard])`. O arquivo `AiPrompt.php` foi preservado.
- Definidos os quatro campos obrigatórios do schema, incluindo `autor` e `titulo` nos objetos de `busca_dedalus` e confiança inteira entre 0 e 100.
- A chamada síncrona `prompt()` delega a execução ao trait do SDK e valida a correspondência das listas antes de devolver `StructuredAgentResponse`. Uma lista ausente, inválida ou de tamanho diferente gera `RuntimeException`.
- Provider, modelo e timeout continuam seguindo os argumentos da chamada ou os padrões do SDK. O agente não fixa a configuração do LiteLLM.
- Validados com HTTP simulado o envio do prompt original para as quatro normas disponíveis, o schema enviado ao provider, os metadados da resposta e a separação entre instruções e entrada do usuário.
- Testadas respostas com listas ausentes, em formato inválido ou com itens a mais ou a menos, usando os fakes do SDK.

O agente pode ser executado diretamente para verificação:

```php
$response = (new BibliografiaAgent($standard))->prompt($referencias);
$resultado = $response->toArray();
```

A integração com cache e estatísticas no `AiService` pertence à etapa 4; a adoção pelos controllers pertence à etapa 5. Esta etapa não altera o fluxo atual das telas.

## Etapa 3 — Implementar o agente de ementa

### Implementação

- Criar `app/Ai/Agents/EmentaAgent.php`.
- Retornar em `instructions()` o texto de `AiPrompt::systemPrompt('ementa')`, sem alterar o prompt existente.
- Definir o schema com os objetos atuais:

| Campo | Estrutura |
| --- | --- |
| `score` | Objeto com `valor`, inteiro entre 0 e 100, e `justificativa`, string. |
| `explicacoes` | Objetos `pt` e `en` com análises de gramática, redação acadêmica e terminologia técnica. |
| `explicacoes.en.status` | Enum com `presente`, `parcial` ou `ausente`. |
| `texto_revisado` | Objetos `ementa`, `objetivos` e `conteudo_programatico`, cada um com strings `pt` e `en`. |

- Declarar os objetos e campos obrigatórios, incluindo os campos aninhados.
- Preservar integralmente as instruções atuais, incluindo os critérios acadêmicos, o significado dos campos e o exemplo de JSON.
- Adicionar o schema em código mantendo também a descrição da estrutura JSON no prompt, conforme a restrição de preservação.

### Reutilização do prompt

Trecho previsto dentro de `EmentaAgent`, além do método `schema()`:

```php
public function instructions(): string
{
    return AiPrompt::systemPrompt('ementa');
}
```

Importar `App\Models\AiPrompt` no agente. A versão inicial utiliza o prompt de ementa atual, sem adicionar novos parâmetros de contexto.

### Critério de conclusão

Ementa revisada pelo agente com estrutura explícita, campos tipados e versões em português e inglês nos campos esperados pela aplicação.

### Resultado da implementação

Etapa 3 concluída em 09/10/2026:

- Criado `app/Ai/Agents/EmentaAgent.php`, implementando `Agent` e `HasStructuredOutput` com o trait `Promptable`.
- `instructions()` retorna integralmente `AiPrompt::systemPrompt('ementa')`, preservando também a descrição e o exemplo de JSON existentes. O arquivo `AiPrompt.php` não foi alterado.
- Definidos `score`, `explicacoes` e `texto_revisado` como objetos obrigatórios, com todos os campos aninhados descritos no prompt.
- A nota é um inteiro entre 0 e 100; o status do inglês aceita somente `presente`, `parcial` ou `ausente`.
- Ementa, objetivos e conteúdo programático exigem versões `pt` e `en`, seguindo a instrução original de traduzir os trechos cujo inglês estiver ausente.
- O agente utiliza a execução síncrona do SDK e os padrões de provider, modelo e timeout, sem adicionar parâmetros de disciplina ou área.
- Validados com HTTP simulado o envio integral do prompt, o schema serializado pelo driver e a conversão da resposta em `StructuredAgentResponse`.
- Testados os três estados do inglês, as notas extremas e o uso dos fakes nativos do SDK. As respostas simuladas verificam o contrato e a integração; a qualidade da revisão e tradução será avaliada com chamadas reais na etapa 6.

O agente pode ser executado diretamente para verificação:

```php
$response = (new EmentaAgent)->prompt($mensagem);
$resultado = $response->toArray();
```

A integração com cache e estatísticas no `AiService` pertence à etapa 4; a adoção pelos controllers pertence à etapa 5.

## Etapa 4 — Adaptar o AiService para executar agentes

### Implementação

- Substituir a chamada manual com `Http::post()` pela execução do agente com `prompt()`.
- Alterar a interface do serviço para receber o agente e o texto de entrada:

```php
public function submit(Agent $agent, string $input): array
```

- Obter os dados da resposta estruturada com `toArray()`.
- Manter `statistics()` para disponibilizar as estatísticas da última execução.
- Preservar o timeout de 60 segundos e a retenção atual do cache.
- Armazenar no cache um array simples com resultado, uso e metadados necessários.
- Versionar a chave do cache e incluir agente, instruções, schema, provider, modelo e entrada.
- Adaptar as estatísticas do SDK às chaves utilizadas nas telas, incluindo `usage.total_tokens`, `duration`, `cached` e `cached_at`.
- Corrigir os problemas atuais: consulta a `$choice` antes de sua definição e perda de `cached_at` nos acertos de cache.
- Aplicar validações comuns antes de armazenar o resultado; respostas inválidas não devem entrar no cache.

### Execução pelo SDK

Trecho previsto dentro do `AiService`, quando não houver resultado em cache:

```php
$response = $agent->prompt(
    $input,
    provider: 'litellm',
    model: config('ai.providers.litellm.models.text.default'),
    timeout: 60,
);

$resultado = $response->toArray();
```

O provider mostrado é o padrão inicial. O serviço deve respeitar o provider selecionado em `config/ai.php` e obter seu modelo correspondente, mantendo a configuração centralizada.

Os dados de uso e metadados são obtidos de `$response` antes de o serviço devolver o array. Esse exemplo mostra somente a execução; cache, estatísticas e tratamento de falhas continuam sendo responsabilidades do serviço. O contrato de `submit()` nesta implementação é receber um agente com saída estruturada e devolver os dados estruturados como array.

### Critério de conclusão

Camada compartilhada pequena, executando agentes e preservando cache e estatísticas. Os agentes definem os schemas e fornecem as instruções reutilizando os textos de `AiPrompt`.

### Resultado da implementação

Etapa 4 concluída em 09/10/2026:

- Adaptado `AiService::submit(Agent $agent, string $input): array` para executar o agente pelo SDK, com timeout de 60 segundos e provider/modelo obtidos de `config/ai.php`.
- A retenção continua sendo obtida de `aiService.cacheRetention`. Retenção zero ou negativa desabilita a leitura e a gravação do cache.
- Versionada a chave com o prefixo `ai:submit:v2:`. O hash inclui classe do agente, instruções, schema, provider, configuração da conexão, modelo e entrada. URLs e credenciais diferentes também separam resultados; os valores não são expostos na chave.
- O cache armazena somente arrays com resultado validado e estatísticas normalizadas. `cached_at` preserva a data da geração original nas leituras seguintes; `duration` mede a execução atual, inclusive nos acertos de cache.
- Normalizados os tokens nas chaves `prompt_tokens`, `completion_tokens` e `total_tokens`, mantendo os campos usados pelas telas. O identificador usa o ID do provider quando disponível ou o ID da invocação do SDK.
- Validada a conclusão normal da resposta e a conformidade com os schemas atuais antes de armazenar: campos obrigatórios, objetos, listas, strings, inteiros, enums e limites numéricos. A correspondência das listas permanece validada pelo agente de bibliografia.
- Respostas inválidas ou incompletas e falhas do provider não são armazenadas. As estatísticas são limpas no início de cada chamada para não exibir dados de uma execução anterior após uma falha.
- Ajustadas as duas chamadas do controller para fornecer os agentes e consumir os arrays retornados. Essa dependência da nova assinatura antecipa a adaptação mínima prevista na etapa 5 e evita deixar os POSTs incompatíveis entre etapas.
- Testados com HTTP simulado a execução, as estatísticas, a persistência e expiração do cache, a separação das chaves, a rejeição de respostas inválidas e os contratos JSON das duas rotas autenticadas. Os fakes nativos do SDK também foram verificados.
- A suíte completa passou com 51 testes e 402 assertions, sem chamadas pagas ou alterações no banco de dados local.
- Os textos de `AiPrompt.php` foram preservados integralmente.

O validador comum cobre os tipos e restrições utilizados pelos dois agentes atuais. Novos tipos ou restrições de schema precisam de suporte explícito antes de ampliar este fluxo.

## Etapa 5 — Integrar os controllers e retirar o fluxo antigo

As chamadas dos controllers e a remoção do `json_decode()` foram antecipadas na etapa 4 para compatibilidade com a nova assinatura. Nesta etapa, concluir o tratamento de erros nas respostas web/JSON, a limpeza da configuração antiga e a verificação da apresentação dos resultados.

### Implementação

- Instanciar `BibliografiaAgent` com a norma selecionada e `EmentaAgent` para a revisão de ementa.
- Passar o texto de entrada diretamente ao serviço. Exemplo de bibliografia:

```php
$resultado = $this->aiService->submit(
    new BibliografiaAgent($standard),
    $referencias,
);
```

Na ementa, a chamada correspondente será:

```php
$resultado = $this->aiService->submit(
    new EmentaAgent,
    $mensagem,
);
```

- Remover a montagem manual de mensagens `system` e `user`.
- Remover o `json_decode()` dos controllers, consumindo o array estruturado.
- Preservar os campos entregues às views e às respostas JSON.
- Manter `AiPrompt` como fonte dos prompts existentes e da lista de normas. Os agentes reutilizam `systemPrompt()`; os controllers deixam de usar a montagem manual de mensagens.
- Remover a configuração antiga de provider de `config/aiService.php`; manter ali apenas opções próprias da aplicação ainda utilizadas, como a retenção do cache.
- Tratar falhas do provider, timeout e resultados inválidos com mensagens compreensíveis na interface e respostas JSON adequadas.

### Critério de conclusão

Os dois fluxos utilizam agentes e saída estruturada, mantendo o contrato externo atual e apresentando falhas de forma explícita.

## Etapa 6 — Validar e documentar

### Implementação

- Usar os fakes do SDK para testar a integração dos agentes com os controllers.
- Verificar a passagem da norma bibliográfica e do texto de entrada.
- Conferir que `instructions()` de cada agente retorna exatamente o prompt atual correspondente, incluindo a interpolação da norma, e que os textos em `AiPrompt.php` não foram modificados.
- Testar cache e confirmar que uma repetição não executa novamente o agente.
- Conferir a separação do cache entre normas, agentes e configurações diferentes.
- Testar tratamento de falhas e regras de negócio, incluindo a correspondência entre as listas de bibliografia.
- Fazer chamadas reais pelo LiteLLM para validar os dois schemas, as estatísticas e o funcionamento das telas.
- Verificar bibliografia com normas diferentes e ementa com inglês presente e ausente.
- Atualizar `.env.example` e README com requisitos e configuração.

Structured output define o formato esperado da resposta. A aplicação continua responsável por verificar regras de negócio e tratar resultados inadequados.

### Critério de conclusão

Migração testada, schemas funcionando no provider real e instruções de configuração atualizadas.

## Referências

- [Laravel AI SDK](https://laravel.com/framework/docs/ai-sdk)
- [Agentes](https://laravel.com/framework/docs/ai-sdk#agents)
- [Saída estruturada](https://laravel.com/framework/docs/ai-sdk#structured-output)
- [Providers compatíveis com OpenAI](https://laravel.com/framework/docs/ai-sdk#openai-compatible-providers)
- [Testes](https://laravel.com/framework/docs/ai-sdk#testing)
- [Requisitos do pacote](https://github.com/laravel/ai/blob/1.x/composer.json)
