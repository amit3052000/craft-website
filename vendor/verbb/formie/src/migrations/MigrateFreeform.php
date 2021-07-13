<?php
namespace verbb\formie\migrations;

use verbb\formie\Formie;
use verbb\formie\base\FormFieldInterface;
use verbb\formie\elements\Form;
use verbb\formie\elements\Submission;
use verbb\formie\events\ModifyMigrationFieldEvent;
use verbb\formie\events\ModifyMigrationFormEvent;
use verbb\formie\events\ModifyMigrationNotificationEvent;
use verbb\formie\events\ModifyMigrationSubmissionEvent;
use verbb\formie\fields\formfields;
use verbb\formie\helpers\Variables;
use verbb\formie\models\Notification;
use verbb\formie\models\FieldLayout;
use verbb\formie\models\FieldLayoutPage;

use Craft;
use craft\db\Migration;
use craft\elements\Asset;
use craft\helpers\ArrayHelper;
use craft\helpers\Console;
use craft\helpers\Json;

use ReflectionClass;
use Throwable;
use yii\helpers\Markdown;

use Solspace\Freeform\Freeform;
use Solspace\Freeform\Models\FormModel;
use Solspace\Freeform\Elements\Submission as FreeformSubmission;
use Solspace\Freeform\Library\Composer\Components\FieldInterface;
use Solspace\Freeform\Library\Composer\Components\Fields\DataContainers\Option;
use Solspace\Freeform\Fields as freeformfields;

/**
 * Migrates Freeform forms, notifications and submissions.
 */
class MigrateFreeform extends Migration
{
    // Constants
    // =========================================================================

    const EVENT_MODIFY_FIELD = 'modifyField';
    const EVENT_MODIFY_FORM = 'modifyForm';
    const EVENT_MODIFY_NOTIFICATION = 'modifyNotification';
    const EVENT_MODIFY_SUBMISSION = 'modifySubmission';


    // Properties
    // =========================================================================

    /**
     * @var int The form ID
     */
    public $formId;

    /**
     * @var FormModel
     */
    private $_freeformForm;

