<?php

namespace App\Ai\Agents;

use App\Models\AiPrompt;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

/**
 * Revisa ementas com o prompt existente e uma saída estruturada em português e inglês.
 *
 * O texto a revisar é enviado a prompt(). Cada chamada é independente, sem
 * contexto de conversa, tools ou parâmetros adicionais de disciplina e área.
 */
class EmentaAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    /**
     * Reutiliza integralmente as instruções de revisão acadêmica mantidas em AiPrompt.
     *
     * @return string Prompt original de ementa, incluindo seu exemplo de JSON.
     */
    public function instructions(): string
    {
        // O schema complementa o contrato de saída, preservando o texto original.
        return AiPrompt::systemPrompt('ementa');
    }

    /**
     * Declara os objetos e campos obrigatórios já descritos no prompt de ementa.
     *
     * @param JsonSchema $schema Construtor de tipos JSON Schema fornecido pelo SDK.
     * @return array<string, Type> Avaliação, explicações e versões revisadas nos dois idiomas.
     */
    public function schema(JsonSchema $schema): array
    {
        // As duas análises usam os mesmos campos; somente a inglesa possui status.
        $analysisFields = [
            'gramatica_acentuacao_pontuacao' => $schema->string()->required(),
            'redacao_academica_clareza_pedagogica' => $schema->string()->required(),
            'terminologia_tecnica' => $schema->string()->required(),
        ];

        // Cada seção revisada deve incluir português e inglês, mesmo quando o
        // inglês estava ausente na entrada e precisa ser traduzido pelo modelo.
        $bilingualFields = [
            'pt' => $schema->string()->required(),
            'en' => $schema->string()->required(),
        ];

        return [
            'score' => $schema->object([
                'valor' => $schema->integer()->min(0)->max(100)->required(),
                'justificativa' => $schema->string()->required(),
            ])->required(),
            'explicacoes' => $schema->object([
                'pt' => $schema->object($analysisFields)->required(),
                'en' => $schema->object([
                    'status' => $schema->string()->enum(['presente', 'parcial', 'ausente'])->required(),
                    ...$analysisFields,
                ])->required(),
            ])->required(),
            'texto_revisado' => $schema->object([
                'ementa' => $schema->object($bilingualFields)->required(),
                'objetivos' => $schema->object($bilingualFields)->required(),
                'conteudo_programatico' => $schema->object($bilingualFields)->required(),
            ])->required(),
        ];
    }
}
