<?php
/**
 * Trax ILIAS Bridge - adapter for ILIAS 10 + Trax LRS 3.
 *
 * Goals:
 *  1. Make ILIAS cmi5 pre-launch compatible with Trax by removing the
 *     non-standard `activity_id` parameter before forwarding to Trax.
 *  2. Make ILIAS cmi5 LMS.LaunchData and state calls compatible with Trax
 *     by removing ILIAS' non-standard `activity_id` parameter from
 *     `/xapi/activities/state` requests.
 *  3. Make ILIAS Learning Experiences / Ranking compatible with Trax by
 *     translating ILIAS `statements/aggregate?pipeline=...` calls into
 *     xAPI `/statements` reads.
 *
 * This file keeps the original first-version filename aggregate.php, but it is a complete front controller. It does not modify ILIAS core.
 */

declare(strict_types=1);

const BRIDGE_VERSION = '2.0.5';
const BYPASS_HEADER = 'X-Trax-Ilias-Bridge-Bypass: 1';

$configFile = __DIR__ . '/config.php';
$config = is_file($configFile) ? require $configFile : require __DIR__ . '/config.sample.php';

try {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($requestUri, PHP_URL_PATH) ?: '/';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if (preg_match('#^/trax/api/gateway/clients/([^/]+)/stores/([^/]+)/cmi5/tokens$#', $path, $m)) {
        validateName($m[1], 'client');
        validateName($m[2], 'store');
        handleSanitizedProxyRequest($config, $method, $path);
        exit;
    }

    if (preg_match('#^/trax/api/gateway/clients/([^/]+)/stores/([^/]+)/xapi/activities/state$#', $path, $m)) {
        validateName($m[1], 'client');
        validateName($m[2], 'store');
        handleSanitizedProxyRequest($config, $method, $path);
        exit;
    }

    if (preg_match('#^/trax/api/gateway/clients/([^/]+)/stores/([^/]+)/api/statements/aggregate$#', $path, $m)) {
        validateName($m[1], 'client');
        validateName($m[2], 'store');
        handleStatementsAggregate($config, $method, $m[1], $m[2]);
        exit;
    }

    if (preg_match('#^/trax/api/gateway/clients/([^/]+)/stores/api/statements/aggregate$#', $path, $m)) {
        validateName($m[1], 'client');
        handleStatementsAggregate($config, $method, $m[1], null);
        exit;
    }

    jsonResponse(404, [
        'error' => 'route_not_handled',
        'message' => 'This URL is not handled by Trax ILIAS Bridge.',
        'path' => $path,
        'version' => BRIDGE_VERSION,
    ]);
} catch (Throwable $e) {
    $debug = (bool)($config['debug'] ?? false);
    jsonResponse(500, [
        'error' => 'bridge_error',
        'message' => $debug ? $e->getMessage() : 'Internal bridge error.',
        'version' => BRIDGE_VERSION,
    ] + ($debug ? ['trace' => $e->getTraceAsString()] : []));
}

/**
 * Sanitized proxy used by cmi5-specific endpoints.
 *
 * ILIAS 10 may add the non-standard `activity_id` parameter in query strings
 * or request bodies. Trax expects the standard xAPI `activityId` parameter and
 * rejects `activity_id`. This proxy removes only `activity_id`, preserves the
 * rest of the request, and forwards it to the real Trax endpoint.
 *
 * Used for:
 *  - /cmi5/tokens
 *  - /xapi/activities/state, including LMS.LaunchData and progress states
 */
function handleSanitizedProxyRequest(array $config, string $method, string $path): void
{
    $queryParams = $_GET;
    unset($queryParams['activity_id']);

    $rawBody = file_get_contents('php://input') ?: '';
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    $body = cleanRequestBody($rawBody, $contentType);

    $targetUrl = buildBaseUrl($config) . $path;
    if ($queryParams !== []) {
        $targetUrl .= '?' . http_build_query($queryParams);
    }

    $headers = forwardHeaders(['authorization', 'accept', 'content-type', 'x-experience-api-version']);
    $headers[] = BYPASS_HEADER;
    $headers[] = 'X-Trax-Ilias-Bridge-Version: ' . BRIDGE_VERSION;

    $response = httpRequest($method, $targetUrl, $headers, $body);
    relayResponse($response);
}

/**
 * ILIAS aggregate compatibility endpoint.
 * ILIAS computes this URL from the xAPI endpoint and loses the store name:
 * /clients/{client}/stores/api/statements/aggregate.
 * The bridge resolves the store using config.php, reads Trax xAPI statements,
 * and returns a Mongo-like aggregate response that ILIAS can consume.
 */
