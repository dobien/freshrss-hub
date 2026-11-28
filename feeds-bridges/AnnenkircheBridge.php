<?php

/**
 * Анненкирхе — расписание недели (annenkirche.com).
 *
 * Порт логики annen-kirhe-rss (server.js):
 *  - парсим Tilda-блоки расписания (t521__row: дата + текст);
 *  - всё расписание текущей недели отдаётся одной новостью;
 *  - guid = понедельник недели (YYYY-n-j, стабильный — контент обновляется в той же записи),
 *    pubDate = понедельник.
 */
class AnnenkircheBridge extends BridgeAbstract
{
    const MAINTAINER = 'dobien';
    const NAME = 'Анненкирхе — расписание';
    const URI = 'https://www.annenkirche.com/';
    const CACHE_TIMEOUT = 1800; // 30 минут
    const DESCRIPTION = 'Расписание недели одной новостью: режим работы, выставки, экскурсии, концерты';
    const PARAMETERS = [];

    const PAGE_URL = 'https://www.annenkirche.com/';

    private const MONTHS_RU = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    public function collectData()
    {
        $html = getContents(self::PAGE_URL, ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'], [CURLOPT_FOLLOWLOCATION => true]);

        $rows = [];
        if (preg_match_all('/<div class="t521__row t-row t-clear">[\s\S]*?<div class="t521__time[^"]*"[^>]*>([\s\S]*?)<\/div>[\s\S]*?<div class="t521__text[^"]*"[^>]*>([\s\S]*?)<\/div>/', $html, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $rows[] = [
                    'date' => $this->cleanText($row[1]),
                    'html' => $this->htmlText($row[2]),
                ];
            }
        }

        if (empty($rows)) {
            throwServerException('Annenkirche: parsed 0 schedule rows — Tilda markup may have changed');
        }

        $desc = '';
        foreach ($rows as $row) {
            $desc .= '<b>' . e($row['date']) . '</b><br/>' . $row['html'] . '<br/><br/>';
        }

        $now = new DateTimeImmutable();
        $monday = $now->setTime(0, 0, 0)->modify('-' . ((int) $now->format('N') - 1) . ' days');
        $sunday = $monday->modify('+6 days');

        $this->items[] = [
            'uri' => self::PAGE_URL . '#week-' . $monday->format('Y-n-j'),
            'title' => 'Неделя ' . $this->fmtDate($monday) . ' — ' . $this->fmtDate($sunday),
            'timestamp' => $monday->getTimestamp(),
            'content' => '<p>' . $desc . '</p>',
        ];
    }

    /** Читаемый HTML: ссылки кликабельные, каждый пункт на своей строке. */
    private function htmlText(string $src): string
    {
        $src = str_replace(['&nbsp;', '&laquo;', '&raquo;', '&lt;', '&gt;'], [' ', '«', '»', '<', '>'], $src);
        $src = preg_replace('/<br\s*\/?>\s*<br\s*\/?>/i', "\n\n", $src) ?? $src;
        $src = preg_replace('/<br\s*\/?>/i', "\n", $src) ?? $src;

        $entries = [];
        $buf = '';
        $last = 0;
        $flush = function () use (&$buf, &$entries) {
            $c = trim(preg_replace('/[ \t]+/', ' ', $buf) ?? '');
            if ($c !== '') {
                $entries[] = $c;
            }
            $buf = '';
        };

        if (preg_match_all('/<a\s+[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/i', $src, $am, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            foreach ($am as $mm) {
                $textPart = substr($src, $last, $mm[0][1] - $last);
                $this->appendText($textPart, $buf, $entries, $flush);
                $txt = trim(strip_tags($mm[2][0]));
                if ($txt !== '') {
                    $buf .= ' <a href="' . e($mm[1][0]) . '">' . e($txt) . '</a>';
                }
                $last = $mm[0][1] + strlen($mm[0][0]);
            }
        }
        $this->appendText(substr($src, $last), $buf, $entries, $flush);
        $flush();

        return implode('<br/>', $entries);
    }

    /** Текстовый сегмент: теги долой, \n управляют разбивкой пунктов. */
    private function appendText(string $part, string &$buf, array &$entries, callable $flush): void
    {
        $part = preg_replace('/<[^>]+>/', '', $part) ?? $part;
        $lines = explode("\n", $part);
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                if (trim($lines[$i - 1]) === '') {
                    if (end($entries) !== '') {
                        $entries[] = '';
                    }
                } else {
                    $flush();
                }
            }
            $clean = trim(preg_replace('/[ \t]+/', ' ', e($line)) ?? '');
            if ($clean !== '') {
                $buf .= ($buf !== '' ? ' ' : '') . $clean;
            }
        }
    }

    private function fmtDate(DateTimeImmutable $d): string
    {
        return $d->format('j') . ' ' . self::MONTHS_RU[(int) $d->format('n') - 1];
    }

    private function cleanText(string $s): string
    {
        $s = str_replace(['&nbsp;', '&amp;', '&laquo;', '&raquo;', '&quot;', '&lt;', '&gt;'],
            [' ', '&', '«', '»', '"', '<', '>'], $s);
        // Ссылки → «текст (url)»
        $s = preg_replace('/<a [^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/i', '$2 ($1)', $s) ?? $s;
        $s = preg_replace('/<br\s*\/?>/i', ' — ', $s) ?? $s;
        $s = preg_replace('/<[^>]+>/', '', $s) ?? $s;
        $s = preg_replace('/( — ?)+/', ' — ', $s) ?? $s;
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;
        $s = preg_replace("/\n{2,}/", "\n", $s) ?? $s;
        return trim($s);
    }
}
