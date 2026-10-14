# USP AI Services (Portal & API)

[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](https://opensource.org/license/mit)

---

## 🛠️ Módulos de Serviços

### 1. Formatação e Normalização Bibliográfica
Ajuste e padronização automática de referências bibliográficas de acordo com normas acadêmicas (ABNT NBR 6023, APA, etc.), com tratamento inteligente de ruídos, autores, intervalos de páginas e extração de dados para consulta ao catálogo Dedalus.

### 2. Revisão de Ementas
Revisão de ementa, objetivos e conteúdo programático em português e inglês, com avaliação de qualidade, explicações e tradução dos trechos cujo inglês estiver ausente.

---

## 🚀 Arquitetura e Rotas

Os dois serviços usam as mesmas rotas para formulário web e resposta JSON:

| Rota | Entrada no POST | Funcionamento |
| --- | --- | --- |
| `/bibliografia` | `message` e `standard` | Formata referências na norma selecionada. |
| `/ementa` | `mensagem` | Revisa o texto acadêmico e suas versões em português e inglês. |

GET exibe o formulário; com `Accept: application/json`, informa os campos esperados no POST. POST devolve a tela de resultados ou JSON, conforme o mesmo cabeçalho. O texto de entrada tem limite de 10.000 caracteres.

```text
Controller → AiService (cache e estatísticas) → Agente → Laravel AI SDK → LiteLLM
```

---

## 💻 Instalação e Configuração Local

### Pré-requisitos

* PHP 8.3 ou superior
* Composer
* Laravel 13
* Banco de Dados (MySQL / PostgreSQL / SQLite)
* Gateway LiteLLM com modelo que aceite saída estruturada via `json_schema`

### Passo a Passo

```bash
    git clone git@github.com:uspdev/ai-services ai-services
    cd ai-services
    composer install
    cp .env.example .env
    php artisan key:generate
```

### Senha única

Cadastre a URL de callback no configurador de Senha Única com o caminho `/callback`. Guarde o `callback_id` para preencher `SENHAUNICA_CALLBACK_ID` no `.env` da aplicação.

### Configuração de IA

Preencha as variáveis no `.env` com os valores do seu gateway:

```dotenv
AI_PROVIDER=litellm
LITELLM_URL=http://localhost:4000
LITELLM_API_KEY=
LITELLM_MODEL=gemini-flash
AI_CACHE_RETENTION=604800
```

| Variável | Uso |
| --- | --- |
| `AI_PROVIDER` | Nome do provider em `config/ai.php`; o padrão é `litellm`. |
| `LITELLM_URL` | URL base do gateway, sem `/chat/completions`. |
| `LITELLM_API_KEY` | Chave de acesso ao gateway. |
| `LITELLM_MODEL` | Alias de modelo exposto pelo LiteLLM; `gemini-flash` é o exemplo do `.env.example`. |
| `AI_CACHE_RETENTION` | Retenção das respostas validadas em segundos; padrão de sete dias. Zero ou valor negativo desabilita o cache. |

`config/ai.php` reúne provider, driver, URL, chave e modelo. A configuração do projeto acrescenta `/v1` para manter o endpoint `/v1/chat/completions` já utilizado; bases com `/v1` também são aceitas sem duplicação. Esse prefixo é uma escolha desta integração, não uma exigência do driver do SDK.

`config/aiService.php` mantém somente a retenção do cache. O armazenamento é escolhido por `config/cache.php`, que neste projeto lê `CACHE_DRIVER`. O cache só guarda respostas validadas, separadas por agente, prompt, schema, provider, configuração, modelo e entrada.

### Agentes e saída estruturada

`BibliografiaAgent` recebe a norma no construtor e retorna `referencias`, `explicacoes`, `busca_dedalus` e `confiancas`. A aplicação aceita as normas listadas em `AiPrompt::getBibliografiaStandards()`.

`EmentaAgent` retorna `score`, `explicacoes` e `texto_revisado`, com os campos em português e inglês. O status do inglês pode ser `presente`, `parcial` ou `ausente`.

Os dois agentes obtêm as regras de conteúdo em `app/Models/AiPrompt.php`. A estrutura de retorno é definida somente nos métodos `schema()` dos agentes e enviada pelo SDK como `response_format: json_schema`; os prompts não repetem exemplos de JSON nem exigências de serialização. As regras bibliográficas, o significado dos campos, a correspondência das listas, a avaliação acadêmica e a tradução continuam nas instruções. As chamadas são síncronas, com timeout de 60 segundos, sem memória de conversa, tools, streaming ou filas.

Em código, utilize o serviço para obter dados validados e reaproveitar o cache:

```php
use App\Ai\Agents\BibliografiaAgent;
use App\Ai\Agents\EmentaAgent;
use App\Services\AiService;

$service = app(AiService::class);
$bibliografia = $service->submit(
    new BibliografiaAgent('ABNT NBR 6023:2025'),
    $referencias,
);
$estatisticas = $service->statistics();

$ementa = $service->submit(new EmentaAgent, $mensagem);
```

As estatísticas da última chamada incluem tokens de entrada, saída e total, provider, modelo, identificador, motivo de término, duração, `cached` e `cached_at`. Nos acertos de cache, os tokens são os da geração original e `cached_at` mantém a data dessa geração.

### Sistema de logs

Os logs gerais ficam em `storage/logs/laravel.log`; a captura das chamadas de IA usa `storage/logs/ai-conversations.log`, e a integração com o Replicado usa `storage/logs/replicado.log`. O visualizador está disponível em `/laravel-tools/logs` para usuários autorizados pelo Gate `admin`.

Consulte o [guia do sistema de logs](docs/sistema-de-logs.md) para configurar canais e níveis, acompanhar os arquivos, entender os eventos de IA, usar o visualizador e administrar a retenção.

### Log de pedidos e retornos da IA

Para inspecionar as chamadas, configure no `.env`:

```dotenv
AI_CONVERSATION_LOG_ENABLED=true
# Use zero para testar novas respostas em toda solicitação, sem reutilizar o cache.
AI_CACHE_RETENTION=0
```

Depois de alterar a configuração, execute `php artisan config:clear`. Faça uma solicitação de bibliografia ou ementa e acompanhe o arquivo:

```bash
tail -f storage/logs/ai-conversations.log
```

O canal dedicado mantém o cabeçalho do Laravel, com data, nível e evento. A identificação da execução aparece primeiro, seguida de blocos indentados para mensagens, payload, resposta e estatísticas. Prompts e textos usam quebras de linha reais; schemas usam JSON com indentação. Cada execução recebe um `request_id`, compartilhado pelos seus registros:

| Evento | Conteúdo |
| --- | --- |
| `ai.request` | Agente, provider, modelo, instruções de sistema, entrada do usuário e schema solicitado pela aplicação. |
| `ai.http.request` | Método, URL e payload HTTP real montado pelo SDK, incluindo `messages` e `response_format`. |
| `ai.http.response` | Status e corpo bruto recebidos, antes de interpretar JSON ou validar os campos. |
| `ai.result` | Resultado validado e estatísticas da geração. |
| `ai.cache_hit` | Resultado e estatísticas reaproveitados, sem nova comunicação com a IA. |
| `ai.http.connection_failed` | Falha de conexão com o provider. |
| `ai.error` | Classe e mensagem interna da falha de execução ou validação. |

Respostas inválidas ou incompletas também ficam registradas. JSON contido em `body` ou `content` é expandido em campos somente para exibição, evitando camadas de aspas escapadas; conteúdo que não seja JSON continua como texto. O formatter normaliza os finais de linha para leitura, preservando sequências literais como `\n` e caminhos com barras. Cabeçalhos de autenticação e configuração de credenciais não são registrados; o arquivo contém os textos completos enviados pelo usuário e recebidos do provider. Sem a variável de ambiente, a captura fica desativada; no `.env.example`, `AI_CONVERSATION_LOG_ENABLED=${APP_DEBUG}` faz a captura acompanhar `APP_DEBUG`. A captura cobre chamadas feitas por `AiService::submit()`; chamadas diretas a `$agent->prompt()` não entram nesse log. Para encerrar os testes, defina `AI_CONVERSATION_LOG_ENABLED=false` e restaure a retenção desejada do cache. O novo formato vale para registros produzidos após a alteração; registros antigos não são reescritos.

Os testes automatizados simulam HTTP e verificam a integração e o arquivo de log. A qualidade das respostas reais após simplificar os prompts deve ser avaliada com o gateway e o modelo configurados.

### Respostas JSON e falhas

O sucesso mantém o envelope `{"success": true, "data": {...}}`. Na bibliografia, os dados incluem `respostas`, `explicacoes`, `confiancas`, a entrada, a norma e as estatísticas. Na ementa, incluem `resposta`, `score`, `explicacoes`, `analiseIngles`, a entrada e as estatísticas. `analiseIngles` corresponde a `explicacoes.en` do resultado do agente.

Falhas de execução retornam `{"success": false, "message": "..."}`:

| Status | Situação |
| --- | --- |
| 429 | Limite de solicitações do provider. |
| 502 | Resposta inválida ou incompleta, ou falha no processamento pelo provider. |
| 503 | Falha de conexão, indisponibilidade ou configuração ausente. |
| 504 | Timeout. |

Entrada inválida retorna 422, com os erros de validação do Laravel; requisições JSON sem autenticação retornam 401. Nas requisições web, falhas de IA retornam ao formulário com um alerta e preservam a entrada.

Consulte a [documentação técnica da integração com o Laravel AI SDK no Laravel 13](docs/implementacao-laravel-ai-sdk.md) para entender as dependências, o código atual, os schemas, o cache, as estatísticas, os fluxos de execução e os testes.
