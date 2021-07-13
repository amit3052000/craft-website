<?php
namespace verbb\formie\fields\formfields;

use verbb\formie\Formie;
use verbb\formie\base\FormField;
use verbb\formie\base\SubfieldInterface;
use verbb\formie\base\SubfieldTrait;
use verbb\formie\helpers\SchemaHelper;

use Craft;
use craft\base\ElementInterface;
use craft\base\PreviewableFieldInterface;
use craft\gql\types\DateTime as DateTimeType;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;

use DateTime;
use yii\db\Schema;
use GraphQL\Type\Definition\Type;

class Date extends FormField implements SubfieldInterface, PreviewableFieldInterface
{
    // Traits
    // =========================================================================

    use SubfieldTrait;


    // Static Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function displayName(): string
    {
        return Craft::t('formie', 'Date/Time');
    }

    /**
     * @inheritDoc
     */
    public static function getSvgIconPath(): string
    {
        return 'formie/_formfields/date/icon.svg';
    }

    /**
     * @inheritDoc
     */
    public static function toDateTime($value)
    {
        // We should never deal with timezones
        return DateTimeHelper::toDateTime($value, false, false);
    }


    // Properties
    // =========================================================================

    public $dateFormat = 'Y-m-d';
    public $timeFormat = 'H:i';
    public $displayType = 'calendar';
    public $includeTime = false;
    public $timeLabel = '';
    public $defaultOption;
    public $dayLabel;
    public $dayPlaceholder;
    public $monthLabel;
    public $monthPlaceholder;
    public $yearLabel;
    public $yearPlaceholder;
    public $hourLabel;
    public $hourPlaceholder;
    public $minuteLabel;
    public $minutePlaceholder;
    public $secondLabel;
    public $secondPlaceholder;
    public $ampmLabel;
    public $ampmPlaceholder;
    public $useDatePicker = true;
    public $minDate;
    public $maxDate;


    // Public Methods
    // =========================================================================

    public function init()
    {
        parent::init();

        if ($this->defaultOption === 'date') {
            if ($this->defaultValue && !$this->defaultValue instanceof DateTime) {
                $defaultValue = self::toDateTime($this->defaultValue);

                if ($defaultValue) {
                    $this->defaultValue = $defaultValue;
                }
            }
        } elseif ($this->defaultOption === 'today') {
            $this->defaultValue = self::toDateTime(new DateTime());
        } else {
            $this->defaultValue = '';
        }
    }

    /**
     * @inheritDoc
     */
    public function getContentColumnType(): string
    {
        return Schema::TYPE_DATETIME;
    }

    /**
     * @inheritDoc
     */
    public function hasSubfields(): bool
    {
        if ($this->displayType !== 'calendar') {
            return true;
        }
        
        return false;
    }

