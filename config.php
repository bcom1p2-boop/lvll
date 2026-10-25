<?php
// ตั้งค่าการเชื่อมต่อฐานข้อมูล (XAMPP ค่าเริ่มต้น: root และไม่มีรหัสผ่าน)
const DB_HOST = '127.0.0.1';
const DB_NAME = 'php_la';
const DB_USER = 'root';
const DB_PASS = '';

// เปลี่ยนเป็นข้อความสุ่มของคุณเอง ใช้ผสมตอนเก็บ IP แบบแฮช
const IP_SALT = 'change-this-to-a-random-text';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]
        );
        $pdo->exec("SET NAMES utf8mb4");
    }
    return $pdo;
}

// กุญแจเข้ารหัส PIN ที่ผู้ดูแลที่มีสิทธิ์ "ເບິ່ງ PIN" เปิดดูได้ เปลี่ยนเป็นข้อความสุ่มยาว ๆ ของคุณเอง
// (ถ้าเปลี่ยนกุญแจ PIN เก่าจะเปิดดูไม่ได้ แต่ยังใช้เข้าสู่ระบบได้ตามปกติ)
const PIN_KEY = 'change-this-secret-key-too';

function encPin(string $pin): string {
    $iv = random_bytes(12);
    $c = openssl_encrypt($pin, 'aes-256-gcm', hash('sha256', PIN_KEY, true), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
}
function decPin(?string $s): ?string {
    if (!$s) return null;
    $b = base64_decode($s, true);
    if ($b === false || strlen($b) < 29) return null;
    $p = openssl_decrypt(substr($b, 28), 'aes-256-gcm', hash('sha256', PIN_KEY, true), OPENSSL_RAW_DATA, substr($b, 0, 12), substr($b, 12, 16));
    return $p === false ? null : $p;
}
