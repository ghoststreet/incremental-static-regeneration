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
    public Entry|null $entry = null;

    public function __construct()
    {
        parent::__construct();

        if ($this->entryId && $this->siteId) {
            $this->entry = Entry::find()->id($this->entryId)->siteId($this->siteId)->one();
        }
    }

    public function execute($queue): void
    {
        if (!$this->entry) {
            return;
        }

        $settings = Plugin::getInstance()->getSettings();
        $urlToReplace = $settings->getSiteToReplace();
        $toReplaceWith = $settings->getTargetSite();

        $urlToHit = $this->entry->url;

        if (!$urlToHit) {
            // redeploy app for entries without URL
            Craft::warning('redeploy', 'incremental-static-regeneration');
            $this->redeploy($settings->getDeployHook());
            return;
        }

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

        $this->setupCurlOptions($curlHandle, $curlOptions);
        curl_exec($curlHandle);

        Craft::warning('ISR invalidate', 'incremental-static-regeneration');
        Craft::warning($urlToHit, 'incremental-static-regeneration');
        Craft::warning(join('|||', $headers), 'incremental-static-regeneration');

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curlHandle);
        $this->result($httpCode, $curlError);
    }

    protected function defaultDescription(): string
    {
        if (!$this->entry) {
            return "incremental-static-regeneration";
        }

        if (!$this->entry->title) {
            return "busting ISR cache {$this->entry->id}";
        }

        return "busting ISR cache {$this->entry->title}";
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

    private function redeploy(string $deployHook): void
    {
        $deployHook .= '?buildCache=false';
        $curlHandle = curl_init($deployHook);
        curl_setopt($curlHandle, CURLOPT_POST, 1);
        curl_exec($curlHandle);

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curlHandle);
        $this->result($httpCode, $curlError);
    }

    private function result(int $httpCode, string $curlError): void
    {
        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            Craft::error("Revalidation failed for entry ID: {$this->entry->id} CP URL: {$this->entry->cpEditUrl} HTTP: {$httpCode} Error: {$curlError}", 'incremental-static-regeneration');
        } else {
            Craft::info("Successful Revalidation for entry ID {$this->entry->id} entry URL {$this->entry->url}", 'incremental-static-regeneration');
        }
    }
}