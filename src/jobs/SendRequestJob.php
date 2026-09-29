<?php

namespace ghoststreet\craftincrementalstaticregeneration\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use yii\base\Exception;
use yii\queue\RetryableJobInterface;

use ghoststreet\craftincrementalstaticregeneration\Plugin;

class SendRequestJob extends BaseJob implements RetryableJobInterface
{
    private const MAX_ATTEMPTS = 3;
    private const REQUEST_TIMEOUT = 30;

    public ?int $entryId = null;
    public ?int $siteId = null;
    public ?string $sectionHandle = null;
    // set for deleted entries, which can no longer be queried when the job runs
    public ?string $url = null;
    public bool $deleted = false;
    // the entry's URL before this save, when it changed (e.g. a slug edit)
    public ?string $previousUrl = null;

    public function __construct($config = [])
    {
        parent::__construct($config);
    }

    public function getTtr(): int
    {
        // every target can take up to the request timeout
        return self::REQUEST_TIMEOUT * 10;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }

    private function getRelatedEntry(): Entry|null
    {
        if ($this->entryId && $this->siteId) {
            // include disabled entries so disabling one still busts its cached page
            return Entry::find()->id($this->entryId)->siteId($this->siteId)->status(null)->one();
        }

        return null;
    }

    public function execute($queue): void
    {
        $settings = Plugin::getInstance()->getSettings();

        if ($this->deleted) {
            $currentUrl = $this->url;
            $isGone = true;
        } else {
            $targetEntry = $this->getRelatedEntry();

            if (!$targetEntry) {
                return;
            }

            $currentUrl = $targetEntry->url;
            $isGone = $targetEntry->getStatus() !== Entry::STATUS_LIVE;
        }

        if (!$currentUrl) {
            // redeploy app for entries without URL
            $this->redeploy($settings->getDeployHook());
            return;
        }

        // map of URL => whether a 404 is expected (the page no longer exists)
        $targets = [$currentUrl => $isGone];

        if ($this->previousUrl) {
            $targets[$this->previousUrl] = true;
        }

        $siteBaseUrl = Craft::$app->getSites()->getSiteById($this->siteId)?->getBaseUrl();

        if ($siteBaseUrl) {
            foreach ($settings->getRevalidatePathsForSection($this->sectionHandle) as $path) {
                $targets[rtrim($siteBaseUrl, '/') . '/' . ltrim($path, '/')] ??= false;
            }
        }

        $failures = [];

        foreach ($targets as $target => $allowNotFound) {
            $target = $this->xformURL($target, $settings->getSiteToReplace(), $settings->getTargetSite());
            $failure = $this->revalidate($target, $settings->getIsrBypassToken(), $allowNotFound);

            if ($failure) {
                $failures[] = $failure;
            }
        }

        if ($failures) {
            // throwing lets the queue retry the job instead of silently marking it done
            throw new Exception('Revalidation failed: ' . implode('; ', $failures));
        }
    }

    protected function defaultDescription(): string
    {
        $targetEntry = $this->getRelatedEntry();

        if (!$targetEntry || !$targetEntry->title) {
            return "busting ISR cache {$this->entryId}";
        }

        return "busting ISR cache {$targetEntry->title}";
    }

    /**
     * Swap the Craft site origin for the front end origin, only when it prefixes the URL
     */
    private function xformURL(string $url, ?string $search, ?string $replace): string
    {
        if (!$search || !$replace) {
            return $url;
        }

        $search = rtrim($search, '/');

        if (!str_starts_with($url, $search)) {
            return $url;
        }

        // make sure we matched a whole origin, not e.g. "example.com" inside "example.com.au"
        $rest = substr($url, strlen($search));
        if ($rest !== '' && !in_array($rest[0], ['/', '?', '#'], true)) {
            return $url;
        }

        return rtrim($replace, '/') . $rest;
    }

    /**
     * @return string|null a description of the failure, or null on success
     */
    private function revalidate(string $url, ?string $isrBypassToken, bool $allowNotFound): ?string
    {
        $curlHandle = curl_init($url);

        $headers = ["x-prerender-revalidate: {$isrBypassToken}", "Cache-control: no-cache"];

        curl_setopt_array($curlHandle, [
            CURLOPT_HEADER          => 0,
            CURLOPT_TIMEOUT         => self::REQUEST_TIMEOUT,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => 'HEAD',
            CURLOPT_NOBODY          => true,
            CURLOPT_HTTPHEADER      => $headers
        ]);
        curl_exec($curlHandle);

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curlHandle);

        if (!$curlError && $allowNotFound && $httpCode === 404) {
            Craft::info("Revalidated removed page for entry ID {$this->entryId} target {$url}", 'incremental-static-regeneration');
            return null;
        }

        return $this->result($httpCode, $curlError, $url);
    }

    private function redeploy(?string $deployHook): void
    {
        if (!$deployHook) {
            Craft::warning("No deploy hook configured, skipping redeploy for entry ID: {$this->entryId}", 'incremental-static-regeneration');
            return;
        }

        $deployHook .= '?buildCache=false';
        $curlHandle = curl_init($deployHook);
        curl_setopt_array($curlHandle, [
            CURLOPT_POST            => 1,
            CURLOPT_TIMEOUT         => self::REQUEST_TIMEOUT,
            CURLOPT_RETURNTRANSFER  => true,
        ]);
        curl_exec($curlHandle);

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curlHandle);
        $failure = $this->result($httpCode, $curlError, 'deploy hook');

        if ($failure) {
            throw new Exception("Redeploy failed: {$failure}");
        }
    }

    /**
     * @return string|null a description of the failure, or null on success
     */
    private function result(int $httpCode, string $curlError, string $target): ?string
    {
        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            // entry is gone if it was deleted, so the CP URL is best-effort
            $cpEditUrl = $this->getRelatedEntry()?->cpEditUrl;
            Craft::error("Revalidation failed for entry ID: {$this->entryId} CP URL: {$cpEditUrl} Target: {$target} HTTP: {$httpCode} Error: {$curlError}", 'incremental-static-regeneration');
            return "{$target} (HTTP {$httpCode}" . ($curlError ? ", {$curlError}" : '') . ')';
        }

        Craft::info("Successful Revalidation for entry ID {$this->entryId} target {$target}", 'incremental-static-regeneration');
        return null;
    }
}
