<?php
declare(strict_types=1);
/* ==========================================================
   booking.php — Darya Nails: sends every new booking to VK
   Accepts multipart/form-data (AJAX or normal POST), PHP 7.4+, needs cURL.
   Fields: name, phone, design, date (YYYY-MM-DD), time, note, order_id,
           ref[] (up to 3 image files), photo_urls[] (optional links), website (honeypot)
   ========================================================== */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const VK_API    = 'https://api.vk.com/method/';
const VK_VER    = '5.199';
const MAX_FILES = 3;
const MAX_BYTES = 8 * 1024 * 1024;     // per image

/* ---------- config: token is NEVER in this file (it lives in ../private/vk-config.php) ---------- */
function cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = [
        'token'         => '',
        'peer_id'       => '803607577',
        'allowed_hosts' => ['daryanails.xsph.ru', 'www.daryanails.xsph.ru'],
    ];
    foreach ([__DIR__ . '/../private/vk-config.php', __DIR__ . '/vk-config.php'] as $f) {
        if (is_file($f)) { $c = array_merge($c, (array)require $f); break; }
    }
    foreach (['VK_TOKEN' => 'token', 'VK_PEER_ID' => 'peer_id'] as $env => $key) {
        $v = getenv($env);
        if ($v !== false && $v !== '') $c[$key] = $v;
    }
    return $c;
}

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function logf(string $msg): void {
    $dir = is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private' : sys_get_temp_dir();
    @file_put_contents($dir . '/booking-errors.log', '[' . date('c') . '] ' . $msg . "\n", FILE_APPEND | LOCK_EX);
}

/* ---------- guards ---------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Allow: POST'); out(405, ['ok' => false, 'error' => 'method']); }

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $oh = parse_url($origin, PHP_URL_HOST);
    $allowed = array_merge([(string)($_SERVER['HTTP_HOST'] ?? '')], (array)(cfg()['allowed_hosts'] ?? []));
    if (!$oh || !in_array($oh, $allowed, true)) out(403, ['ok' => false, 'error' => 'origin']);
}
if (trim((string)($_POST['website'] ?? '')) !== '') out(200, ['ok' => true]);        // honeypot: bots fill this

function rate_ok(string $ip, int $max = 5, int $win = 600): bool {                  // 5 requests / 10 min / IP
    $fp = @fopen(sys_get_temp_dir() . '/dn_rl_' . md5($ip), 'c+');
    if (!$fp) return true;
    flock($fp, LOCK_EX);
    $now = time();
    $h = array_filter(array_map('intval', explode(',', (string)stream_get_contents($fp))), fn($t) => $t > $now - $win);
    $ok = count($h) < $max;
    if ($ok) { $h[] = $now; ftruncate($fp, 0); rewind($fp); fwrite($fp, implode(',', $h)); }
    flock($fp, LOCK_UN); fclose($fp);
    return $ok;
}
if (!rate_ok((string)($_SERVER['REMOTE_ADDR'] ?? '0'))) out(429, ['ok' => false, 'error' => 'rate']);

/* ---------- input ---------- */
function clean($s, int $max, bool $multiline = false): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string)$s) ?? '';
    if (!$multiline) $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return mb_substr(trim($s), 0, $max);
}
$name   = clean($_POST['name']   ?? '', 80);
$phone  = clean($_POST['phone']  ?? '', 30);
$design = clean($_POST['design'] ?? '', 120);
$note   = clean($_POST['note']   ?? '', 800, true);
$time   = clean($_POST['time']   ?? '', 30);
$date   = clean($_POST['date']   ?? '', 10);

if (mb_strlen($name) < 2 || strlen(preg_replace('/\D/', '', $phone)) < 10 || !preg_match('/^[+\d\s()\-]+$/', $phone) || $design === '') {
    out(422, ['ok' => false, 'error' => 'validation']);
}

$months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
$dateRu = 'Дата не указана';
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
    $dateRu = (int)$m[3] . ' ' . $months[(int)$m[2] - 1] . ' ' . $m[1];
}
$when = $dateRu . ', ' . ($time !== '' ? $time : 'любое удобное время');

/* ---------- order number ---------- */
function next_id(): string {
    $dir = cfg()['data_dir'] ?? (is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private' : sys_get_temp_dir());
    $fp = @fopen($dir . '/dn_booking_counter.txt', 'c+');
    if (!$fp) return date('ymd') . random_int(100, 999);
    flock($fp, LOCK_EX);
    $n = (int)trim((string)stream_get_contents($fp)) + 1;
    ftruncate($fp, 0); rewind($fp); fwrite($fp, (string)$n);
    flock($fp, LOCK_UN); fclose($fp);
    return (string)$n;
}
$orderId = preg_match('/^\d{1,12}$/', (string)($_POST['order_id'] ?? '')) ? (string)$_POST['order_id'] : next_id();   // site passes its own booking id

/* ---------- VK API ---------- */
function vk(string $method, array $params): array {
    $tok = (string)(cfg()['token'] ?? '');
    if ($tok === '') throw new RuntimeException('VK token is not configured');
    $ch = curl_init(VK_API . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params + ['v' => VK_VER]),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok],            // header, so the token never lands in URLs/logs
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new RuntimeException('VK network: ' . $err);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) throw new RuntimeException('VK bad response');
    if (isset($j['error'])) throw new RuntimeException('VK ' . ($j['error']['error_code'] ?? '?') . ': ' . ($j['error']['error_msg'] ?? ''));
    return (array)($j['response'] ?? []);
}