    /**
     * @var Form
     */
    private $_form;


    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        if ($this->_freeformForm = Freeform::getInstance()->forms->getFormById($this->formId)) {
            if ($this->_form = $this->_migrateForm()) {
                $this->_migrateSubmissions();
                $this->_migrateNotifications();
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function safeDown()
    {
        return false;
    }

    private function _migrateForm()
    {
        $transaction = Craft::$app->db->beginTransaction();
        $freeformForm = $this->_freeformForm;

        $this->stdout("Form: Preparing to migrate form “{$freeformForm->handle}”.");

        try {
            $form = new Form();
            $form->title = $freeformForm->name;
            $form->handle = $this->_getHandle($freeformForm);
            $form->settings->submissionTitleFormat = $freeformForm->submissionTitleFormat != '{{ dateCreated|date("Y-m-d H:i:s") }}' ? $freeformForm->submissionTitleFormat : '';
            $form->settings->submitMethod = $freeformForm->getForm()->isAjaxEnabled() ? 'ajax' : 'page-reload';
            $form->settings->submitActionUrl = $freeformForm->returnUrl;
            $form->settings->submitAction = 'url';

            // "storeData" is private.
            if ($f = $freeformForm->getForm()) {
                $reflection = new ReflectionClass($f);
                $storeDataProp = $reflection->getProperty('storeData');
                $storeDataProp->setAccessible(true);
                $form->settings->storeData = $storeDataProp->getValue($f) ?? true;
            }

            // Fire a 'modifyForm' event
            $event = new ModifyMigrationFormEvent([
                'form' => $freeformForm,
                'newForm' => $form,
            ]);
            $this->trigger(self::EVENT_MODIFY_FORM, $event);

            $form = $this->_form = $event->newForm;

            if ($fieldLayout = $this->_buildFieldLayout($freeformForm)) {
                $form->setFormFieldLayout($fieldLayout);
            }

            if (!$event->isValid) {
                $this->stdout("    > Skipped form due to event cancellation.", Console::FG_YELLOW);
                return $form;
            }

            if (!Formie::$plugin->getForms()->saveForm($form)) {
                $this->stdout("    > Failed to save form “{$form->handle}”.", Console::FG_RED);

                foreach ($form->getErrors() as $attr => $errors) {
                    foreach ($errors as $error) {
                        $this->stdout("    > $attr: $error", Console::FG_RED);
                    }
                }

                foreach ($form->getPages() as $page) {
                    foreach ($page->getErrors() as $attr => $errors) {
                        foreach ($errors as $error) {
                            $this->stdout("    > $attr: $error", Console::FG_RED);
                        }
                    }

                    foreach ($page->getRows() as $row) {
                        foreach ($row['fields'] as $field) {
                            foreach ($field->getErrors() as $attr => $errors) {
                                foreach ($errors as $error) {
                                    $this->stdout("    > $attr: $error", Console::FG_RED);
                                }
                            }
                        }
                    }
                }
            } else {
                $this->stdout("    > Form “{$form->handle}” migrated.", Console::FG_GREEN);
            }
        } catch (Throwable $e) {
            $this->stdout("    > Failed to migrate “{$freeformForm->handle}”.", Console::FG_RED);
            $this->stdout("    > `{$this->getExceptionTraceAsString($e)}`", Console::FG_RED);

            $transaction->rollBack();

            throw $e;
        }

        return $form;
    }

    private function _migrateSubmissions()
    {
        $status = Formie::$plugin->getStatuses()->getAllStatuses()[0];

        $entries = FreeformSubmission::find()->form($this->_freeformForm->handle)->all();
        $total = count($entries);

        $this->stdout("Entries: Preparing to migrate $total entries to submissions.");

        if (!$total) {
            $this->stdout('    > No entries to migrate.', Console::FG_YELLOW);

            return;
        }

        foreach ($entries as $entry) {
            /* @var FreeformSubmission $entry */
            $submission = new Submission();
            $submission->title = $entry->title;
            $submission->setForm($this->_form);
            $submission->setStatus($status);
            $submission->dateCreated = $entry->dateCreated;
            $submission->dateUpdated = $entry->dateUpdated;

            foreach ($entry as $field) {
                $handle = $field->getHandle();
                $field = $entry->$handle;

                try {
                    switch (get_class($field)) {
                        case freeformfields\Pro\ConfirmationField::class:
                            // Not implemented
                            continue;

                        case freeformfields\Pro\OpinionScaleField::class:
                            // Not implemented
                            continue;

                        case freeformfields\Pro\RatingField::class:
                            // Not implemented
                            continue;

                        case freeformfields\Pro\RichTextField::class:
                            // Not implemented
                            continue;

                        case freeformfields\Pro\SignatureField::class:
                            // Not implemented
                            continue;

                        case freeformfields\DynamicRecipientField::class:
                            // Not implemented
                            continue;

                        case freeformfields\HtmlField::class:
                            // Not implemented
                            continue;

                        case freeformfields\MailingListField::class:
                            // Not implemented
                            continue;

                        case freeformfields\RecaptchaField::class:
                            // Not implemented
                            continue;

                        case freeformfields\SubmitField::class:
                            // Not implemented
                            continue;

                        case freeformfields\CheckboxField::class:
                            $submission->setFieldValue($handle, $field->isChecked());
                            break;

                        case freeformfields\FileUploadField::class:
                            $value = $field->getValue();
                            if (!empty($value)) {
                                $assets = Asset::find()->id($value)->ids();
                                $submission->setFieldValue($handle, $assets);
                            }
                            break;

                        case freeformfields\EmailField::class:
                            $value = $field->getValue();
                            if (!empty($value)) {
                                $submission->setFieldValue($handle, $value[0]);
                            }
                            break;

                        default:
                            $submission->setFieldValue($handle, $field->getValue());
                            break;
                    }
                } catch (Throwable $e) {
                    $this->stdout("    > Failed to migrate “{$handle}”.", Console::FG_RED);
                    $this->stdout("    > `{$this->getExceptionTraceAsString($e)}`", Console::FG_RED);

                    continue;
                }
            }

            // Fire a 'modifySubmission' event
            $event = new ModifyMigrationSubmissionEvent([
                'form' => $this->_form,
                'submission' => $submission,
            ]);
            $this->trigger(self::EVENT_MODIFY_SUBMISSION, $event);

            if (!$event->isValid) {
                $this->stdout("    > Skipped submission due to event cancellation.", Console::FG_YELLOW);
                continue;
            }

            if (!Craft::$app->getElements()->saveElement($event->submission)) {
                $this->stdout("    > Failed to save submission “{$event->submission->id}”.", Console::FG_RED);

                foreach ($submission->getErrors() as $attr => $errors) {
                    foreach ($errors as $error) {
                        $this->stdout("    > $attr: $error", Console::FG_RED);
                    }
                }
            } else {
                $this->stdout("    > Migrated submission “{$event->submission->id}”.", Console::FG_GREEN);
            }
        }

        $this->stdout("    > All entries completed.", Console::FG_GREEN);
    }

    private function _migrateNotifications()
    {
        $this->_freeformForm;

        $props = $this->_freeformForm->getForm()->getAdminNotificationProperties();
        if ($props && $notificationId = $props->getNotificationId()) {
            $notification = Freeform::getInstance()->notifications->getNotificationById($notificationId);
            $this->stdout("Notifications: Preparing to migrate notification.");

            try {
                $newNotification = new Notification();
                $newNotification->formId = $this->_form->id;
                $newNotification->name = $notification->name;
                $newNotification->subject = $notification->getSubject();
                $newNotification->to = str_replace(PHP_EOL, ',', $props->getRecipients());
                $newNotification->cc = $notification->getCc();
                $newNotification->bcc = $notification->getBcc();
                $newNotification->from = $notification->getFromEmail();
                $newNotification->fromName = $notification->getFromName();
                $newNotification->replyTo = $notification->getReplyToEmail();
                $newNotification->templateId = null;
                $newNotification->attachFiles = $notification->isIncludeAttachmentsEnabled();
                $newNotification->enabled = true;

                $body = $this->_tokenizeNotificationBody($notification->getBodyText());
                $newNotification->content = Json::encode($body);

                // Fire a 'modifyNotification' event
                $event = new ModifyMigrationNotificationEvent([
                    'form' => $this->_form,
                    'notification' => $notification,
                    'newNotification' => $newNotification,
                ]);
                $this->trigger(self::EVENT_MODIFY_NOTIFICATION, $event);

                if (!$event->isValid) {
                    $this->stdout("    > Skipped notification due to event cancellation.", Console::FG_YELLOW);
                    return;
                }

                if (Formie::$plugin->getNotifications()->saveNotification($event->newNotification)) {
                    $this->stdout("    > Migrated notification “{$notification->name}”. You may need to check the notification body.", Console::FG_GREEN);
                } else {
                    $this->stdout("    > Failed to save notification “{$notification->name}”.", Console::FG_RED);

                    foreach ($notification->getErrors() as $attr => $errors) {
                        foreach ($errors as $error) {
                            $this->stdout("    > $attr: $error", Console::FG_RED);
                        }
                    }
                }
            } catch (Throwable $e) {
                $this->stdout("    > Failed to migrate “{$notification->name}”.", Console::FG_RED);
                $this->stdout("    > `{$this->getExceptionTraceAsString($e)}`", Console::FG_RED);

                return;
            }
        } else {
            $this->stdout("    > No notifications to migrate.", Console::FG_YELLOW);
        }

        $this->stdout("    > All notifications completed.", Console::FG_GREEN);
    }

    private function _getHandle(FormModel $form)
    {
        $increment = 1;
        $handle = $form->handle;

        while (true) {
            if (!Form::find()->handle($handle)->exists()) {
                return $handle;
            }

            $newHandle = $form->handle . $increment;

            $this->stdout("    > Handle “{$handle}” is taken, will try “{$newHandle}” instead.", Console::FG_YELLOW);

            $handle = $newHandle;

            $increment++;
        }

        return null;
    }

    /**
     * @param FormModel $form
     * @return FieldLayout
     * @noinspection PhpDocMissingThrowsInspection
     */
    private function _buildFieldLayout(FormModel $form)
    {
        $fieldLayout = new FieldLayout([ 'type' => Form::class ]);
        $fieldLayout->type = Form::class;

        $pages = [];
        $fields = [];
        $layout = $form->getLayout();

        foreach ($layout->getPages() as $pageIndex => $page) {
            $newPage = new FieldLayoutPage();
            $newPage->name = $page->getLabel();
            $newPage->sortOrder = '' . $pageIndex;

            $pageFields = [];
            $fieldHashes = [];

            foreach ($page->getRows() as $rowIndex => $row) {
                foreach ($row as $fieldIndex => $field) {
                    if ($newField = $this->_mapField($field)) {
                        // Fire a 'modifyField' event
                        $event = new ModifyMigrationFieldEvent([
                            'form' => $this->_form,
                            'originForm' => $form,
                            'field' => $field,
                            'newField' => $newField,
                        ]);
                        $this->trigger(self::EVENT_MODIFY_FIELD, $event);

                        $newField = $event->newField;

                        if (!$event->isValid) {
                            $this->stdout("    > Skipped field “{$newField->handle}” due to event cancellation.", Console::FG_YELLOW);
                            continue;
                        }

                        $newField->validate();

                        if ($newField->hasErrors()) {
                            $this->stdout("    > Failed to save field “{$newField->handle}”.", Console::FG_RED);

                            foreach ($newField->getErrors() as $attr => $errors) {
                                foreach ($errors as $error) {
                                    $this->stdout("    > $attr: $error", Console::FG_RED);
                                }
                            }
                        } else {
                            $newField->sortOrder = $fieldIndex;
                            $newField->rowIndex = $rowIndex;
                            $pageFields[] = $newField;
                            $fields[] = $newField;
                            $fieldHashes[] = $field->getHash();
                        }
                    } else if (get_class($field) === \Solspace\Freeform\Fields\SubmitField::class) {
                        $newPage->settings->buttonsPosition = $field->getPosition();
                        $newPage->settings->submitButtonLabel = $field->getLabelNext();
                        $newPage->settings->backButtonLabel = $field->getLabelPrev();
                        $newPage->settings->showBackButton = !$field->isDisablePrev();

                        if ($newPage->settings->buttonsPosition === 'spread') {
                            $newPage->settings->buttonsPosition = 'left-right';
                        }
                    } else {
                        $this->stdout("    > Failed to migrate field “{$field->getHandle()}” on form “{$form->handle}”. Unsupported field.", Console::FG_RED);
                    }
                }
            }

            // Migrate any hidden fields excluded from the layout.
            foreach ($this->_freeformForm->getLayout()->getFields() as $field) {
                if ($field->getPageIndex() != $pageIndex) {
                    continue;
                }

                if (in_array($field->getHash(), $fieldHashes)) {
                    continue;
                }
                    
                if ($newField = $this->_mapField($field)) {
                    // Fire a 'modifyField' event
                    $event = new ModifyMigrationFieldEvent([
                        'form' => $this->_form,
                        'originForm' => $form,
                        'field' => $field,
                        'newField' => $newField,
                    ]);
                    $this->trigger(self::EVENT_MODIFY_FIELD, $event);

                    $newField = $event->newField;

                    if (!$event->isValid) {
                        $this->stdout("    > Skipped field “{$newField->handle}” due to event cancellation.", Console::FG_YELLOW);
                        continue;
                    }

                    $newField->validate();

                    if ($newField->hasErrors()) {
                        $this->stdout("    > Failed to save field “{$newField->handle}”.", Console::FG_RED);

                        foreach ($newField->getErrors() as $attr => $errors) {
                            foreach ($errors as $error) {
                                $this->stdout("    > $attr: $error", Console::FG_RED);
                            }
                        }
                    } else {
                        $newField->sortOrder = 0;
                        $newField->rowIndex = count($pageFields);
                        $pageFields[] = $newField;
                        $fields[] = $newField;
                        $fieldHashes[] = $field->getHash();
                    }
                }
            }

            $newPage->setFields($pageFields);
            $pages[] = $newPage;
        }

        $fieldLayout->setPages($pages);
        $fieldLayout->setFields($fields);

        return $fieldLayout;
    }

    /**
     * @param FieldInterface $field
     * @return FormFieldInterface|null
     */
    private function _mapField(FieldInterface $field)
    {
        switch (get_class($field)) {
            case freeformfields\CheckboxField::class:
                /* @var freeformfields\CheckboxField $field */
                $newField = new formfields\Agree();
                $this->_applyFieldDefaults($newField);

                $newField->defaultValue = $field->isChecked();
                $newField->description = $field->getLabel();
                $newField->checkedValue = $field->getValue();
                $newField->uncheckedValue = Craft::t('app', 'No');
                break;

            case freeformfields\Pro\ConfirmationField::class:
                /* @var freeformfields\CheckboxField $field */
                $newField = new formfields\Agree();
                $this->_applyFieldDefaults($newField);

                $newField->defaultValue = $field->isChecked();
                $newField->description = $field->getLabel();
                $newField->checkedValue = $field->getValue();
                $newField->uncheckedValue = Craft::t('app', 'No');
                break;

            case freeformfields\CheckboxGroupField::class:
                /* @var freeformfields\CheckboxGroupField $field */
                $newField = new formfields\Checkboxes();
                $this->_applyFieldDefaults($newField);

                $newField->options = $this->_mapOptions($field->getOptions());
                break;

            case freeformfields\DynamicRecipientField::class:
                // Not implemented
                return null;

            case freeformfields\Pro\DatetimeField::class:
                /* @var freeformfields\CheckboxGroupField $field */
                $newField = new formfields\Date();
                $this->_applyFieldDefaults($newField);

                if ($field->getDateTimeType() === 'both') {
                    $newField->includeTime = true;
                }

                switch ($field->getDateOrder()) {
                    case 'mdy':
                        $newField->dateFormat = 'm-d-Y';

                        break;

                    case 'dmy':
                        $newField->dateFormat = 'd-m-Y';

                        break;

                    case 'ymd':
                        $newField->dateFormat = 'Y-m-d';

                        break;
                }

                break;

            case freeformfields\EmailField::class:
                /* @var freeformfields\EmailField $field */
                $newField = new formfields\Email();
                $this->_applyFieldDefaults($newField);
                break;

            case freeformfields\FileUploadField::class:
                /* @var freeformfields\FileUploadField $field */
                $newField = new formfields\FileUpload();
                $this->_applyFieldDefaults($newField);

                $source = $field->getAssetSourceId();
                if ($source = Craft::$app->getAssets()->getRootFolderByVolumeId($source)) {
                    $newField->uploadLocationSource = "folder:{$source->getVolume()->uid}";
                } else if ($volumes = Craft::$app->getVolumes()->getAllVolumes()) {
                    $newField->uploadLocationSource = "folder:{$volumes[0]->uid}";
                }

                $newField->uploadLocationSubpath = $field->getDefaultUploadLocation();
                $newField->restrictFiles = !empty($field->getFileKinds());
                $newField->allowedKinds = $field->getFileKinds() ?? [];
                break;

            case freeformfields\HiddenField::class:
                /* @var freeformfields\HiddenField $field */
                $newField = new formfields\Hidden();
                $this->_applyFieldDefaults($newField);

                $newField->defaultValue = $field->getValue();
                break;

            case freeformfields\HtmlField::class:
                /* @var freeformfields\HtmlField $field */
                $newField = new formfields\Html();
                $this->_applyFieldDefaults($newField);

                $newField->name = Craft::t('formie', 'HTML Field');
                $newField->handle = 'html' . rand();
                $newField->htmlContent = $field->getValue();
                $newField->labelPosition = 'hidden';
                break;

            case freeformfields\Pro\InvisibleField::class:
                /* @var freeformfields\HiddenField $field */
                $newField = new formfields\Hidden();
                $this->_applyFieldDefaults($newField);

                $newField->defaultValue = $field->getValue();
                break;

            case freeformfields\MailingListField::class:
                // Not implemented
                return null;

            case freeformfields\MultipleSelectField::class:
                /* @var freeformfields\MultipleSelectField $field */
                $newField = new formfields\Dropdown();
                $this->_applyFieldDefaults($newField);

                $newField->setMultiple(true);
                $newField->options = $this->_mapOptions($field->getOptions());
                break;

            case freeformfields\NumberField::class:
                /* @var freeformfields\NumberField $field */
                $newField = new formfields\Number();
                $this->_applyFieldDefaults($newField);

                $newField->min = $field->getMinValue();
                $newField->max = $field->getMaxValue();
                $newField->decimals = $field->getDecimalCount();
                break;

            case freeformfields\Pro\PhoneField::class:
                /* @var freeformfields\TextField $field */
                $newField = new formfields\Phone();

                $this->_applyFieldDefaults($newField);
                break;

            case freeformfields\RadioGroupField::class:
                /* @var freeformfields\RadioGroupField $field */
                $newField = new formfields\Radio();
                $this->_applyFieldDefaults($newField);

                $newField->layout = $field->isOneLine() ? 'horizontal' : 'vertical';
                $newField->options = $this->_mapOptions($field->getOptions());
                break;

            case freeformfields\RecaptchaField::class:
                // Not implemented
                return null;

            case freeformfields\SelectField::class:
                /* @var freeformfields\SelectField $field */
                $newField = new formfields\Dropdown();
                $this->_applyFieldDefaults($newField);

                $newField->options = $this->_mapOptions($field->getOptions());
                break;

            case freeformfields\SubmitField::class:
                // Not implemented
                return null;

            case freeformfields\Pro\TableField::class:
                /* @var freeformfields\TableField $field */
                $newField = new formfields\Table();
                $newField->addRowLabel = $field->getAddButtonLabel();

                foreach ($field->getTableLayout() as $key => $row) {
                    $newField->columns[$key] = [
                        'id' => 'col' . ($key + 1),
                        'heading' => $row['label'] ?? '',
                        'handle' => $row['value'] ?? '',
                        'type' => $row['type'] ?? 'singleline',
                    ];
                }

                break;

            case freeformfields\TextareaField::class:
                /* @var freeformfields\TextareaField $field */
                $newField = new formfields\MultiLineText();

                if ($field->getMaxLength()) {
                    $newField->limit = true;
                    $newField->limitType = 'characters';
                    $newField->limitAmount = $field->getMaxLength();
                }

                $this->_applyFieldDefaults($newField);
                break;

            case freeformfields\TextField::class:
                /* @var freeformfields\TextField $field */
                $newField = new formfields\SingleLineText();

                if ($field->getMaxLength()) {
                    $newField->limit = true;
                    $newField->limitType = 'characters';
                    $newField->limitAmount = $field->getMaxLength();
                }

                $this->_applyFieldDefaults($newField);
                break;

            case freeformfields\Pro\WebsiteField::class:
                /* @var freeformfields\TextField $field */
                $newField = new formfields\SingleLineText();

                $this->_applyFieldDefaults($newField);
                break;

            default:
                return null;
        }

        if (!$newField->name) {
            $newField->name = $field->getLabel();
        }

        if (!$newField->handle) {
            $newField->handle = $field->getHandle();
        }

        $newField->instructions = $field->getInstructions();

        if (method_exists($field, 'getPlaceholder')) {
            $newField->placeholder = $field->getPlaceholder();
        }

        if (method_exists($field, 'getValue')) {
            $newField->defaultValue = $field->getValue();

            // Just use non-arrays for default values
            if (is_array($newField->defaultValue)) {
                $newField->defaultValue = null;
            }
        }

        if (!$newField instanceof formfields\Address and !$newField instanceof formfields\Name) {
            $newField->required = !!($field->isRequired() ?? false);
        }

        return $newField;
    }

    private function _applyFieldDefaults(FormFieldInterface &$field)
    {
        $defaults = $field->getAllFieldDefaults();
        Craft::configure($field, $defaults);
    }

    /**
     * @param Option[] $options
     * @return array
     */
    private function _mapOptions($options)
    {
        if (!$options) {
            return [];
        }

        return array_values(array_map(function ($option) {
            return [
                'isDefault' => $option->isChecked(),
                'label' => $option->getLabel(),
                'value' => $option->getValue(),
            ];
        }, $options));
    }

    private function _tokenizeNotificationBody($body)
    {
        $variables = Variables::getVariables();

        $tokens = preg_split('/(?<!{)({[a-zA-Z0-9 ]+?})(?!})/', $body, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
        $content = [];

        foreach ($tokens as $token) {
            if (preg_match('/^{(?P<handle>.+?)}$/', $token, $matches)) {
                $attrs = ArrayHelper::firstWhere($variables, 'value', $token);
                if (!$attrs && $field = $this->_form->getFieldByHandle(trim($matches['handle']))) {
                    $attrs = [
                        'label' => $field->name,
                        'value' => $token,
                    ];
                }

                if ($attrs) {
                    $content[] = [
                        'type' => 'variableTag',
                        'attrs' => $attrs,
                    ];
                } else {
                    $content[] = [
                        'type' => 'text',
                        'text' => $token,
                    ];
                }
            } else {
                $content[] = [
                    'type' => 'text',
                    'text' => $token,
                ];
            }
        }

        return [
            [
                'type' => 'paragraph',
                'content' => $content
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'variableTag',
                        'attrs' => [
                            'label' => Craft::t('formie', 'All Form Fields'),
                            'value' => '{allFields}',
                        ],
                    ]
                ]
            ],
        ];
    }

    private function stdout($string, $color = '')
    {
        $class = '';

        if ($color) {
            $class = 'color-' . $color;
        }

        echo '<div class="log-label ' . $class . '">' . Markdown::processParagraph($string) . '</div>';
    }

    private function getExceptionTraceAsString($exception) {
        $rtn = "";
        $count = 0;

        foreach ($exception->getTrace() as $frame) {
            $args = "";

            if (isset($frame['args'])) {
                $args = array();

                foreach ($frame['args'] as $arg) {
                    if (is_string($arg)) {
                        $args[] = "'" . $arg . "'";
                    } elseif (is_array($arg)) {
                        $args[] = "Array";
                    } elseif (is_null($arg)) {
                        $args[] = 'NULL';
                    } elseif (is_bool($arg)) {
                        $args[] = ($arg) ? "true" : "false";
                    } elseif (is_object($arg)) {
                        $args[] = get_class($arg);
                    } elseif (is_resource($arg)) {
                        $args[] = get_resource_type($arg);
                    } else {
                        $args[] = $arg;
                    }
                }

                $args = join(", ", $args);
            }

            $rtn .= sprintf( "#%s %s(%s): %s(%s)\n",
                                 $count,
                                 isset($frame['file']) ? $frame['file'] : '[internal function]',
                                 isset($frame['line']) ? $frame['line'] : '',
                                 (isset($frame['class']))  ? $frame['class'].$frame['type'].$frame['function'] : $frame['function'],
                                 $args );

            $count++;
        }

        return $rtn;
    }
}
