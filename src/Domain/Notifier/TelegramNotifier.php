<?php

declare(strict_types=1);

namespace Sphpd\Domain\Notifier;

/**
 * Sends Markdown notifications via the Telegram Bot API.
 *
 * The HTTP transport is injected so the class is fully testable without
 * hitting the network.  In production the default transport uses curl.
 *
 * PHP 7.4 compatible.
 */
class TelegramNotifier
{
    private string $botToken;
    private string $chatId;
    private ?string $threadId;
    private string $logsPath;

    /** @var callable(string $url, array<string,mixed> $payload): array{code:int, body:string, error:string} */
    private $transport;

    /**
     * @param callable|null $transport  Override for testing.
     *                                  Signature: (string $url, array $payload): array{code, body, error}
     */
    public function __construct(
        string $botToken,
        string $chatId,
        ?string $threadId,
        string $logsPath,
        ?callable $transport = null
    ) {
        $this->botToken  = $botToken;
        $this->chatId    = $chatId;
        $this->threadId  = $threadId;
        $this->logsPath  = rtrim($logsPath, '/');
        $this->transport = $transport ?? [$this, 'curlPost'];
    }

    /**
     * Send a MarkdownV2 message.
     * Returns true on success, false on failure (failure is logged to file).
     */
    public function send(string $text): bool
    {
        if (empty($this->botToken)) {
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->botToken}/sendMessage";

        $payload = [
            'chat_id'    => $this->chatId,
            'parse_mode' => 'MarkdownV2',
            'text'       => $text,
        ];

        if ($this->threadId !== null) {
            $payload['message_thread_id'] = $this->threadId;
        }

        $result = ($this->transport)($url, $payload);

        if ($result['code'] !== 200 || $result['body'] === false) {
            $entry = sprintf(
                "[%s] HTTP Code: %s | Error: %s | Response: %s | Params: %s\n",
                date('Y-m-d H:i:s'),
                $result['code'],
                $result['error'],
                $result['body'],
                json_encode($payload)
            );
            file_put_contents($this->logsPath.'/telegram_errors.log', $entry, FILE_APPEND);

            return false;
        }

        return true;
    }

    // ── Report builder ────────────────────────────────────────────────────────

    public function buildReport(
        string $appName,
        bool $success,
        float $duration,
        string $logUrl,
        string $failedTask = '',
        string $errorOutput = ''
    ): string {
        $emoji = $success ? '✅' : '❌';

        if (!empty($failedTask)) {
            $failedTask = $this->escapeMarkdown($failedTask);
        }

        if (!empty($errorOutput)) {
            $errorOutput = "\n*Error Output:*\n```\n".$this->escapeMarkdown($errorOutput)."\n```";
        }

        $status   = $success ? 'SUCCESS' : "FAILED at $failedTask";
        $duration = str_replace('.', '\.', (string) $duration);

        return <<<MARKDOWN
*{$emoji} SPHPD:* `{$appName}`

*Status:* _{$status}_
*Duration:* _{$duration}s_
*Log:* [View Details]({$logUrl})
{$errorOutput}
MARKDOWN;
    }

    /**
     * Escape special characters for MarkdownV2.
     */
    public function escapeMarkdown(string $str): string
    {
        $special = ['\\', '_', '*', '[', ']', '(', ')', '~', '`', '>', '#', '+', '-', '=', '|', '{', '}', '.', '!'];
        foreach ($special as $char) {
            $str = str_replace($char, '\\'.$char, $str);
        }

        return $str;
    }

    // ── Default curl transport ────────────────────────────────────────────────

    /** @param array<string, mixed> $payload */
    private function curlPost(string $url, array $payload): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $body     = curl_exec($ch);
        $error    = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $httpCode, 'body' => $body, 'error' => $error];
    }
}
