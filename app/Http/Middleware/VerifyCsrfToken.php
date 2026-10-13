<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;

/**
 * Protege as rotas web com a verificação de origem e de token do Laravel 13.
 *
 * Mantém o nome local usado pelo kernel e não dispensa nenhuma rota da proteção.
 */
class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        // Os formulários e os POSTs JSON autenticados compartilham a mesma proteção.
    ];
}
