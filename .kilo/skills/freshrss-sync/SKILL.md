---
name: freshrss-sync
description: Синхронизация подписок/настроек FreshRSS с VPS (185.72.146.91) в локальный Docker — запуск scripts/pull-from-server.ps1. Использовать, когда пользователь просит «синхронизируй фиды с сервера», «стяни подписки», обновить локальную копию FreshRSS.
---

# freshrss-sync

Логика — в скрипте репо: `scripts/pull-from-server.ps1` (rss-hub).

1. Убедиться, что локальный стек поднят: `docker compose up -d` в `D:\Work\Software_Development\Projects\freshrss`.
2. Запустить: `./scripts/pull-from-server.ps1` (пароль sudo сервера спросить у пользователя или из `$env:FRESHRSS_SUDO_PASSWORD`; ssh: krechet@185.72.146.91:729).
3. Скрипт: экспорт zip на сервере → scp → `import-for-user.php` → `actualize-user.php`. Перезаписывает локальные подписки пользователя krechet — НЕ запускать на автомате при старте сессии.
4. Проверка: http://127.0.0.1:8090 (логин krechet), `docker compose exec freshrss php ./cli/list-users.php`.
