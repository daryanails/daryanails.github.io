<?php
declare(strict_types=1);
/* ==========================================================
   api.php — Darya Nails: file-based backend (no Supabase, no database)
   Everything lives on YOUR hosting:
     data/*.json   services, works(gallery), clients, bookings, site texts, admins  (blocked from the web)
     uploads/...   every photo you or a client uploads
   PHP 7.4+, no database, no extra libraries.
   ========================================================== */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
register_shutdown_function(function () {                   // a PHP fatal becomes readable JSON, never an empty page
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR], true)) {
        if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
        echo json_encode(['ok' => false, 'error' => 'php: ' . $e['message'] . ' @' . basename((string)$e['file']) . ':' . $e['line']], JSON_UNESCAPED_UNICODE);
    }
});

const MAX_UPLOAD = 10485760;                       // 10 MB per photo
const FOLDERS    = ['refs', 'items', 'works', 'site', 'misc'];
const EMPTY_COLL = ['seq' => 0, 'rows' => []];
const DENY  = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n";
const NOEXE = "<FilesMatch \"\\.(php[0-9]?|phtml|phar|cgi|pl|py|sh)$\">\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n</FilesMatch>\n";

/* field => type: s string, n number, i nullable int, b bool, d nullable date/string, a list of strings */
const COLL = [
    'items'    => ['kind' => 's', 'title' => 's', 'te' => 's', 'desc' => 's', 'de' => 's', 'price' => 'n', 'old' => 'n', 'img' => 's',
                   'rec' => 'b', 'ratio' => 's', 'fit' => 's', 'pos' => 's', 'cat' => 's', 'dur' => 'n'],
    'works'    => ['img' => 's', 'title' => 's', 'te' => 's', 'ratio' => 's', 'fit' => 's', 'pos' => 's'],
    'clients'  => ['name' => 's', 'phone' => 's', 'note' => 's', 'photos' => 'a'],
    'bookings' => ['client_id' => 'i', 'design' => 's', 'date' => 'd', 'time' => 's', 'note' => 's', 'ref' => 's', 'refs' => 'a',
                   'status' => 's', 'price' => 'n', 'paid' => 'd'],
];

/* ---------- output / errors ---------- */
function jout(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $code, string $err): void { jout(['ok' => false, 'error' => $err], $code); }

/* ---------- folders ---------- */
function ensure_dir(string $d, string $ht = ''): void {
    if (!is_dir($d)) @mkdir($d, 0755, true);
    if ($ht !== '' && !is_file($d . '/.htaccess')) @file_put_contents($d . '/.htaccess', $ht);
}
function data_dir(): string {
    static $d = null;
    if ($d === null) {   // outside the web root when possible, otherwise ./data (blocked by its own .htaccess)
        $d = is_dir(__DIR__ . '/../private') ? __DIR__ . '/../private/dn-data' : __DIR__ . '/data';
        ensure_dir($d, DENY);
    }
    return $d;
}
function dpath(string $n): string { return data_dir() . '/' . $n . '.json'; }
function up_dir(): string { $d = __DIR__ . '/uploads'; ensure_dir($d, NOEXE); return $d; }

