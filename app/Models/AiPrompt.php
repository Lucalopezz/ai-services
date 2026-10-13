<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Centraliza as instruções de conteúdo; os agentes definem o formato via JSON Schema.
 * Os textos explicam como revisar e o que cada informação significa. Os métodos
 * schema() de BibliografiaAgent e EmentaAgent mantêm os campos, tipos e limites
 * usados pelo SDK, evitando repetir o contrato de serialização nas instruções.
 */
class AiPrompt extends Model
{
    protected $fillable = [
        'slug',
        'nome',
        'descricao',
        'system_content',
        'user_content'
    ];

    /**
     * Obtém as instruções de um serviço com seus parâmetros padrão.
     *
     * @param string $slug Identificador bibliografia ou ementa.
     * @return string Instruções de conteúdo para o agente.
     * @throws InvalidArgumentException Quando o identificador não existir.
     */
    public static function get(string $slug): string
    {
        return match ($slug) {
            'bibliografia' => self::bibliografiaContent(null),
            'ementa' => self::ementaContent(),
            default => throw new InvalidArgumentException(
                "Prompt '{$slug}' não encontrado."
            ),
        };
    }

    /**
     * Lista as normas disponíveis para seleção e validação da entrada.
     *
     * @return list<string> Normas bibliográficas aceitas pela aplicação.
     */
    public static function getBibliografiaStandards(): array
    {
        return [
            'ABNT NBR 6023:2025',
            'APA 7th Edition',
            // 'Chicago Manual of Style 17th Edition',
            // 'MLA 9th Edition',
            // 'Harvard Referencing Style',
            'Vancouver Style',
            'IEEE Citation Style',
            // 'Turabian 9th Edition',
            // 'AMA Manual of Style 11th Edition',
            // 'CSE (Council of Science Editors) Style'
        ];
    }

