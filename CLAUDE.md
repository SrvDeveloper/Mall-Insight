# CLAUDE.md

このファイルは、このリポジトリで作業する Claude Code (claude.ai/code) 向けのガイダンスを提供する。

## プロジェクト概要

「Mall Insight」（ECモール統合分析システム）— ECモール運営者向けの Laravel + Vue アプリケーション。販売実績・年間販売目標・システム需要予測を分離して管理し、これらを用いて SKU 別の将来在庫、欠品リスク、推奨発注数を算出する。開発はアジャイル型で進めており、作る機能・決定事項は `docs/プロダクト/` で管理する。機能を実装する際は、推測せずにまず次を確認すること：

- `docs/プロダクト/01.プロダクト概要.md` — 目的、変えない原則、進め方と完成の条件
- `docs/プロダクト/02.バックログ.md` — 作る機能の優先順位と受入条件
- `docs/プロダクト/03.決定記録.md` — 作りながら決めたこと。旧設計書と食い違う場合はこちらを優先する
- `docs/documents/` — 2026年10月6日に更新を停止した旧設計書（参考資料）。計算式・取込形式（BOSS・Amazon各レポート、日次在庫CSV）の参照には使えるが、確定した仕様とはみなさない。編集しない
- `docs/参考/` — 取込要件で参照されているサンプル取込データ（BOSSのCSV/XLSX出力、AmazonのAmazon全注文レポート・FBA在庫レポート）

**現在の状態：** バックログに沿って実装中（進捗は `docs/プロダクト/02.バックログ.md` の各項目の状態を参照）。Laravel Boost は導入済みで、`src/CLAUDE.md` は Boost が生成・管理するファイルなので手動で編集しない。`src/AGENTS.md` には Boost のブートストラップ用ブロックが残っているが、`boost.json` の対象エージェントが `claude_code` だけのため再生成されないだけであり、Boost の再インストールは不要。

## 作業ディレクトリ

実際の Laravel アプリケーションはリポジトリのルートではなく **`src/`** 配下にある。`composer`、`npm`、`artisan` の各コマンドはすべて `src/` から実行すること。リポジトリルートには他に `docs/`（要件）と `.devcontainer/`（開発環境定義）がある。

## コマンド

以下はすべて `src/` から実行する。

### PHP / Laravel
```
composer install                 # PHP依存パッケージのインストール
php artisan test                 # PHPUnitテストスイートの実行（Unit + Feature）
php artisan test --filter=Name   # メソッド名/クラス名を指定して単体テストを実行
vendor/bin/phpunit tests/Feature/ExampleTest.php   # テストファイルを直接指定して実行
php artisan dev                  # ローカル開発サーバーの起動（serve + queue + vite、composerのdevスクリプト経由）
php artisan migrate              # マイグレーション実行（.env.exampleではsqliteがデフォルト、devcontainerではMySQL）
```

### JS / Vue
```
npm install
npm run dev            # vite開発サーバー起動（5173番ポート）
npm run build           # 本番ビルド
npm run lint            # resources/js と設定ファイルに対するeslint
npm run format           # prettier --write
npm run format:check     # prettier --check
npm run typecheck        # vue-tsc --noEmit
npm run test             # vitest run
npm run check            # format:check → lint → typecheck → test の順に実行
```

フロントエンド・バックエンドを横断して一括実行するスクリプトは無い。作業完了前に JS 側は `npm run check`、PHP 側は `php artisan test` をそれぞれ個別に実行すること。

## 技術スタックと構成

- **バックエンド**: Laravel 13、PHP 8.3。`App\` 名前空間で PSR-4 オートロード（`src/app/`）。標準的な Laravel の構成（`app/Http/Controllers`、`app/Models`、`app/Providers`、`routes/web.php`、`database/migrations`）。API は `routes/api.php` に `/api/v1/...` として定義し、Eloquent API Resource で返す。`routes/web.php` は `api/` 以外のすべてのパスで SPA の枠（`app.blade.php`）を返す。タイムゾーンは Asia/Tokyo、言語は日本語（`lang/ja/`）。
- **フロントエンド**: Vue 3 + TypeScript、Vite（`laravel-vite-plugin`）でビルド。エントリポイントは `resources/js/app.ts`。状態管理は Pinia（`pinia-plugin-persistedstate` 導入済み）、ルーティングは vue-router、スタイリングは Tailwind CSS v4（`@tailwindcss/vite`）。Tailwind の設定は `resources/css/app.css` の `@theme` に書く（`tailwind.config.js` は使わない）。Flowbite は依存に入っているが現在は使っていない。API 呼び出しは `resources/js/api/client.ts` の共通クライアントを使い、エラーは `ApiError` に変換される。パスエイリアス：`@` → `resources/js`、`@css` → `resources/css`。
- **データベース**: ローカルではデフォルトで sqlite（`src/.env.example`）、devcontainer では MySQL 8.0（`db` サービス、データベース名 `mall_insight`）。テストは sqlite の `:memory:` を強制使用（`src/phpunit.xml`）。
- **開発環境**: `.devcontainer/` が Docker Compose 構成を定義（AlmaLinux 9.6、PHP 8.3、Node 24）。サービスは `app`、`db`（MySQL）、`phpmyadmin`（8081番ポート）。

## コーディング規約

- フォーマットは Prettier で強制される（`.prettierrc`：4スペースインデント、ダブルクォート、printWidth 200、singleAttributePerLine 無効）。JS/TS/Vue に適用され、リポジトリ全体が対象だが `*.md` は除外される（`.prettierignore`）。PHP のフォーマットは Laravel Pint（`laravel/pint` の devDependency）を使用 — `pint.json` はコミットされていないためデフォルト設定が適用される。
- 業務ロジック（計算式、警告条件、画面ID等）は、`docs/プロダクト/` のバックログ受入条件と決定記録を一次情報源とし、足りない部分は `docs/documents/` の旧設計書を参考にする。予測・在庫計算のロジックをコードだけから推測しないこと。実装中に決めたことは決定記録に追記する。
