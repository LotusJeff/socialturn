<?php
declare(strict_types=1);

namespace SocialTurn\Services;

require_once ROOT . DS . 'libraries' . DS . 'postmark.class.php';

/**
 * Sends transactional notification emails via Postmark.
 *
 * Silently does nothing when Postmark is unconfigured or no recipient
 * is available — callers wrap every call in try/catch so email failures
 * never reach cron output or web responses.
 *
 * Recipient resolution: NOTIFY_RECIPIENT_EMAIL if non-empty, else OWNER_EMAIL.
 * Both constants are loaded at bootstrap from admin_settings.
 */
class NotificationService
{
    private string $recipient;

    public function __construct()
    {
        $notifyEmail     = defined('NOTIFY_RECIPIENT_EMAIL') ? (string) NOTIFY_RECIPIENT_EMAIL : '';
        $ownerEmail      = defined('OWNER_EMAIL')            ? (string) OWNER_EMAIL            : '';
        $this->recipient = $notifyEmail !== '' ? $notifyEmail : $ownerEmail;
    }

    /**
     * Sends an immediate alert when a post fails to publish.
     * Silently returns if Postmark is unconfigured or recipient is empty.
     */
    public function sendFailureAlert(
        string $accountName,
        string $platform,
        string $errorMessage,
        string $bodySnapshot,
        string $postedAt
    ): void {
        if (!$this->isConfigured()) {
            return;
        }

        $platformLabel = ucfirst($platform);
        $bodyPreview   = mb_strlen($bodySnapshot) > 280
                         ? mb_substr($bodySnapshot, 0, 280) . '…'
                         : $bodySnapshot;

        $vars = [
            'accountName'  => $accountName,
            'platform'     => $platformLabel,
            'errorMessage' => $errorMessage,
            'bodySnapshot' => $bodyPreview,
            'postedAt'     => $postedAt,
            'baseUrl'      => defined('BASE_URL') ? (string) BASE_URL : '',
        ];

        $html  = $this->render('post_failure', $vars);
        $plain = "Post failed on {$platformLabel} for account: {$accountName}\n"
               . "Time: {$postedAt} UTC\n"
               . "Error: {$errorMessage}\n"
               . "Post: {$bodyPreview}";

        \Mail_Postmark::compose()
            ->to($this->recipient)
            ->subject("[SocialTurn] Post failed \u{2014} {$accountName} ({$platformLabel})")
            ->messageHtml($html)
            ->messagePlain($plain)
            ->tag('post-failure')
            ->send();
    }

    /**
     * Sends a periodic activity recap email.
     * Silently returns if Postmark is unconfigured or recipient is empty.
     *
     * @param array<int,array{platform:string,account_name:string,body_snapshot:string,error_message:string}> $failures
     */
    public function sendRecapEmail(
        string $frequency,
        string $periodLabel,
        int    $succeeded,
        int    $failed,
        array  $failures
    ): void {
        if (!$this->isConfigured()) {
            return;
        }

        $label = ucfirst($frequency);
        $vars  = [
            'label'       => $label,
            'periodLabel' => $periodLabel,
            'succeeded'   => $succeeded,
            'failed'      => $failed,
            'failures'    => $failures,
            'baseUrl'     => defined('BASE_URL') ? (string) BASE_URL : '',
        ];

        $html  = $this->render('recap', $vars);
        $plain = "SocialTurn {$label} Recap\n"
               . "{$periodLabel}\n\n"
               . "{$succeeded} posts published · {$failed} failed\n";
        if (!empty($failures)) {
            $plain .= "\nFailures:\n";
            foreach ($failures as $f) {
                $plain .= '- ' . ($f['account_name'] ?? '') . ' (' . ucfirst((string) ($f['platform'] ?? '')) . '): '
                        . ($f['error_message'] ?? '') . "\n";
            }
        }

        \Mail_Postmark::compose()
            ->to($this->recipient)
            ->subject("[SocialTurn] {$label} recap \u{2014} {$succeeded} sent, {$failed} failed")
            ->messageHtml($html)
            ->messagePlain($plain)
            ->tag('recap')
            ->send();
    }

    private function isConfigured(): bool
    {
        if ($this->recipient === '') {
            return false;
        }
        $apiKey = defined('POSTMARKAPP_API_KEY') ? (string) POSTMARKAPP_API_KEY : '';
        return $apiKey !== '';
    }

    private function render(string $template, array $vars): string
    {
        $path = __DIR__ . '/../../views/emails/' . $template . '.php';
        if (!file_exists($path)) {
            return '';
        }
        extract($vars);
        ob_start();
        include $path;
        return (string) ob_get_clean();
    }
}