    /**
     * Define as regras bibliográficas e o significado dos campos da resposta.
     * Orientações de Dedalus, confiança e correspondência das listas permanecem
     * no texto porque descrevem regras de conteúdo que o JSON Schema não substitui.
     *
     * @param array{standard?: string}|null $params Norma selecionada; ausente usa ABNT.
     * @return string Instruções de revisão sem duplicar a estrutura JSON do agente.
     */
    protected static function bibliografiaContent(?array $params = []): string
    {
        $standard = empty($params['standard']) ? 'ABNT NBR 6023:2025' : $params['standard'];
        // Remove-se apenas a exigência de serialização JSON do prompt. As orientações
        // de cada campo continuam necessárias para o modelo preencher o schema do agente.
        return <<<PROMPT
You are a precise bibliographic formatting assistant following the {$standard} standard
CONTENT GUIDANCE:
- "referencias": Provide ONLY the corrected, standardized references.
- "explicacoes": Explain ONLY the specific adjustments, corrections, or missing fields. Do NOT include generic introductory phrases.
- "busca_dedalus": Prepare catalog lookup information for each reference. For "autor", use the cleaned primary author's name (e.g., "FARRET, F. A." or "ANDERSEN, James M."); if published by an organization, use the entity name. For "titulo", use the full main title including subtitle if present, excluding edition numbers or 'In:' prefixes.
- "confiancas": Evaluate the quality and completeness of the ORIGINAL input reference. High values (e.g. 90-100) indicate the input was nearly complete and required minimal changes. Low values (e.g. 0-50) indicate the original input was poor, highly incomplete, or required heavy modifications.

FORMATTING RULES:
- EXPLICIT RULES PRECEDENCE: Strictly adhere to all explicit formatting rules listed below. Always prioritize these explicit instructions over general knowledge or default guidelines whenever a conflict or ambiguity arises.
- PAGE NUMBERS & PAGINATION: Preserve total page counts (e.g., "295 p." or "295p.") as total page counts. Do NOT convert a total page count into a page range (e.g., do NOT turn "295 p." into "1-295" or "[1]-295"). Do NOT infer or add bracketed starting page numbers.
- DIRECT EXPLANATIONS ONLY: In "explicacoes", start IMMEDIATELY with the specific change, correction, or missing element. NEVER include boilerplate introductory text like "Ajustado para o padrão ABNT", "Normalizado de acordo com...", or "Referência formatada".
- AUTHOR LIMIT & ET AL.: Count distinct human authors carefully. List up to five authors explicitly (separated by semicolons or standard standard-specific delimiters). Use "et al." ONLY when there are 6 or more distinct authors. Ignore typos like "et Cal." or "et all." embedded in raw author names.
- PLAIN TEXT ONLY: "referencias" values must contain plain text only. Do NOT use Markdown, HTML, or formatting syntax.
- DO NOT USE BOLD: Never use **, __, <strong>, <b>, or other markup for emphasis.
- ONE REFERENCE PER ARRAY ELEMENT: Each element must contain exactly one complete bibliographic reference.
- OUTPUT WHITESPACE: Each reference must be a single continuous line. Do NOT insert blank lines, paragraph spacing, indentation, or unnecessary whitespace.
- LINE BREAK HANDLING: Treat line breaks within the same reference as unintended text wraps. Merge broken lines into a single continuous text string. Do NOT split a reference into multiple entries because of line breaks.
- DO NOT GUESS OR INFER UNKNOWN YEARS: If the publication year is missing, use '[s.d.]' or omit the date field. Never invent years.
- WEBSITES & URLS: Format URLs enclosed in '<>'. If 'Acesso em:' is not explicitly provided in the input, omit it entirely. NEVER output 'Acesso em: [s.d.]'.
- BOOK CHAPTERS: Format according to the specified {$standard} standard. Do NOT use bold or other visual emphasis for the book title or chapter title.
- REMOVE UNWANTED CHARACTERS: Remove pilcrows (¶), Markdown markers, HTML tags, and other formatting artifacts.
- CORRECTIONS: Correct obvious typographical errors in words, names, titles, publishers, and other bibliographic elements when the intended information is clear.
- PRESERVE INFORMATION: Do not remove valid bibliographic information merely to simplify the reference.
- MISSING INFORMATION: Do not fabricate bibliographic information. When an element cannot be determined from the input, use the appropriate indication required by {{ $standard }} or omit the element when permitted.
- CONSISTENCY: Apply the same formatting rules consistently to all references.
- CORRESPONDENCE: "explicacoes", "busca_dedalus", and "confiancas" must contain exactly one corresponding item for each element in "referencias".
- CONFIDENCE: Assign a value from 0 to 100 based on the quality, structure, and completeness of the ORIGINAL input. Deduct points for missing mandatory fields (like year, publisher, author), severe formatting defects, or when heavy modifications/inferencing were required to fit the {{ $standard }} standard. A score of 100 means the input was already well-formatted and complete.
PROMPT;
    }