function vk_upload_photo(string $path, string $mime, string $peer): string {
    $srv = vk('photos.getMessagesUploadServer', ['peer_id' => $peer]);
    $ch = curl_init((string)$srv['upload_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['photo' => new CURLFile($path, $mime, 'reference.jpg')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 40,
    ]);
    $raw = curl_exec($ch); curl_close($ch);
    $up = json_decode((string)$raw, true);
    if (!is_array($up) || empty($up['photo'])) throw new RuntimeException('VK upload failed');
    $saved = vk('photos.saveMessagesPhoto', ['server' => $up['server'], 'photo' => $up['photo'], 'hash' => $up['hash']]);
    $p = $saved[0] ?? null;
    if (!$p) throw new RuntimeException('VK save photo failed');
    return 'photo' . $p['owner_id'] . '_' . $p['id'] . (isset($p['access_key']) ? '_' . $p['access_key'] : '');
}

/* ---------- uploaded images (multipart) ---------- */
function incoming_files(string $field): array {
    if (empty($_FILES[$field])) return [];
    $f = $_FILES[$field]; $list = [];
    if (is_array($f['name'])) { foreach ($f['name'] as $i => $_) $list[] = ['tmp' => $f['tmp_name'][$i], 'err' => $f['error'][$i], 'size' => $f['size'][$i]]; }
    else $list[] = ['tmp' => $f['tmp_name'], 'err' => $f['error'], 'size' => $f['size']];
    return $list;
}

$peer = (string)(cfg()['peer_id'] ?? '');
$attach = []; $tmpToDelete = [];
register_shutdown_function(function () use (&$tmpToDelete) { foreach ($tmpToDelete as $t) @unlink($t); });   // exit() skips finally

try {
    if ($peer === '' || !preg_match('/^-?\d+$/', $peer)) throw new RuntimeException('VK peer_id is not configured');

    foreach (array_slice(incoming_files('ref'), 0, MAX_FILES) as $f) {
        if ($f['err'] !== UPLOAD_ERR_OK || $f['size'] <= 0 || $f['size'] > MAX_BYTES || !is_uploaded_file($f['tmp'])) continue;
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp']);
        $path = $f['tmp'];
        if ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {        // VK has no webp -> convert to JPEG
            $im = @imagecreatefromwebp($path);
            if (!$im) continue;
            $path = tempnam(sys_get_temp_dir(), 'dnw'); $tmpToDelete[] = $path;
            imagejpeg($im, $path, 90); imagedestroy($im); $mime = 'image/jpeg';
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true) || !@getimagesize($path)) continue;
        try { $attach[] = vk_upload_photo($path, $mime, $peer); }
        catch (Throwable $e) { logf('photo: ' . $e->getMessage()); }                    // one bad photo never blocks the booking
    }

    /* links sent by the site (e.g. Supabase storage) are used when no file could be attached */
    $links = [];
    foreach ((array)($_POST['photo_urls'] ?? []) as $u) {
        $u = clean($u, 300);
        if (filter_var($u, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $u)) $links[] = $u;
    }
    $links = array_slice($links, 0, MAX_FILES);

    if ($attach)      $photoLine = 'приложено ниже (' . count($attach) . ' шт.)';
    elseif ($links)   $photoLine = implode("\n", $links);
    else              $photoLine = 'не приложено';

    $line = '━━━━━━━━━━━━━━━━━━━';
    $text = "📌 Новая заявка на запись\n" . $line . "\n"
          . "🆔 Номер заказа: # {$orderId}\n"
          . "👤 Имя: {$name}\n"
          . "📞 Телефон: {$phone}\n"
          . "💅 Дизайн/Услуга: {$design}\n"
          . "📅 Дата и время: {$when}\n"
          . '💬 Комментарий: ' . ($note !== '' ? $note : '—') . "\n"
          . "📷 Фото референса: {$photoLine}\n"
          . $line;

    $params = ['peer_id' => $peer, 'random_id' => random_int(1, 2147483647), 'message' => $text, 'dont_parse_links' => 1];
    if ($attach) $params['attachment'] = implode(',', $attach);
    vk('messages.send', $params);

    out(200, ['ok' => true, 'id' => $orderId]);
} catch (Throwable $e) {
    logf('send: ' . $e->getMessage() . " | order #{$orderId} {$name} {$phone}");        // you never lose the lead: it is in the log
    out(502, ['ok' => false, 'error' => 'vk', 'id' => $orderId]);
}
