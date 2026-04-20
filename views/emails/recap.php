<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>SocialTurn <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> Recap</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#333333;max-width:600px;margin:0 auto;padding:24px 20px">

  <h2 style="margin-top:0">SocialTurn <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> Recap</h2>
  <p style="color:#888888;margin-top:-8px;margin-bottom:4px"><?= htmlspecialchars($periodLabel, ENT_QUOTES, 'UTF-8') ?></p>
  <p style="font-size:18px;margin:0 0 28px 0">
    <span style="color:#198754">&#10003; <?= (int) $totalPosted ?> published</span>
    &nbsp;&nbsp;&nbsp;
    <span style="color:<?= $totalFailed > 0 ? '#dc3545' : '#6c757d' ?>">&#10007; <?= (int) $totalFailed ?> failed</span>
  </p>

  <?php foreach ($accounts as $a): ?>
  <?php
      $platformLabel = ucfirst((string) ($a['platform'] ?? ''));
      $hasFailed     = !empty($a['failures']);
  ?>
  <div style="margin-bottom:16px;border:1px solid #dee2e6;border-radius:4px;overflow:hidden">

    <div style="padding:10px 14px;background:#f8f9fa;border-bottom:1px solid #dee2e6">
      <strong><?= htmlspecialchars((string) ($a['account_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
      <span style="color:#888888;font-size:13px;margin-left:6px">(<?= htmlspecialchars($platformLabel, ENT_QUOTES, 'UTF-8') ?>)</span>
    </div>

    <div style="padding:10px 14px;font-size:13px;color:#555555">
      <span style="margin-right:16px"><strong>Recycled:</strong> <?= (int) ($a['recycled_count'] ?? 0) ?></span>
      <span style="margin-right:16px"><strong>Pending:</strong> <?= (int) ($a['pending_count'] ?? 0) ?></span>
      <span style="margin-right:16px;color:#198754"><strong>Published:</strong> <?= (int) ($a['period_posted'] ?? 0) ?></span>
      <span style="color:<?= (int) ($a['period_failed'] ?? 0) > 0 ? '#dc3545' : '#555555' ?>"><strong>Failed:</strong> <?= (int) ($a['period_failed'] ?? 0) ?></span>
    </div>

    <?php if ($hasFailed): ?>
    <div style="border-top:1px solid #f5c6cb">
      <?php foreach ($a['failures'] as $f): ?>
      <?php
          $fBody = (string) ($f['body_snapshot'] ?? '');
          if (mb_strlen($fBody) > 200) {
              $fBody = mb_substr($fBody, 0, 200) . '…';
          }
      ?>
      <div style="padding:10px 14px;background:#fff5f5;border-top:1px solid #f5c6cb">
        <p style="margin:0 0 4px 0;color:#dc3545;font-size:13px"><?= htmlspecialchars((string) ($f['error_message'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
        <p style="margin:0;color:#555555;font-size:12px;font-style:italic"><?= htmlspecialchars($fBody, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
  <?php endforeach; ?>

  <hr style="border:none;border-top:1px solid #dee2e6;margin:24px 0 16px 0">

  <p style="color:#888888;font-size:12px;margin:0">
    SocialTurn &mdash;
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>?controller=queue&amp;action=index"
       style="color:#888888">View queue</a>
  </p>

</body>
</html>
