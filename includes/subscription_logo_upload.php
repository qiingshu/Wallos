<?php
// Validate actual image bytes, not the phone's filename or supplied MIME type.
function wallosStoreSubscriptionLogo($file, $directory)
{
    $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE => '图片超过服务器上传限制，请压缩后重试。',
            UPLOAD_ERR_FORM_SIZE => '图片过大，请压缩后重试。',
            UPLOAD_ERR_PARTIAL => '图片上传不完整，请重新选择并重试。',
            UPLOAD_ERR_NO_FILE => '未收到图片，请重新选择。',
        ];
        throw new RuntimeException($messages[$error] ?? '服务器无法接收图片，请稍后重试。');
    }
    $path = $file['tmp_name'];
    if (!is_uploaded_file($path)) throw new RuntimeException('上传文件无效，请重新选择图片。');
    if (filesize($path) > 10 * 1024 * 1024) throw new RuntimeException('图片超过 10 MB，请压缩后重试。');
    $info = @getimagesize($path);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
        throw new RuntimeException('图片格式不受支持或文件已损坏；请使用 PNG、JPEG、GIF 或 WebP，HEIC/HEIF 请先转为 JPEG。');
    }
    if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 8000000) {
        throw new RuntimeException('图片尺寸过大，请缩小到 800 万像素以内后重试。');
    }
    $image = @imagecreatefromstring(file_get_contents($path));
    if (!$image) throw new RuntimeException('无法解码图片，文件可能已损坏，请更换图片。');
    $resized = null;
    $temporary = null;
    try {
        $scale = min(1, 135 / $info[0], 42 / $info[1]);
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
        if (!imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1])) {
            throw new RuntimeException('图片缩放失败，请更换图片后重试。');
        }
        require_once __DIR__ . '/gd_background_removal.php';
        $resized = gdCropTransparent($resized, 2);
        // A fresh URL also prevents same-name/same-second replacements using stale PWA images.
        $filename = 'logo-' . bin2hex(random_bytes(16)) . '.png';
        $temporary = tempnam($directory, '.upload-');
        if (!$temporary || !@imagepng($resized, $temporary) || !@chmod($temporary, 0644) || !@rename($temporary, $directory . $filename)) {
            throw new RuntimeException('服务器无法写入图标，请稍后重试；订阅尚未保存。');
        }
        $temporary = null;
        return $filename;
    } finally {
        imagedestroy($image);
        if ($resized) imagedestroy($resized);
        if ($temporary && is_file($temporary)) unlink($temporary);
    }
}
