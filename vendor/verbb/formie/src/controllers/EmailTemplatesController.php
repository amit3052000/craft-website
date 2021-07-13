<?php
namespace verbb\formie\controllers;

use Craft;
use craft\errors\MissingComponentException;
use craft\web\Controller;
use craft\helpers\Json;

use yii\base\ErrorException;
use yii\base\Exception;
use yii\base\NotSupportedException;
use yii\web\BadRequestHttpException;
use yii\web\ServerErrorHttpException;
use yii\web\HttpException;
use yii\web\Response;

use Throwable;

use verbb\formie\Formie;
use verbb\formie\helpers\FileHelper;
use verbb\formie\models\EmailTemplate;

class EmailTemplatesController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @return Response
     */
    public function actionIndex(): Response
    {
        $emailTemplates = Formie::$plugin->getEmailTemplates()->getAllTemplates();

        return $this->renderTemplate('formie/settings/email-templates', compact('emailTemplates'));
    }

    /**
     * @param int|null $id
     * @param EmailTemplate|null $template
     * @return Response
     * @throws HttpException
     */
    public function actionEdit(int $id = null, EmailTemplate $template = null): Response
    {
        $variables = compact('id', 'template');

        if (!$variables['template']) {
            if ($variables['id']) {
                $variables['template'] = Formie::$plugin->getEmailTemplates()->getTemplateById($variables['id']);

                if (!$variables['template']) {
                    throw new HttpException(404);
                }
            } else {
                $variables['template'] = new EmailTemplate();
            }
        }

        if ($variables['template']->id) {
            $variables['title'] = $variables['template']->name;
        } else {
            $variables['title'] = Craft::t('formie', 'Create a new template');
        }

        return $this->renderTemplate('formie/settings/email-templates/_edit', $variables);
    }

    /**
     * @throws MissingComponentException
     * @throws ErrorException
     * @throws Exception
     * @throws NotSupportedException
     * @throws BadRequestHttpException
     * @throws ServerErrorHttpException
     */
    public function actionSave()
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();

        $id = $request->getBodyParam('id');
        $template = Formie::$plugin->getEmailTemplates()->getTemplateById($id);

        if (!$template) {
            $template = new EmailTemplate();
        }

        $template->name = $request->getBodyParam('name');
        $template->handle = $request->getBodyParam('handle');
        $template->template = preg_replace('/\/index(?:\.html|\.twig)?$/', '', $request->getBodyParam('template'));
        $template->copyTemplates = $request->getBodyParam('copyTemplates', false);

        // Save it
        if (Formie::$plugin->getEmailTemplates()->saveTemplate($template)) {
            if ($template->copyTemplates) {
                FileHelper::copyTemplateDirectory('@verbb/formie/templates/_special/email-template', $template->template);
            }

            Craft::$app->getSession()->setNotice(Craft::t('formie', 'Template saved.'));
            $this->redirectToPostedUrl($template);
        } else {
            Craft::$app->getSession()->setError(Craft::t('formie', 'Couldn’t save template.'));
        }

        Craft::$app->getUrlManager()->setRouteParams(compact('template'));
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws ErrorException
     * @throws Exception
     * @throws NotSupportedException
     * @throws ServerErrorHttpException
     */
    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $ids = Json::decode(Craft::$app->getRequest()->getRequiredBodyParam('ids'));

        if ($success = Formie::$plugin->getEmailTemplates()->reorderTemplates($ids)) {
            return $this->asJson(['success' => $success]);
        }

        return $this->asJson(['error' => Craft::t('formie', 'Couldn’t reorder templates.')]);
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws Throwable
     */
    public function actionDelete()
    {
        $this->requireAcceptsJson();

        $templateId = Craft::$app->getRequest()->getRequiredParam('id');

        if (Formie::$plugin->getEmailTemplates()->deleteTemplateById((int)$templateId)) {
            return $this->asJson(['success' => true]);
        }

        return $this->asJson(['error' => Craft::t('formie', 'Couldn’t archive template.')]);
    }
}
