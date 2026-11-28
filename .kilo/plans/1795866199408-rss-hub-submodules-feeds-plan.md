# RSS Hub: FreshRSS + rss-bridge (одна фидовая технология)

## ПРОГРЕСС (выполнено, code-агентом)
- Сабмодуль `rss-bridge/` добавлен (upstream rssbridge/rss-bridge, master).
- `feeds-bridges/PetrikircheBridge.php` — работает: Atom 200, 6 недельных айтемов, guid `https://petrikirche-spb.ru/afisha#petri-YYYY-n-j`, pubDate = понедельник.
- `feeds-bridges/AnnenkircheBridge.php` — работает: Atom 200, 1 айтем (порт annen-kirhe-rss).
- docker-compose: сервис `rss-feeds-bridges` (порт 8091), маунты: config + оба адаптера ФАЙЛАМИ в `/app/bridges/` (новый rss-bridge НЕ сканирует custom/). Старый `feeds-bridge`/`runtime/bridge-custom` удалены.
- Нюансы, учтённые в коде: `BridgeAbstract::__construct()` требует 2 аргумента (конструктор у адаптеров убран); `getContents()` не следует редиректам по умолчанию → передаётся `[CURLOPT_FOLLOWLOCATION => true]`; после правки адаптера контейнер нужно `--force-recreate` (file-mount держит inode).
- Локально: контейнер `rss-feeds-bridges` запущен, `runtime/rss-bridge-config.ini.php` создан с плейсхолдером токена; шаблон `.env.example.rss-bridge.ini`.
- Сервер: экспорт готов — `/home/krechet/krechet.opml` (5812 B, подписки+категории) и `/home/krechet/krechet.json` (настройки профиля). Экспорт требует `echo <sudo-pass> | sudo -S -u www-data php /opt/freshrss/cli/export-*... | tee ~/файл`.


## Цель
Головной репо **rss-hub** (`D:\...\freshrss`, GitFlic `dimitriev/rss-hub`; FreshRSS-стек не трогаем — работает) получает **один** сабмодуль:

1. **`rss-bridge/` ← upstream `rssbridge/rss-bridge`** — единый генератор всех фидов:
   - **VK** — штатный бридж **Vk2Bridge** (подтверждено: `access_token` в конфиге + VK API `wall.get` v5.131).
   - **Кастомные адаптеры** — свои PHP-бриджи в папке **`feeds-adapters/`** головного репо (НЕ в апстрим-сабмодуле), подключаются в rss-bridge штатным механизмом `custom/` (mount/symlink):
     - `PetrikircheBridge` — по образцу annen-kirhe-логики: индекс `petrikirche-spb.ru/afisha` → страницы месяцев (`concert-schedule-*`) → группировка по неделям Пн–Вс, **одна новость на неделю**, guid = понедельник недели (`petri-YYYY-mM-dD`), pubDate = понедельник (как в текущем рабочем `feeds-hub/server.js`, только на PHP);
     - `AnnenkircheBridge` — порт `annen-kirhe-rss/server.js`: Tilda-парсер (`t521__row` на `annenkirche.com`), одна новость на текущую неделю, guid `YYYY-mM-dD` (понедельник), pubDate = понедельник.

## Наружный домен `feeds.adventure-awaits.ru` (nginx rewrite → rss-bridge)
- `/petrikirche` → `/?action=display&bridge=Petrikirche&format=Atom`
- `/annenkirche` → `/?action=display&bridge=Annenkirche&format=Atom`
- `/vk/vbazetrpg` → `/?action=display&bridge=Vk2&u=vbazetrpg&format=Atom`
- `/vk/jrrtolkien` → `/?action=display&bridge=Vk2&u=jrrtolkien&format=Atom`

## Структура rss-hub
```
rss-hub/
├── docker-compose.yml      # freshrss + db + rss-bridge (локально)
├── README.md               # «RSS Hub»
├── feeds-adapters/         # свои PHP-бриджи (версионируются здесь)
│   ├── PetrikircheBridge.php
│   └── AnnenkircheBridge.php
├── rss-bridge/             # сабмодуль апстрима rssbridge/rss-bridge (нетронутый)
└── runtime/                # данные FreshRSS; rss-bridge-config с токеном (git-ignored)
```

## Порядок работ

### 0. Синхронизация фидов/учётки сервер → локаль
- Решение: разово сейчас + постоянный **`scripts/pull-from-server.ps1`** в репо rss-hub (ручной запуск; автозапуска нет — синк перезаписывает локальные подписки) + **Kilo-скилл** для вызова из агента.
- Скрипт делает: ssh-export OPML/JSON с сервера (`echo <pass> | sudo -S -u www-data php /opt/freshrss/cli/export-{opml,json}-for-user.php --user krechet | tee ~/{krechet.opml,krechet.json}`) → scp в temp → `docker compose cp` в контейнер freshrss → `php ./cli/import-{opml,json}-for-user.php --user krechet <file>` → проверка количества подписок. Пароль sudo передавать параметром `-SudoPassword` или спросить; ssh-ключи уже настроены.
- Kilo-скилл `freshrss-sync`: файл **`D:\...\freshrss\.kilo\skills\freshrss-sync\SKILL.md`** (внутри этой папки/репо, версионируется в rss-hub; как у остальных скиллов — описание + шаги). Описание: «Синхронизация подписок/настроек FreshRSS с VPS (185.72.146.91) в локальный Docker — запуск scripts/pull-from-server.ps1». Скилл только документирует вызов скрипта; логика живёт в скрипте в репо.

