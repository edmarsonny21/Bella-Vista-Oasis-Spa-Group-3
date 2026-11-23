<?php
declare(strict_types=1);
require_once __DIR__ . '/gcash_helpers.php';

function gcashRedirect(string $orderId, string $notice): never
{
    $query = ['notice' => $notice];
    if ($orderId !== '') {
        $query['order_id'] = $orderId;
    }
    header('Location: checkout_gcash.php?' . http_build_query($query), true, 303);
    exit;
}

$guest = gcashRequireGuest();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the payment form to submit payment details.');
}

gcashRequireCsrf($_POST['csrf_token'] ?? null);
$orderIdValue = $_POST['order_id'] ?? '';
$referenceValue = $_POST['gcash_ref_number'] ?? '';
$orderId = is_string($orderIdValue) ? trim($orderIdValue) : '';
$reference = is_string($referenceValue) ? trim($referenceValue) : '';
if (!preg_match('/^[A-Za-z0-9-]{1,30}$/', $orderId) || !preg_match('/^[0-9]{13}$/', $reference)) {
    gcashRedirect('', 'invalid');
}

$appointment = gcashFindGuestAppointment($orderId, (int) $guest['id']);
if (!$appointment) {
    gcashRedirect('', 'invalid');
}

$upload = $_FILES['receipt_image'] ?? null;
if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
    !isset($upload['tmp_name'], $upload['size'], $upload['name']) ||
    !is_string($upload['tmp_name']) || !is_string($upload['name']) || !is_numeric($upload['size']) ||
    !is_uploaded_file($upload['tmp_name']) || (int) $upload['size'] < 1 ||
    (int) $upload['size'] > 5 * 1024 * 1024) {
    gcashRedirect($orderId, 'invalid');
}

$extension = strtolower(pathinfo((string) $upload['name'], PATHINFO_EXTENSION));
$allowedTypes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
$fileInfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $fileInfo->file($upload['tmp_name']);
$imageInfo = @getimagesize($upload['tmp_name']);
if (!isset($allowedTypes[$extension]) || $mimeType !== $allowedTypes[$extension] ||
    !is_array($imageInfo) || !in_array($imageInfo[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
    gcashRedirect($orderId, 'invalid');
}
$safeExtension = $mimeType === 'image/png' ? 'png' : 'jpg';
$storageDirectory = __DIR__ . '/storage/gcash-receipts';
if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0750, true) && !is_dir($storageDirectory)) {
    error_log('Could not create the private GCash receipt directory.');
    http_response_code(500);
    exit('The receipt could not be stored. Please contact the spa.');
}
$filename = bin2hex(random_bytes(24)) . '.' . $safeExtension;
$absolutePath = $storageDirectory . '/' . $filename;
$relativePath = 'storage/gcash-receipts/' . $filename;
if (!move_uploaded_file($upload['tmp_name'], $absolutePath)) {
    error_log('Could not move an uploaded GCash receipt into private storage.');
    http_response_code(500);
    exit('The receipt could not be stored. Please try again.');
}

$pdo = database();
try {
    $pdo->beginTransaction();
    $bookingLock = $pdo->prepare(
        "SELECT id, booking_code, guest_name, total_amount
         FROM appointments
         WHERE id = :appointment_id AND guest_user_id = :guest_id
           AND status <> 'cancelled'
         LIMIT 1 FOR UPDATE"
    );
    $bookingLock->execute(['appointment_id' => $appointment['id'], 'guest_id' => $guest['id']]);
    $lockedAppointment = $bookingLock->fetch();
    if (!$lockedAppointment) {
        $pdo->rollBack();
        unlink($absolutePath);
        gcashRedirect('', 'invalid');
    }

    $pendingCheck = $pdo->prepare(
        "SELECT id FROM payments
         WHERE appointment_id = :appointment_id AND payment_status = 'Pending Verification'
         LIMIT 1"
    );
    $pendingCheck->execute(['appointment_id' => $lockedAppointment['id']]);
    if ($pendingCheck->fetchColumn()) {
        $pdo->rollBack();
        unlink($absolutePath);
        gcashRedirect($orderId, 'pending');
    }
    $verifiedCheck = $pdo->prepare(
        "SELECT id FROM payments
         WHERE appointment_id = :appointment_id AND payment_status = 'Verified'
         LIMIT 1"
    );
    $verifiedCheck->execute(['appointment_id' => $lockedAppointment['id']]);
    if ($verifiedCheck->fetchColumn()) {
        $pdo->rollBack();
        unlink($absolutePath);
        gcashRedirect($orderId, 'verified');
    }

    $insert = $pdo->prepare(
        "INSERT INTO payments
         (appointment_id, order_id, customer_name, amount, gcash_ref_number, receipt_image, payment_status)
         VALUES (:appointment_id, :order_id, :customer_name, :amount, :reference, :receipt, 'Pending Verification')"
    );
    $insert->execute([
        'appointment_id' => $lockedAppointment['id'],
        'order_id' => $lockedAppointment['booking_code'],
        'customer_name' => $lockedAppointment['guest_name'],
        'amount' => $lockedAppointment['total_amount'],
        'reference' => $reference,
        'receipt' => $relativePath,
    ]);
    $pdo->commit();
} catch (PDOException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_file($absolutePath)) {
        unlink($absolutePath);
    }
    if ($exception->getCode() === '23000') {
        gcashRedirect($orderId, 'duplicate');
    }
    error_log($exception->getMessage());
    http_response_code(500);
    exit('The payment submission could not be saved. Please try again.');
}

gcashRedirect($orderId, 'submitted');
