<?php

/**
 * OzonParser.php
 *
 * Класс для парсинга страницы товара Ozon по URL (например https://www.ozon.ru/product/{OZON SKU}/)
 *
 * Результат: ассоциативный массив с полями:
 * - title
 * - category
 * - type
 * - country
 * - manufacturer_part_number
 * - country_of_manufacture
 * - images (массив url)
 * - description (текст)
 * - characteristics (массив)
 *
 */

class OzonParser
{
    // Таймауты для cURL
    protected $connectTimeout = 10;
    protected $timeout = 30;
    protected $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' .
                           '(KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    /**
     * Основной метод: принимает URL страницы товара Ozon, возвращает массив с результатами парсинга.
     *
     * @param string $url
     * @return array
     * @throws Exception
     */
    public function parse(string $url): array
    {
        // Загружаем HTML страницы
        $html = $this->fetchUrl($url);

        if (!$html) {
            throw new Exception('Не удалось загрузить страницу: ' . $url);
        }

        // Попробуем разные стратегии: 1) JSON-LD 2) window.__INITIAL_STATE__ 3) DOM fallback
        // Результирующий массив инициализируем пустыми значениями
        $result = [
            'title' => '',
            'category' => '',
            'type' => '',
            'country' => '',
            'manufacturer_part_number' => '',
            'country_of_manufacture' => '',
            'images' => [],
            'description' => '',
            'characteristics' => [], // массив элементов ['name'=>..., 'value'=>...]
        ];

        // 1) Попробуем извлечь из JSON-LD (application/ld+json)
        $jsonLd = $this->extractJsonLd($html);
        if ($jsonLd) {
            $this->fillFromJsonLd($jsonLd, $result);
        }

        // 2) Попробуем извлечь из window.__INITIAL_STATE__
        $initialState = $this->extractInitialStateJson($html);
        if ($initialState) {
            $this->fillFromInitialState($initialState, $result);
        }

        // 3) Если каких-то полей не хватает — пробуем DOM-парсинг страницы
        $this->fillFromDom($html, $result);

        // Небольшая постобработка: привести характеристики к удобному виду (массив name=>value)
        $result['characteristics'] = $this->normalizeCharacteristics($result['characteristics']);

        return $result;
    }

