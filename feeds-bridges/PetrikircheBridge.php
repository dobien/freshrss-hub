<?php

/**
 * Петрикирхе — афиша недели (petrikirche-spb.ru).
 *
 * Логика (по образцу annen-kirhe-rss):
 *  - тянем страницу-индекс афиши, находим ссылки на страницы месяцев (concert-schedule-*);
 *  - каждая страница месяца парсится на концерты (блоки, разделённые <hr>, с датой «25 ноября, среда, 19.00»);
 *  - концерты группируются по неделям (Пн–Вс);
 *  - одна неделя = одна новость: «События недели 24–30 ноября», guid = понедельник недели
 *    (petri-YYYY-mM-dD, стабильный — контент недели обновляется в той же записи), pubDate = понедельник.
 */
class PetrikircheBridge extends BridgeAbstract
{
    const MAINTAINER = 'dobien';
    const NAME = 'Петрикирхе — афиша недели';
    const URI = 'https://petrikirche-spb.ru/afisha';
    const CACHE_TIMEOUT = 1800; // 30 минут
    const DESCRIPTION = 'Афиша концертов Петрикирхе по неделям: одна неделя — одна новость';
    const PARAMETERS = [];

    const INDEX_URL = 'https://petrikirche-spb.ru/afisha';

    private const MONTHS_RU = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
        'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];

    private function monthNumber(string $ru): ?int
    {
        $i = array_search(mb_strtolower($ru), self::MONTHS_RU, true);
        return $i === false ? null : $i + 1;
    }

    public function collectData()
    {
        $indexHtml = $this->fetchPage(self::INDEX_URL);

        // Ссылки на страницы месяцев ( concert-schedule-* ), до 6 штук.
        preg_match_all('/https:\/\/petrikirche-spb\.ru\/[a-z0-9-]*concert-schedule[a-z0-9-]*\/?/i', $indexHtml, $m);
        $pages = [];
        foreach ($m[0] as $p) {
            $pages[rtrim($p, '/') . '/'] = true;
        }
        $pages = array_slice(array_keys($pages), 0, 6);

        if (empty($pages)) {
            throwServerException('Petrikirche: no concert-schedule pages found on index');
        }

        $events = [];
        $now = new DateTimeImmutable();

        foreach ($pages as $page) {
            try {
                $html = $this->fetchPage($page);
            } catch (\Exception $e) {
                continue;
            }
            foreach ($this->extractEvents($html, $page, $now) as $ev) {
                $events[$ev['key']] = $ev;
            }
        }

        usort($events, fn ($a, $b) => $a['date'] <=> $b['date']);

        // Группировка по неделям (Пн–Вс недели события); отдаём текущую и будущие недели.
        $currentMonday = $this->mondayOf($now);
        $weeks = [];
        foreach ($events as $ev) {
            $monday = $this->mondayOf($ev['date']);
            if ($monday < $currentMonday) {
                continue;
            }
            $key = $monday->format('Y-n-j');
            if (!isset($weeks[$key])) {
                $weeks[$key] = ['monday' => $monday, 'events' => []];
            }
            $weeks[$key]['events'][] = $ev;
        }
        ksort($weeks);

        foreach ($weeks as $week) {
            $monday = $week['monday'];
            $sunday = $monday->modify('+6 days');

            $desc = '';
            foreach ($week['events'] as $ev) {
                $desc .= '<b>' . e($this->fmtDate($ev['date']) . ($ev['time'] ? ', ' . $ev['time'] : '')) . '</b>'
                    . ' — <b>' . e($ev['title']) . '</b><br/>' . $ev['html'] . '<br/><br/>';
            }

            $guid = 'petri-' . $monday->format('Y-n-j');
            $this->items[] = [
                'uri' => self::INDEX_URL . '#' . $guid,
                'title' => 'Петрикирхе: события недели ' . $this->fmtDate($monday)
                    . ' — ' . $this->fmtDate($sunday)
                    . ' (' . count($week['events']) . ')',
                'timestamp' => $monday->getTimestamp(),
                'content' => '<p>' . $desc . '</p>',
            ];
        }
    }

