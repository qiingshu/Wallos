<?php
error_reporting(E_ERROR | E_PARSE);
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/inputvalidation.php';
require_once '../../includes/getsettings.php';
require_once '../../includes/ssrf_helper.php';
require_once '../../includes/logo_theme_variant.php';
require_once '../../includes/logo_cleanup.php';
require_once '../../includes/subscription_payers.php';
require_once '../../includes/subscription_categories.php';
require_once '../../includes/subscription_logo_upload.php';
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
// A truncated multipart body must not turn into a save with empty fields.
if (empty($_POST) && !empty($_SERVER['CONTENT_LENGTH'])) {
    http_response_code(413);
    echo json_encode(['status' => 'Error', 'message' => '上传内容过大或不完整，请压缩图片后重试。']);
    exit;
}


if (!file_exists('../../images/uploads/logos')) {
    mkdir('../../images/uploads/logos', 0777, true);
    mkdir('../../images/uploads/logos/avatars', 0777, true);
}

function getLogoFromUrl($url, $uploadDir, $name, $settings, $i18n)
{
    $maxRedirects = 3;
    $currentUrl = $url;

    for ($i = 0; $i <= $maxRedirects; $i++) {
        if (!filter_var($currentUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $currentUrl)) {
            return ['success' => false, 'message' => wallos_zh('Invalid URL format.')];
        }

        $parts = parse_url($currentUrl);
        $host = $parts['host'];
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        
        $ip = gethostbyname($host);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false 
            || is_cgnat_ip($ip)) {
            return ['success' => false, 'message' => 'Invalid IP Address.'];
        }

        $ch = curl_init($currentUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Manual handling to re-validate IPs
        
        curl_setopt($ch, CURLOPT_RESOLVE, ["$host:$port:$ip"]);

        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode >= 300 && $httpCode < 400) {
            $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            unset($ch);

            if (!$redirectUrl) {
                break;
            }

            $currentUrl = $redirectUrl;
            continue; 
        }

        if ($imageData !== false && $httpCode === 200) {
            $fileName = 'logo-' . bin2hex(random_bytes(16)) . '.png';
            $uploadFile = $uploadDir . $fileName; // Note: Use the provided $uploadDir variable

            if (saveLogo($imageData, $uploadFile, $name, $settings)) {
                unset($ch);
                return ['success' => true, 'filename' => $fileName];
            }

            unset($ch);
            return ['success' => false, 'message' => translate('error_saving_logo', $i18n)];
        }

        $error = curl_error($ch);
        unset($ch);
        return ['success' => false, 'message' => translate('error_fetching_image', $i18n) . ': ' . $error];
    }

    return ['success' => false, 'message' => translate('error_fetching_image', $i18n)];
}

function saveLogo($imageData, $uploadFile, $name, $settings)
{
    $image = imagecreatefromstring($imageData);
    $removeBackground = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';

    if ($image !== false) {
        $tempFile = tempnam(sys_get_temp_dir(), 'logo');

        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagepng($image, $tempFile);
        imagedestroy($image);

        $newImage = imagecreatefrompng($tempFile);
        if ($newImage !== false) {
            imagealphablending($newImage, false);
            imagesavealpha($newImage, true);

            if ($removeBackground) {
                require_once __DIR__ . '/../../includes/gd_background_removal.php';
                // On palette images imagecolorat() returns palette indexes, not RGB values
                if (!imageistruecolor($newImage)) {
                    imagepalettetotruecolor($newImage);
                    imagealphablending($newImage, false);
                    imagesavealpha($newImage, true);
                }
                // Paint out the corner color with ~10% fuzz
                $corner = imagecolorat($newImage, 0, 0);
                if ((($corner >> 24) & 0x7F) !== 127) {
                    gdRemoveBackgroundColor($newImage, ($corner >> 16) & 0xFF, ($corner >> 8) & 0xFF, $corner & 0xFF);
                }
            }

            // Crop/trim transparent margins
            require_once __DIR__ . '/../../includes/gd_background_removal.php';
            $newImage = gdCropTransparent($newImage, 2);

            $saved = imagepng($newImage, $uploadFile);
            imagedestroy($newImage);
        } else {
            unlink($tempFile);
            return false;
        }

        unlink($tempFile);
        return $saved;
    }

    return false;
}

