<?php

namespace ghoststreet\craftincrementalstaticregeneration;

use ghoststreet\craftincrementalstaticregeneration\models\Settings;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\helpers\App;
use craft\helpers\Queue;
use craft\models\Section;

use yii\base\Event;
use ghoststreet\craftincrementalstaticregeneration\jobs\SendRequestJob;

/**
 * IncrementalStaticRegeneration plugin
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    /**
     * URLs entries had before the save in progress, keyed by "{entryId}-{siteId}"
     *
     * @var array<string, string|null>
     */
    private static array $previousUrls = [];

    public static function config(): array
    {
        return [
            'components' => [
                // Define component configs here...
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function () {
            $updatesService = Craft::$app->getUpdates();

            if (!$updatesService->isUpdatePending) {
                $settings = Plugin::getInstance()->getSettings();

                if ($settings->getIsEnabled()) {
                    $this->attachEventHandlers();
                }
            }
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('_incremental-static-regeneration/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function attachEventHandlers(): void
    {
        $settings = $this->getSettings();

        // capture the URL before it changes (e.g. a slug edit) so the old path can be revalidated too
        Event::on(Entry::class, Entry::EVENT_BEFORE_SAVE, static function (ModelEvent $event) {
            $entry = $event->sender;

            if (!$entry->id || !self::isCanonicalSave($entry)) {
                return;
            }

            $savedEntry = Entry::find()->id($entry->id)->siteId($entry->siteId)->status(null)->one();
            self::$previousUrls[self::previousUrlKey($entry)] = $savedEntry?->url;
        });

        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, static function (ModelEvent $event) use ($settings) {
            $entry = $event->sender;

            $key = self::previousUrlKey($entry);
            $previousUrl = self::$previousUrls[$key] ?? null;
            unset(self::$previousUrls[$key]);

            if (!self::entryShouldSendISRRequest($entry, $settings)) {
                return;
            }

            Queue::push(new SendRequestJob([
                "entryId" => $entry->id,
                "siteId" => $entry->siteId,
                "sectionHandle" => $entry->getSection()?->handle,
                "previousUrl" => $previousUrl !== $entry->url ? $previousUrl : null,
            ]));
        });

        Event::on(Entry::class, Entry::EVENT_AFTER_DELETE, static function (Event $event) use ($settings) {
            $entry = $event->sender;

            if (!self::entryShouldSendISRRequest($entry, $settings)) {
                return;
            }

            // deleted entries can't be re-queried by the job, so capture the URL now
            Queue::push(new SendRequestJob([
                "entryId" => $entry->id,
                "siteId" => $entry->siteId,
                "sectionHandle" => $entry->getSection()?->handle,
                "url" => $entry->url,
                "deleted" => true
            ]));
        });
    }

    private static function entryShouldSendISRRequest(Entry $entry, Settings $settings): bool
    {
        // drafts, revisions, propagation to other sites and bulk resaves must never revalidate or redeploy
        if (!self::isCanonicalSave($entry)) {
            return false;
        }

        // nested entries (e.g. Matrix block entries) have no section, only classic sections do
        $section = $entry->getSection();
        $isRouteless = $settings->isRoutelessSection($section?->handle);

        // entries with a page of their own revalidate it
        if ($entry->url && !$isRouteless) {
            return true;
        }

        // singles without URL get to redeploy
        if (!$entry->url && !$isRouteless && $section?->type === Section::TYPE_SINGLE) {
            return true;
        }

        // any other entry only revalidates the pages configured for its section, so with none there is nothing to do
        return $section !== null && $settings->getRevalidatePathsForSection($section->handle) !== [];
    }

    private static function isCanonicalSave(Entry $entry): bool
    {
        return !$entry->getIsDraft()
            && !$entry->getIsRevision()
            && !$entry->propagating
            && !$entry->resaving;
    }

    private static function previousUrlKey(Entry $entry): string
    {
        return "{$entry->id}-{$entry->siteId}";
    }
}
