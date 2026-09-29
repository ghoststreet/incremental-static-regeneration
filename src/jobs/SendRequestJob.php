<?php

namespace ghoststreet\craftincrementalstaticregeneration\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use craft\helpers\App;

use ghoststreet\craftincrementalstaticregeneration\Plugin;

class SendRequestJob extends BaseJob
{
    public ?int $entryId = null;
    public ?int $siteId = null;
    // set for deleted entries, which can no longer be queried when the job runs
    public ?string $url = null;
    public bool $deleted = false;

    public function __construct($config = [])
    {
        parent::__construct($config);
    }

    private function getRelatedEntry(): Entry|null
    {
        if ($this->entryId && $this->siteId) {
            return Entry::find()->id($this->entryId)->siteId($this->siteId)->one();
        }

        return null;
    }

    public function execute($queue): void
    {
        if ($this->deleted) {
            $urlToHit = $this->url;
        } else {
            $targetEntry = $this->getRelatedEntry();

            if (!$targetEntry) {
                return;
            }

            $urlToHit = $targetEntry->url;
        }

        $settings = Plugin::getInstance()->getSettings();

        if (!$urlToHit) {
            // redeploy app for entries without URL
            $this->redeploy($settings->getDeployHook());
            return;
        }

        $urlToReplace = $settings->getSiteToReplace();
        $toReplaceWith = $settings->getTargetSite();

        // invalidate single URL otherwise
        $urlToHit = $this->xformURL($urlToHit, [$urlToReplace => $toReplaceWith]);

        // setup curl
        $curlHandle = curl_init($urlToHit);

        $isrBypassToken = $settings->getIsrBypassToken();
        $headers = ["x-prerender-revalidate: {$isrBypassToken}", "Cache-control: no-cache"];

        $curlOptions = [
            CURLOPT_HEADER          => 0,
            CURLOPT_TIMEOUT         => 30,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CUSTOMREQUEST   => 'HEAD',
            CURLOPT_NOBODY          => true,
            CURLOPT_HTTPHEADER      => $headers
        ];

        $curlHandle = $this->setupCurlOptions($curlHandle, $curlOptions);
        curl_exec($curlHandle);

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curlHandle);
        $this->result($httpCode, $curlError, $urlToHit);

        return;
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
     *
     */
    private function xformURL(string $url, array $replaceStrings): string
    {
        $newUrl = $url;
        foreach($replaceStrings as $search => $replace) {
            if ($search && $replace) {
                $newUrl = str_replace($search, $replace, $newUrl);
            }
        }
        return $newUrl;
    }

    private function setupCurlOptions(\CurlHandle $curlHandle, array $curlOptions): \CurlHandle
    {
        foreach($curlOptions as $key => $val) {
            curl_setopt($curlHandle, $key, $val);
        }

        return $curlHandle;
    }

    private function redeploy(?string $deployHook): void
    {
        if (!$deployHook) {
            Craft::warning("No deploy hook configured, skipping redeploy for entry ID: {$this->entryId}", 'incremental-static-regeneration');
            return;
        }

        $deployHook .= '?buildCache=false';
        $curlHandle = curl_init($deployHook);
        curl_setopt($curlHandle, CURLOPT_POST, 1);
        curl_exec($curlHandle);

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curlHandle);
        $this->result($httpCode, $curlError, 'deploy hook');
    }

    private function result(int $httpCode, string $curlError, string $target): void
    {
        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            // entry is gone if it was deleted, so the CP URL is best-effort
            $cpEditUrl = $this->getRelatedEntry()?->cpEditUrl;
            Craft::error("Revalidation failed for entry ID: {$this->entryId} CP URL: {$cpEditUrl} Target: {$target} HTTP: {$httpCode} Error: {$curlError}", 'incremental-static-regeneration');
            return;
        }

        Craft::info("Successful Revalidation for entry ID {$this->entryId} target {$target}", 'incremental-static-regeneration');
        return;
    }
}