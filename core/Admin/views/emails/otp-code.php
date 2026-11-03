<?php
/**
 * @var string $name
 * @var string $code
 * @var int $minutes
 * @var string $siteName
 */
?>
<!doctype html>
<html lang="<?= e(\Velk\App::instance()->adminI18n->current()) ?>">
<body style="font-family: -apple-system, system-ui, sans-serif; background: #f5f5f6; padding: 32px 16px;">
  <table cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width: 520px; margin: 0 auto; background: #fff; border: 1px solid #e6e6e8; border-radius: 12px;">
    <tr><td style="padding: 32px;">
      <h1 style="margin: 0 0 16px; font-size: 20px; color: #1a1a1a;"><?= __('email.otp.title') ?></h1>
      <p style="margin: 0 0 16px; color: #444; line-height: 1.5;"><?= __('email.otp.greeting', ['name' => $name]) ?></p>
      <p style="margin: 0 0 24px; color: #444; line-height: 1.5;"><?= __('email.otp.intro', ['site' => $siteName, 'minutes' => (int) $minutes]) ?></p>
      <p style="margin: 0 0 24px; text-align: center;">
        <span style="display: inline-block; background: #f5f5f6; border: 1px solid #e6e6e8; border-radius: 8px; padding: 14px 28px; font-size: 28px; letter-spacing: 8px; font-weight: 600; color: #1a1a1a; font-family: ui-monospace, monospace;"><?= e($code) ?></span>
      </p>
      <p style="margin: 0; color: #888; font-size: 13px; line-height: 1.5;"><?= __('email.otp.footer') ?></p>
    </td></tr>
  </table>
</body>
</html>