$isEdit = isset($_POST['id']) && $_POST['id'] != "";
$name = validate($_POST["name"]);
$price = $_POST['price'];
$currencyId = $_POST["currency_id"];
$frequency = $_POST["frequency"];
$cycle = $_POST["cycle"];
$nextPayment = $_POST["next_payment"];
$autoRenew = isset($_POST['auto_renew']) ? true : false;
$startDate = $_POST["start_date"];
$paymentMethodId = $_POST["payment_method_id"];
$payerIds = parsePayerIds($_POST["payer_user_id"] ?? "");
$payerUserId = $payerIds[0] ?? null;
$categoryIds = parsePayerIds($_POST['category_id'] ?? "");
$categoryId = $categoryIds[0] ?? null;
$folderId = isset($_POST['folder_id']) && $_POST['folder_id'] != "" ? intval($_POST['folder_id']) : null;
if ($folderId === 0) {
    $folderId = null;
}
if ($folderId !== null) {
    $folderCheck = $db->prepare("SELECT id FROM folders WHERE id = :id AND user_id = :userId");
    $folderCheck->bindParam(':id', $folderId, SQLITE3_INTEGER);
    $folderCheck->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $folderResult = $folderCheck->execute();
    if (!$folderResult || !$folderResult->fetchArray()) {
        $folderId = null;
    }
}
$notes = validate_markdown($_POST["notes"]);
$url = validate($_POST['url']);
$logoUrl = validate($_POST['logo-url']);
$logo = "";
$notify = isset($_POST['notifications']) ? true : false;
$notifyDaysBefore = $_POST['notify_days_before'];
$inactive = isset($_POST['inactive']) ? true : false;
$cancellationDate = $_POST['cancellation_date'] ?? null;
$replacementSubscriptionId = $_POST['replacement_subscription_id'];

if ($replacementSubscriptionId == 0 || $inactive == 0) {
    $replacementSubscriptionId = null;
}

if ($replacementSubscriptionId !== null) {
    $ownerCheck = $db->prepare("SELECT id FROM subscriptions WHERE id = :id AND user_id = :userId");
    $ownerCheck->bindParam(':id', $replacementSubscriptionId, SQLITE3_INTEGER);
    $ownerCheck->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $ownerResult = $ownerCheck->execute();
    if (!$ownerResult || !$ownerResult->fetchArray()) {
        $replacementSubscriptionId = null;
    }
}

// Reject any foreign key that doesn't belong to the authenticated user, so a
// subscription can't be attached to another tenant's currency/category/
// household member/payment method (IDs are global auto-increment integers
// and easily enumerable).
function rejectForeignId($message)
{
    header('Content-Type: application/json');
    echo json_encode(['status' => 'Error', 'message' => $message]);
    exit();
}

$currStmt = $db->prepare("SELECT id FROM currencies WHERE id = :id AND user_id = :userId");
$currStmt->bindParam(':id', $currencyId, SQLITE3_INTEGER);
$currStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$currResult = $currStmt->execute();
if (!$currResult || !$currResult->fetchArray()) {
    rejectForeignId('The specified currency does not exist or does not belong to you.');
}

if (!categoriesBelongToUser($db, $userId, $categoryIds)) {
    rejectForeignId('The specified category does not exist or does not belong to you.');
}

if (!payersBelongToUser($db, $userId, $payerIds)) {
    rejectForeignId('The specified household member does not exist or does not belong to you.');
}

if ($paymentMethodId !== null && $paymentMethodId !== '') {
    $pmStmt = $db->prepare("SELECT id FROM payment_methods WHERE id = :id AND (user_id = :userId OR user_id = 0 OR user_id IS NULL)");
    $pmStmt->bindParam(':id', $paymentMethodId, SQLITE3_INTEGER);
    $pmStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
    $pmResult = $pmStmt->execute();
    if (!$pmResult || !$pmResult->fetchArray()) {
        rejectForeignId('The specified payment method does not exist or does not belong to you.');
    }
}

