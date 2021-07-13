<?php
namespace verbb\formie\base;

use verbb\formie\Formie;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\events\ModifyWebhookPayloadEvent;

use Craft;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

abstract class Webhook extends Integration implements IntegrationInterface
{
    // Constants
    // =========================================================================

    const EVENT_MODIFY_WEBHOOK_PAYLOAD = 'modifyWebhookPayload';


    // Static Methods
    // =========================================================================
    
    /**
     * @inheritDoc
     */
    public static function typeName(): string
    {
        return Craft::t('formie', 'Webhooks');
    }


    // Public Methods
    // =========================================================================

    /**
     * @inheritDoc
     */
    public function getIconUrl(): string
    {
        $handle = StringHelper::toKebabCase($this->displayName());

        return Craft::$app->getAssetManager()->getPublishedUrl("@verbb/formie/web/assets/webhooks/dist/img/{$handle}.svg", true);
    }

    /**
     * @inheritDoc
     */
    public function getSettingsHtml(): string
    {
        $handle = StringHelper::toKebabCase($this->displayName());

        return Craft::$app->getView()->renderTemplate("formie/integrations/webhooks/{$handle}/_plugin-settings", [
            'integration' => $this,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getFormSettingsHtml($form): string
    {
        $handle = StringHelper::toKebabCase($this->displayName());

        return Craft::$app->getView()->renderTemplate("formie/integrations/webhooks/{$handle}/_form-settings", [
            'integration' => $this,
            'form' => $form,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function getCpEditUrl(): string
    {
        return UrlHelper::cpUrl('formie/settings/webhooks/edit/' . $this->id);
    }

    /**
     * @inheritDoc
     */
    protected function generatePayloadValues(Submission $submission): array
    {
        $submissionContent = [];
        $submissionAttributes = $submission->getAttributes();

        $formAttributes = $submission->getForm()->getAttributes();

        // Trim the form settings a little
        $formAttributes['settings'] = $formAttributes['settings']->toArray();
        unset($formAttributes['settings']['integrations']);

        foreach ($submission->getForm()->getFields() as $field) {
            $value = $submission->getFieldValue($field->handle);
            $submissionContent[$field->handle] = $field->serializeValueForWebhook($value, $submission);
        }

        $payload = [
            'json' => [
                'submission' => array_merge($submissionAttributes, $submissionContent),
                'form' => $formAttributes,
            ],
        ];

        // Fire a 'modifyWebhookPayload' event
        $event = new ModifyWebhookPayloadEvent([
            'payload' => $payload,
        ]);
        $this->trigger(self::EVENT_MODIFY_WEBHOOK_PAYLOAD, $event);

        return $event->payload;
    }

    /**
     * @inheritDoc
     */
    protected function getWebhookUrl($url, Submission $submission)
    {
        $url = Craft::$app->getView()->renderObjectTemplate($url, $submission);

        return Craft::parseEnv($url);
    }
}
