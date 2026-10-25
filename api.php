<?php
// API ระบบลงทะเบียนรับน้อง 2026-2027 (PHP 8 + MySQL/MariaDB)
require __DIR__ . '/config.php';
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const OFF = 'ຟັງຊັນນີ້ປິດໃຊ້ງານຊົ່ວຄາວ';
const SHIFTS = ['ເຊົ້າ', 'ບ່າຍ', 'ຄ່ຳ'];
const PERM_KEYS = ['spin' => 'can_spin', 'approve' => 'can_approve', 'edit' => 'can_edit',
                   'del' => 'can_delete', 'reset' => 'can_reset', 'settings' => 'can_settings', 'pdf' => 'can_pdf', 'codes' => 'can_codes'];

function out(array $a): never { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
function fail(string $code, string $msg): never { out(['error' => $code, 'msg' => $msg]); }

$pdo = db();
$in = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($in)) $in = [];
$act = (string)($in['a'] ?? '');
$ipHash = hash('sha256', IP_SALT . ($_SERVER['REMOTE_ADDR'] ?? ''));

/* ---------- ตัวช่วย ---------- */
function rateCount(string $action, int $minutes): int {
    global $pdo, $ipHash;
    $st = $pdo->prepare('SELECT COUNT(*) FROM rate_limits WHERE ip_hash=? AND action=? AND hit_at > (NOW() - INTERVAL ' . (int)$minutes . ' MINUTE)');
    $st->execute([$ipHash, $action]);
    return (int)$st->fetchColumn();
}
function rateHit(string $action): void {
    global $pdo, $ipHash;
    $pdo->prepare('INSERT INTO rate_limits (ip_hash, action) VALUES (?,?)')->execute([$ipHash, $action]);
    if (random_int(1, 50) === 1) $pdo->exec('DELETE FROM rate_limits WHERE hit_at < (NOW() - INTERVAL 1 DAY)');
}
function rateLimit(string $action, int $max, int $minutes): void {
    if (rateCount($action, $minutes) >= $max) fail('rate', 'ມີການໃຊ້ງານຖີ່ເກີນໄປ ກະລຸນາລໍຖ້າສັກຄູ່ແລ້ວລອງໃໝ່');
    rateHit($action);
}
function settings(): array {
    global $pdo;
    $m = [];
    foreach ($pdo->query('SELECT k, v FROM settings') as $r) $m[$r['k']] = $r['v'];
    return [
        'regOff'  => ($m['reg_off'] ?? '0') === '1',
        'chkOff'  => ($m['check_off'] ?? '0') === '1',
        'whOff'   => ($m['wheel_off'] ?? '0') === '1',
        'noAppr'  => ($m['auto_approve'] ?? '0') === '1',
        'link'    => $m['group_link'] ?? '',
    ];
}
function normPhone(string $v): ?array {
    $v = trim($v);
    $cc = '856';
    $rest = preg_replace('/[\s\-().]/', '', $v);
    if (preg_match('/^\+(\d{1,3})[\s-]*([\d\s\-().]+)$/', $v, $m)) {
        $cc = $m[1];
        $rest = preg_replace('/[\s\-().]/', '', $m[2]);
    } elseif (str_starts_with($rest, '856') && strlen($rest) > 10) {
        $rest = substr($rest, 3);
    }
    if ($rest === '' || !ctype_digit($rest)) return null;
    return [$cc, ltrim($rest, '0')];
}
function phoneOk(string $cc, string $n): bool {
    if ($cc === '856') return (bool)preg_match('/^(20\d{8}|30\d{7})$/', $n);
    return (bool)preg_match('/^\d{6,12}$/', $n);
}
function digits($s): int { return (int)preg_replace('/\D/', '', (string)$s); }
function laoName(string $s): bool {
    return (bool)preg_match('/^[\x{0E81}-\x{0ECD}\x{0EDC}\x{0EDD}]+( [\x{0E81}-\x{0ECD}\x{0EDC}\x{0EDD}]+)*$/u', $s);
}
function cleanName($s): string { return trim(preg_replace('/\s+/u', ' ', (string)$s)); }
function fmtReg(array $r): array {
    return [
        'ref' => $r['ref'], 'id' => $r['ref'],
        'firstName' => $r['first_name'], 'lastName' => $r['last_name'],
        'gender' => $r['gender'], 'phone' => '+' . $r['country_code'] . ' ' . $r['phone'],
        'year' => 'ປີ ' . $r['year_level'], 'shift' => $r['shift'], 'batch' => 'ຊຸດ ' . $r['batch'],
        'hint' => $r['hint'], 'status' => $r['status'], 'createdAt' => $r['created_at'],
        'spins' => isset($r['spins']) ? (int)$r['spins'] : 0,
    ];
}
function ptxt(array $r): string {
    return '+' . $r['country_code'] . ' ' . $r['phone'] . ($r['hint'] !== '' ? ' · ' . $r['hint'] : '');
}
function validateReg(array $d, bool $needPin): array {
    $fn = cleanName($d['firstName'] ?? ''); $ln = cleanName($d['lastName'] ?? '');
    if (!laoName($fn) || !laoName($ln)) fail('name', 'ຊື່ ແລະ ນາມສະກຸນ ຕ້ອງເປັນພາສາລາວເທົ່ານັ້ນ (ຫ້າມໃຊ້ຕົວເລກ ຫຼື ພາສາອື່ນ)');
    $gender = (string)($d['gender'] ?? '');
    if (!in_array($gender, ['ຊາຍ', 'ຍິງ'], true)) fail('gender', 'ກະລຸນາເລືອກເພດ');
    $year = digits($d['year'] ?? '');
    if ($year < 1 || $year > 3) fail('year', 'ກະລຸນາເລືອກປີຮຽນ');
    $shift = (string)($d['shift'] ?? '');
    if (!in_array($shift, SHIFTS, true)) fail('shift', 'ກະລຸນາເລືອກພາກຮຽນ');
    $batch = digits($d['batch'] ?? '');
    if ($batch < 1 || $batch > 3) fail('batch', 'ກະລຸນາເລືອກຊຸດ');
    $p = normPhone((string)($d['phone'] ?? ''));
    if (!$p || !phoneOk($p[0], $p[1])) fail('phone', 'ເບີໂທບໍ່ຖືກຕ້ອງ (ຕົວຢ່າງ 020 5555 1234)');
    $hint = $year >= 2 ? mb_substr(trim((string)($d['hint'] ?? '')), 0, 120) : '';
    $pin = (string)($d['pin'] ?? '');
    if ($needPin && !preg_match('/^\d{4,6}$/', $pin)) fail('pin', 'PIN ຕ້ອງເປັນຕົວເລກ 4-6 ໂຕ');
    return compact('fn', 'ln', 'gender', 'year', 'shift', 'batch', 'hint', 'pin') + ['cc' => $p[0], 'ph' => $p[1]];
}
function getByRef(string $ref): ?array {
    global $pdo;
    $st = $pdo->prepare('SELECT * FROM registrations WHERE ref=?');
    $st->execute([$ref]);
    return $st->fetch() ?: null;
}
function availableSeniors(): int {
    global $pdo;
    return (int)$pdo->query("SELECT COUNT(*) FROM registrations r LEFT JOIN spins s ON s.senior_ref=r.ref
        WHERE r.year_level IN (2,3) AND r.status='approved' AND r.deleted_at IS NULL AND s.id IS NULL")->fetchColumn();
}
function picksOf(string $junior): array {
    global $pdo;
    $st = $pdo->prepare('SELECT r.country_code, r.phone, r.hint FROM spins s JOIN registrations r ON r.ref=s.senior_ref WHERE s.junior_ref=? ORDER BY s.id');
    $st->execute([$junior]);
    return array_map(fn($r) => ['phone' => '+' . $r['country_code'] . ' ' . $r['phone'], 'hint' => $r['hint']], $st->fetchAll());
}
// ตรวจน้องปี 1 ด้วยเบอร์ + PIN (ใช้ในแท็บหมุนวงล้อ)
function authJunior(array $d): array {
    global $pdo;
    $p = normPhone((string)($d['phone'] ?? ''));
    if (!$p) fail('phone', 'ເບີໂທບໍ່ຖືກຕ້ອງ');
    if (rateCount('pinfail', 15) >= 15) fail('rate', 'ໃສ່ PIN ຜິດຫຼາຍເກີນໄປ ກະລຸນາລໍຖ້າ 15 ນາທີ');
    $st = $pdo->prepare('SELECT * FROM registrations WHERE country_code=? AND phone=?');
    $st->execute([$p[0], $p[1]]);
    $me = $st->fetch();
    if (!$me) { rateHit('pinfail'); fail('nf', 'ບໍ່ພົບເບີໂທນີ້'); }
    if (!password_verify((string)($d['pin'] ?? ''), (string)$me['pin_hash'])) { rateHit('pinfail'); fail('pin', 'PIN ບໍ່ຖືກຕ້ອງ'); }
    if ((int)$me['year_level'] !== 1) fail('year', 'ສະເພາະນ້ອງປີ 1 ເທົ່ານັ້ນທີ່ມີສິດໝຸນ');
    if ($me['status'] === 'pending') fail('status', 'ຂໍ້ມູນຂອງທ່ານຍັງລໍຖ້າຜູ້ດູແລອະນຸມັດ');
    if ($me['status'] === 'rejected') fail('status', 'ຂໍ້ມູນຂອງທ່ານຖືກປະຕິເສດ');
    return $me;
}
// เรียกหมุนวงล้อใน DB (procedure) พร้อมลองใหม่เมื่อชนกัน
function doSpin(string $junior, string $by): string {
    global $pdo;
    for ($t = 0; $t < 4; $t++) {
        try {
            $pdo->exec("CALL spin_wheel(" . $pdo->quote($junior) . ", " . $pdo->quote($by) . ", @sr)");
            $sr = $pdo->query('SELECT @sr')->fetchColumn();
            if (!$sr) fail('none', 'ບໍ່ມີຮຸ່ນພີ່ເຫຼືອໃຫ້ສຸ່ມແລ້ວ');
            return $sr;
        } catch (PDOException $e) {
            $m = $e->getMessage();
            if (str_contains($m, 'spin limit')) fail('limit', 'ໝຸນຄົບ 2 ຄັ້ງແລ້ວ');
            if (str_contains($m, 'no seniors')) fail('none', 'ບໍ່ມີຮຸ່ນພີ່ເຫຼືອໃຫ້ສຸ່ມແລ້ວ');
            if (str_contains($m, 'only year 1')) fail('year', 'ສະເພາະນ້ອງປີ 1 ເທົ່ານັ້ນທີ່ມີສິດໝຸນ');
            $dup = ($e->errorInfo[1] ?? 0) == 1062 || $e->getCode() === '23000';
            $dead = ($e->errorInfo[1] ?? 0) == 1213; // deadlock
            if (!($dup || $dead) || $t === 3) fail('busy', 'ມີຄົນໝຸນພ້ອມກັນຫຼາຍ ກະລຸນາກົດໝຸນອີກຄັ້ງ');
            usleep(random_int(100, 400) * 1000);
        }
    }
    fail('busy', 'ມີຄົນໝຸນພ້ອມກັນຫຼາຍ ກະລຸນາກົດໝຸນອີກຄັ້ງ');
}
function logSpin(string $junior, ?string $senior, string $src, ?int $staff, int $n): void {
    global $pdo;
    $pdo->prepare('INSERT INTO spin_log (junior_ref, senior_ref, src, staff_id, n) VALUES (?,?,?,?,?)')
        ->execute([$junior, $senior, $src, $staff, $n]);
}
function audit(string $action, ?string $ref = null, $detail = null): void {
    global $pdo, $STAFF;
    if (!$STAFF) return;
    $pdo->prepare('INSERT INTO audit_log (staff_id, action, target_ref, detail) VALUES (?,?,?,?)')
        ->execute([$STAFF['id'], $action, $ref, $detail === null ? null : json_encode($detail, JSON_UNESCAPED_UNICODE)]);
}

/* ---------- ผู้ดูแล ---------- */
$STAFF = null;
if (!empty($_SESSION['staff_id'])) {
    $st = $pdo->prepare('SELECT * FROM staff WHERE id=? AND active=1');
    $st->execute([$_SESSION['staff_id']]);
    $STAFF = $st->fetch() ?: null;
    if (!$STAFF) unset($_SESSION['staff_id']);
}
function permsOf(array $s): array {
    $p = [];
    foreach (PERM_KEYS as $k => $col) $p[$k] = (bool)$s['is_main'] || (bool)$s[$col];
    return $p;
}
function need(?string $perm): void {
    global $STAFF;
    if (!$STAFF) fail('auth', 'ກະລຸນາເຂົ້າສູ່ລະບົບຜູ້ດູແລກ່ອນ');
    if ($perm === 'main' && !$STAFF['is_main']) fail('perm', 'ບໍ່ມີສິດ');
    if ($perm && $perm !== 'main' && !permsOf($STAFF)[$perm]) fail('perm', 'ບັນຊີນີ້ບໍ່ມີສິດໃຊ້ງານນີ້');
}

try {
switch ($act) {

/* ===== ສາທາລະນະ ===== */
case 'settings':
    out(['settings' => settings()]);

case 'register': {
    rateLimit('register', 40, 60);
    $s = settings();
    if ($s['regOff']) fail('off', OFF);
    $v = validateReg($in, true);
    $q = $pdo->prepare('SELECT 1 FROM registrations WHERE country_code=? AND phone=? LIMIT 1');
    $q->execute([$v['cc'], $v['ph']]);
    if ($q->fetch()) fail('dup_phone', 'ເບີໂທນີ້ໄດ້ລົງທະບຽນແລ້ວ');
    $q = $pdo->prepare('SELECT 1 FROM registrations WHERE first_name=? AND last_name=? LIMIT 1');
    $q->execute([$v['fn'], $v['ln']]);
    if ($q->fetch()) fail('dup_name', 'ຊື່ ແລະ ນາມສະກຸນນີ້ໄດ້ລົງທະບຽນແລ້ວ');
    try {
        $pdo->prepare('CALL register_student(?,?,?,?,?,?,?,?,?,?,@r)')->execute([
            $v['fn'], $v['ln'], $v['gender'], $v['cc'], $v['ph'], $v['year'], $v['shift'], $v['batch'], $v['hint'],
            password_hash($v['pin'], PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1062) fail('dup', 'ເບີໂທ ຫຼື ຊື່ນີ້ໄດ້ລົງທະບຽນແລ້ວ');
        throw $e;
    }
    $ref = (string)$pdo->query('SELECT @r')->fetchColumn();
    $status = $s['noAppr'] ? 'approved' : 'pending';
    $pdo->prepare('UPDATE registrations SET status=?, pin_enc=? WHERE ref=?')->execute([$status, encPin($v['pin']), $ref]);
    out(['ref' => $ref, 'status' => $status]);
}

case 'lookup': {
    rateLimit('lookup', 120, 15);
    if (settings()['chkOff']) fail('off', OFF);
    $code = trim((string)($in['code'] ?? ''));
    $p = normPhone((string)($in['phone'] ?? ''));
    $nf = 'ບໍ່ພົບຂໍ້ມູນ, ກະລຸນາກວດເບີໂທ ຫຼື ລະຫັດອີກຄັ້ງ';
    if ($code === '' || !$p) fail('nf', $nf);
    if (rateCount('pinfail', 15) >= 15) fail('rate', 'ໃສ່ລະຫັດຜິດຫຼາຍເກີນໄປ ກະລຸນາລໍຖ້າ 15 ນາທີ');
    $st = $pdo->prepare('SELECT * FROM registrations WHERE country_code=? AND phone=?');
    $st->execute([$p[0], $p[1]]);
    $r = $st->fetch();
    $ok = false;
    if ($r) { // ລະຫັດ = PIN ຫຼື ລະຫັດອ້າງອິງ (LV....)
        $cu = strtoupper(preg_replace('/\s/', '', $code));
        $ok = preg_match('/^LV\d+$/', $cu) ? ($cu === $r['ref']) : password_verify($code, (string)$r['pin_hash']);
    }
    if (!$ok) { rateHit('pinfail'); fail('nf', $nf); }
    $ref = $r['ref'];
    $ex = [];
    if ((int)$r['year_level'] === 1) {
        $st = $pdo->prepare('SELECT r.* FROM spins s JOIN registrations r ON r.ref=s.senior_ref WHERE s.junior_ref=? ORDER BY s.id');
        $st->execute([$ref]);
        foreach ($st->fetchAll() as $i => $x) $ex[] = ['ຮຸ່ນພີ່ທີ່ສຸ່ມໄດ້ ຄັ້ງທີ ' . ($i + 1), ptxt($x)];
    } else {
        $st = $pdo->prepare('SELECT j.country_code, j.phone FROM spins s JOIN registrations j ON j.ref=s.junior_ref WHERE s.senior_ref=?');
        $st->execute([$ref]);
        if ($x = $st->fetch()) $ex[] = ['ນ້ອງທີ່ສຸ່ມໄດ້ທ່ານ', '+' . $x['country_code'] . ' ' . $x['phone']];
    }
    out(['d' => fmtReg($r), 'ex' => $ex]);
}

case 'wheel_check': {
    rateLimit('wheel', 300, 15);
    if (settings()['whOff']) fail('off', OFF);
    $me = authJunior($in);
    out(['me' => ['ref' => $me['ref'], 'firstName' => $me['first_name']], 'picks' => picksOf($me['ref']), 'n' => availableSeniors()]);
}

case 'wheel_spin': {
    rateLimit('wheel', 300, 15);
    if (settings()['whOff']) fail('off', OFF);
    $me = authJunior($in);
    $nBefore = availableSeniors();
    $sr = doSpin($me['ref'], 'self');
    $w = getByRef($sr);
    $picks = picksOf($me['ref']);
    logSpin($me['ref'], $sr, 'self', null, count($picks));
    out(['w' => ['phone' => '+' . $w['country_code'] . ' ' . $w['phone'], 'hint' => $w['hint']],
         'picks' => $picks, 'nBefore' => $nBefore, 'n' => availableSeniors()]);
}

/* ===== ຜູ້ດູແລ ===== */
case 'login': {
    if (rateCount('loginfail', 15) >= 8) fail('rate', 'ໃສ່ລະຫັດຜິດຫຼາຍເກີນໄປ ກະລຸນາລໍຖ້າ 15 ນາທີ');
    $code = (string)($in['code'] ?? '');
    $name = trim((string)($in['name'] ?? ''));
    $found = null;
    if ($code !== '' && $name !== '') {
        $q = $pdo->prepare('SELECT * FROM staff WHERE active=1 AND LOWER(name)=LOWER(?)');
        $q->execute([$name]);
        $s = $q->fetch();
        if ($s && password_verify($code, $s['password_hash'])) $found = $s;
    }
    if (!$found) { rateHit('loginfail'); fail('login', 'ຊື່ ຫຼື ລະຫັດບໍ່ຖືກຕ້ອງ'); }
    session_regenerate_id(true);
    $_SESSION['staff_id'] = (int)$found['id'];
    $STAFF = $found;
    out(['main' => (bool)$found['is_main'], 'name' => $found['name'], 'perms' => permsOf($found)]);
}
case 'logout':
    session_destroy();
    out(['ok' => true]);

case 'me':
    need(null);
    out(['main' => (bool)$STAFF['is_main'], 'name' => $STAFF['name'], 'perms' => permsOf($STAFF)]);

case 'admin_list': {
    need(null);
    $rows = $pdo->query('SELECT r.*, (SELECT COUNT(*) FROM spins s WHERE s.junior_ref=r.ref) AS spins
                         FROM registrations r WHERE r.deleted_at IS NULL ORDER BY r.ref')->fetchAll();
    out(['regs' => array_map('fmtReg', $rows)]);
}

case 'admin_pin': { // ເບິ່ງ PIN ຂອງຄົນອື່ນ (ຕ້ອງມີສິດ codes ແລະບັນທຶກທຸກຄັ້ງ)
    need('codes');
    $ref = (string)($in['ref'] ?? '');
    $r = getByRef($ref);
    if (!$r) fail('nf', 'ບໍ່ພົບລາຍການ');
    $pin = decPin($r['pin_enc'] ?? null);
    audit('view_pin', $ref);
    if ($pin === null) fail('nopin', 'ບໍ່ມີ PIN ທີ່ເບິ່ງໄດ້ສຳລັບຄົນນີ້ ກະລຸນາກົດແກ້ໄຂ ແລ້ວຕັ້ງ PIN ໃໝ່ໃຫ້');
    out(['pin' => $pin]);
}

case 'admin_spins': {
    need(null);
    $ref = (string)($in['ref'] ?? '');
    $r = ['picks' => picksOf($ref), 'by' => null];
    $st = $pdo->prepare('SELECT j.ref, j.country_code, j.phone FROM spins s JOIN registrations j ON j.ref=s.junior_ref WHERE s.senior_ref=?');
    $st->execute([$ref]);
    if ($x = $st->fetch()) $r['by'] = $x['ref'] . ' · +' . $x['country_code'] . ' ' . $x['phone'];
    out($r);
}

case 'admin_settings':
    need('settings');
    out(['settings' => settings()]);

case 'admin_settings_save': {
    need('settings');
    $link = trim((string)($in['link'] ?? ''));
    if ($link !== '' && !preg_match('#^https?://#', $link)) fail('link', 'ລິ້ງບໍ່ຖືກຕ້ອງ');
    $up = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?,?) ON DUPLICATE KEY UPDATE v=VALUES(v)');
    foreach (['reg_off' => 'regOff', 'check_off' => 'chkOff', 'wheel_off' => 'whOff', 'auto_approve' => 'noAppr'] as $k => $f)
        $up->execute([$k, !empty($in[$f]) ? '1' : '0']);
    $up->execute(['group_link', $link]);
    audit('settings');
    out(['ok' => true]);
}

case 'admin_status': {
    need('approve');
    $ref = (string)($in['ref'] ?? ''); $to = (string)($in['status'] ?? '');
    if (!in_array($to, ['approved', 'rejected'], true)) fail('arg', 'ສະຖານະບໍ່ຖືກຕ້ອງ');
    $r = getByRef($ref);
    if (!$r) fail('nf', 'ບໍ່ພົບລາຍການ');
    if ($r['status'] === 'approved' && $to === 'rejected') fail('arg', 'ອະນຸມັດແລ້ວ ປະຕິເສດຍ້ອນຫຼັງບໍ່ໄດ້');
    $pdo->prepare('UPDATE registrations SET status=? WHERE ref=?')->execute([$to, $ref]);
    audit($to === 'approved' ? 'approve' : 'reject', $ref);
    out(['ok' => true]);
}

case 'admin_approve_all': {
    need('approve');
    $n = $pdo->exec("UPDATE registrations SET status='approved' WHERE status='pending'");
    audit('approve_all', null, ['count' => $n]);
    out(['count' => $n]);
}

case 'admin_edit': {
    need('edit');
    $ref = (string)($in['ref'] ?? '');
    if (!getByRef($ref)) fail('nf', 'ບໍ່ພົບລາຍການ');
    $v = validateReg($in, false);
    $newPin = trim((string)($in['pin'] ?? ''));
    if ($newPin !== '' && !preg_match('/^\d{4,6}$/', $newPin)) fail('pin', 'PIN ຕ້ອງເປັນຕົວເລກ 4-6 ໂຕ');
    try {
        $pdo->prepare('UPDATE registrations SET first_name=?, last_name=?, gender=?, country_code=?, phone=?, year_level=?, shift=?, batch=?, hint=? WHERE ref=?')
            ->execute([$v['fn'], $v['ln'], $v['gender'], $v['cc'], $v['ph'], $v['year'], $v['shift'], $v['batch'], $v['hint'], $ref]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1062) fail('dup', 'ເບີໂທ ຫຼື ຊື່ນີ້ຊ້ຳກັບຄົນອື່ນ');
        throw $e;
    }
    if ($newPin !== '') {
        $pdo->prepare('UPDATE registrations SET pin_hash=?, pin_enc=? WHERE ref=?')->execute([password_hash($newPin, PASSWORD_DEFAULT), encPin($newPin), $ref]);
        audit('set_pin', $ref);
    }
    audit('edit', $ref);
    out(['ok' => true]);
}

case 'admin_delete': {
    need('del');
    $ref = (string)($in['ref'] ?? '');
    $pdo->prepare('DELETE FROM registrations WHERE ref=?')->execute([$ref]);
    audit('delete', $ref);
    out(['ok' => true]);
}

case 'admin_reset': {
    need('reset');
    $ref = (string)($in['ref'] ?? '');
    $st = $pdo->prepare('SELECT COUNT(*) FROM spins WHERE junior_ref=?');
    $st->execute([$ref]);
    if ((int)$st->fetchColumn() === 0) fail('not_spun', 'ນ້ອງຄົນນີ້ຍັງບໍ່ໄດ້ໝຸນວົງລໍ້ ຈຶ່ງຍັງຣີເຊັດບໍ່ໄດ້');
    $pdo->prepare('DELETE FROM spins WHERE junior_ref=?')->execute([$ref]);
    logSpin($ref, null, 'reset', (int)$STAFF['id'], 0);
    audit('reset_spin', $ref);
    out(['ok' => true]);
}

case 'admin_spin': {
    need('spin');
    $ref = (string)($in['ref'] ?? '');
    $me = getByRef($ref);
    if (!$me || (int)$me['year_level'] !== 1) fail('year', 'ກະລຸນາເລືອກນ້ອງປີ 1');
    if ($me['status'] !== 'approved') fail('status', 'ນ້ອງຄົນນີ້ຍັງບໍ່ໄດ້ຮັບການອະນຸມັດ');
    $nBefore = availableSeniors();
    $sr = doSpin($ref, 'admin');
    $pdo->prepare('UPDATE spins SET staff_id=? WHERE junior_ref=? AND senior_ref=?')->execute([$STAFF['id'], $ref, $sr]);
    $w = getByRef($sr);
    $picks = picksOf($ref);
    logSpin($ref, $sr, 'admin', (int)$STAFF['id'], count($picks));
    audit('admin_spin', $ref);
    out(['w' => ['phone' => '+' . $w['country_code'] . ' ' . $w['phone'], 'hint' => $w['hint']], 'picks' => $picks, 'nBefore' => $nBefore]);
}

case 'staff_list': {
    need('main');
    $rows = $pdo->query('SELECT * FROM staff ORDER BY id')->fetchAll();
    out(['staff' => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'main' => (bool)$s['is_main'],
        'active' => (bool)$s['active'], 'perms' => permsOf($s) ], $rows)]);
}
case 'staff_create': {
    need('main');
    $name = trim((string)($in['name'] ?? '')); $code = (string)($in['code'] ?? '');
    if ($name === '' || mb_strlen($code) < 6) fail('arg', 'ກະລຸນາໃສ່ຊື່ ແລະ ລະຫັດຢ່າງໜ້ອຍ 6 ໂຕ');
    foreach ($pdo->query('SELECT password_hash FROM staff') as $s)
        if (password_verify($code, $s['password_hash'])) fail('dup', 'ລະຫັດນີ້ຖືກໃຊ້ແລ້ວ');
    $perms = (array)($in['perms'] ?? []);
    if (!array_filter($perms)) fail('arg', 'ກະລຸນາເລືອກສິດຢ່າງໜ້ອຍ 1 ຢ່າງ');
    $cols = ['name', 'password_hash', 'created_by']; $vals = [$name, password_hash($code, PASSWORD_DEFAULT), $STAFF['id']];
    foreach (PERM_KEYS as $k => $col) { $cols[] = $col; $vals[] = !empty($perms[$k]) ? 1 : 0; }
    try {
        $pdo->prepare('INSERT INTO staff (' . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) == 1062) fail('dup', 'ຊື່ບັນຊີນີ້ຖືກໃຊ້ແລ້ວ');
        throw $e;
    }
    audit('staff_create', null, ['name' => $name]);
    out(['ok' => true]);
}
case 'staff_perm': {
    need('main');
    $id = (int)($in['id'] ?? 0); $perms = (array)($in['perms'] ?? []);
    $sets = []; $vals = [];
    foreach (PERM_KEYS as $k => $col) { $sets[] = "$col=?"; $vals[] = !empty($perms[$k]) ? 1 : 0; }
    $vals[] = $id;
    $pdo->prepare('UPDATE staff SET ' . implode(',', $sets) . ' WHERE id=? AND is_main=0')->execute($vals);
    audit('staff_perm', null, ['id' => $id]);
    out(['ok' => true]);
}
case 'staff_delete': {
    need('main');
    $id = (int)($in['id'] ?? 0);
    try {
        $pdo->prepare('DELETE FROM staff WHERE id=? AND is_main=0')->execute([$id]);
    } catch (PDOException $e) { // ມີປະຫວັດຜູກຢູ່ ປິດບັນຊີແທນ
        $pdo->prepare('UPDATE staff SET active=0 WHERE id=? AND is_main=0')->execute([$id]);
    }
    audit('staff_delete', null, ['id' => $id]);
    out(['ok' => true]);
}

case 'log_list': {
    need('main');
    $rows = $pdo->query("SELECT l.*, j.first_name jf, j.last_name jl, s.country_code sc, s.phone sp, st.name staff
        FROM spin_log l
        LEFT JOIN registrations j ON j.ref=l.junior_ref
        LEFT JOIN registrations s ON s.ref=l.senior_ref
        LEFT JOIN staff st ON st.id=l.staff_id
        ORDER BY l.id DESC LIMIT 100")->fetchAll();
    out(['log' => array_map(fn($l) => [
        'at' => $l['created_at'], 'src' => $l['src'], 'staff' => $l['staff'] ?? '',
        'junior' => $l['junior_ref'], 'juniorName' => trim(($l['jf'] ?? '') . ' ' . ($l['jl'] ?? '')),
        'id' => (int)$l['id'], 'senior' => $l['senior_ref'] ?? '', 'seniorPhone' => $l['sp'] ? '+' . $l['sc'] . ' ' . $l['sp'] : '', 'n' => (int)$l['n'],
    ], $rows),
    'counts' => $pdo->query("SELECT src, COUNT(*) c FROM spin_log GROUP BY src")->fetchAll(PDO::FETCH_KEY_PAIR)]);
}

case 'log_delete': { // ລຶບປະຫວັດການໝຸນ (ຜູ້ດູແລຫຼັກ)
    need('main');
    if (!empty($in['all'])) { $n = $pdo->exec('DELETE FROM spin_log'); audit('clear_spin_log', null, ['count' => $n]); }
    else { $pdo->prepare('DELETE FROM spin_log WHERE id=?')->execute([(int)($in['id'] ?? 0)]); audit('delete_spin_log', null, ['id' => (int)($in['id'] ?? 0)]); }
    out(['ok' => true]);
}
case 'audit_list': {
    need('main');
    $rows = $pdo->query("SELECT a.*, st.name staff FROM audit_log a LEFT JOIN staff st ON st.id=a.staff_id ORDER BY a.id DESC LIMIT 100")->fetchAll();
    out(['audit' => array_map(fn($a) => ['id' => (int)$a['id'], 'at' => $a['created_at'], 'staff' => $a['staff'] ?? '',
        'action' => $a['action'], 'ref' => $a['target_ref'] ?? '', 'detail' => $a['detail'] ?? ''], $rows),
        'total' => (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn()]);
}
case 'audit_delete': { // ລຶບປະຫວັດການກະທຳຂອງຜູ້ດູແລ (ຜູ້ດູແລຫຼັກ) ແລະເຫຼືອບັນທຶກວ່າໃຜລຶບ
    need('main');
    if (!empty($in['all'])) { $n = $pdo->exec('DELETE FROM audit_log'); audit('clear_audit', null, ['count' => $n]); }
    else { $pdo->prepare('DELETE FROM audit_log WHERE id=?')->execute([(int)($in['id'] ?? 0)]); }
    out(['ok' => true]);
}

case 'admin_wipe': { // ລຶບປະຫວັດ/ຂໍ້ມູນທັງໝົດ (ຜູ້ດູແລຫຼັກ ແລະຕ້ອງຢືນຢັນ)
    need('main');
    if (empty($in['confirm'])) fail('arg', 'ຕ້ອງຢືນຢັນກ່ອນ');
    $w = (string)($in['what'] ?? '');
    if (!in_array($w, ['spin_log', 'spins', 'regs', 'all'], true)) fail('arg', 'ຄຳຂໍບໍ່ຖືກຕ້ອງ');
    $pdo->beginTransaction();
    try {
        if ($w === 'spin_log' || $w === 'all') $pdo->exec('DELETE FROM spin_log');
        if (in_array($w, ['spins', 'regs', 'all'], true)) $pdo->exec('DELETE FROM spins');
        if ($w === 'regs' || $w === 'all') { $pdo->exec('DELETE FROM registrations'); $pdo->exec('UPDATE ref_counters SET last_no=0'); }
        if ($w === 'all') { $pdo->exec('DELETE FROM audit_log'); $pdo->exec('DELETE FROM rate_limits'); }
        $pdo->commit();
    } catch (PDOException $e) { $pdo->rollBack(); throw $e; }
    audit('wipe_' . $w);
    out(['ok' => true]);
}

default:
    fail('bad', 'ຄຳຂໍບໍ່ຖືກຕ້ອງ');
}
} catch (PDOException $e) {
    error_log('freshy api: ' . $e->getMessage());
    fail('db', 'ລະບົບຖານຂໍ້ມູນມີບັນຫາ ກະລຸນາລອງໃໝ່');
}
