<?php
/**
 * Adaptateur dynamique TRAX -> ILIAS CmiXapi aggregate.
 *
 * Objectif : permettre aux onglets ILIAS "Learning Experiences" et en partie
 * "Ranking" de lire les statements stockés dans Trax LRS.
 *
 * ILIAS CmiXapi appelle un endpoint du type :
 *   /trax/api/gateway/clients/{client}/stores/api/statements/aggregate?pipeline=...
 * ou, si le core ILIAS est corrigé pour conserver le store :
 *   /trax/api/gateway/clients/{client}/stores/{store}/api/statements/aggregate?pipeline=...
 *
 * Cet adaptateur déduit automatiquement le client et le store depuis l'URL,
 * puis appelle l'endpoint xAPI Trax correspondant :
 *   /trax/api/gateway/clients/{client}/stores/{store}/xapi/statements
 */
declare(strict_types=1);

const XAPI_VERSION = '1.0.3';

function adapter_config(): array
{
    $default = [
        'trax_base_url' => null,
        'default_store' => 'default',
        'client_store_map' => [],
        'max_statements_to_fetch' => 5000,
        'debug' => false,
    ];

    $file = __DIR__ . '/config.php';
    if (is_file($file)) {
        $cfg = require $file;
        if (is_array($cfg)) {
            return array_replace($default, $cfg);
        }
    }
    return $default;
}

function debug_enabled(): bool
{
    return (bool) (adapter_config()['debug'] ?? false);
}

function cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: X-Experience-API-Version,Accept,Authorization,Etag,Cache-Control,Content-Type,Origin,User-Agent');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('X-Experience-API-Version: ' . XAPI_VERSION);
}

function send_json(mixed $data, int $status = 200): never
{
    cors();
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function fail(string $message, int $status = 500, array $debug = []): never
{
    if (!debug_enabled()) {
        $debug = [];
    }
    send_json(['error' => $message, 'debug' => $debug], $status);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    cors();
    http_response_code(204);
    exit;
}

function inbound_authorization_header(): string
{
    if (!empty($_SERVER['PHP_AUTH_USER']) && isset($_SERVER['PHP_AUTH_PW'])) {
        return 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (stripos($header, 'Basic ') === 0) {
        return $header;
    }

    fail('Missing Basic Authorization header', 401);
}

function request_origin(): string
{
    $cfg = adapter_config();
    if (!empty($cfg['trax_base_url'])) {
        return rtrim((string) $cfg['trax_base_url'], '/');
    }

    $scheme = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']);
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $scheme = 'https';
    }

    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    return $scheme . '://' . $host;
}

function dynamic_trax_statements_endpoint(): string
{
    $cfg = adapter_config();
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $path = rawurldecode($path);

    // Cas recommandé : le store est présent dans l'URL.
    // /trax/api/gateway/clients/eform/stores/default/api/statements/aggregate
    if (preg_match('~^(.*?/clients/([^/]+)/stores/)([^/]+)/api/statements/aggregate/?$~', $path, $m)) {
        $prefix = $m[1];
        $store = $m[3];
        return request_origin() . $prefix . rawurlencode($store) . '/xapi/statements';
    }

    // Cas ILIAS 10 natif : dirname(endpoint, 2) supprime le store.
    // /trax/api/gateway/clients/eform/stores/api/statements/aggregate
    if (preg_match('~^(.*?/clients/([^/]+)/stores)/api/statements/aggregate/?$~', $path, $m)) {
        $client = $m[2];
        $store = (string) (($cfg['client_store_map'][$client] ?? null) ?: ($cfg['default_store'] ?? 'default'));
        return request_origin() . $m[1] . '/' . rawurlencode($store) . '/xapi/statements';
    }

    fail('Unable to infer Trax xAPI statements endpoint from request URI', 400, [
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '',
        'expected_1' => '/trax/api/gateway/clients/{client}/stores/{store}/api/statements/aggregate',
        'expected_2' => '/trax/api/gateway/clients/{client}/stores/api/statements/aggregate',
    ]);
}

function fetch_url_json(string $url, string $authHeader): array
{
    $headers = [
        'X-Experience-API-Version: ' . XAPI_VERSION,
        'Accept: application/json',
        'Authorization: ' . $authHeader,
        'Cache-Control: no-cache',
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno) {
        fail('cURL error while reading TRAX', 502, ['errno' => $errno, 'error' => $error, 'url' => $url]);
    }
    if ($status < 200 || $status >= 300) {
        fail('TRAX returned HTTP ' . $status, 502, ['body' => substr((string) $body, 0, 1000), 'url' => $url]);
    }

    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        fail('TRAX returned non JSON response', 502, ['body' => substr((string) $body, 0, 1000), 'url' => $url]);
    }
    return $json;
}

