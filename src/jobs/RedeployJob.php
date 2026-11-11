<?php

namespace ghoststreet\craftincrementalstaticregeneration\jobs;

use Craft;
use craft\helpers\Queue;
use craft\queue\BaseJob;
use yii\base\Exception;
use yii\queue\RetryableJobInterface;

use ghoststreet\craftincrementalstaticregeneration\Plugin;

/**
 * Triggers the Vercel deploy hook, collapsing bursts of saves into a single deploy.
 *
 * An editor often saves a Single several times in a row (e.g. "Save and continue editing" while
 * translating), and every save used to POST the deploy hook, starting a production build each time.
 * Now the first save schedules one delayed job and every save that lands before it runs is dropped.
 * The build reads Craft when it runs, so it picks up all of those saves.
 */
class RedeployJob extends BaseJob implements RetryableJobInterface
{
    private const LOCK_KEY = 'isr-redeploy-pending';
    private const DEBOUNCE_SECONDS = 120;
    // Safety net so a lost job can't block redeploys for good
    private const LOCK_TTL_SECONDS = self::DEBOUNCE_SECONDS + 300;
    private const MAX_ATTEMPTS = 3;
    private const REQUEST_TIMEOUT = 15;

    public static function schedule(): void
    {
        // add() only succeeds when the key doesn't exist yet, so only the first save in a window queues a job
        if (!Craft::$app->getCache()->add(self::LOCK_KEY, 1, self::LOCK_TTL_SECONDS)) {
            return;
        }

        Queue::push(new self(), null, self::DEBOUNCE_SECONDS);
    }

    public function getTtr(): int
    {
        return self::REQUEST_TIMEOUT * 4;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }

    public function execute($queue): void
    {
        // Release the lock first: a save that lands after this point needs its own deploy
        Craft::$app->getCache()->delete(self::LOCK_KEY);

        $deployHook = Plugin::getInstance()->getSettings()->getDeployHook();

        if (!$deployHook) {
            Craft::warning('No deploy hook configured, skipping redeploy', 'incremental-static-regeneration');
            return;
        }

        $deployHook .= (str_contains($deployHook, '?') ? '&' : '?') . 'buildCache=false';

        $curlHandle = curl_init($deployHook);
        curl_setopt_array($curlHandle, [
            CURLOPT_POST            => true,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT  => 5,
            CURLOPT_TIMEOUT         => self::REQUEST_TIMEOUT,
        ]);
        curl_exec($curlHandle);

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curlHandle);
        curl_close($curlHandle);

        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            Craft::error("Redeploy failed. HTTP: {$httpCode} Error: {$curlError}", 'incremental-static-regeneration');
            // throwing lets the queue retry the job instead of silently marking it done
            throw new Exception("Redeploy failed: HTTP {$httpCode}" . ($curlError ? ", {$curlError}" : ''));
        }

        Craft::info("Vercel redeploy triggered. HTTP: {$httpCode}", 'incremental-static-regeneration');
    }

    protected function defaultDescription(): ?string
    {
        return 'Triggering Vercel redeploy';
    }
}
