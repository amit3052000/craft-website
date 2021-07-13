<?php
namespace verbb\formie\base;

use craft\base\ComponentInterface;
use craft\base\ElementInterface;

interface SubfieldInterface extends ComponentInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the subfields for the field.
     *
     * @return array
     */
    public function getFrontEndSubfields(): array;

    /**
     * Returns the subfield options (label and handle).
     *
     * @return array
     */
    public function getSubfieldOptions(): array;

    /**
     * Validates the required name sub-fields.
     *
     * @param ElementInterface $element
     */
    public function validateRequiredFields(ElementInterface $element);
}
