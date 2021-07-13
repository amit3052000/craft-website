<?php
namespace verbb\formie\services;

use verbb\formie\Formie;
use verbb\formie\base\Element;
use verbb\formie\controllers\SubmissionsController;
use verbb\formie\elements\NestedFieldRow;
use verbb\formie\elements\Submission;
use verbb\formie\elements\db\NestedFieldRowQuery;
use verbb\formie\events\SubmissionEvent;
use verbb\formie\events\SendNotificationEvent;
use verbb\formie\events\TriggerIntegrationEvent;
use verbb\formie\fields\formfields;
use verbb\formie\helpers\Variables;
use verbb\formie\jobs\SendNotification;
use verbb\formie\jobs\TriggerIntegration;
use verbb\formie\models\FakeElement;
use verbb\formie\models\FakeElementQuery;
use verbb\formie\models\Settings;

use Craft;
use craft\db\Query;
use craft\elements\db\ElementQuery;
use craft\events\DefineUserContentSummaryEvent;
use craft\events\ModelEvent;
use craft\fields\data\MultiOptionsFieldData;
use craft\helpers\Console;
use craft\helpers\Json;

use yii\base\Event;
use yii\base\Component;

use DateInterval;
use DateTime;
use Throwable;
use Faker;

class Submissions extends Component
{
    // Constants
    // =========================================================================

    const EVENT_AFTER_SUBMISSION = 'afterSubmission';
    const EVENT_AFTER_INCOMPLETE_SUBMISSION = 'afterIncompleteSubmission';
    const EVENT_BEFORE_SEND_NOTIFICATION = 'beforeSendNotification';
    const EVENT_BEFORE_TRIGGER_INTEGRATION = 'beforeTriggerIntegration';


    // Public Methods
    // =========================================================================

    /**
     * Returns a submission by it's ID.
     *
     * @param $submissionId
     * @param null $siteId
     * @return Submission|null
     */
    public function getSubmissionById($submissionId, $siteId = '*')
    {
        /* @var Submission $submission */
        $submission = Craft::$app->getElements()->getElementById($submissionId, Submission::class, $siteId);
        return $submission;
    }

    /**
     * Executed after a submission has been saved.
     *
     * @param bool $success whether the submission was successful
     * @param Submission $submission
     * @see SubmissionsController::actionSubmit()
     */
    public function onAfterSubmission(bool $success, Submission $submission)
    {
        // Check to see if this is an incomplete submission. Return immedately, but fire an event
        if ($submission->isIncomplete) {
            // Fire an 'afterIncompleteSubmission' event
            $event = new SubmissionEvent([
                'submission' => $submission,
                'success' => $success,
            ]);
            $this->trigger(self::EVENT_AFTER_INCOMPLETE_SUBMISSION, $event);

            return;
        }

        // Check if the submission is spam
        if ($submission->isSpam) {
            $success = false;
        }

        // Fire an 'afterSubmission' event
        $event = new SubmissionEvent([
            'submission' => $submission,
            'success' => $success,
        ]);
        $this->trigger(self::EVENT_AFTER_SUBMISSION, $event);

        if ($event->success) {
            // Send off some emails, if all good!
            $this->sendNotifications($event->submission);

            // Trigger any integrations
            $this->triggerIntegrations($event->submission);
        }
    }

    /**
     * Sends enabled notifications for a submission.
     *
     * @param Submission $submission
     */
    public function sendNotifications(Submission $submission)
    {
        $settings = Formie::$plugin->getSettings();

        // Get all enabled notifications, and push them to the queue for performance
        $form = $submission->getForm();
        $notifications = $form->getEnabledNotifications();

        foreach ($notifications as $notification) {
            // Evaluate conditions for each notification
            if (!Formie::$plugin->getNotifications()->evaluateConditions($notification, $submission)) {
                continue;
            }

            if ($settings->useQueueForNotifications) {
                Craft::$app->getQueue()->push(new SendNotification([
                    'submissionId' => $submission->id,
                    'notificationId' => $notification->id,
                ]));
            } else {
                $this->sendNotificationEmail($notification, $submission);
            }
        }
    }