function absolute_more_url(string $more, string $statementsEndpoint): string
{
    if ($more === '') {
        return '';
    }
    if (preg_match('~^https?://~i', $more)) {
        return $more;
    }

    $parts = parse_url($statementsEndpoint);
    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? '127.0.0.1';
    $port = isset($parts['port']) ? ':' . $parts['port'] : '';
    return $scheme . '://' . $host . $port . $more;
}

function fetch_all_statements(string $authHeader, string $statementsEndpoint): array
{
    $cfg = adapter_config();
    $max = (int) ($cfg['max_statements_to_fetch'] ?? 5000);
    $url = $statementsEndpoint . '?limit=100';
    $all = [];

    while ($url !== '' && count($all) < $max) {
        $json = fetch_url_json($url, $authHeader);
        foreach (($json['statements'] ?? []) as $statement) {
            if (is_array($statement)) {
                $all[] = $statement;
                if (count($all) >= $max) {
                    break;
                }
            }
        }
        $url = absolute_more_url((string) ($json['more'] ?? ''), $statementsEndpoint);
    }
    return $all;
}

function is_list_array(mixed $value): bool
{
    return is_array($value) && array_keys($value) === range(0, count($value) - 1);
}

function get_field(array $statement, string $path): mixed
{
    if (str_starts_with($path, 'statement.')) {
        $path = substr($path, strlen('statement.'));
    }

    $parts = explode('.', $path);
    $current = $statement;

    for ($i = 0; $i < count($parts); $i++) {
        $part = $parts[$i];

        if ($part === 'extensions') {
            if (!is_array($current) || !array_key_exists('extensions', $current)) {
                return null;
            }
            $extensionKey = implode('.', array_slice($parts, $i + 1));
            $extensionKey = str_replace('&46;', '.', $extensionKey);
            return $current['extensions'][$extensionKey] ?? null;
        }

        if (is_list_array($current)) {
            $values = [];
            foreach ($current as $item) {
                if (is_array($item)) {
                    $value = get_field($item, implode('.', array_slice($parts, $i)));
                    if ($value !== null) {
                        if (is_array($value)) {
                            $values = array_merge($values, $value);
                        } else {
                            $values[] = $value;
                        }
                    }
                }
            }
            return $values ?: null;
        }

        if (!is_array($current) || !array_key_exists($part, $current)) {
            return null;
        }
        $current = $current[$part];
    }

    return $current;
}

function any_value_matches(mixed $value, callable $predicate): bool
{
    if (is_array($value)) {
        foreach ($value as $v) {
            if (any_value_matches($v, $predicate)) {
                return true;
            }
        }
        return false;
    }
    return $predicate($value);
}

function match_regex(mixed $value, string $regex): bool
{
    return any_value_matches($value, function (mixed $v) use ($regex): bool {
        if ($v === null) {
            return false;
        }
        $pattern = '~' . str_replace('~', '\\~', $regex) . '~u';
        return @preg_match($pattern, (string) $v) === 1;
    });
}

function match_scalar(mixed $value, mixed $expected): bool
{
    return any_value_matches($value, fn(mixed $v): bool => (string) $v === (string) $expected);
}

function condition_matches(array $statement, string $field, mixed $condition): bool
{
    $value = get_field($statement, $field);

    if (is_array($condition) && array_key_exists('$regex', $condition)) {
        return match_regex($value, (string) $condition['$regex']);
    }
    if (is_array($condition) && array_key_exists('$exists', $condition)) {
        $exists = $value !== null;
        return ((bool) $condition['$exists']) === $exists;
    }
    if (is_array($condition) && (array_key_exists('$gt', $condition) || array_key_exists('$lt', $condition))) {
        if ($value === null) {
            return false;
        }
        if (array_key_exists('$gt', $condition) && !((string) $value > (string) $condition['$gt'])) {
            return false;
        }
        if (array_key_exists('$lt', $condition) && !((string) $value < (string) $condition['$lt'])) {
            return false;
        }
        return true;
    }

    return match_scalar($value, $condition);
}

