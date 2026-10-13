<?php

namespace App\Logging;

use JsonException;
use Monolog\Formatter\LineFormatter;
use Monolog\LogRecord;

/**
 * Exibe conversas em blocos legíveis, mantendo o cabeçalho reconhecido pelo Laravel.
 * A formatação usa os dados já registrados: não altera prompts, requisições HTTP
 * ou respostas utilizadas pelo serviço. Schemas mantêm a sintaxe JSON, enquanto
 * mensagens e resultados são apresentados como campos e textos indentados.
 */
class AiConversationFormatter extends LineFormatter
{
    /**
     * Configura o cabeçalho e os limites de normalização para os agentes atuais.
     * Os limites se aplicam à apresentação de arrays e objetos pelo Monolog.
     *
     * @return void
     */
    public function __construct()
    {
        // Deixa os dados fora do cabeçalho para impedir que o formatter padrão
        // transforme todo o contexto em uma única linha de JSON escapado.
        parent::__construct("[%datetime%] %channel%.%level_name%: %message%\n", 'Y-m-d H:i:s', false, true);
        // O limite padrão do Monolog corta campos dos schemas profundamente aninhados de ementa.
        $this->setMaxNormalizeDepth(32);
        // Mantém listas extensas dos pedidos e respostas dentro do limite de apresentação.
        $this->setMaxNormalizeItemCount(10000);
    }

    /**
     * Organiza a identificação da execução antes das mensagens, schemas e respostas.
     * Normaliza os valores para exibição e mantém metadados extras do Monolog,
     * sem modificar o contexto original do LogRecord.
     *
     * @param LogRecord $record Evento original com contexto e metadados do Monolog.
     * @return string Cabeçalho Laravel seguido de campos indentados e uma linha em branco.
     */
    public function format(LogRecord $record): string
    {
        // Converte objetos para representações imprimíveis usando os limites configurados.
        // Esta cópia permite reorganizar a exibição sem alterar os dados do evento.
        $context = $this->normalize($record->context);
        $metadata = [];
        // A ordem fixa facilita localizar a chamada e seu destino antes de ler os
        // blocos longos de prompt, schema ou resposta, independentemente da ordem de entrada.
        foreach (['request_id', 'agent', 'provider', 'model', 'method', 'url', 'status'] as $key) {
            if (array_key_exists($key, $context)) {
                $metadata[$key] = $context[$key];
            }
        }

        // Mantém o cabeçalho em uma linha para o Log Viewer identificar cada evento.
        $output = parent::format($record->with(context: [], extra: []));
        // A união mantém os metadados primeiro e inclui os demais campos uma única vez.
        foreach ($metadata + $context as $key => $value) {
            $output .= $this->formatField((string) $key, $value);
        }
        if ($record->extra !== []) {
            // Preserva informações acrescentadas por processadores do Monolog em seu próprio bloco.
            $output .= $this->formatField('extra', $this->normalize($record->extra));
        }

        // Uma linha vazia separa os eventos, tornando a sequência legível também em tail -f.
        return $output."\n";
    }

    /**
     * Exibe um campo com texto literal ou filhos indentados, sem alterar o evento original.
     * Arrays são percorridos recursivamente; escalares ficam ao lado do nome do campo.
     * Textos com várias linhas ganham um bloco próprio e conservam seu conteúdo visível.
     *
     * @param string $key Nome do campo ou índice da lista.
     * @param mixed $value Valor normalizado do campo, incluindo texto ou estrutura aninhada.
     * @param int $depth Nível de indentação do bloco.
     * @return string Campo legível com quebras de linha reais; JSON inválido permanece como texto.
     */
    private function formatField(string $key, mixed $value, int $depth = 0): string
    {
        $indent = str_repeat('    ', $depth);

        // O corpo HTTP e o conteúdo do assistente podem conter JSON dentro de strings.
        // Decodifica somente para exibição, evitando camadas de aspas e barras escapadas.
        if (is_string($value) && in_array($key, ['body', 'content'], true)) {
            try {
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
                // Expande estruturas não vazias. Um JSON escalar ou vazio permanece como
                // texto para não apagar sua representação original na conversa.
                if (is_array($decoded) && $decoded !== []) {
                    $value = $decoded;
                }
            } catch (JsonException) {
                // Texto livre e JSON inválido são esperados neste diagnóstico: uma falha
                // de decodificação muda apenas a apresentação, sem descartar o conteúdo.
            }
        }

        if (is_array($value) && $value !== []) {
            $output = $indent.$key.":\n";
            if (in_array($key, ['schema', 'response_format'], true)) {
                // Preserva chaves, tipos e limites do contrato em JSON com indentação.
                // Unicode e URLs ficam legíveis; bytes inválidos usam substituição na exibição.
                $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

                return $output.$this->indentText($json, $depth + 1);
            }
            foreach ($value as $field => $item) {
                // Índices entre colchetes distinguem itens de listas dos nomes dos campos;
                // cada nível acrescenta quatro espaços para mostrar a estrutura aninhada.
                $label = is_int($field) ? "[{$field}]" : (string) $field;
                $output .= $this->formatField($label, $item, $depth + 1);
            }

            return $output;
        }

        // Strings ficam literais para preservar acentos, aspas e caminhos. JSON representa
        // valores vazios, booleanos e null explicitamente, evitando que pareçam ausentes.
        $text = is_string($value) && $value !== ''
            ? $value
            : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strpbrk($text, "\r\n") !== false) {
            // Um texto longo conserva suas linhas em um bloco sob o nome do campo,
            // em vez de espalhar escapes como \n pelo registro.
            return $indent.$key.":\n".$this->indentText($text, $depth + 1);
        }

        return $indent.$key.': '.$text."\n";
    }

    /**
     * Indenta cada linha do conteúdo para distingui-lo do cabeçalho de um novo evento.
     * Linhas do prompt que se pareçam com um cabeçalho também recebem indentação,
     * permitindo distinguir o texto fornecido da abertura real de outro registro.
     *
     * @param string $text Conteúdo a exibir, preservando as linhas e os caracteres visíveis.
     * @param int $depth Nível de indentação da primeira linha e das linhas seguintes.
     * @return string Bloco de texto indentado e terminado por quebra de linha.
     */
    private function indentText(string $text, int $depth): string
    {
        // Aceita finais de linha de Windows e Unix, sem substituir sequências literais
        // como "\\n" ou barras de caminhos. A normalização vale somente para o arquivo de log.
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $indent = str_repeat('    ', $depth);

        return $indent.str_replace("\n", "\n".$indent, $text)."\n";
    }
}
