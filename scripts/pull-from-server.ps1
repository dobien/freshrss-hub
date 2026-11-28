# Синхронизация подписок/настроек FreshRSS: сервер (VPS) → локальный Docker.
# Запуск вручную:  ./scripts/pull-from-server.ps1 [-SudoPassword <пароль>]
# Перезаписывает локальные подписки пользователя krechet снимком с сервера.

param(
    [string]$Server = '185.72.146.91',
    [int]$SshPort = 729,
    [string]$SshUser = 'krechet',
    [string]$FreshRssUser = 'krechet',
    [string]$SudoPassword = $(if ($env:FRESHRSS_SUDO_PASSWORD) { $env:FRESHRSS_SUDO_PASSWORD } else { Read-Host 'sudo password for server' })
)

$ErrorActionPreference = 'Stop'
$tmp = Join-Path $env:TEMP 'rss-sync'
New-Item -ItemType Directory -Force $tmp | Out-Null
$zip = Join-Path $tmp 'krechet.zip'

Write-Host "[1/4] Экспорт zip на сервере..."
ssh -p $SshPort "$SshUser@$Server" "echo '$SudoPassword' | sudo -S -u www-data php /opt/freshrss/cli/export-zip-for-user.php --user $FreshRssUser 2>/dev/null > ~/$FreshRssUser.zip && ls -la ~/$FreshRssUser.zip"

Write-Host "[2/4] Копирование..."
scp -P $SshPort "$SshUser@$Server`:~/$FreshRssUser.zip" $zip | Out-Null

Write-Host "[3/4] Импорт в локальный FreshRSS..."
docker compose cp $zip freshrss:/tmp/sync.zip
docker compose exec freshrss php ./cli/import-for-user.php --user $FreshRssUser --filename /tmp/sync.zip

Write-Host "[4/4] Обновление фидов..."
docker compose exec freshrss php ./cli/actualize-user.php --user $FreshRssUser 2>&1 | Select-Object -Last 1

Write-Host "Готово: локальные подписки синхронизированы с сервером."