function match_stage(array $statement, array $match): bool
{
    foreach ($match as $field => $condition) {
        if ($field === '$and') {
            foreach ((array) $condition as $sub) {
                if (!match_stage($statement, $sub)) {
                    return false;
                }
            }
            continue;
        }
        if ($field === '$or') {
            $ok = false;
            foreach ((array) $condition as $sub) {
                if (match_stage($statement, $sub)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
            continue;
        }
        if (!condition_matches($statement, (string) $field, $condition)) {
            return false;
        }
    }
    return true;
}

function pipeline_match_stage(array $pipeline): array
{
    foreach ($pipeline as $stage) {
        if (isset($stage['$match']) && is_array($stage['$match'])) {
            return $stage['$match'];
        }
    }
    return [];
}

function sort_stage(array $pipeline): array
{
    foreach ($pipeline as $stage) {
        if (isset($stage['$sort']) && is_array($stage['$sort'])) {
            return $stage['$sort'];
        }
    }
    return [];
}

function sort_statements(array $statements, array $sort): array
{
    if (!$sort) {
        return $statements;
    }
    $field = array_key_first($sort);
    $direction = (int) $sort[$field];

    usort($statements, function (array $a, array $b) use ($field, $direction): int {
        $va = get_field($a, (string) $field);
        $vb = get_field($b, (string) $field);
        $cmp = strcmp((string) $va, (string) $vb);
        return $direction < 0 ? -$cmp : $cmp;
    });
    return $statements;
}

function facet_skip_limit(array $pipeline): array
{
    $skip = 0;
    $limit = 0;
    foreach ($pipeline as $stage) {
        if (!isset($stage['$facet']['stage2']) || !is_array($stage['$facet']['stage2'])) {
            continue;
        }
        foreach ($stage['$facet']['stage2'] as $op) {
            if (isset($op['$skip'])) {
                $skip = (int) $op['$skip'];
            }
            if (isset($op['$limit'])) {
                $limit = (int) $op['$limit'];
            }
        }
    }
    return [$skip, $limit];
}

function is_verbs_pipeline(array $pipeline): bool
{
    foreach ($pipeline as $stage) {
        if (($stage['$group']['_id'] ?? null) === '$statement.verb.id') {
            return true;
        }
    }
    return false;
}

function is_highscore_pipeline(array $pipeline): bool
{
    foreach ($pipeline as $stage) {
        if (isset($stage['$group']['score'])) {
            return true;
        }
    }
    return false;
}

function is_statements_table_pipeline(array $pipeline): bool
{
    foreach ($pipeline as $stage) {
        if (isset($stage['$facet'])) {
            return true;
        }
    }
    return false;
}

function build_verbs_response(array $statements): array
{
    $verbs = [];
    foreach ($statements as $s) {
        $verb = $s['verb']['id'] ?? null;
        if ($verb) {
            $verbs[$verb] = true;
        }
    }
    ksort($verbs);
    return array_map(fn(string $v): array => ['_id' => $v], array_keys($verbs));
}

function build_statements_table_response(array $statements, array $pipeline): array
{
    $statements = sort_statements($statements, sort_stage($pipeline));
    $max = count($statements);
    [$skip, $limit] = facet_skip_limit($pipeline);
    $slice = $limit > 0 ? array_slice($statements, $skip, $limit) : array_slice($statements, $skip);

    return [[
        'maxcount' => $max,
        'statements' => array_values($slice),
    ]];
}

function build_highscore_response(array $statements, array $pipeline): array
{
    $statements = sort_statements($statements, sort_stage($pipeline));
    $groups = [];

    foreach ($statements as $s) {
        $account = $s['actor']['account']['name'] ?? null;
        $mbox = $s['actor']['mbox'] ?? null;
        $key = $account ?: $mbox;
        if (!$key) {
            continue;
        }
        if (!isset($groups[$key])) {
            $groups[$key] = [
                '_id' => $key,
                'mbox' => $mbox,
                'account' => $account,
                'username' => $s['actor']['name'] ?? '',
                'timestamp' => $s['timestamp'] ?? $s['stored'] ?? '',
                'duration' => [],
                'score' => $s['result']['score'] ?? null,
            ];
        }

        $groups[$key]['mbox'] = $mbox;
        $groups[$key]['account'] = $account;
        $groups[$key]['username'] = $s['actor']['name'] ?? '';
        $groups[$key]['timestamp'] = $s['timestamp'] ?? $s['stored'] ?? '';
        $groups[$key]['score'] = $s['result']['score'] ?? $groups[$key]['score'];
        if (!empty($s['result']['duration'])) {
            $groups[$key]['duration'][] = $s['result']['duration'];
        }
    }

    return array_values($groups);
}

$pipelineRaw = $_GET['pipeline'] ?? '';
if ($pipelineRaw === '') {
    send_json([]);
}

$pipeline = json_decode((string) $pipelineRaw, true);
if (!is_array($pipeline)) {
    fail('Invalid or non JSON pipeline parameter', 400, ['pipeline' => $pipelineRaw]);
}

$auth = inbound_authorization_header();
$endpoint = dynamic_trax_statements_endpoint();
$statements = fetch_all_statements($auth, $endpoint);
$match = pipeline_match_stage($pipeline);
if ($match) {
    $statements = array_values(array_filter($statements, fn(array $s): bool => match_stage($s, $match)));
}

if (is_verbs_pipeline($pipeline)) {
    send_json(build_verbs_response($statements));
}

if (is_highscore_pipeline($pipeline)) {
    send_json(build_highscore_response($statements, $pipeline));
}

if (is_statements_table_pipeline($pipeline)) {
    send_json(build_statements_table_response($statements, $pipeline));
}

send_json([]);