/* ---------- JSON file storage with locking ---------- */
function sub(string $s, int $n): string { return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
function slen(string $s): int { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }
function jenc($v): string { return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT); }
function rd(string $n, $def) {
    $f = dpath($n);
    if (!is_file($f)) return $def;
    $fp = fopen($f, 'rb');
    if (!$fp) return $def;
    flock($fp, LOCK_SH);
    $s = (string)stream_get_contents($fp);
    flock($fp, LOCK_UN); fclose($fp);
    $j = json_decode($s, true);
    return $j === null ? $def : $j;
}
function mut(string $n, $def, callable $fn) {            // read → change → write, all under an exclusive lock
    $fp = fopen(dpath($n), 'c+');
    if (!$fp) fail(500, 'storage');
    flock($fp, LOCK_EX);
    $s = (string)stream_get_contents($fp);
    $cur = $s === '' ? $def : json_decode($s, true);
    if ($cur === null) $cur = $def;
    $res = $fn($cur);
    ftruncate($fp, 0); rewind($fp); fwrite($fp, jenc($cur)); fflush($fp);
    flock($fp, LOCK_UN); fclose($fp);
    return $res;
}
function rows(string $t): array { $c = rd($t, EMPTY_COLL); return array_values((array)($c['rows'] ?? [])); }
function add_row(array &$c, array $row): array {
    $max = 0;
    foreach ($c['rows'] as $r) $max = max($max, (int)$r['id']);
    $c['seq'] = max((int)$c['seq'], $max) + 1;
    $row['id'] = $c['seq'];
    $c['rows'][] = $row;
    return $row;
}
function cast(string $t, $v) {
    switch ($t) {
        case 'n': return is_numeric($v) ? $v + 0 : 0;
        case 'i': return (is_numeric($v) && (int)$v > 0) ? (int)$v : null;
        case 'b': return $v === true || $v === 1 || $v === '1' || $v === 'true';
        case 'd': return ($v === null || $v === '') ? null : sub((string)$v, 40);
        case 'a':
            $o = [];
            foreach ((array)$v as $x) if (is_string($x) && $x !== '') $o[] = sub($x, 500);
            return array_slice($o, 0, 30);
        default:  return is_scalar($v) ? sub((string)$v, 4000) : '';
    }
}
function defaults(string $t): array {
    $z = ['s' => '', 'n' => 0, 'i' => null, 'b' => false, 'd' => null, 'a' => []];
    $o = [];
    foreach (COLL[$t] as $k => $ty) $o[$k] = $z[$ty];
    if ($t === 'bookings') { $o['status'] = 'new'; $o['created'] = gmdate('c'); }
    return $o;
}
function cl($s, int $max): string {                       // plain single-line text
    $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', is_scalar($s) ? (string)$s : '') ?? '';
    return sub(trim(preg_replace('/\s+/u', ' ', $s) ?? ''), $max);
}

/* ---------- guards ---------- */
function rate(string $key, int $max, int $win): void {
    $fp = @fopen(sys_get_temp_dir() . '/dn_' . md5($key . '|' . ($_SERVER['REMOTE_ADDR'] ?? '')), 'c+');
    if (!$fp) return;
    flock($fp, LOCK_EX);
    $now = time();
    $h = array_values(array_filter(array_map('intval', explode(',', (string)stream_get_contents($fp))), function ($t) use ($now, $win) { return $t > $now - $win; }));
    $over = count($h) >= $max;
    if (!$over) { $h[] = $now; ftruncate($fp, 0); rewind($fp); fwrite($fp, implode(',', $h)); }
    flock($fp, LOCK_UN); fclose($fp);
    if ($over) fail(429, 'rate');
}
function same_origin(): void {                             // blocks cross-site requests (CSRF)
    $o = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($o !== '') {
        $port = parse_url($o, PHP_URL_PORT);
        $host = (string)parse_url($o, PHP_URL_HOST) . ($port ? ':' . $port : '');
        if ($host !== (string)($_SERVER['HTTP_HOST'] ?? '')) fail(403, 'origin');
    }
    if (($_SERVER['HTTP_X_DN'] ?? '') !== '1') fail(403, 'csrf');
}

/* ---------- admins & sessions ---------- */
function sess(bool $create = false): bool {
    if (session_status() === PHP_SESSION_ACTIVE) return true;
    if (!$create && !isset($_COOKIE['dnsid'])) return false;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('dnsid');
    session_set_cookie_params(['lifetime' => 1209600, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    ini_set('session.gc_maxlifetime', '1209600');
    return session_start();
}
function pub_admin(array $a): array { return ['id' => $a['id'], 'email' => $a['email'], 'role' => $a['role']]; }
function me(): ?array {
    if (!sess()) return null;
    $id = (string)($_SESSION['uid'] ?? '');
    if ($id === '') return null;
    foreach (rows('admins') as $a) if ($a['id'] === $id) return pub_admin($a);
    return null;
}
function need_auth(): array { $u = me(); if (!$u) fail(401, 'auth'); return $u; }
function need_super(): array { $u = need_auth(); if ($u['role'] !== 'super') fail(403, 'forbidden'); return $u; }
function login_as(array $a): void { sess(true); session_regenerate_id(true); $_SESSION['uid'] = $a['id']; }
function new_admin(string $email, string $pass, string $role): array {
    return ['id' => bin2hex(random_bytes(8)), 'email' => $email, 'role' => $role === 'super' ? 'super' : 'editor',
            'hash' => password_hash($pass, PASSWORD_DEFAULT), 'created_at' => gmdate('c')];
}

/* ---------- uploads ---------- */
function save_upload($f, string $folder): string {
    if (!is_array($f) || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) fail(400, 'upload');
    if ($f['size'] <= 0 || $f['size'] > MAX_UPLOAD) fail(413, 'too_big');
    $gi = @getimagesize((string)$f['tmp_name']);
    $mime = is_array($gi) ? (string)($gi['mime'] ?? '') : '';
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($map[$mime])) fail(415, 'type');
    $dir = up_dir() . '/' . $folder;
    ensure_dir($dir);
    $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $map[$mime];
    if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $name)) fail(500, 'save');
    @chmod($dir . '/' . $name, 0644);
    return '/uploads/' . $folder . '/' . $name;
}
function drop_orphans(array $urls): void {                 // delete a photo file once nothing uses it any more
    $base = realpath(up_dir());
    foreach (array_unique($urls) as $u) {
        if (!is_string($u) || strpos($u, '/uploads/') !== 0 || strpos($u, '..') !== false) continue;
        $used = false;
        foreach (['items', 'works', 'clients', 'bookings', 'site'] as $n) {
            $f = dpath($n);
            if (is_file($f) && strpos((string)file_get_contents($f), '"' . $u . '"') !== false) { $used = true; break; }
        }
        if ($used) continue;
        $rp = realpath(__DIR__ . $u);
        if ($rp && $base && strpos($rp, $base . DIRECTORY_SEPARATOR) === 0) @unlink($rp);
    }
}