function handleStatementsAggregate(array $config, string $method, string $client, ?string $storeFromUrl): void
{
    if ($method !== 'GET') {
        jsonResponse(405, ['error' => 'method_not_allowed', 'allowed' => ['GET']]);
        return;
    }

    $store = $storeFromUrl ?? resolveStore($config, $client);
    $limit = max(1, (int)($config['max_statements_to_fetch'] ?? 5000));

    $targetUrl = buildBaseUrl($config)
        . '/trax/api/gateway/clients/' . rawurlencode($client)
        . '/stores/' . rawurlencode($store)
        . '/xapi/statements?limit=' . $limit;

    $headers = forwardHeaders(['authorization', 'accept']);
    $headers[] = BYPASS_HEADER;
    $headers[] = 'X-Trax-Ilias-Bridge-Version: ' . BRIDGE_VERSION;
    // ILIAS envoie déjà parfois X-Experience-API-Version sur l'appel aggregate.
    // On ne le transfère pas depuis la requête entrante, sinon Trax reçoit deux valeurs : [1.0.3, 1.0.3].
    $headers[] = 'X-Experience-API-Version: 1.0.3';

    $response = httpRequest('GET', $targetUrl, $headers, null);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        relayResponse($response);
        return;
    }

    $payload = json_decode($response['body'], true);
    if (!is_array($payload)) {
        jsonResponse(502, ['error' => 'invalid_trax_json']);
        return;
    }

    $statements = $payload['statements'] ?? [];
    if (!is_array($statements)) {
        $statements = [];
    }

    $pipelineRaw = $_GET['pipeline'] ?? '[]';
    $pipeline = json_decode((string)$pipelineRaw, true);
    if (!is_array($pipeline)) {
        $pipeline = [];
    }

    $result = aggregateForIlias($statements, $pipeline);
    jsonResponse(200, $result);
}

