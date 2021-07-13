<?php
namespace verbb\formie\records;

use craft\db\ActiveRecord;
use craft\db\SoftDeleteTrait;

/**
 * Class Nested
 *
 * @property int $id
 * @property int $fieldId
 * @property int $fieldLayoutId
 *
 * @package Formie
 */
class Nested extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function tableName(): string
    {
        return '{{%formie_nested}}';
    }
}
