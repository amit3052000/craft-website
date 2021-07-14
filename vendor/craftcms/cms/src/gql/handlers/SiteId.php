<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\gql\handlers;

use craft\gql\base\ArgumentHandler;

/**
 * Class SiteId
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.6.18
 */
class SiteId extends Site
{
    protected $argumentName = 'siteId';
}
