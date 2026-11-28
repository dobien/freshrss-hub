#Requires -Version 7
<#
.SYNOPSIS
    Синхронизация подписок и настроек FreshRSS: VPS (185.72.146.91) -> локальный Docker.
.DESCRIPTION
    Экспортирует OPML (подписки/категории) и JSON (настройки профиля) с серверного
    FreshRSS через его CLI и импортирует в локальный контейнер freshrss.
    ВНИМАНИЕ: перезаписывает подписки/настройки ЛОКАЛЬНОГО пользователя krechet.
.PARAMETER SudoPassword
    Пароль sudo пользователя krechet на сервере. Если не указан — запрашивается интерактивно.
.PARAMETER ServerUser
    SSH-пользователь сервера (по умолчанию krechet).
.PARAMETER FreshRssUser
    Пользователь FreshRSS на обеих сторонах (по умолчанию krechet).
.EXAMPLE
    ./scripts/pull-from-server.ps1 -SudoPassword 'xxx'
#>
param(
    [string]$SudoPassword = $(Read-Host 'sudo password (server)'),
    [string]$ServerUser = 'krechet',
    [string]$FreshRssUser = 'krechet'
)

$ErrorActionPreference = 'Stop'
$sshArgs = @('-p', '729', "$ServerUser@185.72.146.91")
$tmp = Join-Path ([IO.Path]::GetTempPath()) "freshrss-sync-$([guid]::NewGuid())"
New-Item -ItemType Directory -Path $tmp | Out-Null

function Invoke-Ssh([string]$cmd) {
    & ssh @sshArgs $cmd
    if ($LASTEXITCODE -ne 0) { throw "ssh command failed: $cmd" }
}

Write-Host '1/4 Экспорт OPML и настроек на сервере...'
$export = "echo '$SudoPassword' | sudo -S -u www-data php /opt/freshrss/cli/export-opml-for-user.php --user $FreshRssUser 2>/dev/null > ~/freshrss-sync.opml; " +
          "echo '$SudoPassword' | sudo -S -u www-data php /opt/freshrss/cli/export-json-for-user.php --user $FreshRssUser 2>/dev/null > ~/freshrss-sync.json; " +
          "wc -c ~/freshrss-sync.opml ~/freshrss-sync.json"
Invoke-Ssh $export

Write-Host '2/4 Скачивание...'
& scp -P 729 "${ServerUser}@185.72.146.91:~/freshrss-sync.opml" "${ServerUser}@185.72.146.91:~/freshrss-sync.json" $tmp
if ($LASTEXITCODE -ne 0) { throw 'scp failed' }

Write-Host '3/4 Импорт в локальный FreshRSS...'
& docker compose cp "$tmp/freshrss-sync.opml" "freshrss:/tmp/sync.opml"
if ($LASTEXITCODE -ne 0) { throw 'docker compose cp failed (стек запущен? docker compose up -d)' }
& docker compose exec -T freshrss php ./cli/import-for-user.php --user $FreshRssUser --filename /tmp/sync.opml
# Настройки (JSON): CLI-скрипт export-json-for-user.php появился только в новых версиях FreshRSS;
# если на сервере его нет — пропускаем с предупреждением.
$jsonHead = Get-Content "$tmp/freshrss-sync.json" -Raw
if ($jsonHead.TrimStart().StartsWith('{')) {
    & docker compose cp "$tmp/freshrss-sync.json" "freshrss:/tmp/sync.json"
    & docker compose exec -T freshrss php ./cli/import-for-user.php --user $FreshRssUser --filename /tmp/sync.json
} else {
    Write-Host 'Настройки (JSON): серверная версия FreshRSS не поддерживает export-json — пропущено (импортированы только подписки/категории).'
}

Write-Host '4/4 Очистка...'
Invoke-Ssh "rm -f ~/freshrss-sync.opml ~/freshrss-sync.json"
Remove-Item $tmp -Recurse -Force

Write-Host "Готово. Проверьте http://127.0.0.1:8090 (пользователь $FreshRssUser)."
