<?php
namespace verbb\formie\integrations\captchas;

use verbb\formie\base\Captcha;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;

use Craft;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\web\View;

class Recaptcha extends Captcha
{
    // Constants
    // =========================================================================

    const RECAPTCHA_TYPE_V2_CHECKBOX  = 'v2_checkbox';
    const RECAPTCHA_TYPE_V2_INVISIBLE = 'v2_invisible';
    const RECAPTCHA_TYPE_V3 = 'v3';


    // Properties
    // =========================================================================

    public $handle = 'recaptcha';
    public $secretKey;
    public $siteKey;
    public $type = 'v3';
    public $size = 'normal';
    public $theme = 'light';
    public $badge = 'bottomright';
    public $language = 'en';
    public $minScore = 0.5;


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return Craft::t('formie', 'reCAPTCHA');
    }

    /**
     * @inheritDoc
     */
    public function getDescription(): string
    {
        return Craft::t('formie', 'reCAPTCHA is a free service that protects your forms from spam and abuse. Find out more via [Google reCAPTCHA](https://www.google.com/recaptcha).');
    }

    /**
     * @inheritDoc
     */
    public function getSettingsHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('formie/integrations/captchas/recaptcha/_plugin-settings', [
            'integration' => $this,
            'languageOptions' => $this->_getLanguageOptions(),
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getFormSettingsHtml($form): string
    {
        return Craft::$app->getView()->renderTemplate('formie/integrations/captchas/recaptcha/_form-settings', [
            'integration' => $this,
            'form' => $form,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndHtml(Form $form, $page = null): string
    {
        if ($this->type === self::RECAPTCHA_TYPE_V3) {
            // We don't technically need this for V3, but we use it to control whether we should validate
            // based on the specific page they're on, and if the user wants a captcha on each page.
            // We're doing this for V2, so we might as well copy that.
            return '<div class="formie-recaptcha-placeholder"></div>';
        }

        if ($this->type === self::RECAPTCHA_TYPE_V2_CHECKBOX) {
            return '<div class="formie-recaptcha-placeholder"></div>';
        }

        if ($this->type === self::RECAPTCHA_TYPE_V2_INVISIBLE) {
            return '<div class="formie-recaptcha-placeholder"></div>';
        }

        return '';
    }

    /**
     * @inheritDoc
     */
    public function getFrontEndJsVariables(Form $form, $page = null)
    {
        $settings = [
            'siteKey' => Craft::parseEnv($this->siteKey),
            'formId' => $form->getFormId(),
            'theme' => $this->theme,
            'size' => $this->size,
            'badge' => $this->badge,
            'language' => $this->_getMatchedLanguageId() ?? 'en',
            'submitMethod' => $form->settings->submitMethod ?? 'page-reload',
            'hasMultiplePages' => $form->hasMultiplePages() ?? false,
        ];

        if ($this->type === self::RECAPTCHA_TYPE_V3) {
            $src = Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/captchas/dist/js/recaptcha-v3.js', true);

            return [
                'src' => $src,
                'module' => 'FormieRecaptchaV3',
                'settings' => $settings,
            ];
        }

        if ($this->type === self::RECAPTCHA_TYPE_V2_CHECKBOX) {
            $src = Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/captchas/dist/js/recaptcha-v2-checkbox.js', true);

            return [
                'src' => $src,
                'module' => 'FormieRecaptchaV2Checkbox',
                'settings' => $settings,
            ];
        }

        if ($this->type === self::RECAPTCHA_TYPE_V2_INVISIBLE) {
            $src = Craft::$app->getAssetManager()->getPublishedUrl('@verbb/formie/web/assets/captchas/dist/js/recaptcha-v2-invisible.js', true);

            return [
                'src' => $src,
                'module' => 'FormieRecaptchaV2Invisible',
                'settings' => $settings,
            ];
        }

        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateSubmission(Submission $submission): bool
    {
        $response = Craft::$app->request->post('g-recaptcha-response');

        if (!$response) {
            return false;
        }

        $client = Craft::createGuzzleClient();

        $response = $client->post('https://www.google.com/recaptcha/api/siteverify', [
            'form_params' => [
                'secret' => Craft::parseEnv($this->secretKey),
                'response' => $response,
                'remoteip' => Craft::$app->request->getRemoteIP(),
            ],
        ]);

        $result = Json::decode((string)$response->getBody(), true);

        if (isset($result['score'])) {
            return ($result['score'] >= $this->minScore);
        }

        return $result['success'] ?? false;
    }

    /**
     * @inheritDoc
     */
    public function hasValidSettings(): bool
    {
        if ($this->siteKey && $this->secretKey) {
            return true;
        }

        return false;
    }


    // Private Methods
    // =========================================================================

    public function _getMatchedLanguageId()
    {
        if ($this->language && $this->language != 'auto') {
            return $this->language;
        }

        $currentLanguageId = Craft::$app->getLocale()->getLanguageID();

        // 700+ languages supported
        $allCraftLocales = Craft::$app->getI18n()->getAllLocales();
        $allCraftLanguageIds = ArrayHelper::getColumn($allCraftLocales, 'id');

        // ~70 languages supported
        $allRecaptchaLanguageIds = ArrayHelper::getColumn($this->_getLanguageOptions(), 'value');

        // 65 matched language IDs
        $matchedLanguageIds = array_intersect($allRecaptchaLanguageIds, $allCraftLanguageIds);

        // If our current request Language ID matches a reCAPTCHA language ID, use it
        if (in_array($currentLanguageId, $matchedLanguageIds, true)) {
            return $currentLanguageId;
        }

        // If our current language ID has a more generic match, use it
        if (strpos($currentLanguageId, '-') !== false) {
            $parts = explode('-', $currentLanguageId);
            $baseLanguageId = $parts['0'] ?? null;

            if (in_array($baseLanguageId, $matchedLanguageIds, true)) {
                return $baseLanguageId;
            }
        }

        return null;
    }

    private function _getLanguageOptions(): array
    {
        $languages = [
            'Auto' => 'auto',
            'Arabic' => 'ar',
            'Afrikaans' => 'af',
            'Amharic' => 'am',
            'Armenian' => 'hy',
            'Azerbaijani' => 'az',
            'Basque' => 'eu',
            'Bengali' => 'bn',
            'Bulgarian' => 'bg',
            'Catalan' => 'ca',
            'Chinese (Hong Kong)' => 'zh-HK',
            'Chinese (Simplified)' => 'zh-CN',
            'Chinese (Traditional)' => 'zh-TW',
            'Croatian' => 'hr',
            'Czech' => 'cs',
            'Danish' => 'da',
            'Dutch' => 'nl',
            'English (UK)' => 'en-GB',
            'English (US)' => 'en',
            'Estonian' => 'et',
            'Filipino' => 'fil',
            'Finnish' => 'fi',
            'French' => 'fr',
            'French (Canadian)' => 'fr-CA',
            'Galician' => 'gl',
            'Georgian' => 'ka',
            'German' => 'de',
            'German (Austria)' => 'de-AT',
            'German (Switzerland)' => 'de-CH',
            'Greek' => 'el',
            'Gujarati' => 'gu',
            'Hebrew' => 'iw',
            'Hindi' => 'hi',
            'Hungarian' => 'hu',
            'Icelandic' => 'is',
            'Indonesian' => 'id',
            'Italian' => 'it',
            'Japanese' => 'ja',
            'Kannada' => 'kn',
            'Korean' => 'ko',
            'Laothian' => 'lo',
            'Latvian' => 'lv',
            'Lithuanian' => 'lt',
            'Malay' => 'ms',
            'Malayalam' => 'ml',
            'Marathi' => 'mr',
            'Mongolian' => 'mn',
            'Norwegian' => 'no',
            'Persian' => 'fa',
            'Polish' => 'pl',
            'Portuguese' => 'pt',
            'Portuguese (Brazil)' => 'pt-BR',
            'Portuguese (Portugal)' => 'pt-PT',
            'Romanian' => 'ro',
            'Russian' => 'ru',
            'Serbian' => 'sr',
            'Sinhalese' => 'si',
            'Slovak' => 'sk',
            'Slovenian' => 'sl',
            'Spanish' => 'es',
            'Spanish (Latin America)' => 'es-419',
            'Swahili' => 'sw',
            'Swedish' => 'sv',
            'Tamil' => 'ta',
            'Telugu' => 'te',
            'Thai' => 'th',
            'Turkish' => 'tr',
            'Ukrainian' => 'uk',
            'Urdu' => 'ur',
            'Vietnamese' => 'vi',
            'Zulu' => 'zu'
        ];

        $languageOptions = [];

        foreach ($languages as $languageName => $languageCode) {
            $languageOptions[] = [
                'label' => Craft::t('formie', $languageName),
                'value' => $languageCode
            ];
        }

        return $languageOptions;
    }

}
