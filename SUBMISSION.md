# SUBMISSION.md — публикация zerokyc-pay на WordPress.org

Состояние на 2026-09-12: плагин готов (тесты зелёные, PHPCS чист, E2E на sandbox
пройден), репо `zerokyc-payments/zerokyc-woocommerce` запушен, CI зелёный,
сабмит-зип собран: `dist/zerokyc-pay.zip`.

Дальше только ручные шаги владельца (нужен аккаунт WP.org и вход в GitHub).

## Шаг 1. Отправить на ревью (5 минут)

1. Войди на wordpress.org аккаунтом **zerokyc** (email на домене
   zerokyc-payments.com — ревьюеры сверяют представительство бренда).
2. Убедись, что `plugins@wordpress.org` в белом списке почты.
3. Открой **https://wordpress.org/plugins/developers/add/**
4. Plugin name: `ZeroKYC – Crypto Payments for WooCommerce`
5. Plugin description (вставь как есть):

   > ZeroKYC Pay is a crypto payment gateway for WooCommerce. The buyer pays in
   > USDT, USDC, BTC, XMR or TON through the ZeroKYC hosted checkout — no KYC
   > for buyers, no wallet keys on the merchant server. Orders are completed by
   > HMAC-SHA256 verified webhooks with replay protection and an optional
   > server-side double check; a WP-Cron job reconciles lost webhooks every 15
   > minutes. This is the official plugin by ZeroKYC Payments
   > (zerokyc-payments.com), powered by our open-source PHP SDK
   > (github.com/zerokyc-payments/zkp-sdk-php, MIT). Service terms:
   > zerokyc-payments.com/terms, privacy: zerokyc-payments.com/privacy.

6. Прикрепи **dist/zerokyc-pay.zip** (не переименовывай) → Submit.

Если ревьюер спросит про права на бренд: аккаунт `zerokyc`, домен
zerokyc-payments.com, сервис принадлежит тебе.

## Шаг 2. Ждать апрува

Обычно 1–14 рабочих дней. Придёт письмо от plugins@wordpress.org с адресом
SVN-репозитория вида
`https://plugins.trac.wordpress.org/changeset/…` / slug `zerokyc-pay`.
Никаких действий до письма не требуется. До апрува **не** пушь git-теги `v*`
и не меняй папку `.wordpress-org/` (workflows деплоя сработают и упадут без
SVN-доступа).

## Шаг 3. После письма-апрува (10 минут)

1. На GitHub: **Settings → Secrets and variables → Actions → New repository
   secret**, добавь:
   - `SVN_USERNAME` = `zerokyc` (логин WP.org)
   - `SVN_PASSWORD` = пароль аккаунта WP.org
2. Локально (нужен push-доступ к репо):

   ```bash
   git checkout main && git pull
   git tag v1.0.0
   git push origin v1.0.0
   ```

3. Workflow **Deploy to WordPress.org** сам:
   - проверит соответствие тега `Version:` в zerokyc-pay.php и `Stable tag:` в
     readme.txt (обе = 1.0.0);
   - зальёт код в `trunk/` + `tags/1.0.0/`, ассеты (иконка, баннеры) в
     `/assets` SVN;
   - прикрепит zerokyc-pay.zip к GitHub Release v1.0.0.

Через несколько минут плагин появится на
`wordpress.org/plugins/zerokyc-pay` и в поиске внутри WP-админок.

## Шаг 4. Проверка после публикации

- Открой страницу плагина: иконка/баннер на месте, readme рендерится.
- На чистовом WP: Plugins → Add New → поиск "ZeroKYC" → Install → настрой по
  Installation-инструкции (sandbox-ключом можно прогнать безопасно).
- Проверь, что `Test connection` в настройках показывает `sandbox: OK`.

## Следующие релизы

1. Поднять `Version:` в `zerokyc-pay.php` и `Stable tag:` + Changelog в
   `readme.txt`.
2. Коммит в main → CI (тесты + PHPCS) должен быть зелёным.
3. `git tag v1.1.0 && git push origin v1.1.0` — деплой и Release автоматически.

## Где что лежит

| Что | Где |
|---|---|
| Сабмит-зип | `dist/zerokyc-pay.zip` (пересборка: `bin/build-zip.sh`) |
| Ассеты каталога | `.wordpress-org/` (icon.svg/png, banner 772x250 + retina) |
| Тесты / линт | `docker compose run --rm tooling phpunit` / `phpcs` |
| E2E на sandbox | `ZKP_E2E_API_KEY=… ZKP_E2E_WEBHOOK_SECRET=… bin/e2e-sandbox.sh` |
| Спека/план (workspace-репо) | `docs/superpowers/specs/2026-09-12-zerokyc-woocommerce-plugin-design.md`, `docs/superpowers/plans/2026-09-12-zerokyc-woocommerce-plugin.md` |
