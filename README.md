# USP AI Services (Portal & API)

[![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com/)
[![PHP](https://img.shields.io/badge/PHP-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net/)
[![License](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)

Plataforma centralizada de serviços e microsserviços baseados em Inteligência Artificial . O sistema oferece tanto uma **interface web (playground)** para uso direto quanto uma **API RESTful** para integração com sistemas.

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

As rotas exigem autenticação pela sessão da aplicação e proteção CSRF nos POSTs. Para integração JSON, reutilize a sessão autenticada e envie o token CSRF correspondente. Os endpoints de IA estão definidos em `routes/web.php`; `/api/user` é a rota de usuário autenticado com Sanctum.

```text
Controller → AiService (cache e estatísticas) → Agente → Laravel AI SDK → LiteLLM
```

---

## 💻 Instalação e Configuração Local

### Pré-requisitos

* PHP 8.3 ou superior
* Composer
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

Os dois agentes reutilizam integralmente os prompts de `app/Models/AiPrompt.php`. Os schemas descrevem em código a estrutura já exigida pelos prompts. As chamadas são síncronas, com timeout de 60 segundos, sem memória de conversa, tools, streaming ou filas.

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

Consulte a [documentação técnica da integração com o Laravel AI SDK](docs/implementacao-laravel-ai-sdk.md) para entender o código atual, os schemas, o cache, as estatísticas, os fluxos de execução e os testes. O [plano de implementação](docs/plano-implementacao-laravel-ai-sdk.md) registra as etapas e os resultados da migração.
