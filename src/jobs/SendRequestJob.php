<?php

namespace ghoststreet\craftincrementalstaticregeneration\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\BaseJob;
use craft\helpers\App;

class SendRequestJob extends BaseJob
{

    public Entry $entry;
    public string $action;

    public function __construct(int $entryId)
    {
        parent::__construct();
        $this->entry = Entry::find()->id($entryId)->one();
        if ($this->entry) {
            $this->action = $this->entry->dateDeleted ? 'Entry Delete' : 'Entry Save';
        }
    }

    public function execute($queue): void
    {


        // setup curl
        $curlHandle = curl_init($this->entry->url);
        curl_setopt($curlHandle, CURLOPT_HEADER, 0);
        curl_setopt($curlHandle, CURLOPT_TIMEOUT, 30);
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);

        $isrBypassToken = App::env('ISR_BYPASS_TOKEN');
        $headers = array("x-prerender-revalidate: {$isrBypassToken}");

        if (!$this->entry->dateDeleted) {
            array_push($headers, 'Cache-control: no-cache');
        } else {
            curl_setopt($curlHandle, CURLOPT_NOBODY, true);
            curl_setopt($curlHandle, CURLOPT_CUSTOMREQUEST, 'HEAD');
        }

        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, $headers);
        curl_exec($curlHandle);

        $httpCode = (int) curl_getinfo($curlHandle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curlHandle);


        if ($curlError || $httpCode < 200 || $httpCode >= 300) {
            Craft::error("Revalidation {$this->action} failed for entry ID: {$this->entry->id} URL:  {$this->entry->url} HTTP: {$httpCode} Error: {$curlError}", 'incremental-static-regeneration');
        } else {
            Craft::info("Successful Revalidation for entry ID {$this->entry->id} entry URL {$this->entry->url}", 'incremental-static-regeneration');
        }
    }

    protected function defaultDescription(): string
    {
        if (!$this->entry) {
            return "incremental-static-regeneration";
        }

        return "busting ISR cache {$this->entry->id}";
    }
}