/* ---------- VK notification (every new booking is sent to your VK) ---------- */
function vk_cfg(): array {
    static $c = null;
    if ($c !== null) return $c;
    $c = ['token' => '', 'peer_id' => ''];
    foreach ([__DIR__ . '/../private/vk-config.php', data_dir() . '/vk-config.php', __DIR__ . '/vk-config.php'] as $f) {
        if (is_file($f)) { $c = array_merge($c, (array)(require $f)); break; }
    }
    foreach (['VK_TOKEN' => 'token', 'VK_PEER_ID' => 'peer_id'] as $env => $key) { $v = getenv($env); if ($v) $c[$key] = $v; }
    return $c;
}
function vk_log(string $m): void { @file_put_contents(data_dir() . '/vk-errors.log', '[' . date('c') . '] ' . $m . "\n", FILE_APPEND | LOCK_EX); }
function vk_call(string $method, array $params): array {
    $tok = (string)(vk_cfg()['token'] ?? '');
    if ($tok === '') throw new RuntimeException('VK token is empty (vk-config.php not found)');
    if (!function_exists('curl_init')) throw new RuntimeException('cURL is not enabled on the hosting');
    $ch = curl_init('https://api.vk.com/method/' . $method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($params + ['v' => '5.199']),
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 20]);
    $raw = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($raw === false) throw new RuntimeException('VK network: ' . $err);
    $j = json_decode((string)$raw, true);
    if (!is_array($j)) throw new RuntimeException('VK bad response');
    if (isset($j['error'])) throw new RuntimeException('VK ' . ($j['error']['error_code'] ?? '?') . ': ' . ($j['error']['error_msg'] ?? ''));
    return (array)($j['response'] ?? []);
}
function vk_photo(string $file, string $peer): string {
    $srv = vk_call('photos.getMessagesUploadServer', ['peer_id' => $peer]);
    $ch = curl_init((string)$srv['upload_url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => ['photo' => new CURLFile($file, (string)mime_content_type($file), 'reference.jpg')],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 40]);
    $raw = curl_exec($ch); curl_close($ch);
    $up = json_decode((string)$raw, true);
    if (!is_array($up) || empty($up['photo'])) throw new RuntimeException('VK upload failed');
    $s = vk_call('photos.saveMessagesPhoto', ['server' => $up['server'], 'photo' => $up['photo'], 'hash' => $up['hash']]);
    $p = $s[0] ?? null;
    if (!$p) throw new RuntimeException('VK save photo failed');
    return 'photo' . $p['owner_id'] . '_' . $p['id'] . (isset($p['access_key']) ? '_' . $p['access_key'] : '');
}
function vk_send(string $text, array $photoUrls = []): void {
    $peer = (string)(vk_cfg()['peer_id'] ?? '');
    if (!preg_match('/^-?\d+$/', $peer)) throw new RuntimeException('VK peer_id is not configured');
    $att = [];
    foreach ($photoUrls as $u) {                                   // /uploads/refs/xxx.jpg -> local file
        $rp = realpath(__DIR__ . $u);
        if (!$rp || strpos($rp, (string)realpath(up_dir()) . DIRECTORY_SEPARATOR) !== 0) continue;
        try {
            $f = $rp;
            if (preg_match('/\.(webp|gif)$/i', $rp) && function_exists('imagecreatefromwebp')) {   // VK: no webp
                $im = preg_match('/\.webp$/i', $rp) ? @imagecreatefromwebp($rp) : @imagecreatefromgif($rp);
                if (!$im) continue;
                $f = tempnam(sys_get_temp_dir(), 'dnv'); imagejpeg($im, $f, 90); imagedestroy($im);
                $att[] = vk_photo($f, $peer); @unlink($f); continue;
            }
            $att[] = vk_photo($f, $peer);
        } catch (Throwable $e) { vk_log('photo: ' . $e->getMessage()); }
    }
    $p = ['peer_id' => $peer, 'random_id' => random_int(1, 2147483647), 'message' => $text, 'dont_parse_links' => 1];
    if ($att) $p['attachment'] = implode(',', $att);
    vk_call('messages.send', $p);
}
function vk_booking_text(int $id, string $name, string $phone, string $design, ?string $date, string $time, string $note, int $nPhotos): string {
    $mo = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
    $d = 'Дата не указана';
    if ($date && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) $d = (int)$m[3] . ' ' . $mo[(int)$m[2] - 1] . ' ' . $m[1];
    $ln = '━━━━━━━━━━━━━━━━━━━';
    return "📌 Новая заявка на запись\n$ln\n🆔 Номер заказа: # $id\n👤 Имя: $name\n📞 Телефон: $phone\n💅 Дизайн/Услуга: $design\n📅 Дата и время: $d, " . ($time !== '' ? $time : 'любое удобное время')
         . "\n💬 Комментарий: " . ($note !== '' ? $note : '—') . "\n📷 Фото референса: " . ($nPhotos ? "приложено ($nPhotos шт.)" : 'не приложено') . "\n$ln";
}

/* ==========================================================
   Router
   ========================================================== */
$a = (string)($_GET['a'] ?? '');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
$in = [];
if ($method === 'POST') {
    same_origin();
    if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0) {
        $j = json_decode((string)file_get_contents('php://input', false, null, 0, 8388608), true);
        $in = is_array($j) ? $j : [];
    } else {
        $in = $_POST;
    }
} elseif ($method !== 'GET') {
    fail(405, 'method');
}
if (($method === 'GET') !== in_array($a, ['public', 'all', 'me', 'admins', 'diag', 'vk_test'], true)) fail(405, 'method');

switch ($a) {
    /* ----- reads ----- */
    case 'public': {
        $s = rd('site', []);
        jout(['ok' => true, 'items' => rows('items'), 'works' => rows('works'), 'site' => $s ? $s : new stdClass()]);
    }
    case 'all': {
        need_auth();
        $b = rows('bookings');
        usort($b, function ($x, $y) { return strcmp((string)($y['created'] ?? ''), (string)($x['created'] ?? '')); });
        $s = rd('site', []);
        jout(['ok' => true, 'clients' => rows('clients'), 'bookings' => $b, 'items' => rows('items'), 'works' => rows('works'), 'site' => $s ? $s : new stdClass()]);
    }
    case 'diag': {
        need_auth();
        $w = function ($d) { return is_dir($d) && is_writable($d); };
        jout(['ok' => true, 'php' => PHP_VERSION, 'mbstring' => function_exists('mb_substr'), 'gd' => function_exists('imagecreatefromjpeg'),
              'data_dir' => data_dir(), 'data_writable' => $w(data_dir()), 'uploads_writable' => $w(up_dir()),
              'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'),
              'free_mb' => (int)(@disk_free_space(__DIR__) / 1048576)]);
    }
    case 'me': {
        $u = me();
        if (!$u) fail(401, 'auth');
        jout(['ok' => true, 'user' => $u]);
    }
    case 'admins': {
        need_auth();
        jout(['ok' => true, 'rows' => array_map(function ($r) { return ['id' => $r['id'], 'email' => $r['email'], 'role' => $r['role'], 'created_at' => $r['created_at'] ?? '']; }, rows('admins'))]);
    }

    /* ----- visitors ----- */
    case 'create_booking': {
        rate('bk', 8, 600);
        $name = cl($in['name'] ?? '', 80); $phone = cl($in['phone'] ?? '', 30); $design = cl($in['design'] ?? '', 200);
        if (slen($name) < 2 || strlen((string)preg_replace('/\D/', '', $phone)) < 10 || !preg_match('/^[+\d\s()\-]+$/', $phone) || $design === '') fail(422, 'validation');
        $date = cl($in['date'] ?? '', 10); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = null;
        $time = cl($in['time'] ?? '', 30);
        $note = sub(trim((string)($in['note'] ?? '')), 800);
        $photos = [];
        foreach ((array)($in['photos'] ?? []) as $u) if (is_string($u) && strpos($u, '/uploads/refs/') === 0 && strpos($u, '..') === false) $photos[] = $u;
        $photos = array_slice($photos, 0, 3);

        $cid = mut('clients', EMPTY_COLL, function (array &$c) use ($name, $phone, $photos) {
            foreach ($c['rows'] as $i => $r) {
                if (trim((string)$r['phone']) === $phone) {
                    if ($photos) $c['rows'][$i]['photos'] = array_slice(array_merge((array)$r['photos'], $photos), 0, 30);
                    return (int)$r['id'];
                }
            }
            $row = add_row($c, array_merge(defaults('clients'), ['name' => $name, 'phone' => $phone, 'photos' => $photos]));
            return (int)$row['id'];
        });
        $bid = mut('bookings', EMPTY_COLL, function (array &$c) use ($cid, $design, $date, $time, $note, $photos) {
            $row = add_row($c, array_merge(defaults('bookings'), ['client_id' => $cid, 'design' => $design, 'date' => $date, 'time' => $time,
                'note' => $note, 'ref' => $photos[0] ?? '', 'refs' => $photos]));
            return (int)$row['id'];
        });
        $vkOk = true;                                                  // the booking is already saved; VK failure never loses it
        try { vk_send(vk_booking_text($bid, $name, $phone, $design, $date, $time, $note, count($photos)), $photos); }
        catch (Throwable $e) { $vkOk = false; vk_log('booking #' . $bid . ' ' . $name . ' ' . $phone . ' | ' . $e->getMessage()); }
        jout(['ok' => true, 'id' => $bid, 'vk' => $vkOk]);
    }
    case 'vk_test': {                                                  // open /api.php?a=vk_test while logged in to the admin
        need_auth();
        try { vk_send("✅ Тест: уведомления Darya Nails работают"); jout(['ok' => true, 'vk' => 'sent']); }
        catch (Throwable $e) { jout(['ok' => false, 'error' => $e->getMessage()], 200); }
    }
    case 'upload': {
        $folder = preg_replace('/[^a-z]/', '', strtolower((string)($in['folder'] ?? 'misc'))) ?? 'misc';
        if (!me()) { $folder = 'refs'; rate('up', 12, 600); }          // visitors: reference photos only
        elseif (!in_array($folder, FOLDERS, true)) $folder = 'misc';
        jout(['ok' => true, 'url' => save_upload($_FILES['file'] ?? null, $folder)]);
    }

    /* ----- sign in / out ----- */
    case 'login': {
        rate('login', 10, 900);
        $email = strtolower(cl($in['email'] ?? '', 120)); $pass = (string)($in['pass'] ?? '');
        $adm = null;
        foreach (rows('admins') as $r) if (strtolower((string)$r['email']) === $email) $adm = $r;
        if (!$adm || !password_verify($pass, (string)$adm['hash'])) { usleep(300000); fail(401, 'bad_login'); }
        login_as($adm);
        jout(['ok' => true, 'user' => pub_admin($adm)]);
    }
    case 'logout': {
        if (sess()) { $_SESSION = []; session_destroy(); }
        jout(['ok' => true]);
    }
    case 'setup': {                                                       // first admin only; refuses once any admin exists
        rate('setup', 10, 900);
        $email = strtolower(cl($in['email'] ?? '', 120)); $pass = (string)($in['pass'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) fail(422, 'validation');
        $adm = mut('admins', EMPTY_COLL, function (array &$c) use ($email, $pass) {
            if ($c['rows']) return null;
            $row = new_admin($email, $pass, 'super'); $c['rows'][] = $row; return $row;
        });
        if (!$adm) fail(403, 'already_setup');
        login_as($adm);
        jout(['ok' => true, 'user' => pub_admin($adm)]);
    }

    /* ----- admin: content ----- */
    case 'save': {
        need_auth();
        $t = (string)($in['t'] ?? '');
        if (!isset(COLL[$t])) fail(400, 'collection');
        $src = (array)($in['row'] ?? []);
        $row = [];
        foreach (COLL[$t] as $k => $ty) if (array_key_exists($k, $src)) $row[$k] = cast($ty, $src[$k]);
        $id = (int)($src['id'] ?? 0);
        $saved = mut($t, EMPTY_COLL, function (array &$c) use ($t, $row, $id) {
            if ($id > 0) foreach ($c['rows'] as $i => $r) {
                if ((int)$r['id'] === $id) { $c['rows'][$i] = array_merge($r, $row, ['id' => $id]); return $c['rows'][$i]; }
            }
            return add_row($c, array_merge(defaults($t), $row));
        });
        jout(['ok' => true, 'row' => $saved]);
    }
    case 'delete': {
        need_super();
        $t = (string)($in['t'] ?? ''); $id = (int)($in['id'] ?? 0);
        if (!isset(COLL[$t]) || $id <= 0) fail(400, 'bad');
        $gone = mut($t, EMPTY_COLL, function (array &$c) use ($id) {
            foreach ($c['rows'] as $i => $r) if ((int)$r['id'] === $id) { array_splice($c['rows'], $i, 1); return $r; }
            return null;
        });
        if (!$gone) fail(404, 'not_found');
        drop_orphans(array_merge([(string)($gone['img'] ?? ''), (string)($gone['ref'] ?? '')], (array)($gone['photos'] ?? []), (array)($gone['refs'] ?? [])));
        jout(['ok' => true]);
    }
    case 'site_save': {
        need_auth();
        $d = $in['data'] ?? null;
        if (!is_array($d)) fail(400, 'data');
        if (strlen(jenc($d)) > 300000) fail(413, 'too_big');
        mut('site', [], function (&$x) use ($d) { $x = $d; return true; });
        jout(['ok' => true]);
    }

    /* ----- admin: accounts ----- */
    case 'password': {
        $u = need_auth();
        $pass = (string)($in['pass'] ?? '');
        if (strlen($pass) < 6) fail(422, 'short');
        mut('admins', EMPTY_COLL, function (array &$c) use ($u, $pass) {
            foreach ($c['rows'] as $i => $r) if ($r['id'] === $u['id']) $c['rows'][$i]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
            return true;
        });
        jout(['ok' => true]);
    }
    case 'admin_add': {
        need_super();
        $email = strtolower(cl($in['email'] ?? '', 120)); $pass = (string)($in['pass'] ?? ''); $role = (string)($in['role'] ?? 'editor');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 6) fail(422, 'validation');
        $ok = mut('admins', EMPTY_COLL, function (array &$c) use ($email, $pass, $role) {
            foreach ($c['rows'] as $r) if (strtolower((string)$r['email']) === $email) return false;
            $c['rows'][] = new_admin($email, $pass, $role);
            return true;
        });
        if (!$ok) fail(409, 'exists');
        jout(['ok' => true]);
    }
    case 'admin_remove': {
        need_super();
        $id = (string)($in['id'] ?? '');
        mut('admins', EMPTY_COLL, function (array &$c) use ($id) {
            $c['rows'] = array_values(array_filter($c['rows'], function ($r) use ($id) { return !($r['id'] === $id && $r['role'] !== 'super'); }));
            return true;
        });
        jout(['ok' => true]);
    }

    /* ----- one-time import from the old Supabase data (migrate.html) ----- */
    case 'import': {
        need_super();
        $n = [];
        foreach (array_keys(COLL) as $t) {
            $out = []; $max = 0; $def = defaults($t);
            foreach ((array)($in[$t] ?? []) as $r) {
                if (!is_array($r)) continue;
                $row = [];
                foreach (COLL[$t] as $k => $ty) $row[$k] = array_key_exists($k, $r) ? cast($ty, $r[$k]) : $def[$k];
                $row['id'] = (int)($r['id'] ?? 0) > 0 ? (int)$r['id'] : $max + 1;
                if ($t === 'bookings') $row['created'] = isset($r['created']) && is_string($r['created']) ? sub($r['created'], 40) : gmdate('c');
                $max = max($max, $row['id']);
                $out[] = $row;
            }
            mut($t, EMPTY_COLL, function (array &$c) use ($out, $max) { $c = ['seq' => $max, 'rows' => $out]; return true; });
            $n[$t] = count($out);
        }
        if (is_array($in['site'] ?? null)) { $s = $in['site']; mut('site', [], function (&$x) use ($s) { $x = $s; return true; }); }
        jout(['ok' => true, 'imported' => $n]);
    }

    default:
        fail(404, 'action');
}
