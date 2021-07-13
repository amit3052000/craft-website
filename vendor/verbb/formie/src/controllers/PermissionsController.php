<?php
namespace verbb\formie\controllers;

use verbb\formie\Formie;
use verbb\formie\models\Settings;

use craft\web\Controller;

use yii\web\Response;

class PermissionsController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionIndex(): Response
    {
        return $this->renderTemplate('formie/settings/permissions', []);
    }

}
