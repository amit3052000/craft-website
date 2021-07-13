<?php
namespace verbb\formie\helpers;

use Craft;
use craft\helpers\UrlHelper as CraftUrlHelper;
use craft\helpers\StringHelper;

use Throwable;

class UrlHelper
{
    // Public Methods
    // =========================================================================

    public static function siteActionUrl(string $path = '', $params = null, string $protocol = null): string
    {
        // Force `addTrailingSlashesToUrls` to `false` while we generate the redirectUri
        $addTrailingSlashesToUrls = Craft::$app->getConfig()->getGeneral()->addTrailingSlashesToUrls;
        Craft::$app->getConfig()->getGeneral()->addTrailingSlashesToUrls = false;

        $redirectUri = CraftUrlHelper::actionUrl($path, $params, $protocol);

        // Set `addTrailingSlashesToUrls` back to its original value
        Craft::$app->getConfig()->getGeneral()->addTrailingSlashesToUrls = $addTrailingSlashesToUrls;

        // We don't want the CP trigger showing in the action URL.
        $redirectUri = str_replace(Craft::$app->getConfig()->getGeneral()->cpTrigger . '/', '', $redirectUri);
        $redirectUri = str_replace('/index.php', '', $redirectUri);

        return $redirectUri;
    }
}