function cleanRequestBody(string $rawBody, string $contentType): ?string
{
    if ($rawBody === '') {
        return null;
    }

    $ct = strtolower($contentType);

    if (str_contains($ct, 'application/json')) {
        $json = json_decode($rawBody, true);
        if (is_array($json)) {
            unset($json['activity_id']);
            return json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $rawBody;
    }

    if (str_contains($ct, 'application/x-www-form-urlencoded')) {
        parse_str($rawBody, $form);
        unset($form['activity_id']);
        return http_build_query($form);
    }

    // Conservative fallback for unexpected text payloads.
    parse_str($rawBody, $form);
    if (is_array($form) && array_key_exists('activity_id', $form)) {
        unset($form['activity_id']);
        return http_build_query($form);
    }

    return $rawBody;
}

function aggregateForIlias(array $statements, array $pipeline): array
{
    $docs = [];
    foreach ($statements as $s) {
        if (!is_array($s)) {
            continue;
        }
        // Return both shapes: direct xAPI fields and Mongo-like `statement` wrapper.
        $docs[] = ['statement' => $s] + $s;
    }

    $facetExpected = false;

    foreach ($pipeline as $stage) {
        if (!is_array($stage)) {
            continue;
        }

        if (isset($stage['$match']) && is_array($stage['$match'])) {
            $match = $stage['$match'];
            $docs = array_values(array_filter($docs, static fn($doc) => matchesCriteria($doc, $match)));
            continue;
        }

        if (isset($stage['$sort']) && is_array($stage['$sort'])) {
            $docs = sortDocs($docs, $stage['$sort']);
            continue;
        }

        if (isset($stage['$skip'])) {
            $docs = array_slice($docs, max(0, (int)$stage['$skip']));
            continue;
        }

        if (isset($stage['$limit'])) {
            $docs = array_slice($docs, 0, max(0, (int)$stage['$limit']));
            continue;
        }

        if (isset($stage['$group']) && is_array($stage['$group'])) {
            $docs = groupDocs($docs, $stage['$group']);
            continue;
        }

        if (isset($stage['$facet']) && is_array($stage['$facet'])) {
            $facetExpected = true;
            // Very small compatibility layer for ILIAS statement table pipelines.
            // It normally asks for maxcount + paginated statements.
            $total = count($docs);
            return [[
                'maxcount' => $total,
                'statements' => array_values($docs),
            ]];
        }
    }

    if ($facetExpected) {
        return [[
            'maxcount' => count($docs),
            'statements' => array_values($docs),
        ]];
    }

    // Non-facet pipelines are used by dynamic verbs and Ranking.
    return array_values($docs);
}

function sortDocs(array $docs, array $sort): array
{
    usort($docs, static function ($a, $b) use ($sort) {
        foreach ($sort as $field => $direction) {
            $av = firstValue(pathValues($a, (string)$field));
            $bv = firstValue(pathValues($b, (string)$field));
            if ($av == $bv) {
                continue;
            }
            $cmp = $av <=> $bv;
            return ((int)$direction < 0) ? -$cmp : $cmp;
        }
        return 0;
    });
    return $docs;
}

function groupDocs(array $docs, array $group): array
{
    $idExpression = $group['_id'] ?? null;
    $hasOnlyId = count($group) === 1;
    $groups = [];
    $order = [];

    foreach ($docs as $doc) {
        $idValue = evaluateExpression($doc, $idExpression);

        // ILIAS Ranking expects a usable user identifier. When grouping by a
        // missing actor field, skip the statement instead of returning an item
        // without `account`/`mbox`, which triggers PHP warnings in ILIAS.
        if ($idValue === null || $idValue === '') {
            continue;
        }

        $key = is_scalar($idValue) ? (string)$idValue : json_encode($idValue);
        if (!isset($groups[$key])) {
            $groups[$key] = [
                '_id' => $idValue,
                '__docs' => [],
            ];
            $order[] = $key;
        }
        $groups[$key]['__docs'][] = $doc;
    }

    $out = [];
    foreach ($order as $key) {
        $groupedDocs = $groups[$key]['__docs'];
        $row = ['_id' => $groups[$key]['_id']];

        if ($hasOnlyId) {
            $out[] = $row;
            continue;
        }

        foreach ($group as $field => $expr) {
            if ($field === '_id') {
                continue;
            }

            if (is_array($expr) && count($expr) === 1) {
                $op = array_key_first($expr);
                $arg = $expr[$op];
                $row[$field] = evaluateAccumulator($groupedDocs, (string)$op, $arg);
            } else {
                $row[$field] = evaluateExpression(end($groupedDocs), $expr);
            }
        }

        // Safety for ILIAS cmi5 Ranking: it accesses $item['account'] directly.
        // If a pipeline grouped by actor.account.name, use the group id as fallback.
        if (!array_key_exists('account', $row) && is_string($idExpression) && str_contains($idExpression, 'actor.account.name')) {
            $row['account'] = is_scalar($row['_id']) ? (string)$row['_id'] : '';
        }

        // ILIAS 10 calls ilCmiXapiDateTime::fromXapiTimestamp() on Ranking rows.
        // A missing or non-parsable timestamp can crash the Ranking tab. Normalize
        // the grouped timestamp and fall back to the statement stored date if needed.
        if (array_key_exists('timestamp', $row) || array_key_exists('score', $row)) {
            $normalizedTimestamp = normalizeXapiTimestamp($row['timestamp'] ?? null)
                ?? fallbackTimestampFromDocs($groupedDocs)
                ?? gmdate('Y-m-d\TH:i:s.000\Z');
            $row['timestamp'] = $normalizedTimestamp;
        }

        // Keep only rows with a real score.
        // ILIAS Ranking accepts numeric scalar scores produced by $max, such as 0.92,
        // and score arrays when a pipeline returns a complete score object.
        if (array_key_exists('score', $row)) {
            if ($row['score'] === null || $row['score'] === '') {
                continue;
            }

            if (is_array($row['score']) && $row['score'] === []) {
                continue;
            }
        }
        if (array_key_exists('account', $row) && ($row['account'] === null || $row['account'] === '')) {
            continue;
        }

        $out[] = $row;
    }

    return $out;
}

function evaluateAccumulator(array $docs, string $op, mixed $arg): mixed
{
    switch ($op) {
        case '$last':
            if ($docs === []) {
                return null;
            }
            return evaluateExpression($docs[array_key_last($docs)], $arg);

        case '$first':
            if ($docs === []) {
                return null;
            }
            return evaluateExpression($docs[array_key_first($docs)], $arg);

        case '$push':
            $values = [];
            foreach ($docs as $doc) {
                $value = evaluateExpression($doc, $arg);
                if ($value !== null && $value !== '') {
                    $values[] = $value;
                }
            }
            return $values;

        case '$max':
            $values = [];
            foreach ($docs as $doc) {
                $value = evaluateExpression($doc, $arg);
                if ($value !== null) {
                    $values[] = $value;
                }
            }
            return $values === [] ? null : max($values);

        case '$min':
            $values = [];
            foreach ($docs as $doc) {
                $value = evaluateExpression($doc, $arg);
                if ($value !== null) {
                    $values[] = $value;
                }
            }
            return $values === [] ? null : min($values);

        case '$sum':
            $sum = 0;
            foreach ($docs as $doc) {
                $value = is_numeric($arg) ? $arg : evaluateExpression($doc, $arg);
                if (is_numeric($value)) {
                    $sum += $value;
                }
            }
            return $sum;

        default:
            return evaluateExpression($docs[array_key_last($docs)] ?? [], $arg);
    }
}

function evaluateExpression(array $doc, mixed $expression): mixed
{
    if (is_string($expression) && str_starts_with($expression, '$')) {
        return firstValue(pathValues($doc, substr($expression, 1)));
    }
    return $expression;
}

function fallbackTimestampFromDocs(array $docs): ?string
{
    if ($docs === []) {
        return null;
    }

    for ($i = count($docs) - 1; $i >= 0; $i--) {
        $doc = $docs[$i];
        if (!is_array($doc)) {
            continue;
        }

        $candidates = [
            firstValue(pathValues($doc, 'statement.timestamp')),
            firstValue(pathValues($doc, 'timestamp')),
            firstValue(pathValues($doc, 'statement.stored')),
            firstValue(pathValues($doc, 'stored')),
        ];

        foreach ($candidates as $candidate) {
            $normalized = normalizeXapiTimestamp($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }
    }

    return null;
}

function normalizeXapiTimestamp(mixed $value): ?string
{
    if (is_array($value)) {
        foreach ($value as $item) {
            $normalized = normalizeXapiTimestamp($item);
            if ($normalized !== null) {
                return $normalized;
            }
        }
        return null;
    }

    if (!is_scalar($value)) {
        return null;
    }

    $timestamp = trim((string)$value);
    if ($timestamp === '') {
        return null;
    }

    try {
        $date = new DateTimeImmutable($timestamp);
    } catch (Throwable) {
        return null;
    }

    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
}

function matchesCriteria(array $doc, array $criteria): bool
{
    foreach ($criteria as $field => $condition) {
        if ($field === '$and' && is_array($condition)) {
            foreach ($condition as $sub) {
                if (!is_array($sub) || !matchesCriteria($doc, $sub)) {
                    return false;
                }
            }
            continue;
        }

        if ($field === '$or' && is_array($condition)) {
            $ok = false;
            foreach ($condition as $sub) {
                if (is_array($sub) && matchesCriteria($doc, $sub)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
            continue;
        }

        $values = pathValues($doc, (string)$field);
        if (!conditionMatches($values, $condition)) {
            return false;
        }
    }
    return true;
}

function conditionMatches(array $values, mixed $condition): bool
{
    if (is_array($condition) && hasOperatorKeys($condition)) {
        foreach ($condition as $op => $expected) {
            $exists = count($values) > 0;
            switch ($op) {
                case '$exists':
                    if ((bool)$expected !== $exists) {
                        return false;
                    }
                    break;
                case '$in':
                    if (!is_array($expected) || !anyValue($values, static fn($v) => in_array($v, $expected, true))) {
                        return false;
                    }
                    break;
                case '$nin':
                    if (is_array($expected) && anyValue($values, static fn($v) => in_array($v, $expected, true))) {
                        return false;
                    }
                    break;
                case '$ne':
                    if (anyValue($values, static fn($v) => $v == $expected)) {
                        return false;
                    }
                    break;
                case '$eq':
                    if (!anyValue($values, static fn($v) => $v == $expected)) {
                        return false;
                    }
                    break;
                case '$gte':
                    if (!anyValue($values, static fn($v) => $v >= $expected)) {
                        return false;
                    }
                    break;
                case '$gt':
                    if (!anyValue($values, static fn($v) => $v > $expected)) {
                        return false;
                    }
                    break;
                case '$lte':
                    if (!anyValue($values, static fn($v) => $v <= $expected)) {
                        return false;
                    }
                    break;
                case '$lt':
                    if (!anyValue($values, static fn($v) => $v < $expected)) {
                        return false;
                    }
                    break;
                case '$regex':
                    $pattern = (string)$expected;
                    $pattern = '/' . str_replace('/', '\\/', $pattern) . '/i';
                    if (!anyValue($values, static fn($v) => is_scalar($v) && preg_match($pattern, (string)$v) === 1)) {
                        return false;
                    }
                    break;
                default:
                    // Unknown Mongo operator: be permissive to avoid breaking ILIAS UI.
                    break;
            }
        }
        return true;
    }

    return anyValue($values, static fn($v) => $v == $condition);
}

function hasOperatorKeys(array $arr): bool
{
    foreach (array_keys($arr) as $k) {
        if (is_string($k) && str_starts_with($k, '$')) {
            return true;
        }
    }
    return false;
}

function anyValue(array $values, callable $predicate): bool
{
    foreach ($values as $v) {
        if ($predicate($v)) {
            return true;
        }
    }
    return false;
}

function pathValues(mixed $data, string $path): array
{
    $parts = explode('.', $path);
    return pathValuesRecursive($data, $parts);
}

function pathValuesRecursive(mixed $data, array $parts): array
{
    if ($parts === []) {
        return [$data];
    }

    if (!is_array($data)) {
        return [];
    }

    $part = array_shift($parts);
    $out = [];

    if (array_is_list($data)) {
        foreach ($data as $item) {
            $out = array_merge($out, pathValuesRecursive($item, array_merge([$part], $parts)));
        }
        return $out;
    }

    if (array_key_exists($part, $data)) {
        return pathValuesRecursive($data[$part], $parts);
    }

    return [];
}

function firstValue(array $values): mixed
{
    return $values[0] ?? null;
}

function resolveStore(array $config, string $client): string
{
    $map = $config['client_store_map'] ?? [];
    if (is_array($map) && isset($map[$client])) {
        validateName((string)$map[$client], 'store');
        return (string)$map[$client];
    }

    $strategy = $config['aggregate_store_strategy'] ?? 'client_name';
    if ($strategy === 'client_name') {
        return $client;
    }

    if (isset($config['default_store']) && $config['default_store'] !== null && $config['default_store'] !== '') {
        validateName((string)$config['default_store'], 'store');
        return (string)$config['default_store'];
    }

    throw new RuntimeException("Cannot resolve Trax store for client [$client]. Add it to client_store_map in config.php.");
}

function buildBaseUrl(array $config): string
{
    if (!empty($config['trax_base_url'])) {
        return rtrim((string)$config['trax_base_url'], '/');
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    return $scheme . '://' . $host;
}

function forwardHeaders(array $allow): array
{
    $allow = array_flip(array_map('strtolower', $allow));
    $headers = [];
    $hasAuthorization = false;

    foreach ($_SERVER as $key => $value) {
        if (!str_starts_with($key, 'HTTP_')) {
            continue;
        }
        $name = strtolower(str_replace('_', '-', substr($key, 5)));
        if (isset($allow[$name])) {
            if ($name === 'authorization') {
                $hasAuthorization = true;
            }
            $headers[] = normalizeHeaderName($name) . ': ' . $value;
        }
    }

    if (!$hasAuthorization && isset($allow['authorization'])) {
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers[] = 'Authorization: ' . $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            $hasAuthorization = true;
        } elseif (!empty($_SERVER['PHP_AUTH_USER'])) {
            $headers[] = 'Authorization: Basic ' . base64_encode((string)$_SERVER['PHP_AUTH_USER'] . ':' . (string)($_SERVER['PHP_AUTH_PW'] ?? ''));
            $hasAuthorization = true;
        }
    }

    if (isset($_SERVER['CONTENT_TYPE']) && isset($allow['content-type'])) {
        $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
    }

    return array_values(array_unique($headers));
}

function normalizeHeaderName(string $name): string
{
    return implode('-', array_map(static fn($p) => ucfirst($p), explode('-', $name)));
}

function httpRequest(string $method, string $url, array $headers, ?string $body): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Cannot initialize cURL.');
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($body !== null && !in_array($method, ['GET', 'HEAD'], true)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('cURL error: ' . $err);
    }

    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr($raw, 0, $headerSize);
    $responseBody = substr($raw, $headerSize);

    return [
        'status' => $status,
        'headers' => parseResponseHeaders($rawHeaders),
        'body' => $responseBody,
    ];
}

function parseResponseHeaders(string $rawHeaders): array
{
    $headers = [];
    foreach (preg_split('/\r\n|\n|\r/', trim($rawHeaders)) as $line) {
        if ($line === '' || str_starts_with(strtolower($line), 'http/')) {
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        $headers[] = [$name, $value];
    }
    return $headers;
}

function relayResponse(array $response): void
{
    http_response_code((int)$response['status']);
    foreach ($response['headers'] as [$name, $value]) {
        $lower = strtolower($name);
        if (in_array($lower, ['transfer-encoding', 'content-length', 'connection', 'server', 'date'], true)) {
            continue;
        }
        header($name . ': ' . $value, false);
    }
    echo $response['body'];
}

function jsonResponse(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Trax-Ilias-Bridge-Version: ' . BRIDGE_VERSION);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

function validateName(string $value, string $label): void
{
    if (!preg_match('/^[A-Za-z0-9_.-]+$/', $value)) {
        throw new InvalidArgumentException("Invalid $label name.");
    }
}