    /**
     * Sends a notification email. Normally called from the queue job.
     *
     * @param Notification $notification
     * @param Submission $submission
     */
    public function sendNotificationEmail($notification, $submission)
    {
        // Fire a 'beforeSendNotification' event
        $event = new SendNotificationEvent([
            'submission' => $submission,
            'notification' => $notification,
        ]);
        $this->trigger(self::EVENT_BEFORE_SEND_NOTIFICATION, $event);

        if (!$event->isValid) {
            return true;
        }

        return Formie::$plugin->getEmails()->sendEmail($event->notification, $event->submission);
    }

    /**
     * Triggers any enabled integrations.
     *
     * @param Submission $submission
     */
    public function triggerIntegrations(Submission $submission)
    {
        $settings = Formie::$plugin->getSettings();

        $form = $submission->getForm();

        $integrations = Formie::$plugin->getIntegrations()->getAllEnabledIntegrationsForForm($form);

        foreach ($integrations as $integration) {
            if (!$integration->supportsPayloadSending()) {
                continue;
            }

            // Add additional useful info for the integration
            $integration->referrer = Craft::$app->getRequest()->getReferrer();

            if ($settings->useQueueForIntegrations) {
                Craft::$app->getQueue()->push(new TriggerIntegration([
                    'submissionId' => $submission->id,
                    'integration' => $integration,
                ]));
            } else {
                $this->sendIntegrationPayload($integration, $submission);
            }
        }
    }

    /**
     * Triggers an integration's payload to be sent. Normally called from the queue job.
     *
     * @param Integration $integration
     * @param Submission $submission
     */
    public function sendIntegrationPayload($integration, Submission $submission)
    {
        // Fire a 'beforeTriggerIntegration' event
        $event = new TriggerIntegrationEvent([
            'submission' => $submission,
            'type' => get_class($integration),
            'integration' => $integration,
        ]);
        $this->trigger(self::EVENT_BEFORE_TRIGGER_INTEGRATION, $event);

        if (!$event->isValid) {
            return true;
        }

        return $integration->sendPayLoad($event->submission);
    }

    /**
     * Deletes incomplete submissions older than the configured interval.
     */
    public function pruneIncompleteSubmissions($consoleInstance = null)
    {
        /* @var Settings $settings */
        $settings = Formie::$plugin->getSettings();

        if ($settings->maxIncompleteSubmissionAge <= 0) {
            return;
        }

        $interval = new DateInterval("P{$settings->maxIncompleteSubmissionAge}D");
        $date = new DateTime();
        $date->sub($interval);

        $submissions = Submission::find()
            ->isIncomplete(true)
            ->dateUpdated('< ' . $date->format('c'))
            ->all();

        foreach ($submissions as $submission) {
            try {
                Craft::$app->getElements()->deleteElement($submission, true);
            } catch (Throwable $e) {
                Formie::error("Failed to prune submission with ID: #{$submission->id}." . $e->getMessage());
            }
        }

        // Also check for spam pruning
        if ($settings->saveSpam) {
            if ($settings->spamLimit <= 0) {
                return;
            }

            $submissions = Submission::find()
                ->limit(null)
                ->offset($settings->spamLimit)
                ->isSpam(true)
                ->orderBy(['dateCreated' => SORT_DESC])
                ->all();

            if ($submissions && $consoleInstance) {
                $consoleInstance->stdout('Preparing to prune ' . count($submissions) . ' submissions.' . PHP_EOL, Console::FG_YELLOW);
            }

            foreach ($submissions as $submission) {
                try {
                    Craft::$app->getElements()->deleteElement($submission, true);

                    if ($consoleInstance) {
                        $consoleInstance->stdout("Pruned spam submission with ID: #{$submission->id}." . PHP_EOL, Console::FG_GREEN);
                    }
                } catch (Throwable $e) {
                    Formie::error("Failed to prune spam submission with ID: #{$submission->id}." . $e->getMessage());

                    if ($consoleInstance) {
                        $consoleInstance->stdout("Failed to prune spam submission with ID: #{$submission->id}. " . $e->getMessage() . PHP_EOL, Console::FG_RED);
                    }
                }
            }
        }
    }

