<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SocialTurn <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> Recap</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#333333;max-width:600px;margin:0 auto;padding:24px 20px">

  <h2 style="margin-top:0">SocialTurn <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> Recap</h2>
  <p style="color:#888888;margin-top:-8px;margin-bottom:20px"><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></p>

  <p style="font-size:18px;margin:0 0 24px 0">
    <span style="color:#198754">&#10003; <?= (int) $succeeded ?> published</span>
    &nbsp;&nbsp;&nbsp;
    <span style="color:<?= $failed > 0 ? '#dc3545' : '#6c757d' ?>">&#10007; <?= (int) $failed ?> failed</span>
  </p>

  <?php if (!empty($failures)): ?>

  <h3 style="color:#dc3545;border-bottom:2px solid #f5c6cb;padding-bottom:8px;margin-bottom:16px">Failures</h3>

  <?php foreach ($failures as $f): ?>
  <?php
      $fBody = (string) ($f['body_snapshot'] ?? '');
      if (mb_strlen($fBody) > 200) {
          $fBody = mb_substr($fBody, 0, 200) . '…';
      }
  ?>
  <div style="margin-bottom:16px;padding:12px 16px;background:#fff5f5;border-left:4px solid #dc3545">
    <p style="margin:0 0 4px 0">
      <strong><?= htmlspecialchars((string) ($f['account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
      <span style="color:#888888;font-size:13px">(<?= htmlspecialchars(ucfirst((string) ($f['platform'] ?? '')), ENT_QUOTES, 'UTF-8') ?>)</span>
    </p>
    <p style="margin:0 0 6px 0;color:#dc3545;font-size:14px"><?= htmlspecialchars((string) ($f['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
    <p style="margin:0;color:#555555;font-size:13px;font-style:italic"><?= htmlspecialchars($fBody, ENT_QUOTES, 'UTF-8') ?></p>
  </div>
  <?php endforeach; ?>

  <?php else: ?>
  <p style="color:#198754">&#10003; No failures this period.</p>
  <?php endif; ?>

  <hr style="border:none;border-top:1px solid #dee2e6;margin:24px 0 16px 0">

  <p style="color:#888888;font-size:12px;margin:0">
    SocialTurn &mdash;
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>?controller=queue&amp;action=index"
       style="color:#888888">View queue</a>
  </p>

</body>
</html>
