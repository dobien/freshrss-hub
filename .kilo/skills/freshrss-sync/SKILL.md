---
name: freshrss-sync
description: Синхронизация подписок/настроек FreshRSS с VPS (185.72.146.91) в локальный Docker — запуск scripts/pull-from-server.ps1. Использовать, когда нужно стянуть актуальные подписки/категории/настройки профиля с серверного FreshRSS в локальный.
---

# FreshRSS sync (server → local)

Запускает `scripts/pull-from-server.ps1` из корня репо rss-hub.

Шаги:
1. Убедиться, что локальный стек запущен: `docker compose up -d` (репо `D:\Work\Software_Development\Projects\freshrss`).
2. Запросить у пользователя sudo-пароль сервера (krechet@185.72.146.91, порт 729), если не дан.
3. Выполнить:
   `pwsh -File scripts/pull-from-server.ps1 -SudoPassword '<пароль>'`
4. Скрипт перезаписывает подписки/настройки ЛОКАЛЬНОГО пользователя `krechet` — предупредить пользователя.
5. Проверка: http://127.0.0.1:8090 — категории и подписки с сервера.

Данные: OPML (подписки) + JSON (настройки) экспортируются CLI серверного FreshRSS (`/opt/freshrss/cli/export-*`), импортируются CLI локального контейнера. Тип БД не важен (сервер SQLite, локаль MariaDB).
