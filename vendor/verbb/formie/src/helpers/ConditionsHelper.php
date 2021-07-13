<?php
namespace verbb\formie\helpers;

use verbb\formie\Formie;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\StringHelper;

use Hoa\Ruler\Ruler;
use Hoa\Ruler\Context;

class ConditionsHelper
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public static function getRuler()
    {
        $ruler = new Ruler();

        $ruler->getDefaultAsserter()->setOperator('contains', function($subject, $pattern) {
            return StringHelper::contains($subject, $pattern);
        });

        $ruler->getDefaultAsserter()->setOperator('startswith', function($subject, $pattern) {
            return StringHelper::startsWith($subject, $pattern);
        });

        $ruler->getDefaultAsserter()->setOperator('endswith', function($subject, $pattern) {
            return StringHelper::endsWith($subject, $pattern);
        });

        return $ruler;
    }

    /**
     * @inheritDoc
     */
    public static function getContext($conditions = [])
    {
        return new Context($conditions);
    }

    /**
     * @inheritDoc
     */
    public static function getConditionalTestResult($conditionSettings, $submission)
    {
        $conditions = $conditionSettings['conditions'] ?? [];
        
        $results = [];
        $ruler = ConditionsHelper::getRuler();

        // Fetch the values, serialized for string comparison
        $serializedFieldValues = $submission->getSerializedFieldValuesForIntegration();

        foreach ($conditions as $condition) {
            try {
                $rule = "field {$condition['condition']} value";

                $condition['field'] = str_replace(['{', '}'], ['', ''], $condition['field']);

                // Check to see if this is a custom field, or an attribute on the submission
                if (StringHelper::startsWith($condition['field'], 'submission:')) {
                    $condition['field'] = str_replace('submission:', '', $condition['field']);

                    $condition['field'] = ArrayHelper::getValue($submission, $condition['field']);
                } else {
                    // Parse the field handle first to get the submission value
                    $condition['field'] = ArrayHelper::getValue($serializedFieldValues, $condition['field']);
                }

                // Check for array values, we should always be comparing strings
                if (is_array($condition['field'])) {
                    $condition['field'] = ConditionsHelper::recursiveImplode(' ', $condition['field']);
                }

                // Protect against empty conditions
                if (!trim(ConditionsHelper::recursiveImplode('', $condition))) {
                    continue;
                }

                $context = ConditionsHelper::getContext($condition);

                // Test the condition
                $results[] = $ruler->assert($rule, $context);
            } catch (\Throwable $e) {
                Formie::error(Craft::t('formie', 'Failed to parse conditional “{rule}”: “{message}” {file}:{line}', [
                    'rule' => trim(ConditionsHelper::recursiveImplode('', $condition)),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]));

                continue;
            }
        }

        $result = false;

        // Check to see how to compare the result (any or all).
        if ($conditionSettings['conditionRule'] === 'all') {
            // Are _all_ the conditions the same?
            $result = (bool)array_product($results);
        } else {
            $result = (bool)in_array(true, $results);
        }

        return $result;
    }

    /**
     * Recursively implodes an array with optional key inclusion
     * 
     * Example of $include_keys output: key, value, key, value, key, value
     * 
     * @access  public
     * @param   array   $array         multi-dimensional array to recursively implode
     * @param   string  $glue          value that glues elements together   
     * @param   bool    $include_keys  include keys before their values
     * @param   bool    $trim_all      trim ALL whitespace from string
     * @return  string  imploded array
     */ 
    public static function recursiveImplode($glue = ',', array $array, $include_keys = false, $trim_all = false)
    {
        $glued_string = '';

        // Recursively iterates array and adds key/value to glued string
        array_walk_recursive($array, function($value, $key) use ($glue, $include_keys, &$glued_string) {
            $include_keys && $glued_string .= $key.$glue;
            $glued_string .= $value.$glue;
        });

        // Removes last $glue from string
        strlen($glue) > 0 && $glued_string = substr($glued_string, 0, -strlen($glue));

        // Trim ALL whitespace
        $trim_all && $glued_string = preg_replace("/(\s)/ixsm", '', $glued_string);

        return (string)$glued_string;
    }
}