### 1. Сабмодули и адаптеры — ВЫПОЛНЕНО (см. ПРОГРЕСС)
- `git submodule add https://github.com/RSS-Bridge/rss-bridge.git rss-bridge`
- Убрать старый сервис `feeds-bridge` из compose (прототип), удалить `runtime/bridge-custom/`.
- Написать `feeds-adapters/PetrikircheBridge.php` и `feeds-adapters/AnnenkircheBridge.php` (порт логики из `feeds-hub/server.js` и `annen-kirhe-rss/server.js`: multi-page fetch, недельная группировка, guid/pubDate по понедельникам; кэш rss-bridge — 30 мин).
- Локально подключить: compose-сервис `rss-bridge` (image `rssbridge/rss-bridge:latest`, порт 8091, volumes `./rss-bridge:/app` + `./feeds-adapters:/app/custom` + `./runtime/rss-bridge-config.ini.php:/app/config.ini.php:ro`).
  - Если образ не подхватывает `/app/custom` — fallback: копировать адаптеры при старте или вести форк; проверить первым делом.
- VK-токен (действие пользователя): implicit-flow `https://oauth.vk.com/authorize?client_id=2685278&scope=wall,offline&response_type=token` → `[Vk2] access_token = ...` в `runtime/rss-bridge-config.ini.php`.
- Локальная проверка `127.0.0.1:8091`:
  - `?action=display&bridge=Petrikirche&format=Atom` → ~6 недельных айтемов;
  - `?action=display&bridge=Annenkirche&format=Atom` → 1 айтем;
  - `?action=display&bridge=Vk2&u=vbazetrpg&format=Atom` → айтемы; то же `jrrtolkien`.

### 1a. Остатки шага 1
- VK-токен (действие пользователя): implicit-flow `https://oauth.vk.com/authorize?client_id=2685278&scope=wall,offline&response_type=token` → вписать в `runtime/rss-bridge-config.ini.php` `[Vk2] access_token = ...`, `docker compose up -d --force-recreate rss-feeds-bridges`.
- Проверить `?action=display&bridge=Vk2&u=vbazetrpg&format=Atom` и `u=jrrtolkien`.
- README rss-hub: описать FreshRSS-стек + сабмодуль rss-bridge + `feeds-bridges/`, маршруты, обновление (`git submodule update --remote`).
- Коммит + пуш rss-hub: origin = gitflic `dimitriev/rss-hub`, mirror github `dobien/rss-hub` (github опционален).

### 2. Деплой на сервер (185.72.146.91, krechet, порт 729; БЕЗ Docker)
- `git clone --depth 1 https://github.com/RSS-Bridge/rss-bridge /opt/rss-bridge`
- `feeds-bridges/` с сервера → положить файлы в `/opt/rss-bridge/bridges/` (PetrikircheBridge.php, AnnenkircheBridge.php; можно scp из rss-hub).
- `config.ini.php` с Vk2-токеном (chmod 600), кэш-папка writable; в конфиге включить `follow` редиректы или адаптеры уже передают CURLOPT_FOLLOWLOCATION.
- Отдельный php-fpm pool `rss-bridge` (listen 127.0.0.1:9001, user krechet).
- nginx `feeds.adventure-awaits.ru`: rewrites → fastcgi на 9001 (root=/opt/rss-bridge):
  - `/petrikirche` → `/?action=display&bridge=Petrikirche&format=Atom`
  - `/annenkirche` → `/?action=display&bridge=Annenkirche&format=Atom`
  - `/vk/<группа>` → `/?action=display&bridge=Vk2&u=<группа>&format=Atom`
  - сам UI rss-bridge наружу не светить.
- `certbot --nginx -d feeds.adventure-awaits.ru` (DNS уже резолвится).
- Проверка curl: все 4 маршрута по https, код 200, XML валиден.

### 3. FreshRSS (сервер) — подписки
- Добавить фиды: `https://feeds.adventure-awaits.ru/petrikirche`, `/annenkirche`, `/vk/vbazetrpg`, `/vk/jrrtolkien`.
- Удалить старые/ручные версии этих подписок, если есть.

### 4. Уборка (только после проверки ВСЕГО, по подтверждению пользователя)
- TS-путь закрывается: удалить репо **rss-feeds-hub** (GitFlic `dimitriev/rss-feeds-hub`, GitHub `dobien/rss-feeds-hub`) и папку `D:\...\feeds-hub\`.
- Удалить `D:\...\annen-kirhe-rss\` и его репо на GitFlic/GitHub.
- Локально: `docker rm -f feeds-bridge` если жив; остановить фоновый node-сервер на 3003.
- Переименовать локальную папку головного репо: `D:\...\freshrss` → **`D:\...\rss-hub`** (имя уже совпадает с репо на GitFlic/GitHub). Compose-проект называется `freshrss-local` (фиксирован в yml), поэтому контейнеры/тома не пострадают; после переименования проверить `docker compose ps` из новой папки.

## Риски
- rss-bridge docker-образ и поддержка `custom/`: проверить сразу; fallback — копирование адаптеров или лёгкий форк.
- Vk2Bridge: лимиты VK API (5000 wall.get/день; error 29 → сам ставит rate-limit 30 мин); токен может отозваться.
- Tilda-разметка Анненкирхе может измениться (в коде warning «parsed 0 rows»).
- Порт логики на PHP — новый код, обязательно сравнить выдачу с рабочим TS/JS (количество недель, guid'ы).

## Валидация
- Локально: 3 бриджа отдают XML; Petrikirche guid'ы совпадают с TS-версией (`petri-YYYY-mM-dD`, понедельники).
- Сервер: 4 https-маршрута 200/XML.
- FreshRSS: фиды в категориях, записи видны; после этого — уборка (шаг 5).