    /**
     * Deletes submissions older than the form data retention settings.
     */
    public function pruneDataRetentionSubmissions($consoleInstance = null)
    {
        // Find all the forms with data retention settings
        $forms = (new Query())
            ->select(['id', 'dataRetention', 'dataRetentionValue'])
            ->from(['{{%formie_forms}}'])
            ->where(['not', ['dataRetention' => 'forever']])
            ->all();

        foreach ($forms as $form) {
            $dataRetention = $form['dataRetention'] ?? '';
            $dataRetentionValue = (int)$form['dataRetentionValue'];

            // Setup intervals, depending on the setting
            $intervalLookup = ['minutes' => 'MIN', 'hours' => 'H', 'days' => 'D', 'weeks' => 'W', 'months' => 'M', 'years' => 'Y'];
            $intervalValue = $intervalLookup[$dataRetention] ?? '';

            if (!$intervalValue || !$dataRetentionValue) {
                continue;
            }

            // Handle weeks - not available built-in interval
            if ($intervalValue === 'W') {
                $intervalValue = 'D';
                $dataRetentionValue = $dataRetentionValue * 7;
            }

            $period = ($intervalValue === 'H' || $intervalValue === 'MIN') ? 'PT' : 'P';

            if ($intervalValue === 'MIN') {
                $intervalValue = 'M';
            }

            $interval = new DateInterval("{$period}{$dataRetentionValue}{$intervalValue}");
            $date = new DateTime();
            $date->sub($interval);

            $submissions = Submission::find()
                ->dateCreated('< ' . $date->format('c'))
                ->formId($form['id'])
                ->all();

            if ($submissions && $consoleInstance) {
                $consoleInstance->stdout('Preparing to prune ' . count($submissions) . ' submissions.' . PHP_EOL, Console::FG_YELLOW);
            }

            foreach ($submissions as $submission) {
                try {
                    Craft::$app->getElements()->deleteElement($submission, true);

                    if ($consoleInstance) {
                        $consoleInstance->stdout("Pruned submission with ID: #{$submission->id}." . PHP_EOL, Console::FG_GREEN);
                    }
                } catch (Throwable $e) {
                    Formie::error("Failed to prune submission with ID: #{$submission->id}." . $e->getMessage());

                    if ($consoleInstance) {
                        $consoleInstance->stdout("Failed to prune submission with ID: #{$submission->id}. " . $e->getMessage() . PHP_EOL, Console::FG_RED);
                    }
                }
            }
        }
    }

    /**
     * Defining a summary of content owned by a user(s), before they are deleted.
     */
    public function defineUserSubmssions(DefineUserContentSummaryEvent $event)
    {
        $userIds = Craft::$app->getRequest()->getRequiredBodyParam('userId');

        $submissionCount = Submission::find()
            ->userId($userIds)
            ->siteId('*')
            ->unique()
            ->anyStatus()
            ->count();

        if ($submissionCount) {
            $event->contentSummary[] = $submissionCount == 1 ? Craft::t('formie', '1 form submission') : Craft::t('formie', '{num} form submissions', ['num' => $submissionCount]);
        }
    }

    /**
     * Deletes any submissions related to a user.
     */
    public function deleteUserSubmssions(Event $event)
    {
        /** @var User $user */
        $user = $event->sender;

        $submissions = Submission::find()
            ->userId($user->id)
            ->siteId('*')
            ->unique()
            ->anyStatus()
            ->all();

        if (!$submissions) {
            return;
        }

        // Are we transferring to another user, or just deleting?
        $inheritorOnDelete = $user->inheritorOnDelete ?? null;

        if ($inheritorOnDelete) {
            // Re-assign each submission to the new user
            Craft::$app->getDb()->createCommand()
                ->update('{{%formie_submissions}}', ['userId' => $inheritorOnDelete->id], ['userId' => $user->id])
                ->execute();

        } else {
            // We just want to delete each submission - bye!
            foreach ($submissions as $submission) {
                try {
                    Craft::$app->getElements()->deleteElement($submission);
                } catch (Throwable $e) {
                    Formie::error("Failed to delete user submission with ID: #{$submission->id}." . $e->getMessage());
                }
            }
        }
    }