    /** Извлечение концертов из HTML страницы месяца: блоки, разделённые <hr>. */
    private function extractEvents(string $html, string $pageUrl, DateTimeImmutable $now): array
    {
        if (preg_match('/<div class="entry-content"[^>]*>([\s\S]*?)<\/div>\s*<!--\s*\.entry-content/', $html, $cm)) {
            $html = $cm[1];
        }
        $blocks = preg_split('/<hr[^>]*>/i', $html) ?: [];
        $events = [];
        $seen = [];

        foreach ($blocks as $block) {
            if (!preg_match('/\d{1,2}\s+[а-яё]+/i', $block, $startM, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $tail = substr($block, $startM[0][1]);
            $months = implode('|', self::MONTHS_RU);
            if (!preg_match('/(\d{1,2})\s+(' . $months . ')/i', $tail, $dm)) {
                continue;
            }
            $day = (int) $dm[1];
            $month = $this->monthNumber($dm[2]);
            if (!$month) {
                continue;
            }

            // Год: текущий; если месяц получился в прошлом более чем на 45 дней — следующий.
            $year = (int) $now->format('Y');
            $date = new DateTimeImmutable(sprintf('%d-%02d-%02d', $year, $month, $day));
            if ($date->getTimestamp() < $now->getTimestamp() - 45 * 86400) {
                $date = $date->modify('+1 year');
            }

            // Время «19.00» или «19:00».
            $time = '';
            if (preg_match('/\d{1,2}[.:]\d{2}/', $tail, $tm)) {
                $time = str_replace('.', ':', $tm[0]);
            }

            // Название: строки после даты внутри первого <strong>, иначе первое «...».
            $title = '';
            if (preg_match('/<strong>([\s\S]*?)<\/strong>/i', $block, $sm)) {
                $lines = array_filter(array_map('trim', preg_split('/<br\s*\/?>/i', $sm[1]) ?: []), 'strlen');
                $nameLines = [];
                foreach ($lines as $line) {
                    $clean = $this->cleanText($line);
                    if ($clean !== '' && !preg_match('/^\d{1,2}\s+(' . $months . ')/iu', $clean)) {
                        $nameLines[] = $clean;
                    }
                }
                $title = mb_substr(implode(' — ', $nameLines), 0, 140);
            }
            if ($title === '' && preg_match('/«([^»]{2,120})»/u', $block, $qm)) {
                $title = $this->cleanText($qm[1]);
            }
            if ($title === '') {
                $title = 'Концерт';
            }

            // Ссылка на билеты: первый внешний <a href> в блоке.
            $link = $pageUrl;
            if (preg_match('/<a[^>]+href="(https?:\/\/[^"]+)"/i', $block, $lm)) {
                $link = $lm[1];
            }

            $key = $date->format('Y-n-j') . ':' . $time . ':' . $title;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $events[] = [
                'key' => $key,
                'date' => $date,
                'time' => $time,
                'title' => $title,
                'html' => $this->buildEventHtml($block),
            ];
        }

        return $events;
    }

    /** HTML события: кликабельные ссылки, без script/style/img. */
    private function buildEventHtml(string $block): string
    {
        $s = str_replace('&nbsp;', ' ', $block);
        $s = preg_replace_callback(
            '/<a\s+[^>]*href="(https?:\/\/[^"]+|\/[^"]*)"[^>]*>([\s\S]*?)<\/a>/i',
            function ($mm) {
                $href = $mm[1];
                if (!str_starts_with($href, 'http')) {
                    $href = 'https://petrikirche-spb.ru' . $href;
                }
                return '<a href="' . e($href) . '">' . e($this->cleanText($mm[2])) . '</a>';
            },
            $s
        ) ?? $s;
        $s = preg_replace('/<(script|style)[\s\S]*?<\/\1>/i', '', $s) ?? $s;
        $s = preg_replace('/<img[^>]*>/i', '', $s) ?? $s;
        $s = preg_replace('/<hr[^>]*>/i', '', $s) ?? $s;
        return trim($s);
    }

    private function fetchPage(string $url): string
    {
        return getContents($url, ['User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36'], [CURLOPT_FOLLOWLOCATION => true]);
    }

    /** Понедельник недели, содержащей $d (00:00 локального времени). */
    private function mondayOf(DateTimeImmutable $d): DateTimeImmutable
    {
        $dt = $d->setTime(0, 0, 0);
        $dow = (int) $dt->format('N'); // 1 = Пн … 7 = Вс
        return $dt->modify('-' . ($dow - 1) . ' days');
    }

    private function fmtDate(DateTimeImmutable $d): string
    {
        return $d->format('j') . ' ' . self::MONTHS_RU[(int) $d->format('n') - 1];
    }

    private function cleanText(string $s): string
    {
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML401, 'UTF-8');
        $s = preg_replace('/<[^>]+>/', ' ', $s) ?? $s;
        $s = preg_replace('/[ \t]+/', ' ', $s) ?? $s;
        return trim($s);
    }
}