    /**
     * Загрузить URL через cURL и вернуть HTML (строка)
     *
     * @param string $url
     * @return string|null
     */
    protected function fetchUrl(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        // Установим некоторые заголовки, чтобы имитировать браузер
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language: ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
        ]);

        $html = curl_exec($ch);
        $err = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || !$html || $status >= 400) {
            return null;
        }

        return $html;
    }

    /**
     * Извлечь JSON-LD (application/ld+json) из HTML — возвращает декодированный массив/объект или null
     *
     * @param string $html
     * @return array|null
     */
    protected function extractJsonLd(string $html): ?array
    {
        $matches = [];
        // Ищем все <script type="application/ld+json"> ... </script>
        preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $matches);

        if (empty($matches[1])) {
            return null;
        }

        foreach ($matches[1] as $jsonText) {
            $jsonText = trim($jsonText);
            // Иногда внутри могут быть несколько JSON объектов, или массив
            $decoded = json_decode($jsonText, true);
            if ($decoded === null) {
                // Попробуем убрать лишние символы и повторить
                $clean = $this->cleanJsonLike($jsonText);
                $decoded = json_decode($clean, true);
            }
            if ($decoded !== null) {
                // Ищем объект типа Product
                if (isset($decoded['@type']) && stripos((string)$decoded['@type'], 'Product') !== false) {
                    return $decoded;
                }
                // Если это массив, попробуем найти Product внутри
                if (is_array($decoded)) {
                    $found = $this->findInArrayRecursive($decoded, function ($v) {
                        return is_array($v) && isset($v['@type']) && stripos((string)$v['@type'], 'Product') !== false;
                    });
                    if ($found !== null) {
                        return $found;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Попытка очистить/исправить JSON-подобную строку (например убирание JS-слэшей)
     *
     * @param string $s
     * @return string
     */
    protected function cleanJsonLike(string $s): string
    {
        // Удалим HTML-комментарии
        $s = preg_replace('#<!--.*?-->#s', '', $s);
        // Удалим JS-метки типа /* ... */
        $s = preg_replace('#/\*.*?\*/#s', '', $s);
        return $s;
    }

    /**
     * Извлечь window.__INITIAL_STATE__ JSON (или похожую глобальную переменную) — возвращает массив или null
     *
     * @param string $html
     * @return array|null
     */
    protected function extractInitialStateJson(string $html): ?array
    {
        // Ищем "window.__INITIAL_STATE__ = { ... }"
        $pos = strpos($html, 'window.__INITIAL_STATE__');
        if ($pos === false) {
            // Альтернативное имя: "window.__PRELOADED_STATE__" или "__INITIAL_STATE__"
            $pos = strpos($html, 'window.__PRELOADED_STATE__');
            if ($pos === false) {
                $pos = strpos($html, '__INITIAL_STATE__');
            }
            if ($pos === false) {
                return null;
            }
        }

        // Найдем первый символ "{" после знака "="
        $eqPos = strpos($html, '=', $pos);
        if ($eqPos === false) {
            return null;
        }

        $bracePos = strpos($html, '{', $eqPos);
        if ($bracePos === false) {
            return null;
        }

        // Теперь извлечем JSON-объект, учитывая вложенные скобки
        $jsonText = $this->extractBalancedJson($html, $bracePos);
        if (!$jsonText) {
            return null;
        }

        $decoded = json_decode($jsonText, true);
        if ($decoded === null) {
            // Попробуем почистить и декодировать снова
            $decoded = json_decode($this->cleanJsonLike($jsonText), true);
        }

        return $decoded;
    }

    /**
     * Вырезать из строки JSON-объект, начиная с позиции первой '{', используя подсчёт скобок.
     *
     * @param string $s
     * @param int $startPos позиция символа '{'
     * @return string|null
     */
    protected function extractBalancedJson(string $s, int $startPos): ?string
    {
        $len = strlen($s);
        $i = $startPos;
        $depth = 0;
        $inString = false;
        $escape = false;
        for (; $i < $len; $i++) {
            $char = $s[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            } else {
                if ($char === '"') {
                    $inString = true;
                    continue;
                }
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        // Возвращаем от startPos до текущей позиции включительно
                        return substr($s, $startPos, $i - $startPos + 1);
                    }
                }
            }
        }
        return null;
    }

    /**
     * Вспомогательная рекурсивная функция поиска в массиве по предикату
     *
     * @param array $arr
     * @param callable $predicate
     * @return mixed|null
     */
    protected function findInArrayRecursive(array $arr, callable $predicate)
    {
        foreach ($arr as $k => $v) {
            if ($predicate($v, $k)) {
                return $v;
            }
            if (is_array($v)) {
                $res = $this->findInArrayRecursive($v, $predicate);
                if ($res !== null) {
                    return $res;
                }
            }
        }
        return null;
    }

    /**
     * Заполнить результат данными из JSON-LD
     *
     * @param array $json
     * @param array &$result
     * @return void
     */
    protected function fillFromJsonLd(array $json, array &$result): void
    {
        // Название
        if (!empty($json['name'])) {
            $result['title'] = trim($json['name']);
        }

        // Описание
        if (!empty($json['description'])) {
            $result['description'] = trim($json['description']);
        }

        // Изображения — может быть строка или массив
        if (!empty($json['image'])) {
            if (is_array($json['image'])) {
                $result['images'] = array_values(array_unique(array_map('trim', $json['image'])));
            } else {
                $result['images'] = [trim($json['image'])];
            }
        }

        // Характеристики: иногда передаются в 'additionalProperty'
        if (!empty($json['additionalProperty']) && is_array($json['additionalProperty'])) {
            foreach ($json['additionalProperty'] as $prop) {
                if (is_array($prop) && isset($prop['name'])) {
                    $name = $prop['name'];
                    $value = $prop['value'] ?? ($prop['valueString'] ?? '');
                    $result['characteristics'][] = ['name' => $name, 'value' => $value];
                }
            }
        }

        // Возможно указаны бренд/sku
        if (!empty($json['sku'])) {
            $result['manufacturer_part_number'] = (string)$json['sku'];
        }
        if (!empty($json['brand'])) {
            if (is_array($json['brand']) && !empty($json['brand']['name'])) {
                // Можно записать в один из полей, но оставить как характеристика
                $result['characteristics'][] = ['name' => 'Brand', 'value' => $json['brand']['name']];
            } elseif (is_string($json['brand'])) {
                $result['characteristics'][] = ['name' => 'Brand', 'value' => $json['brand']];
            }
        }
    }

    /**
     * Заполнить результат данными из initial state JSON (heuristic search)
     *
     * @param array $state
     * @param array &$result
     * @return void
     */
    protected function fillFromInitialState(array $state, array &$result): void
    {
        // Пытаемся найти узел с информацией о товаре: ищем объект, в котором есть 'id' и 'name'/'title' и 'images' или 'media'
        $productNode = $this->findInArrayRecursive($state, function ($v) {
            if (!is_array($v)) return false;
            $hasId = isset($v['id']) || isset($v['offerId']) || isset($v['productId']);
            $hasName = isset($v['name']) || isset($v['title']);
            $hasImages = isset($v['images']) || isset($v['pictures']) || isset($v['media']);
            return $hasName && ($hasId || $hasImages);
        });

        if (is_array($productNode)) {
            // Название
            if (!empty($productNode['name'])) {
                $result['title'] = $result['title'] ?: trim($productNode['name']);
            } elseif (!empty($productNode['title'])) {
                $result['title'] = $result['title'] ?: trim($productNode['title']);
            }

            // Изображения
            $imgs = [];
            if (!empty($productNode['images']) && is_array($productNode['images'])) {
                // В узлах может быть ['url' => '...'] или просто строки
                foreach ($productNode['images'] as $it) {
                    if (is_string($it)) $imgs[] = $it;
                    elseif (is_array($it) && !empty($it['url'])) $imgs[] = $it['url'];
                    elseif (is_array($it) && !empty($it['original'])) $imgs[] = $it['original'];
                }
            }
            if (empty($imgs) && !empty($productNode['media']) && is_array($productNode['media'])) {
                foreach ($productNode['media'] as $it) {
                    if (is_array($it) && !empty($it['url'])) $imgs[] = $it['url'];
                }
            }
            if ($imgs) {
                $result['images'] = array_values(array_unique(array_merge($result['images'], $imgs)));
            }

            // Описание
            if (!empty($productNode['description'])) {
                $result['description'] = $result['description'] ?: trim($productNode['description']);
            } elseif (!empty($productNode['shortDescription'])) {
                $result['description'] = $result['description'] ?: trim($productNode['shortDescription']);
            }

            // Характеристики - различные ключи: 'characteristics', 'specs', 'attributes'
            $specKeys = ['characteristics', 'specs', 'attributes', 'properties', 'characteristicsData'];
            foreach ($specKeys as $k) {
                if (!empty($productNode[$k]) && is_array($productNode[$k])) {
                    foreach ($productNode[$k] as $spec) {
                        if (is_array($spec)) {
                            $name = $spec['name'] ?? $spec['title'] ?? null;
                            $value = $spec['value'] ?? $spec['values'] ?? null;
                            if ($name && $value !== null) {
                                if (is_array($value)) {
                                    $value = implode(', ', array_map(function ($v) {
                                        return is_array($v) ? ($v['value'] ?? '') : (string)$v;
                                    }, $value));
                                }
                                $result['characteristics'][] = ['name' => $name, 'value' => $value];
                            } elseif ($name && is_string($spec)) {
                                $result['characteristics'][] = ['name' => $name, 'value' => $spec];
                            } else {
                                // Попробуем раскрыть вложенные пары
                                foreach ($spec as $kk => $vv) {
                                    if (is_string($vv) || is_numeric($vv)) {
                                        $result['characteristics'][] = ['name' => $kk, 'value' => (string)$vv];
                                    }
                                }
                            }
                        }
                    }
                    // Если нашли какие-то характеристики — выходим
                    break;
                }
            }

            // Партномер / артикул
            if (!empty($productNode['vendorCode'])) {
                $result['manufacturer_part_number'] = $result['manufacturer_part_number'] ?: (string)$productNode['vendorCode'];
            }
            if (!empty($productNode['sku'])) {
                $result['manufacturer_part_number'] = $result['manufacturer_part_number'] ?: (string)$productNode['sku'];
            }
            if (!empty($productNode['code'])) {
                $result['manufacturer_part_number'] = $result['manufacturer_part_number'] ?: (string)$productNode['code'];
            }

            // Страна-производитель
            if (!empty($productNode['countryOfOrigin'])) {
                $result['country_of_manufacture'] = $result['country_of_manufacture'] ?: (string)$productNode['countryOfOrigin'];
            }
            if (!empty($productNode['country'])) {
                $result['country'] = $result['country'] ?: (string)$productNode['country'];
            }

            // Категория — может быть в breadcrumbs или categories
            if (!empty($productNode['breadcrumbs']) && is_array($productNode['breadcrumbs'])) {
                $cats = array_map(function ($b) {
                    if (is_array($b)) return $b['title'] ?? $b['name'] ?? '';
                    return (string)$b;
                }, $productNode['breadcrumbs']);
                $cats = array_filter($cats);
                if ($cats) {
                    $result['category'] = implode('/', $cats);
                }
            }
            if (empty($result['category']) && !empty($productNode['categories']) && is_array($productNode['categories'])) {
                $cats = array_map(function ($c) { return is_array($c) ? ($c['name'] ?? $c['title'] ?? '') : (string)$c; }, $productNode['categories']);
                $cats = array_filter($cats);
                if ($cats) {
                    $result['category'] = implode('/', $cats);
                }
            }
        }
    }

    /**
     * Заполнить результат частично, используя DOM-парсинг — запасной вариант
     *
     * @param string $html
     * @param array &$result
     * @return void
     */
    protected function fillFromDom(string $html, array &$result): void
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        // Предотвращаем проблемы с кодировкой
        $encoding = mb_detect_encoding($html, ['UTF-8', 'CP1251', 'ISO-8859-1']);
        if ($encoding && strtoupper($encoding) !== 'UTF-8') {
            $html = mb_convert_encoding($html, 'HTML-ENTITIES', $encoding);
        }
        @$dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Заголовок: обычно в <h1>
        if (empty($result['title'])) {
            $h1 = $xpath->query('//h1');
            if ($h1->length) {
                $result['title'] = trim($h1->item(0)->textContent);
            }
        }

        // Категория: хлебные крошки. Ищем элементы с role="navigation" или классами breadcrumb
        if (empty($result['category'])) {
            $cats = [];
            $nodes = $xpath->query('//nav//a | //ul[contains(@class,"breadcrumb")]//a | //div[contains(@class,"breadcrumb")]//a');
            foreach ($nodes as $n) {
                $text = trim($n->textContent);
                if ($text) $cats[] = $text;
            }
            if ($cats) {
                $result['category'] = implode('/', $cats);
            }
        }

        // Изображения: ищем теги <img> внутри галереи либо с data-testid или ролями
        if (empty($result['images'])) {
            $imgUrls = [];
            // Часто галерея содержит data-testid="picture" или класс содержащий "image"
            $queries = [
                '//div[contains(@class,"gallery")]//img',
                '//div[contains(@class,"product")]//img',
                '//img[contains(@class,"image")]',
                '//img[contains(@src,"cdn") or contains(@src,"ozon.ru")]',
                '//picture//img',
            ];
            foreach ($queries as $q) {
                $nodes = $xpath->query($q);
                foreach ($nodes as $n) {
                    /** @var DOMElement $n */
                    $src = $n->getAttribute('src') ?: $n->getAttribute('data-src') ?: $n->getAttribute('data-image');
                    if ($src) {
                        // иногда src может быть относительным
                        $imgUrls[] = $this->absUrl($src);
                    }
                }
                if ($imgUrls) break;
            }
            if ($imgUrls) {
                $result['images'] = array_values(array_unique($imgUrls));
            }
        }

        // Описание: ищем элемент с ролью description или class product-description
        if (empty($result['description'])) {
            $descNodes = $xpath->query('//div[contains(@class,"description") or contains(@class,"product-description") or @id="description"]');
            if ($descNodes->length) {
                $result['description'] = trim($this->innerHtml($descNodes->item(0)));
            } else {
                // fallback: мета description
                $meta = $xpath->query('//meta[@name="description"]');
                if ($meta->length) {
                    $result['description'] = trim($meta->item(0)->getAttribute('content'));
                }
            }
        }

        // Характеристики: ищем таблицы или блоки с парами ключ-значение
        $charCandidates = [];
        // Часто характеристики находятся в блоке с data-widget="webProductCharacteristics" или похожими
        $queries = [
            '//div[contains(@data-widget, "webProductCharacteristics")]',
            '//section[contains(@class,"specs") or contains(@class,"characteristics")]',
            '//table[contains(@class,"specs") or contains(@class,"characteristics")]',
            '//ul[contains(@class,"properties")]',
        ];
        foreach ($queries as $q) {
            $nodes = $xpath->query($q);
            foreach ($nodes as $n) {
                $charCandidates[] = $n;
            }
        }

        // Если ничего не найдено — попробуем общую стратегию: найти все элементы, где есть пара элементов ключ-значение
        if (empty($charCandidates)) {
            // возможная структура: <li><span class="name">Название</span><span class="value">Значение</span></li>
            $nodes = $xpath->query('//li[.//span[contains(@class,"name")] and .//span[contains(@class,"value")]]');
            foreach ($nodes as $n) {
                $charCandidates[] = $n;
            }
        }

        // Извлекаем пары name/value из кандидатов
        $chars = [];
        foreach ($charCandidates as $node) {
            // попробуем несколько вариантов парсинга
            // 1) таблица <tr><th>key</th><td>value</td></tr>
            $trs = $xpath->query('.//tr', $node);
            foreach ($trs as $tr) {
                $th = $xpath->query('.//th', $tr);
                $td = $xpath->query('.//td', $tr);
                if ($th->length && $td->length) {
                    $key = trim($th->item(0)->textContent);
                    $val = trim($td->item(0)->textContent);
                    if ($key) $chars[] = ['name' => $key, 'value' => $val];
                }
            }
            // 2) списки li с двумя span
            $lis = $xpath->query('.//li', $node);
            foreach ($lis as $li) {
                $spans = $xpath->query('.//span', $li);
                if ($spans->length >= 2) {
                    $key = trim($spans->item(0)->textContent);
                    $val = trim($spans->item(1)->textContent);
                    if ($key) $chars[] = ['name' => $key, 'value' => $val];
                } else {
                    // некоторые li содержат "Name: Value"
                    $text = trim($li->textContent);
                    if (preg_match('/^(.+?)\s*[:\-]\s*(.+)$/u', $text, $m)) {
                        $chars[] = ['name' => trim($m[1]), 'value' => trim($m[2])];
                    }
                }
            }

            // 3) пара элементов div span
            $pairs = $xpath->query('.//*[contains(@class,"spec") or contains(@class,"property")]', $node);
            foreach ($pairs as $p) {
                // пытаемся выделить ключ и значение внутри
                $keyNode = $xpath->query('.//*[contains(@class,"name") or contains(@class,"key")]', $p);
                $valNode = $xpath->query('.//*[contains(@class,"value") or contains(@class,"val")]', $p);
                if ($keyNode->length && $valNode->length) {
                    $key = trim($keyNode->item(0)->textContent);
                    $val = trim($valNode->item(0)->textContent);
                    if ($key) $chars[] = ['name' => $key, 'value' => $val];
                }
            }
        }

        if ($chars) {
            $result['characteristics'] = array_merge($result['characteristics'], $chars);
        }

        // Партномер/артикул: ищем по вхождению слов "Артикул", "Партномер", "Vendor code"
        if (empty($result['manufacturer_part_number'])) {
            // Поиск по тексту страницы: "Артикул: XXXX" или "Артикул — XXXX"
            if (preg_match('/Артикул\s*[:\-\x{2014}\x{2013}]\s*([^\s<]+)/u', $html, $m)) {
                $result['manufacturer_part_number'] = trim($m[1]);
            } elseif (preg_match('/Партномер\s*[:\-\x{2014}\x{2013}]\s*([^\s<]+)/iu', $html, $m)) {
                $result['manufacturer_part_number'] = trim($m[1]);
            } else {
                // Попробуем в характеристиках
                foreach ($result['characteristics'] as $c) {
                    if (preg_match('/(Артикул|Партномер|Vendor|Vendor code|Part no|Part number)/iu', $c['name'])) {
                        $result['manufacturer_part_number'] = $result['manufacturer_part_number'] ?: $c['value'];
                        break;
                    }
                }
            }
        }

        // Country / Страна-изготовитель: ищем по ключу "Страна" или "Страна-изготовитель"
        if (empty($result['country_of_manufacture']) || empty($result['country'])) {
            foreach ($result['characteristics'] as $c) {
                if (preg_match('/Страна\s*(изготовитель)?/iu', $c['name'])) {
                    $result['country_of_manufacture'] = $result['country_of_manufacture'] ?: $c['value'];
                    $result['country'] = $result['country'] ?: $c['value'];
                }
            }
        }

        // Если тип не определён — возможно можно взять из категории (последний сегмент)
        if (empty($result['type']) && !empty($result['category'])) {
            $parts = explode('/', $result['category']);
            $result['type'] = trim(end($parts));
        }
    }

    /**
     * Преобразовать характеристики в удобный одноуровневый массив (name => value) при необходимости.
     *
     * @param array $chars входные массивы ['name'=>,'value'=>]
     * @return array
     */
    protected function normalizeCharacteristics(array $chars): array
    {
        $out = [];
        foreach ($chars as $c) {
            if (!is_array($c)) continue;
            $name = trim($c['name'] ?? '');
            $val = isset($c['value']) ? $c['value'] : '';
            if ($name === '') continue;
            // Если уже есть такое имя — конкатенируем значения через '; '
            if (isset($out[$name])) {
                if ($val && $out[$name] !== $val) {
                    $out[$name] = $out[$name] . '; ' . $val;
                }
            } else {
                $out[$name] = $val;
            }
        }
        // Вернём в виде массива пар (если нужно) — но в задаче сказано: изображения и характеристики должны возвращаться в виде вложенного массива.
        // Здесь оставим associative array, т.к. это удобно. Если нужен список пар — можно изменить.
        return $out;
    }

    /**
     * Взять innerHTML DOMNode
     *
     * @param DOMNode $node
     * @return string
     */
    protected function innerHtml(DOMNode $node): string
    {
        $doc = $node->ownerDocument;
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
        return $html;
    }

    /**
     * Преобразовать относительный URL в абсолютный (простая версия)
     *
     * @param string $url
     * @return string
     */
    protected function absUrl(string $url): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        // Если встречается URL вида //cdn.ozon.ru/...
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }
        // Иначе — вернём как есть (в реальных задачах лучше комбинировать с базовым URL)
        return $url;
    }
}

?>