    /**
     * Restores any submissions related to a user.
     */
    public function restoreUserSubmssions(Event $event)
    {
        /** @var User $user */
        $user = $event->sender;

        $submissions = Submission::find()
            ->userId($user->id)
            ->siteId('*')
            ->unique()
            ->anyStatus()
            ->trashed(true)
            ->all();
        
        foreach ($submissions as $submission) {
            try {
                Craft::$app->getElements()->restoreElement($submission);
            } catch (Throwable $e) {
                Formie::error("Failed to restore user submission with ID: #{$submission->id}." . $e->getMessage());
            }
        }
    }

    /**
     * Performs spam checks on a submission.
     *
     * @param Submission $submission
     */
    public function spamChecks(Submission $submission)
    {
        /* @var Settings $settings */
        $settings = Formie::$plugin->getSettings();

        // Is it already spam? Return
        if ($submission->isSpam) {
            return;
        }

        $excludes = $this->_getArrayFromMultiline($settings->spamKeywords);

        // Handle any Twig used in the field
        foreach ($excludes as $key => $exclude) {
            if (strstr($exclude, '{')) {
                unset($excludes[$key]);

                $parsedString = $this->_getArrayFromMultiline(Variables::getParsedValue($exclude));
                $excludes = array_merge($excludes, $parsedString);
            }
        }

        // Build a string based on field content - much easier to find values
        // in a single string than iterate through multiple arrays
        $fieldValues = $this->_getContentAsString($submission);

        foreach ($excludes as $exclude) {
            // Check if string contains
            if (strtolower($exclude) && strstr(strtolower($fieldValues), strtolower($exclude))) {
                $submission->isSpam = true;
                $submission->spamReason = Craft::t('formie', 'Contains banned keyword: “{c}”', ['c' => $exclude]);

                break;
            }

            // Check for IPs
            if ($submission->ipAddress && $submission->ipAddress === $exclude) {
                $submission->isSpam = true;
                $submission->spamReason = Craft::t('formie', 'Contains banned IP: “{c}”', ['c' => $exclude]);

                break;
            }
        }
    }

    /**
     * Logs spam to the Formie log.
     *
     * @param Submission $submission
     */
    public function logSpam(Submission $submission)
    {
        $fieldValues = $submission->getSerializedFieldValues();
        $fieldValues = array_filter($fieldValues);

        $error = Craft::t('formie', 'Submission marked as spam - “{r}” - {j}.', [
            'r' => $submission->spamReason,
            'j' => Json::encode($fieldValues),
        ]);

        Formie::log($error);
    }

    /**
     * @inheritdoc
     */
    public function populateFakeSubmission(Submission $submission)
    {
        $fields = $submission->getFieldLayout()->getFields();
        $fieldContent = [];

        $fieldContent = $this->_getFakeFieldContent($fields);

        $submission->setFieldValues($fieldContent);
    }


    // Private Methods
    // =========================================================================

    /**
     * Converts a multiline string to an array.
     *
     * @param $string
     * @return array
     */
    private function _getArrayFromMultiline($string)
    {
        $array = [];

        if ($string) {
            $array = array_map('trim', explode(PHP_EOL, $string));
        }

        return array_filter($array);
    }