    /**
     * Define a avaliação acadêmica e a revisão bilíngue das seções fornecidas.
     * O exemplo JSON foi retirado porque EmentaAgent::schema() define a estrutura;
     * os critérios de avaliação e tradução continuam orientando os valores gerados.
     *
     * @param string|null $disciplina Nome opcional utilizado para preparar o contexto.
     * @param string|null $area Área opcional utilizada para preparar o contexto.
     * @return string Instruções acadêmicas; o formato da resposta pertence ao agente.
     */
    protected static function ementaContent(?string $disciplina = null, ?string $area = null): string
    {
        // Constrói o bloco de contexto apenas se houver dados válidos
        $contexto = '';

        $infoDisciplina = ($disciplina && $disciplina !== 'null') ? trim($disciplina) : null;
        $infoArea = ($area && $area !== 'null') ? trim($area) : null;

        if ($infoDisciplina || $infoArea) {
            $contexto = "";
            if ($infoDisciplina) {
                $contexto .= "- Disciplina: {$infoDisciplina}.\n";
            }
            if ($infoArea) {
                $contexto .= "- Área do Conhecimento: {$infoArea}.\n";
            }
            $contexto .= "\nUtilize este contexto para alinhar a terminologia técnica, o nível acadêmico e o jargão específico da área.\n";
        }

        // As instruções de inglês ausente continuam aqui porque o schema exige os
        // campos, mas não explica quando traduzir nem como avaliar equivalência entre idiomas.
        return <<<PROMPT
You are an Academic and Curriculum Reviewer specializing in curriculum design across all fields, including STEM, Humanities, Life Sciences, Social Sciences, and Engineering.

Review course descriptions, learning objectives, syllabi, and academic/pedagogical texts in the context of the University of São Paulo (USP).

INPUT:
The user may provide:

1. Structured JSON with fields such as `ementa`, `objetivos`, and `conteudo_programatico`, optionally containing `pt` and `en`.
2. Free text using labels such as Ementa/Course Description, Objetivos/Objectives, and Conteúdo Programático/Full Program, including Markdown or tag delimiters.

Identify the Portuguese and English versions of each section:

- Ementa / Course Description
- Objetivos / Objectives
- Conteúdo Programático / Full Program

If an English section is missing, empty, or contains only its default heading, set its status to `ausente` and provide an accurate technical translation.
If English is present, assess its technical and semantic equivalence to Portuguese and revise it when necessary.

EVALUATION:

1. QUALITY SCORE (0–100)

- 90–100: Excellent; minimal or no changes.
- 70–89: Good; minor grammar, formatting, or clarity issues.
- 50–69: Fair; noticeable grammar, terminology, clarity, or redundancy issues.
- Below 50: Insufficient; major structural, writing, or technical problems.

Provide a 1–2 sentence justification.

2. ANALYSIS

Evaluate each language separately:

Portuguese (`pt`):

- Grammar, spelling, agreement, and punctuation.
- Academic writing, pedagogical clarity, conciseness, redundancy, and syntactic parallelism.
- Technical terminology and conceptual precision.

English (`en`):

- `status`: `presente` = complete; `parcial` = incomplete or missing parts; `ausente` = no meaningful English text.
- Assess spelling, grammar, punctuation, academic tone, clarity, PT equivalence, and field-specific terminology; explain when English is absent.

3. REVISION

Provide final polished versions in Portuguese and English for all three sections. Ensure technical accuracy, consistency, homogeneous style, and suitability for inclusion in a PPC or syllabus.

Use a neutral, professional, concise, and evidence-based academic tone.

PROMPT;
    }

    /**
     * Renderiza um prompt para envio à API de IA, combinando as instruções
     * de sistema com o conteúdo fornecido pelo usuário.
     *
     * @param string $slug Identificador do prompt a ser utilizado.
     * @param string $userContent Conteúdo fornecido pelo usuário.
     * @return array Mensagens no formato esperado pela API Chat Completions.
     *
     * @throws \InvalidArgumentException Quando o prompt informado não existir.
     */
    public static function render(string $slug, string $userContent): array
    {
        return [
            [
                'role' => 'system',
                'content' => self::get($slug),
            ],
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ];
    }

    /**
     * Renderiza a estrutura de mensagens passando o texto do system prompt diretamente.
     *
     * @param string $systemPrompt Instruções de conteúdo já selecionadas.
     * @param string $userContent Texto fornecido pelo usuário.
     * @return list<array{role: string, content: string}> Mensagens de sistema e usuário.
     */
    public static function renderFromPrompt(string $systemPrompt, string $userContent): array
    {
        return [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => $userContent,
            ],
        ];
    }

    /**
     * Retorna as instruções de sistema associadas a um prompt.
     *
     * @param string $slug Identificador do prompt.
     * @param array{standard?: string}|null $params Norma bibliográfica opcional.
     * @return string Instruções de sistema utilizadas pela IA.
     *
     * @throws \InvalidArgumentException Quando o prompt informado não existir.
     */
    public static function systemPrompt(string $slug, ?array $params = []): string
    {
        return match ($slug) {
            'bibliografia' => self::bibliografiaContent($params),
            'ementa' => self::ementaContent(),

            default => throw new InvalidArgumentException(
                "Prompt '{$slug}' não encontrado."
            ),
        };
    }
}
