<?php

namespace ghoststreet\craftincrementalstaticregeneration\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;

/**
 * IncrementalStaticRegeneration settings
 */
class Settings extends Model
{

    public string $isEnabled = "false";
    public null|string $siteToReplace = null;
    public null|string $targetSite = null;
    public null|string $isrBypassToken = null;
    public null|string $deployHook = null;

    public function defineRules(): array
    {
        return [
            [['isEnabled', 'siteToReplace', 'targetSite', 'isrBypassToken', 'deployHook'], 'string'],
        ];
    }

    /**
     * Get the isEanbled variable, resolving any environment variables
     */
    public function getIsEnabled(): bool
    {
        return App::parseBooleanEnv($this->isEnabled) ?? false;
    }

    /**
     * Get the siteToReplace, resolving any environment variable reference.
     */
    public function getSiteToReplace(): ?string
    {
        return App::parseEnv($this->siteToReplace) ?? null;
    }


    /**
     * Get the targetSite, resolving any environment variable reference.
     */
    public function getTargetSite(): ?string
    {
        return App::parseEnv($this->targetSite) ?? null;
    }

    /**
     * Get the ISR Bypass Token, resolving any environment variable reference.
     */
    public function getIsrBypassToken(): ?string
    {
        return App::parseEnv($this->isrBypassToken);
    }

    /**
     * Get the Deploy Hook, resolving any environment variable reference.
     */
    public function getDeployHook(): ?string
    {
        return App::parseEnv($this->deployHook);
    }
}
