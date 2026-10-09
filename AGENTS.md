# Repository Guidelines

## エージェントの応答言語

ユーザーへの応答は、進捗報告・質問・最終回答を含め、必ず日本語で行う。

## Project Structure & Module Organization

Mall Insight analyzes marketplace sales and inventory using Laravel 13 (PHP 8.3+) and Vue 3/TypeScript.

- `src/` contains the application: `app/` for backend classes, `routes/` for HTTP endpoints, and `database/` for migrations, factories, and seeders.
- `src/resources/js/` contains Vue views, components, Pinia stores, API clients, and colocated frontend tests. Styles live in `src/resources/css/`; public assets live in `src/public/`.
- `src/tests/Unit/` and `src/tests/Feature/` contain PHP tests.
- `docs/プロダクト/` holds current product goals, backlog acceptance criteria, and decisions. `docs/参考/` contains sample imports. `.devcontainer/` defines the Docker development environment.

## Build, Test, and Development Commands

Run all application commands from `src/` (`cd src`):

- `composer setup`: install dependencies, initialize `.env`, generate the application key, migrate, and build frontend assets. Configure the intended local database first.
- `composer dev`: start the Laravel server, queue worker, and Vite development server.
- `npm run build`: create production frontend assets.
- `npm run check`: check formatting, lint, type-check, and run frontend tests.
- `php artisan test`: run PHP unit and feature tests; add `--filter=Name` to target a test.
- `npm run format` and `vendor/bin/pint`: format frontend files and PHP respectively.

## Coding Style & Naming Conventions

Prettier uses four spaces, double quotes, semicolons, and a 200-character print width. ESLint checks Vue and TypeScript; Laravel Pint formats PHP. Use PascalCase Vue component filenames, camelCase TypeScript helpers, and PSR-4 PHP classes under `App\`. Use the shared `resources/js/api/client.ts` for API calls and `@` for imports from `resources/js`.

## Testing Guidelines

Use PHPUnit for backend behavior and Vitest with Vue Test Utils and jsdom for frontend behavior. Name PHP tests `*Test.php` and colocate frontend `*.test.ts` files with their modules. PHP tests use in-memory SQLite. Cover changed calculations, import validation, API behavior, and UI interactions. No numeric coverage threshold is configured. Before completing implementation work, run both `npm run check` and `php artisan test`.

## Commit & Pull Request Guidelines

Recent commits use concise Japanese descriptions, often ending with a backlog identifier such as `（B-127）`. PRs should describe the behavior change, link the relevant backlog item or issue, report validation, and include screenshots for UI changes.

## Requirements & Configuration

Consult product acceptance criteria and decisions before implementing business rules; record new decisions there. `docs/documents/` contains frozen legacy designs: consult but do not edit them. Keep credentials in local `.env` files. Do not manually edit Boost-managed `src/CLAUDE.md`.
