# IncrementalStaticRegeneration



## Requirements

This plugin requires Craft CMS 5.9.0 or later, and PHP 8.2 or later.

## What happens when an entry changes

When an entry is saved or deleted, the plugin queues a `SendRequestJob`. Drafts, revisions, propagation to other sites and bulk resaves never queue anything.

| Entry | What the job does |
| --- | --- |
| Has a URL | Revalidates its URL (and its previous URL after a slug change), plus the extra paths configured for its section |
| Single without a URL | Triggers a Vercel redeploy through the deploy hook, debounced so a burst of saves is one deploy |
| Any other entry without a URL | Revalidates only the extra paths configured for its section. No job is queued when the section has none, and it never redeploys |
| In a routeless section | Revalidates only the extra paths configured for its section, never its own URL. No job is queued when the section has none, and it never redeploys |
| Nested entry (e.g. a Matrix block) without a URL | Nothing |

Revalidation sends a `HEAD` request with the ISR bypass token to each URL, after swapping the Craft site URL for the front end URL. A failed request is logged and the job is retried (3 attempts in total).

## Settings

All settings can be set in the control panel or in `config/_incremental-static-regeneration.php`.

| Setting | Description |
| --- | --- |
| `isEnabled` | Turns the plugin on. Accepts an environment variable |
| `siteToReplace` | The Craft site URL to swap out of entry URLs |
| `targetSite` | The front end URL to swap in |
| `isrBypassToken` | The ISR bypass token configured on the front end |
| `deployHook` | The Vercel deploy hook used for Singles without a URL |
| `revalidatePaths` | Extra pages to revalidate per section, see below |
| `routelessSections` | Sections whose entries have a URL in Craft but no page on the front end, see below |

### Extra paths to revalidate (`revalidatePaths`)

Use this for pages that list or embed entries from another section, for example a media page that shows videos. One section per line, as `sectionHandle: /path, /other-path`. Paths are relative to the base URL of the site the entry was saved in, so `/media` on a site with base URL `https://example.com/en/` revalidates `https://example.com/en/media`. A leading slash is optional and `/` is the site home page.

`*` matches every section. Be careful with it: every save of an entry without a URL (other than in a Single) then queues a job.

```
videos: /media, /support
faqs: /support
*: /sitemap.xml
```

### Routeless sections (`routelessSections`)

A comma or newline separated list of section handles, e.g. `offices`. Entries in these sections have URLs in Craft, but the front end has no route for them, so revalidating them would 404, log an error and retry. For these sections the entry's own URL and previous URL are never revalidated, only the extra paths configured for the section in `revalidatePaths`. A routeless section never triggers a redeploy, even if it is a Single.

### Example config file

```php
<?php

use craft\helpers\App;

return [
    'isEnabled' => App::env('ENABLE_ISR_REVALIDATION') ?? false,
    'isrBypassToken' => App::env('ISR_BYPASS_TOKEN'),
    'siteToReplace' => App::env('PRIMARY_SITE_URL'),
    'targetSite' => App::env('PREVIEW_SITE_URL'),
    'deployHook' => App::env('VERCEL_DEPLOY_HOOK_URL'),
    'revalidatePaths' => implode("\n", [
        'videos: /media',
        'offices: /about-us',
    ]),
    'routelessSections' => 'offices',
];
```

## Limitations

- Paths are revalidated only in the site the entry was saved in. When a save changes what other sites show (for example content that propagates to every site, or a page in every site that lists entries from one site), those other sites are not revalidated.
- Pages that show an entry through a relation (for example a product page with a block that lists selected kits) are not revalidated unless their path is configured for the section.
