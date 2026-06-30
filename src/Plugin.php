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
        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, static function (ModelEvent $event) {
            $entry = $event->sender;
            $entryId = $entry->id;
            $siteId = $entry->siteId;


            Craft::error("{$entryId}. {$siteId}", 'incremental-static-regeneration');

            if (!self::entryShouldSendISRRequest($entry)) {
                return;
            }

            Queue::push(new SendRequestJob([
                "entryId" => $entryId,
                "siteId" => $siteId
            ]));
        });

        Event::on(Entry::class, Entry::EVENT_AFTER_DELETE, static function (Event $event) {
            $entry = $event->sender;
            $entryId = $entry->id;
            $siteId = $entry->siteId;

            if (!self::entryShouldSendISRRequest($entry)) {
                return;
            }

            Queue::push(new SendRequestJob([
                "entryId" => $entryId,
                "siteId" => $siteId
            ]));
        });
    }

    private static function entryShouldSendISRRequest(Entry $entry):bool {
        return !$entry->getIsDraft()
            && !$entry->getIsRevision()
            && !$entry->propagating;
    }
}
