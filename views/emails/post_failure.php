<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Post Failed &mdash; SocialTurn</title>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;color:#333333;max-width:600px;margin:0 auto;padding:24px 20px">

  <h2 style="margin-top:0;color:#dc3545">Post Failed &mdash; SocialTurn</h2>

  <table style="border-collapse:collapse;width:100%;margin-bottom:24px">
    <tr>
      <td style="padding:6px 16px 6px 0;font-weight:bold;white-space:nowrap;vertical-align:top;width:120px">Workspace</td>
      <td style="padding:6px 0;vertical-align:top"><?= htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <tr>
      <td style="padding:6px 16px 6px 0;font-weight:bold;white-space:nowrap;vertical-align:top">Platform</td>
      <td style="padding:6px 0;vertical-align:top"><?= htmlspecialchars($platform, ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <tr>
      <td style="padding:6px 16px 6px 0;font-weight:bold;white-space:nowrap;vertical-align:top">Time</td>
      <td style="padding:6px 0;vertical-align:top"><?= htmlspecialchars($postedAt, ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
    <tr>
      <td style="padding:6px 16px 6px 0;font-weight:bold;white-space:nowrap;vertical-align:top">Error</td>
      <td style="padding:6px 0;vertical-align:top;color:#dc3545"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
  </table>

  <p style="font-weight:bold;margin:0 0 8px 0">Post body (preview)</p>
  <blockquote style="margin:0 0 24px 0;padding:12px 16px;background:#f8f9fa;border-left:4px solid #dee2e6;color:#555555;font-style:italic;font-size:14px">
    <?= htmlspecialchars($bodySnapshot, ENT_QUOTES, 'UTF-8') ?>
  </blockquote>

  <hr style="border:none;border-top:1px solid #dee2e6;margin:0 0 16px 0">

  <p style="color:#888888;font-size:12px;margin:0">
    SocialTurn &mdash;
    <a href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>?controller=queue&amp;action=errors"
       style="color:#888888">View queue error log</a>
  </p>

</body>
</html>
