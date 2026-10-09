<?php

/*
| Configuração publicada pelo Laravel AI SDK e adaptada para este projeto.
|
| O bloco "litellm" e a seleção do provider padrão são as opções preparadas
| para os agentes de bibliografia e ementa. As demais opções vieram do SDK
| e ficam disponíveis para integrações futuras; defini-las não executa chamadas.
|
| O AiService executa os agentes com o provider e modelo selecionados aqui.
| config/aiService.php mantém a retenção do cache de respostas. Os prompts
| continuam definidos em App\Models\AiPrompt e são reutilizados pelos agentes.
*/

// Reutiliza a URL raiz já configurada para o serviço atual, removendo a barra final.
$litellmUrl = rtrim((string) env('LITELLM_URL', ''), '/');

return [

    /*
    |--------------------------------------------------------------------------
    | Providers padrão por operação
    |--------------------------------------------------------------------------
    |
    | Cada valor corresponde a uma chave do array "providers" abaixo.
    | "default" seleciona o provider de texto quando prompt() não informa um.
    | O projeto usa LiteLLM para texto e saída estruturada; o modelo de texto
    | fica em providers.litellm.models.text.default.
    |
    | As opções default_for_* são os padrões publicados pelo SDK para outros
    | recursos, ainda sem uso nos fluxos de bibliografia e ementa. Só entram
    | em ação se a aplicação executar a operação correspondente.
    |
    */

    'default' => env('AI_PROVIDER', 'litellm'),
    // Geração de imagens a partir de descrições.
    'default_for_images' => 'gemini',
    // Síntese de voz: texto para áudio.
    'default_for_audio' => 'openai',
    // Transcrição: áudio para texto.
    'default_for_transcription' => 'openai',
    // Representações vetoriais de conteúdo para busca por similaridade.
    'default_for_embeddings' => 'openai',
    // Reordenação de resultados conforme sua relevância para uma consulta.
    'default_for_reranking' => 'cohere',
    // Classificação de conteúdo em categorias ou decisões definidas pela aplicação.
    'default_for_classification' => 'typesafe',

    /*
    |--------------------------------------------------------------------------
    | Cache de embeddings do SDK
    |--------------------------------------------------------------------------
    |
    | Este bloco controla o reaproveitamento de embeddings, recurso que não
    | é usado nesta migração. "cache" habilita a estratégia, "store" escolhe
    | o armazenamento do Laravel e "individually" permite reutilizar cada
    | entrada separadamente, em vez de armazenar somente o lote inteiro.
    |
    | O cache das respostas de bibliografia e ementa é controlado pelo
    | AiService e por AI_CACHE_RETENTION em config/aiService.php.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
            'individually' => true,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conexões com os providers
    |--------------------------------------------------------------------------
    |
    | A chave de cada bloco é o nome usado para selecionar o provider;
    | "driver" identifica o protocolo que o SDK utiliza para se comunicar.
    | "key" contém a credencial do destino e "url", quando configurada,
    | informa sua base de API. As credenciais são lidas do ambiente.
    |
    | "litellm" é a conexão utilizada na validação e prevista para os agentes.
    | Os outros blocos são alternativas publicadas pelo SDK, sem uso no fluxo
    | atual. Não é necessário preencher suas chaves para utilizar o LiteLLM.
    | Se forem selecionados explicitamente ou como padrão de uma operação,
    | precisam das respectivas credenciais e configurações.
    |
    */

    'providers' => [
        // Gateway do projeto: o LiteLLM encaminha a chamada ao modelo configurado nele.
        'litellm' => [
            // Usa Chat Completions compatível com OpenAI, mesmo para modelos de outros fornecedores.
            'driver' => 'openai-compatible',
            // O SDK acrescenta /chat/completions à base. Incluímos /v1 para manter
            // o endpoint já usado pelo AiService: /v1/chat/completions.
            // /v1 é uma escolha desta integração, não uma exigência do driver.
            // Uma base já terminada em /v1 é aceita sem duplicar esse segmento;
            // uma URL vazia permanece sem configuração para o SDK apontar o erro.
            'url' => $litellmUrl === '' ? null : (
                str_ends_with($litellmUrl, '/v1') ? $litellmUrl : $litellmUrl.'/v1'
            ),
            // Chave do gateway LiteLLM; as credenciais dos fornecedores ficam no gateway.
            'key' => env('LITELLM_API_KEY'),
            'models' => [
                'text' => [
                    // Alias exposto pelo LiteLLM. Usado quando prompt() não informa modelo.
                    // Para saída estruturada, gateway e modelo devem aceitar json_schema.
                    'default' => env('LITELLM_MODEL'),
                ],
            ],
        ],

        // Conexão direta à Anthropic, sem passar pelo LiteLLM.
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
        ],

        // OpenAI hospedado no Azure; usa os deployments configurados no recurso Azure.
        'azure' => [
            'driver' => 'azure',
            'key' => env('AZURE_OPENAI_API_KEY'),
            'url' => env('AZURE_OPENAI_URL'),
            // Versão da API Azure e nomes dos deployments por modalidade.
            'api_version' => env('AZURE_OPENAI_API_VERSION', '2025-04-01-preview'),
            'deployment' => env('AZURE_OPENAI_DEPLOYMENT', 'gpt-6-sol'),
            'embedding_deployment' => env('AZURE_OPENAI_EMBEDDING_DEPLOYMENT', 'text-embedding-3-small'),
            'image_deployment' => env('AZURE_OPENAI_IMAGE_DEPLOYMENT', 'gpt-image-2.5-flare'),
            // Persistência de respostas no provider, quando suportada; não é memória do SDK.
            'store' => env('AZURE_OPENAI_STORE', true),
        ],

        // Modelos via AWS Bedrock; exige o SDK AWS sugerido pelo pacote e autenticação AWS.
        'bedrock' => [
            'driver' => 'bedrock',
            'region' => env('AWS_BEDROCK_REGION', 'us-east-1'),
            // Autenticação por bearer token ou credenciais AWS, conforme o ambiente.
            'key' => env('AWS_BEARER_TOKEN_BEDROCK'),
            'access_key_id' => env('AWS_ACCESS_KEY_ID'),
            'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
            'session_token' => env('AWS_SESSION_TOKEN'),
            // Permite resolver credenciais pela cadeia padrão do SDK AWS (ex.: IAM role).
            'use_default_credential_provider' => env('AWS_USE_DEFAULT_CREDENTIALS', true),
            // Opcional: assumir outra IAM role com credenciais temporárias.
            'assume_role' => [
                'arn' => env('AWS_BEDROCK_ASSUME_ROLE_ARN'),
                'session_name' => env('AWS_BEDROCK_ASSUME_ROLE_SESSION_NAME'),
                'duration_seconds' => env('AWS_BEDROCK_ASSUME_ROLE_DURATION_SECONDS'),
                'external_id' => env('AWS_BEDROCK_ASSUME_ROLE_EXTERNAL_ID'),
            ],
        ],

        // Cohere: texto, embeddings e reordenação por relevância.
        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        // Conexão direta aos modelos de texto da DeepSeek.
        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        // ElevenLabs: síntese de voz e transcrição de áudio.
        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        // Conexão direta ao Gemini. Não é usada quando um modelo Gemini é acessado pelo LiteLLM.
        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
            'url' => env('GEMINI_URL', 'https://generativelanguage.googleapis.com/v1beta/'),
        ],

        // Conexão direta à API Groq para os recursos suportados pelo driver.
        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        // Jina: embeddings e reordenação por relevância para consultas.
        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        // Conexão direta à API Mistral para os recursos suportados pelo driver.
        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        // Servidor Ollama, normalmente local; usa seu protocolo próprio.
        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        ],

        // Conexão direta à OpenAI pelo driver dedicado do SDK.
        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            // Persistência de respostas na OpenAI, quando suportada; não cria conversas locais.
            'store' => env('OPENAI_STORE', true),
        ],

        // Entrada genérica para outro gateway compatível com OpenAI.
        // Usa o mesmo driver de "litellm", mas tem URL e chave independentes.
        'openai-compatible' => [
            'driver' => 'openai-compatible',
            'url' => env('OPENAI_COMPATIBLE_URL'),
            'key' => env('OPENAI_COMPATIBLE_API_KEY'),
        ],

        // Gateway OpenRouter: acesso a modelos de diferentes fornecedores.
        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        // TypeSafe: classificação de conteúdo e decisões por categorias.
        'typesafe' => [
            'driver' => 'typesafe',
            'key' => env('TYPESAFE_API_KEY'),
        ],

        // Voyage AI: embeddings e reordenação por relevância.
        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        // Conexão direta à API xAI para os recursos suportados pelo driver.
        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

];