try {
    if (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $logo = wallosStoreSubscriptionLogo($_FILES['logo'], '../../images/uploads/logos/');
    } elseif ($logoUrl !== '') {
        $result = getLogoFromUrl($logoUrl, '../../images/uploads/logos/', $name, $settings, $i18n);
        if (!$result['success']) throw new RuntimeException('下载或处理图标失败，请重新选择图标或上传本地图片。');
        $logo = $result['filename'];
    }
} catch (Throwable $error) {
    http_response_code(422);
    $message = $error instanceof RuntimeException ? $error->getMessage() : '服务器处理图片失败，请换用较小的 PNG 或 JPEG 后重试。';
    echo json_encode(['status' => 'Error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$logoTextColor = null;
$logoVariant = null;
$removeBackgroundEnabled = isset($settings['removeBackground']) && $settings['removeBackground'] === 'true';

// Themed variant generation piggybacks on the same "remove background"
// setting: both only make sense for logos we're already reprocessing, and
// this avoids running pixel classification on every single upload.
if ($logo !== "" && $removeBackgroundEnabled) {
    $logoExtension = strtolower(pathinfo($logo, PATHINFO_EXTENSION));
    $logoPath = '../../images/uploads/logos/' . $logo;

    if ($logoExtension === 'png' || $logoExtension === 'webp') {
        $sourceImage = $logoExtension === 'png' ? imagecreatefrompng($logoPath) : imagecreatefromwebp($logoPath);

        if ($sourceImage !== false) {
            imagealphablending($sourceImage, false);
            imagesavealpha($sourceImage, true);

            $logoTextColor = classifyLogoTextColor($sourceImage);

            if ($logoTextColor !== null) {
                $variantImage = generateThemedLogoVariant($sourceImage);
                $logoVariant = pathinfo($logo, PATHINFO_FILENAME) . '-variant.png';
                imagepng($variantImage, '../../images/uploads/logos/' . $logoVariant);
                imagedestroy($variantImage);
            }

            imagedestroy($sourceImage);
        }
    }
}

if (!$isEdit) {
    $sql = "INSERT INTO subscriptions (
                        name, logo, price, currency_id, next_payment, cycle, frequency, notes,
                        payment_method_id, payer_user_id, category_id, folder_id, notify, inactive, url,
                        notify_days_before, user_id, cancellation_date, replacement_subscription_id,
                        auto_renew, start_date, logo_text_color, logo_variant
                    ) VALUES (
                        :name, :logo, :price, :currencyId, :nextPayment, :cycle, :frequency, :notes,
                        :paymentMethodId, :payerUserId, :categoryId, :folderId, :notify, :inactive, :url,
                        :notifyDaysBefore, :userId, :cancellationDate, :replacement_subscription_id,
                        :autoRenew, :startDate, :logoTextColor, :logoVariant
                    )";
} else {
    $id = $_POST['id'];

    // When the logo is being replaced, remember the old files so they can be
    // removed after the update instead of lingering as orphans.
    $oldLogo = null;
    $oldLogoVariant = null;
    if ($logo != "") {
        $oldLogoStmt = $db->prepare("SELECT logo, logo_variant FROM subscriptions WHERE id = :id AND user_id = :userId");
        $oldLogoStmt->bindParam(':id', $id, SQLITE3_INTEGER);
        $oldLogoStmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
        $oldLogoResult = $oldLogoStmt->execute();
        if ($oldLogoResult && ($oldLogoRow = $oldLogoResult->fetchArray(SQLITE3_ASSOC))) {
            $oldLogo = $oldLogoRow['logo'];
            $oldLogoVariant = $oldLogoRow['logo_variant'];
        }
    }

    $sql = "UPDATE subscriptions SET
                        name = :name,
                        price = :price,
                        currency_id = :currencyId,
                        next_payment = :nextPayment,
                        auto_renew = :autoRenew,
                        start_date = :startDate,
                        cycle = :cycle, 
                        frequency = :frequency, 
                        notes = :notes, 
                        payment_method_id = :paymentMethodId,
                        payer_user_id = :payerUserId, 
                        category_id = :categoryId,
                        folder_id = :folderId,
                        notify = :notify,
                        inactive = :inactive, 
                        url = :url, 
                        notify_days_before = :notifyDaysBefore, 
                        cancellation_date = :cancellationDate, 
                        replacement_subscription_id = :replacement_subscription_id";

    if ($logo != "") {
        $sql .= ", logo = :logo, logo_text_color = :logoTextColor, logo_variant = :logoVariant";
    }

    $sql .= " WHERE id = :id AND user_id = :userId";
}

$stmt = $db->prepare($sql);
$stmt->bindParam(':name', $name, SQLITE3_TEXT);
if ($logo != "") {
    $stmt->bindParam(':logo', $logo, SQLITE3_TEXT);
    $stmt->bindParam(':logoTextColor', $logoTextColor, SQLITE3_TEXT);
    $stmt->bindParam(':logoVariant', $logoVariant, SQLITE3_TEXT);
}
$stmt->bindParam(':price', $price, SQLITE3_FLOAT);
$stmt->bindParam(':currencyId', $currencyId, SQLITE3_INTEGER);
$stmt->bindParam(':nextPayment', $nextPayment, SQLITE3_TEXT);
$stmt->bindParam(':autoRenew', $autoRenew, SQLITE3_INTEGER);
$stmt->bindParam(':startDate', $startDate, SQLITE3_TEXT);
$stmt->bindParam(':cycle', $cycle, SQLITE3_INTEGER);
$stmt->bindParam(':frequency', $frequency, SQLITE3_INTEGER);
$stmt->bindParam(':notes', $notes, SQLITE3_TEXT);
$stmt->bindParam(':paymentMethodId', $paymentMethodId, SQLITE3_INTEGER);
$stmt->bindParam(':payerUserId', $payerUserId, SQLITE3_INTEGER);
$stmt->bindParam(':categoryId', $categoryId, SQLITE3_INTEGER);
$stmt->bindParam(':folderId', $folderId, SQLITE3_INTEGER);
$stmt->bindParam(':notify', $notify, SQLITE3_INTEGER);
$stmt->bindParam(':inactive', $inactive, SQLITE3_INTEGER);
$stmt->bindParam(':url', $url, SQLITE3_TEXT);
$stmt->bindParam(':notifyDaysBefore', $notifyDaysBefore, SQLITE3_INTEGER);
$stmt->bindParam(':cancellationDate', $cancellationDate, SQLITE3_TEXT);
if ($isEdit) {
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
}
$stmt->bindParam(':userId', $userId, SQLITE3_INTEGER);
$stmt->bindParam(':replacement_subscription_id', $replacementSubscriptionId, SQLITE3_INTEGER);

if ($stmt->execute()) {
    $success['status'] = "Success";
    $text = $isEdit ? "updated" : "added";
    $success['message'] = translate('subscription_' . $text . '_successfuly', $i18n);

    $savedSubscriptionId = $isEdit ? intval($id) : $db->lastInsertRowID();
    saveSubscriptionPayers($db, $savedSubscriptionId, $payerIds);
    saveSubscriptionCategories($db, $savedSubscriptionId, $categoryIds);

    // The logo was just replaced: drop the previous files if nothing else uses them.
    if ($isEdit && $logo != "") {
        if ($oldLogo !== null && $oldLogo !== $logo) {
            deleteLogoFileIfUnused($db, $oldLogo, '../../images/uploads/logos/');
        }
        if ($oldLogoVariant !== null && $oldLogoVariant !== $logoVariant) {
            deleteLogoFileIfUnused($db, $oldLogoVariant, '../../images/uploads/logos/');
        }
    }

    header('Content-Type: application/json');
    echo json_encode($success);
    exit();
} else {
    if ($logo !== '') deleteLogoFileIfUnused($db, $logo, '../../images/uploads/logos/');
    if ($logoVariant) deleteLogoFileIfUnused($db, $logoVariant, '../../images/uploads/logos/');
    http_response_code(500);
    echo json_encode(['status' => 'Error', 'message' => '数据库保存失败，请稍后重试；已填内容仍保留。'], JSON_UNESCAPED_UNICODE);
}
$db->close();
?>