<?php
declare(strict_types=1);
require_once __DIR__ . '/gcash_helpers.php';
gcashRequireManager();

$paymentId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$paymentId) {
    http_response_code(404);
    exit('Receipt not found.');
}
$statement = database()->prepare('SELECT receipt_image FROM payments WHERE id = :id LIMIT 1');
$statement->execute(['id' => $paymentId]);
$relativePath = $statement->fetchColumn();
if (!is_string($relativePath) || !preg_match('/^storage\/gcash-receipts\/[a-f0-9]{48}\.(?:jpg|png)$/', $relativePath)) {
    http_response_code(404);
    exit('Receipt not found.');
}
$filePath = __DIR__ . '/' . $relativePath;
if (!is_file($filePath)) {
    http_response_code(404);
    exit('Receipt not found.');
}
$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($filePath);
if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
    http_response_code(415);
    exit('Unsupported receipt image.');
}
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string) filesize($filePath));
header('Content-Disposition: inline; filename="gcash-receipt.' . ($mimeType === 'image/png' ? 'png' : 'jpg') . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
