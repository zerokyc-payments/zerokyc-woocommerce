# SUBMISSION.md — публикация zerokyc-pay на WordPress.org

Состояние на 2026-09-17: плагин готов — официальный **Plugin Check: 0 ошибок**
(19 предупреждений `DirectDatabaseQuery` на наших таблицах идемпотентности —
осознанное решение для race-safe вебхуков), PHPCS WordPress-Core чист, 48
тестов зелёные, CI зелёный. Репо: `zerokyc-payments/zerokyc-woocommerce`.
Сабмит-зип: `dist/zerokyc-pay.zip`.

## ВАЖНО: слаг плагина

WordPress.org генерирует постоянный слаг из `Plugin Name` в **главном файле**
зипа. Поэтому на сабмите имя = **`ZeroKYC Pay`** → слаг `zerokyc-pay`.
Полное название «ZeroKYC – Crypto Payments for WooCommerce» вернём сразу после
апрува (шаг 3.1) — слаг к тому моменту уже зафиксирован и не изменится.

## Шаг 1. Отправить на ревью (5 минут)

1. Убедись, что аккаунт **zerokypayments** создан на wordpress.org (email на
   домене zerokyc-payments.com — ревьюеры сверяют представительство бренда;
   `plugins@wordpress.org` в белом списке почты).
2. Открой **https://wordpress.org/plugins/developers/add/** под этим аккаунтом.
3. Plugin name: `ZeroKYC Pay`
4. Plugin description (вставь как есть):

   > ZeroKYC Pay is a crypto payment gateway for WooCommerce. The buyer pays in
   > USDT, USDC, BTC, XMR or TON through the ZeroKYC hosted checkout — no KYC
   > for buyers, no wallet keys on the merchant server. Orders are completed by
   > HMAC-SHA256 verified webhooks with replay protection and an optional
   > server-side double check; a WP-Cron job reconciles lost webhooks every 15
   > minutes. This is the official plugin by ZeroKYC Payments
   > (zerokyc-payments.com), powered by our open-source PHP SDK
   > (github.com/zerokyc-payments/zkp-sdk-php, MIT). Service terms:
   > zerokyc-payments.com/terms, privacy: zerokyc-payments.com/privacy.

5. Прикрепи **dist/zerokyc-pay.zip** → Submit.

Если ревьюер спросит про права на бренд: аккаунт zerokypayments, домен
zerokyc-payments.com, сервис принадлежит тебе. Слаг можно изменить один раз до
начала ревью (ссылка на странице сабмита), но при имени «ZeroKYC Pay» он сразу
будет правильным.

## Шаг 2. Ждать апрува

Обычно 1–10 дней. До письма-апрува **не** пушь git-теги `v*` и не меняй папку
`.wordpress-org/` (workflows деплоя сработают и упадут без SVN-доступа).

## Шаг 3. После письма-апрува (15 минут)

1. **Вернуть полное название** (слаг уже зафиксирован): в `zerokyc-pay.php`
   `Plugin Name: ZeroKYC – Crypto Payments for WooCommerce`, заголовок
   `readme.txt` — такой же. Коммит в main.
2. На GitHub: **Settings → Secrets and variables → Actions → New repository
   secret**:
   - `SVN_USERNAME` = `zerokypayments`
   - `SVN_PASSWORD` = пароль аккаунта WP.org
3. Локально:

   ```bash
   git checkout main && git pull
   git tag v1.0.0
   git push origin main --tags
   ```

4. Workflow **Deploy to WordPress.org** сам:
   - проверит соответствие тега `Version:` и `Stable tag:` (обе 1.0.0);
   - зальёт код в `trunk/` + `tags/1.0.0/`, ассеты (иконка, баннеры) в `/assets`;
   - прикрепит zerokyc-pay.zip к GitHub Release v1.0.0.

Через несколько минут плагин появится на
`wordpress.org/plugins/zerokyc-pay` и в поиске внутри WP-админок.

## Шаг 4. Проверка после публикации

- Страница плагина: иконка/баннер на месте, readme рендерится, имя полное.
- На чистовом WP: Plugins → Add New → поиск "ZeroKYC" → Install → настроить по
  Installation-инструкции (sandbox-ключом можно прогонять безопасно).
- `Test connection` в настройках должен показать `sandbox: OK`.

## Следующие релизы

1. Поднять `Version:` в `zerokyc-pay.php` и `Stable tag:` + Changelog в
   `readme.txt`.
2. Коммит в main → CI зелёный.
3. `git tag v1.1.0 && git push origin v1.1.0` — деплой и Release автоматически.

## Где что лежит

| Что | Где |
|---|---|
| Сабмит-зип | `dist/zerokyc-pay.zip` (пересборка: `bin/build-zip.sh`) |
| Ассеты каталога | `.wordpress-org/` (icon.svg/png, banner 772x250 + retina) |
| Тесты / линт | `docker compose run --rm tooling phpunit` / `phpcs` |
| Официальная проверка | Plugin Check: `wp plugin install plugin-check && wp plugin check zerokyc-pay` |
| E2E на sandbox | `ZKP_E2E_API_KEY=… ZKP_E2E_WEBHOOK_SECRET=… bin/e2e-sandbox.sh` |
| Спека/план (workspace-репо) | `docs/superpowers/specs/2026-09-12-zerokyc-woocommerce-plugin-design.md` |
