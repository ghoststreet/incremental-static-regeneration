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

        if (App::env('CRAFT_ENVIRONMENT') !== 'dev') {
            $this->attachEventHandlers();
        }


        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {

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
        Event::on(Entry::class, Entry::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            $entry = $event->sender;

            if (!self::entryShouldSendISRRequest($entry)) {
                return;
            }

            Queue::push(new SendRequestJob($entry->id));
        });

        Event::on(Entry::class, Entry::EVENT_AFTER_DELETE, function (Event $event) {
            $entry = $event->sender;

            if (!self::entryShouldSendISRRequest($entry)) {
                return;
            }

            Queue::push(new SendRequestJob($entry->id));
        });
    }

    private static function entryShouldSendISRRequest(Entry $entry):bool {
        return $entry->url
            && !$entry->getIsDraft()
            && !$entry->getIsRevision();
    }
}