    /**
     * Converts a field value to a string.
     *
     * @param $submission
     * @return string
     */
    private function _getContentAsString($submission)
    {
        $fieldValues = [];

        if (($fieldLayout = $submission->getFieldLayout()) !== null) {
            foreach ($fieldLayout->getFields() as $field) {
                try {
                    $value = $submission->getFieldValue($field->handle);

                    if ($value instanceof NestedFieldRowQuery) {
                        $values = [];

                        foreach ($value->all() as $row) {
                            $fieldValues[] = $this->_getContentAsString($row);
                        }

                        continue;
                    }

                    if ($value instanceof ElementQuery) {
                        $value = $value->one();
                    }

                    if ($value instanceof MultiOptionsFieldData) {
                        $value = implode(' ', array_map(function($item) {
                            return $item->value;
                        }, (array)$value));
                    }

                    $fieldValues[] = (string)$value;
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }

        return implode(' ', $fieldValues);
    }

    /**
     * @inheritdoc
     */
    public function _getFakeFieldContent($fields)
    {
        $fieldContent = [];

        $faker = Faker\Factory::create();

        foreach ($fields as $key => $field) {
            switch (get_class($field)) {
                case formfields\Address::class:
                    $fieldContent[$field->handle]['address1'] = $faker->address;
                    $fieldContent[$field->handle]['address2'] = $faker->buildingNumber;
                    $fieldContent[$field->handle]['address3'] = $faker->streetSuffix;
                    $fieldContent[$field->handle]['city'] = $faker->city;
                    $fieldContent[$field->handle]['zip'] = $faker->postcode;
                    $fieldContent[$field->handle]['state'] = $faker->state;
                    $fieldContent[$field->handle]['country'] = $faker->country;

                    break;
                case formfields\Checkboxes::class:
                    $values = $faker->randomElement($field->options)['value'] ?? '';
                    $fieldContent[$field->handle] = [$values];

                    break;
                case formfields\Date::class:
                    $fieldContent[$field->handle] = $faker->iso8601;

                    break;
                case formfields\Dropdown::class:
                    $fieldContent[$field->handle] = $faker->randomElement($field->options)['value'] ?? '';

                    break;
                case formfields\Email::class:
                    $fieldContent[$field->handle] = $faker->email;

                    break;
                case formfields\Group::class:
                    // Create a fake object to query. Maybe one day I'll figure out how to generate a fake elementQuery.
                    // The fields rely on a NestedRowQuery for use in emails, so we need some similar.
                    $query = new FakeElementQuery(FakeElement::class);

                    if ($fieldLayout = $field->getFieldLayout()) {
                        $content = $this->_getFakeFieldContent($fieldLayout->getFields());
                        $query->setFieldValues($content, $fieldLayout);
                    }

                    $fieldContent[$field->handle] = $query;

                    break;
                case formfields\MultiLineText::class:
                    $fieldContent[$field->handle] = $faker->realText;

                    break;
                case formfields\Name::class:
                    if ($field->useMultipleFields) {
                        $fieldContent[$field->handle]['prefix'] = $faker->title;
                        $fieldContent[$field->handle]['firstName'] = $faker->firstName;
                        $fieldContent[$field->handle]['middleName'] = $faker->firstName;
                        $fieldContent[$field->handle]['lastName'] = $faker->lastName;
                    } else {
                        $fieldContent[$field->handle] = $faker->name;
                    }

                    break;
                case formfields\Number::class:
                    $fieldContent[$field->handle] = $faker->randomDigit;

                    break;
                case formfields\Phone::class:
                    if ($field->countryEnabled) {
                        $number = $faker->e164PhoneNumber;

                        $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
                        $numberProto = $phoneUtil->parse($number);

                        $fieldContent[$field->handle]['number'] = $number;
                        $fieldContent[$field->handle]['country'] = $phoneUtil->getRegionCodeForNumber($numberProto);
                    } else {
                        $fieldContent[$field->handle] = $faker->phoneNumber;
                    }

                    break;
                case formfields\Radio::class:
                    $fieldContent[$field->handle] = $faker->randomElement($field->options)['value'] ?? '';

                    break;
                case formfields\Recipients::class:
                    $fieldContent[$field->handle] = $faker->email;

                    break;                    
                default:
                    $fieldContent[$field->handle] = $faker->text;

                    break;
            }
        }

        return $fieldContent;
    }
}
