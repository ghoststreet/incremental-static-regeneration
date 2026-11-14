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
    public null|string $revalidatePaths = null;
    public null|string $routelessSections = null;

    public function defineRules(): array
    {
        return [
            [['isEnabled', 'siteToReplace', 'targetSite', 'isrBypassToken', 'deployHook', 'revalidatePaths', 'routelessSections'], 'string'],
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

    /**
     * Get the extra paths to revalidate when an entry in the given section changes.
     *
     * Each line of the setting is "sectionHandle: /path, /other-path", and "*" matches every section.
     *
     * @return string[]
     */
    public function getRevalidatePathsForSection(?string $sectionHandle): array
    {
        $paths = [];
        $lines = preg_split('/\R/', (string) $this->revalidatePaths, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$handle, $pathList] = array_map('trim', explode(':', $line, 2));

            if ($handle !== '*' && $handle !== $sectionHandle) {
                continue;
            }

            foreach (explode(',', $pathList) as $path) {
                $path = trim($path);
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Get the handles of sections whose entries have a URL in Craft but no page on the front end.
     *
     * The setting is a comma or newline separated list of section handles. Entries in these
     * sections never revalidate their own URL, only the paths configured for their section.
     *
     * @return string[]
     */
    public function getRoutelessSections(): array
    {
        $handles = preg_split('/[\s,]+/', (string) $this->routelessSections, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($handles));
    }

    /**
     * Whether entries in the given section have no page of their own on the front end.
     */
    public function isRoutelessSection(?string $sectionHandle): bool
    {
        return $sectionHandle !== null && in_array($sectionHandle, $this->getRoutelessSections(), true);
    }
}