    /**
     * @inheritDoc
     */
    public function normalizeValue($value, ElementInterface $element = null)
    {
        if (!$value || $value instanceof DateTime) {
            return $value;
        }

        if (is_string($value) && ($date = self::toDateTime($value)) !== false) {
            return $date;
        }

        if ($this->displayType !== 'calendar') {
            // Always use the full format to store dates, even if only dates enabled.
            // This helps to set all non-included items (like time for date-only) to zero.
            $format = $this->dateFormat . ' ' . $this->timeFormat;

            if (is_array($value)) {
                $value = array_filter($value);
            }

            if ($value) {
                $formatted = preg_replace_callback('/[A-Za-z]/', function($matches) use ($value) {
                    // Handle time or other omitted parts of date/time string. Set to zero.
                    $part = $value[$matches[0]] ?? '0';

                    return StringHelper::padLeft($part, 2, '0');
                }, $format);

                if (($date = DateTime::createFromFormat($format, $formatted)) !== false) {
                    // Always ensure we strip out the timezone
                    return self::toDateTime($date->format('Y-m-d H:i:s'));
                }
            }
        }

        if (($date = self::toDateTime($value)) !== false) {
            return $date;
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function getSearchKeywords($value, ElementInterface $element): string
    {
        return '';
    }

    /**
     * @inheritDoc
     */
    public function getFieldDefaults(): array
    {
        $settings = Formie::$plugin->getSettings();
        $displayType = $settings->defaultDateDisplayType ?? 'calendar';
        $defaultOption = $settings->defaultDateValue ?? '';
        $defaultValue = $settings->getDefaultDateTimeValue();

        return [
            'dateFormat' => 'Y-m-d',
            'timeFormat' => 'H:i',
            'displayType' => $displayType,
            'defaultValue' => $defaultValue,
            'defaultOption' => $defaultOption,
            'includeTime' => true,
            'dayLabel' => Craft::t('formie', 'Day'),
            'dayPlaceholder' => '',
            'monthLabel' => Craft::t('formie', 'Month'),
            'monthPlaceholder' => '',
            'yearLabel' => Craft::t('formie', 'Year'),
            'yearPlaceholder' => '',
            'hourLabel' => Craft::t('formie', 'Hour'),
            'hourPlaceholder' => '',
            'minuteLabel' => Craft::t('formie', 'Minute'),
            'minutePlaceholder' => '',
            'secondLabel' => Craft::t('formie', 'Second'),
            'secondPlaceholder' => '',
            'ampmLabel' => Craft::t('formie', 'AM/PM'),
            'ampmPlaceholder' => '',
            'useDatePicker' => true,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndSubfields(): array
    {
        $defaultValue = $this->defaultValue ? $this->defaultValue : new DateTime();
        $year = intval($defaultValue->format('Y'));
        $minYear = $year - 100;
        $maxYear = $year + 100;

        $yearOptions = [];

        for ($y = $minYear; $y < $maxYear; ++$y) {
            $yearOptions[] = ['value' => $y, 'label' => $y];
        }

        $available = [
            'Y' => [
                'handle' => 'year',
                'options' => $yearOptions,
                'min' => $minYear,
                'max' => $maxYear,
            ],
            'm' => [
                'handle' => 'month',
                'options' => $this->getMonths(),
                'min' => 1,
                'max' => 12,
            ],
            'd' => [
                'handle' => 'day',
                'min' => 1,
                'max' => 31,
            ],
            'H' => [
                'handle' => 'hour',
                'min' => 0,
                'max' => 23,
            ],
            'h' => [
                'handle' => 'hour',
                'min' => 1,
                'max' => 12,
            ],
            'i' => [
                'handle' => 'minute',
                'min' => 0,
                'max' => 59,
            ],
            's' => [
                'handle' => 'second',
                'min' => 0,
                'max' => 59,
            ],
            'A' => [
                'handle' => 'ampm',
                'options' => [
                    ['value' => 'AM', 'label' => 'AM'],
                    ['value' => 'PM', 'label' => 'PM'],
                ],
                'maxlength' => 2,
            ],
        ];

        $format = $this->dateFormat . ($this->includeTime ? $this->timeFormat : '');
        $format = preg_replace('/[.\-:\/ ]/', '', $format);

        $row = [];
        foreach (str_split($format) as $char) {
            $row[$char] = $available[$char];
        }

        return [
            $row,
        ];
    }

    /**
     * @inheritDoc
     */
    public function getSubfieldOptions(): array
    {
        return [
            [
                'label' => Craft::t('formie', 'Year'),
                'handle' => 'year',
            ],
            [
                'label' => Craft::t('formie', 'Month'),
                'handle' => 'month',
            ],
            [
                'label' => Craft::t('formie', 'Day'),
                'handle' => 'day',
            ],
            [
                'label' => Craft::t('formie', 'Hour'),
                'handle' => 'hour',
            ],
            [
                'label' => Craft::t('formie', 'Minute'),
                'handle' => 'minute',
            ],
            [
                'label' => Craft::t('formie', 'Second'),
                'handle' => 'second',
            ],
            [
                'label' => Craft::t('formie', 'AM/PM'),
                'handle' => 'ampm',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        // Keep to disable trait validation on subfields.
        return parent::getElementValidationRules();
    }

    /**
     * @inheritDoc
     */
    public function getIsTextInput(): bool
    {
        return $this->displayType === 'calendar';
    }

    /**
     * @inheritDoc
     */
    public function getIsFieldset(): bool
    {
        if ($this->displayType === 'calendar' && !$this->includeTime) {
            return false;
        }

        return true;
    }

    /**
     * @inheritDoc
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        return Craft::$app->getView()->renderTemplate('formie/_formfields/date/input', [
            'name' => $this->handle,
            'value' => $value,
            'field' => $this,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getPreviewInputHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('formie/_formfields/date/preview', [
            'field' => $this
        ]);
    }

    /**
     * Returns an array of month names.
     *
     * @return array
     */
    public function getMonths()
    {
        $months = [];

        foreach (Craft::$app->getLocale()->getMonthNames() as $index => $monthName) {
            $months[] = ['value' => $index + 1, 'label' => $monthName];
        }

        return $months;
    }

    /**
     * @inheritDoc
     */
    public function getDefaultDate()
    {
        // An alias for `defaultValue` for GQL, as `defaultValue` returns a date, not string
        return $this->defaultValue;
    }

    /**
     * @inheritdoc
     */
    public function getFrontEndJsModules()
    {
        if ($this->displayType === 'calendar' && $this->useDatePicker) {
            $locale = Craft::$app->locale->id;

            // Handle language variants
            if (preg_match('/^([a-z]{2})-/', $locale, $matches)) {
                $locale = $matches[1];
            }

            $supportedLocales = ['ar', 'at', 'az', 'be', 'bg', 'bn', 'cat', 'cs', 'cy', 'da', 'de', 'eo', 'es', 'et', 'fa', 'fi', 'fo', 'fr', 'gr', 'he', 'hi', 'hr', 'hu', 'id', 'is', 'it', 'ja', 'km', 'ko', 'kz', 'lt', 'lv', 'mk', 'mn', 'ms', 'my', 'nl', 'no', 'pa', 'pl', 'pt', 'ro', 'ru', 'si', 'sk', 'sl', 'sq', 'sr-cyr', 'sr', 'sv', 'th', 'tr', 'uk', 'vn', 'zh-tw', 'zh'];

            if (in_array(strtolower($locale), $supportedLocales, true)) {
                $locale = strtolower($locale);
            }

            $minDate = null;
            $maxDate = null;

            if ($minDate = DateTimeHelper::toDateTime($this->minDate)) {
                $minDate = $minDate->format($this->dateFormat);
            }

            if ($maxDate = DateTimeHelper::toDateTime($this->maxDate)) {
                $maxDate = $maxDate->format($this->dateFormat);
            }

            return [
                'src' => Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/frontend/dist/js/fields/date-picker.js', true),
                'module' => 'FormieDatePicker',
                'settings' => [
                    'dateFormat' => $this->includeTime ? $this->dateFormat . ' ' . $this->timeFormat : $this->dateFormat,
                    'includeTime' => $this->includeTime,
                    'locale' => $locale,
                    'minDate' => $minDate,
                    'maxDate' => $maxDate,
                ],
            ];
        }
    }

    /**
     * @inheritDoc
     */
    public function defineGeneralSchema(): array
    {
        $toggleBlocks = [];

        foreach ($this->getSubfieldOptions() as $key => $nestedField) {
            $subfields = [
                SchemaHelper::textField([
                    'label' => Craft::t('formie', 'Label'),
                    'help' => Craft::t('formie', 'The label that describes this field.'),
                    'name' => $nestedField['handle'] . 'Label',
                    'validation' => 'requiredIfNotEqual:displayType=calendar',
                    'required' => true,
                ]),
                SchemaHelper::textField([
                    'label' => Craft::t('formie', 'Placeholder'),
                    'help' => Craft::t('formie', 'The text that will be shown if the field doesn’t have a value.'),
                    'name' => $nestedField['handle'] . 'Placeholder',
                ]),
            ];

            $toggleBlocks[] = SchemaHelper::toggleBlock([
                'blockLabel' => $nestedField['label'],
                'blockHandle' => $nestedField['handle'],
                'showEnabled' => false,
            ], $subfields);
        }

        return [
            SchemaHelper::labelField(),
            SchemaHelper::lightswitchField([
                'label' => Craft::t('formie', 'Include Time'),
                'help' => Craft::t('formie', 'Whether this field should include the time.'),
                'name' => 'includeTime',
            ]),
            SchemaHelper::toggleContainer('settings.includeTime', [
                SchemaHelper::textField([
                    'label' => Craft::t('formie', 'Time Label'),
                    'help' => Craft::t('formie', 'The label shown for the time field.'),
                    'name' => 'timeLabel',
                ]),
            ]),
            SchemaHelper::selectField([
                'label' => Craft::t('formie', 'Default Value'),
                'help' => Craft::t('formie', 'Select a default value for this field.'),
                'name' => 'defaultOption',
                'options' => [
                    [ 'label' => Craft::t('formie', 'None'), 'value' => '' ],
                    [ 'label' => Craft::t('formie', 'Today‘s Date/Time'), 'value' => 'today' ],
                    [ 'label' => Craft::t('formie', 'Specific Date/Time'), 'value' => 'date' ],
                ],
            ]),
            SchemaHelper::toggleContainer('settings.defaultOption=date', [
                SchemaHelper::dateField([
                    'label' => Craft::t('formie', 'Default Date/Time'),
                    'help' => Craft::t('formie', 'Entering a default value will place the value in the field when it loads.'),
                    'name' => 'defaultValue',
                ]),
            ]),
            SchemaHelper::selectField([
                'label' => Craft::t('formie', 'Display Type'),
                'help' => Craft::t('formie', 'Set different display layouts for this field.'),
                'name' => 'displayType',
                'options' => [
                    [ 'label' => Craft::t('formie', 'Calendar'), 'value' => 'calendar' ],
                    [ 'label' => Craft::t('formie', 'Dropdowns'), 'value' => 'dropdowns' ],
                    [ 'label' => Craft::t('formie', 'Text Inputs'), 'value' => 'inputs' ],
                ],
            ]),
            SchemaHelper::toggleContainer('!settings.displayType=calendar', $toggleBlocks),
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineSettingsSchema(): array
    {
        return [
            SchemaHelper::lightswitchField([
                'label' => Craft::t('formie', 'Required Field'),
                'help' => Craft::t('formie', 'Whether this field should be required when filling out the form.'),
                'name' => 'required',
            ]),
            SchemaHelper::toggleContainer('settings.required', [
                SchemaHelper::textField([
                    'label' => Craft::t('formie', 'Error Message'),
                    'help' => Craft::t('formie', 'When validating the form, show this message if an error occurs. Leave empty to retain the default message.'),
                    'name' => 'errorMessage',
                ]),
            ]),
            SchemaHelper::prePopulate(),
            SchemaHelper::toggleContainer('settings.displayType=calendar', [
                SchemaHelper::dateField([
                    'label' => Craft::t('formie', 'Min Date'),
                    'help' => Craft::t('formie', 'Set a minimum date for dates to be picked from.'),
                    'name' => 'minDate',
                ]),
                SchemaHelper::dateField([
                    'label' => Craft::t('formie', 'Max Date'),
                    'help' => Craft::t('formie', 'Set a maximum date for dates to be picked up to.'),
                    'name' => 'maxDate',
                ]),
            ]),
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineAppearanceSchema(): array
    {
        return [
            SchemaHelper::visibility(),
            SchemaHelper::labelPosition($this),
            SchemaHelper::toggleContainer('!settings.displayType=calendar', [
                SchemaHelper::subfieldLabelPosition(),
            ]),
            SchemaHelper::selectField([
                'label' => Craft::t('formie', 'Date Format'),
                'help' => Craft::t('formie', 'Select what format to present dates as.'),
                'name' => 'dateFormat',
                'options' => [
                    [ 'label' => 'YYYY-MM-DD', 'value' => 'Y-m-d' ],
                    [ 'label' => 'MM-DD-YYYY', 'value' => 'm-d-Y' ],
                    [ 'label' => 'DD-MM-YYYY', 'value' => 'd-m-Y' ],
                    [ 'label' => 'YYYY/MM/DD', 'value' => 'Y/m/d' ],
                    [ 'label' => 'MM/DD/YYYY', 'value' => 'm/d/Y' ],
                    [ 'label' => 'DD/MM/YYYY', 'value' => 'd/m/Y' ],
                    [ 'label' => 'YYYY.MM.DD', 'value' => 'Y.m.d' ],
                    [ 'label' => 'MM.DD.YYYY', 'value' => 'm.d.Y' ],
                    [ 'label' => 'DD.MM.YYYY', 'value' => 'd.m.Y' ],
                ],
            ]),
            SchemaHelper::toggleContainer('settings.includeTime', [
                SchemaHelper::selectField([
                    'label' => Craft::t('formie', 'Time Format'),
                    'help' => Craft::t('formie', 'Select what format to present dates as.'),
                    'name' => 'timeFormat',
                    'options' => [
                        [ 'label' => '23:59:59 (HH:M:S)', 'value' => 'H:i:s' ],
                        [ 'label' => '03:59:59 PM (H:M:S AM/PM)', 'value' => 'H:i:s A' ],
                        [ 'label' => '23:59 (HH:M)', 'value' => 'H:i' ],
                        [ 'label' => '03:59 PM (H:M AM/PM)', 'value' => 'H:i A' ],
                        [ 'label' => '59:59 (M:S)', 'value' => 'i:s' ],
                    ],
                ]),
            ]),
            SchemaHelper::instructions(),
            SchemaHelper::instructionsPosition($this),
            SchemaHelper::toggleContainer('settings.displayType=calendar', [
                SchemaHelper::lightswitchField([
                    'label' => Craft::t('formie', 'Use Date Picker'),
                    'help' => Craft::t('formie', 'Whether this field should use the bundled cross-browser datepicker (Flatpickr.js) when rendering this field.'),
                    'name' => 'useDatePicker',
                ]),
            ]),
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineAdvancedSchema(): array
    {
        return [
            SchemaHelper::handleField(),
            SchemaHelper::cssClasses(),
            SchemaHelper::containerAttributesField(),
            SchemaHelper::toggleContainer('settings.displayType=calendar', [
                SchemaHelper::inputAttributesField(),
            ]),
        ];
    }

    /**
     * @inheritDoc
     */
    public function defineConditionsSchema(): array
    {
        return [
            SchemaHelper::enableConditionsField(),
            SchemaHelper::conditionsField(),
        ];
    }


    // Protected Methods
    // =========================================================================
   
    /**
     * @inheritDoc
     */
    protected function getSettingGqlType($attribute, $type, $fieldInfo)
    {
        // Disable normal `defaultValue` as it is a DateTime, not string. We can't have the same attributes 
        // return multiple types. Instead, return `defaultDate` as the attribute name and correct type.
        if ($attribute === 'defaultValue') {
            return [
                'name' => 'defaultDate',
                'type' => DateTimeType::getType(),
            ];
        }

        return parent::getSettingGqlType($attribute, $type, $fieldInfo);
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlType()
    {
        return DateTimeType::getType();
    }

    /**
     * @inheritdoc
     */
    public function getContentGqlMutationArgumentType()
    {
        return [
            'name' => $this->handle,
            'type' => DateTimeType::getType(),
            'description' => $this->instructions,
        ];
    }
}
