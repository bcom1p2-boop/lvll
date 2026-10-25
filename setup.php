<?php
// รันครั้งเดียวหลังนำเข้า schema.sql แล้วลบไฟล์นี้ทิ้ง
// สร้างบัญชีผู้ดูแลเริ่มต้น (ทำงานเฉพาะตอนตาราง staff ยังว่าง)
require __DIR__ . '/config.php';
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();
if ((int)$pdo->query('SELECT COUNT(*) FROM staff')->fetchColumn() > 0) {
    exit("ตาราง staff มีข้อมูลแล้ว ไม่ทำอะไร กรุณาลบไฟล์ setup.php\n");
}
$ins = $pdo->prepare('INSERT INTO staff
  (name,password_hash,is_main,can_spin,can_approve,can_edit,can_delete,can_reset,can_settings,can_pdf,can_codes)
  VALUES (?,?,?,?,?,?,?,?,?,?,?)');
// ชื่อ, รหัส, main, spin, approve, edit, delete, reset, settings, pdf, codes
$rows = [
    ['main',    'Ap228899', 1, 1, 1, 1, 1, 1, 1, 1, 1],
    ['wheel',   '778899',   0, 1, 0, 0, 0, 0, 0, 0, 0],
    ['approve', '999999',   0, 0, 1, 0, 0, 0, 0, 0, 0],
];
foreach ($rows as $r) {
    $code = array_splice($r, 1, 1)[0];
    array_splice($r, 1, 0, [password_hash($code, PASSWORD_DEFAULT)]);
    $ins->execute($r);
}
echo "สร้างบัญชีแล้ว (ล็อกอินด้วย ชื่อ + รหัส): main / Ap228899 , wheel / 778899 , approve / 999999\n";
echo "กรุณาลบไฟล์ setup.php ทันที และเปลี่ยนรหัสที่หน้าผู้ดูแลหลัก\n";
