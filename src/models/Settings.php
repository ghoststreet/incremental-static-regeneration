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

    public function defineRules(): array
    {
        return [
            [['isEnabled', 'siteToReplace', 'targetSite'], 'string'],
        ];
    }

    /**
     * Get the isEanbled variable, resolving any environment variables
     */
    public function getIsEnabled(): bool
    {
        return !!App::parseEnv($this->isEnabled);
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
}
