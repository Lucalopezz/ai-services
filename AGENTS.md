# Repository Guidelines

## Project Structure & Module Organization
This Laravel 12 application provides USP bibliography formatting and course-description review through the Laravel AI SDK. PHP dependencies require PHP 8.3 or newer.

- `app/Http/Controllers/`: request validation and web/JSON responses.
- `app/Services/AiService.php`: synchronous SDK execution, response validation, caching, and usage statistics.
- `app/Ai/Agents/`: bibliography and syllabus agents with structured output schemas.
- `app/Models/AiPrompt.php`: existing bibliography and syllabus prompts, reused unchanged by the agents.
- `routes/`: web and API route definitions; bibliography and syllabus routes currently live in `web.php`.
- `resources/views/`: Blade screens and reusable partials; `resources/js/` and `resources/css/`: asset sources compiled into `public/`.
- `config/`, `database/`, and `lang/`: configuration, migrations/factories/seeders, and translations.
- `tests/Unit/`, `tests/Feature/`, and `tests/Browser/`: automated tests.

## Build, Test, and Development Commands
- `composer install`: install PHP dependencies from the lockfile.
- `cp .env.example .env` and `php artisan key:generate`: initialize local configuration.
- `php artisan migrate`: apply migrations after configuring a local database.
- `php artisan serve`: run the local application; set `APP_URL` to its address.
- `npm install` and `npm run dev`: install frontend dependencies and compile assets with Laravel Mix.
- `npm run watch`: rebuild assets on changes; `npm run prod`: compile production assets.
- `vendor/bin/phpunit`: run unit and feature suites; append `--filter ExampleTest` to target a test.
- `php artisan dusk`: run browser tests with the application running and Chrome/ChromeDriver available.

## Coding Style & Naming Conventions
Follow `.editorconfig`: four spaces, UTF-8, LF, final newline; YAML uses two spaces. `.styleci.yml` defines the Laravel PHP preset and JS/CSS checks; no local lint script is configured. Use PSR-4 namespaces, PascalCase classes, camelCase methods, and descriptive kebab-case Blade partials such as `copiar-btn.blade.php`. Keep provider integration in services and reusable presentation in partials.

### Code Documentation
Always document all new or modified code. Add or update complete PHPDoc/JSDoc blocks for classes, functions, and methods: describe their purpose, every parameter with its type and meaning, and the return type and meaning (`@return` in PHPDoc, `@returns` in JSDoc; use `void` when applicable). Document exceptions, side effects, and data structures where relevant; keep documentation consistent with actual behavior.

Include concise inline comments explaining implementation decisions, processing steps, business rules, and non-obvious logic inside functions and other code blocks. Explain why the logic exists and how it works without narrating every statement or repeating obvious names and operations. Apply this documentation standard consistently to features, fixes, and refactors, including test code, using the appropriate comment syntax for each language.

## Testing Guidelines
Use PHPUnit for unit/feature tests and Laravel Dusk for browser flows. Name files `*Test.php` and methods with a `test` prefix. No coverage threshold is configured. For behavior changes, cover validation, web/JSON responses, provider failures, and caching where relevant. Fake HTTP responses rather than calling paid AI services. Use a separate test database; Dusk creates users and changes permissions.

## Commit & Pull Request Guidelines
Recent commits favor `feat:` and `fix:` with short, often Portuguese descriptions. Follow that pattern with a focused subject. PRs should describe the behavior change, link relevant issues, report verification commands/results, and include screenshots for UI changes. Call out configuration or migration requirements.

## Agent-Specific Instructions
At the end of every feature, provide the user with ready-to-run commands in the final chat response: `git add` listing the specific files belonging to the feature, followed by `git commit -m "<commit message>"` with an appropriate message. Exclude unrelated changes; do not use `git add .`. Never execute a commit automatically under any circumstances; the user must run the commit command manually.

## Security & Configuration Tips
Keep credentials in `.env`; never commit real keys. Configure `AI_PROVIDER`, `LITELLM_URL`, `LITELLM_API_KEY`, and `LITELLM_MODEL` through `config/ai.php`. `config/aiService.php` only holds the response-cache retention from `AI_CACHE_RETENTION`. Preserve the existing prompts and authentication on bibliography and syllabus routes.
