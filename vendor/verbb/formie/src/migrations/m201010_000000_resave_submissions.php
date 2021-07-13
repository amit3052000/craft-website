<?php
namespace verbb\formie\migrations;

use verbb\formie\elements\Submission;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\MigrationHelper;
use craft\queue\jobs\ResaveElements;

class m201010_000000_resave_submissions extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        Craft::$app->getQueue()->push(new ResaveElements([
            'elementType' => Submission::class,
            'criteria' => [
                'siteId' => '*',
            ],
            'updateSearchIndex' => true,
        ]));

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        echo "m201010_000000_resave_submissions cannot be reverted.\n";
        return false;
    }
}