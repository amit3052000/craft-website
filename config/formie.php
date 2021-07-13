<?php

return [
    '*' => [
        'pluginName' => 'Formie',
        'defaultPage' => 'forms',

        // Forms
        'defaultFormTemplate' => '',
        'defaultEmailTemplate' => '',
        'enableUnloadWarning' => true,
        'ajaxTimeout' => 10,

        // General Fields
        'defaultLabelPosition' => 'above-input',
        'defaultInstructionsPosition' => 'below-input',

        // Fields
        'defaultFileUploadVolume' => '',
        'defaultDateDisplayType' => '',
        'defaultDateValueOption' => '',
        'defaultDateTime' => '',

        // Submissions
        'maxIncompleteSubmissionAge' => 30,
        'useQueueForNotifications' => true,
        'useQueueForIntegrations' => true,

        // Sent Notifications
        'maxSentNotificationsAge' => 30,

        // Spam
        'saveSpam' => false,
        'spamLimit' => 500,
        'spamBehaviour' => 'showSuccess',
        'spamKeywords' => '',
        'spamBehaviourMessage' => '',

        // Alerts
        'sendEmailAlerts' => false,
        'alertEmails' => [],
    ]
];