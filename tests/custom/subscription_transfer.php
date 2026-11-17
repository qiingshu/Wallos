<?php
require_once __DIR__ . '/../../includes/subscription_logo_upload.php';
wallosValidateSubscriptionLogoTransfer(['error' => UPLOAD_ERR_OK]);
foreach ([UPLOAD_ERR_PARTIAL => '上传不完整', UPLOAD_ERR_INI_SIZE => '上传限制', UPLOAD_ERR_NO_TMP_DIR => '无法接收'] as $error => $expected) {
    try {
        wallosValidateSubscriptionLogoTransfer(['error' => $error]);
        throw new LogicException('Transfer failure was accepted');
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), $expected)) throw new LogicException('Wrong transfer error');
    }
}
echo "PASS upload transfer classification\n";
