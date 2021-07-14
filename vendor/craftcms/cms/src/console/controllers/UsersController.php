<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Manages user accounts.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.6.0
 */
class UsersController extends Controller
{
    /**
     * @var string|null The user’s email
     * @since 3.7.0
     */
    public $email;

    /**
     * @var string|null The user’s username
     * @since 3.7.0
     */
    public $username;

    /**
     * @var string|null The user’s new password
     */
    public $password;

    /**
     * @var bool|null Whether the user should be an admin
     * @since 3.7.0
     */
    public $admin;

    /**
     * @var string[] The group handles to assign the created user to
     * @since 3.7.0
     */
    public $groups = [];

    /**
     * @var int[] The group IDs to assign the user to the created user to
     * @since 3.7.0
     */
    public $groupIds = [];

    /**
     * @var string|null The email or username of the user to inherit content when deleting a user
     * @since 3.7.0
     */
    public $inheritor;

    /**
     * @var bool Whether to delete the user’s content if no inheritor is specified
     * @since 3.7.0
     */
    public $deleteContent = false;

    /**
     * @var bool Whether the user should be hard-deleted immediately, instead of soft-deleted
     * @since 3.7.0
     */
    public $hard = false;

    /**
     * @inheritdoc
     */
    public function options($actionID)
    {
        $options = parent::options($actionID);

        switch ($actionID) {
            case 'create':
                $options[] = 'email';
                $options[] = 'username';
                $options[] = 'password';
                $options[] = 'admin';
                $options[] = 'groups';
                $options[] = 'groupIds';
                break;
            case 'delete':
                $options[] = 'inheritor';
                $options[] = 'deleteContent';
                $options[] = 'hard';
                break;
            case 'set-password':
                $options[] = 'password';
                break;
        }

        return $options;
    }

    /**
     * Lists admin users.
     *
     * @return int
     */
    public function actionListAdmins(): int
    {
        $users = User::find()
            ->admin()
            ->anyStatus()
            ->orderBy(['username' => SORT_ASC])
            ->all();
        $total = count($users);
        $generalConfig = Craft::$app->getConfig()->getGeneral();

        $this->stdout("$total admin " . ($total === 1 ? 'user' : 'users') . ' found:' . PHP_EOL, Console::FG_YELLOW);

        foreach ($users as $user) {
            $this->stdout('    - ');
            if ($generalConfig->useEmailAsUsername) {
                $this->stdout($user->email);
            } else {
                $this->stdout("$user->username ($user->email)");
            }
            switch ($user->getStatus()) {
                case User::STATUS_SUSPENDED:
                    $this->stdout(" [suspended]", Console::FG_RED);
                    break;
                case User::STATUS_ARCHIVED:
                    $this->stdout(" [archived]", Console::FG_RED);
                    break;
                case User::STATUS_PENDING:
                    $this->stdout(" [pending]", Console::FG_YELLOW);
                    break;
            }
            $this->stdout(PHP_EOL);
        }

        return ExitCode::OK;
    }

    /**
     * Creates a user.
     *
     * @return int
     * @since 3.7.0
     */
    public function actionCreate(): int
    {
        // Validate the arguments
        $attributesFromArgs = ArrayHelper::withoutValue([
            'email' => $this->email,
            'username' => $this->username,
            'newPassword' => $this->password,
            'admin' => $this->admin,
        ], null);

        $user = new User($attributesFromArgs);

        if (!$user->validate(array_keys($attributesFromArgs))) {
            $this->stderr('Invalid arguments:' . PHP_EOL . '    - ' . implode(PHP_EOL . '    - ', $user->getErrorSummary(true)) . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (Craft::$app->getConfig()->getGeneral()->useEmailAsUsername) {
            $user->username = $this->email ?: $this->prompt('Email:', [
                'required' => true,
                'validator' => $this->createAttributeValidator($user, 'email'),
            ]);
        } else {
            $user->email = $this->email ?: $this->prompt('Email:', [
                'required' => true,
                'validator' => $this->createAttributeValidator($user, 'email'),
            ]);
            $user->username = $this->username ?: $this->prompt('Username:', [
                'required' => true,
                'validator' => $this->createAttributeValidator($user, 'username'),
            ]);
        }

        $user->admin = $this->admin ?? $this->confirm('Make this user an admin?', false);

        if ($this->password) {
            $user->newPassword = $this->password;
        } else if ($this->interactive) {
            if ($this->confirm('Set a password for this user?', false)) {
                $user->newPassword = $this->passwordPrompt([
                    'validator' => $this->createAttributeValidator($user, 'newPassword'),
                ]);
            }
        }

        $this->stdout('Saving the user ... ');

        if (!Craft::$app->getElements()->saveElement($user)) {
            $this->stderr('failed:' . PHP_EOL . '    - ' . implode(PHP_EOL . '    - ', $user->getErrorSummary(true)) . PHP_EOL, Console::FG_RED);

            return ExitCode::USAGE;
        }

        $this->stdout('done' . PHP_EOL, Console::FG_GREEN);

        $groupIds = array_merge($this->groupIds, array_map(function($handle) {
            return Craft::$app->getUserGroups()->getGroupByHandle($handle)->id ?? null;
        }, $this->groups));

        if (!$groupIds) {
            return ExitCode::OK;
        }

        $this->stdout('Assigning user to groups ... ');

        // Most likely an invalid group ID will throw…
        try {
            Craft::$app->getUsers()->assignUserToGroups($user->id, $groupIds);
        } catch (\Throwable $e) {
            $this->stderr('failed: Couldn’t assign user to specified groups.' . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('done' . PHP_EOL, Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Deletes a user.
     *
     * @param string $usernameOrEmail The user’s username or email address
     * @return int
     */
    public function actionDelete(string $usernameOrEmail): int
    {
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($usernameOrEmail);

        if (!$user) {
            $this->stderr("No user exists with a username/email of “{$usernameOrEmail}”." . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->deleteContent && $this->inheritor) {
            $this->stdout('Only one of --delete-content or --inheritor may be specified.' . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!$this->inheritor && $this->confirm('Transfer this user’s content to an existing user?', true)) {
            $this->inheritor = $this->prompt('Enter the email or username of the user to inherit the content:', [
                'required' => true,
            ]);
        }

        if ($this->inheritor) {
            $inheritor = Craft::$app->getUsers()->getUserByUsernameOrEmail($this->inheritor);

            if (!$inheritor) {
                $this->stderr("No user exists with a username/email of “{$this->inheritor}”." . PHP_EOL, Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }

            if (!$this->confirm("Delete user “{$usernameOrEmail}” and transfer their content to user “{$this->inheritor}”?")) {
                $this->stdout('Aborting.' . PHP_EOL);
                return ExitCode::USAGE;
            }

            $user->inheritorOnDelete = $inheritor;
        } else if ($this->interactive) {
            $this->deleteContent = $this->confirm("Delete user “{$usernameOrEmail}” and their content?");

            if (!$this->deleteContent) {
                $this->stdout('Aborting.' . PHP_EOL);
                return ExitCode::USAGE;
            }
        }

        if (!$user->inheritorOnDelete && !$this->deleteContent) {
            $this->stdout('You must specify either --delete-content or --inheritor to proceed.' . PHP_EOL, Console::FG_RED);
            return ExitCode::USAGE;
        }

        $this->stdout('Deleting the user ... ');

        if (!Craft::$app->getElements()->deleteElement($user, $this->hard)) {
            $this->stderr('failed: Couldn’t delete the user.' . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('done' . PHP_EOL, Console::FG_GREEN);
        return ExitCode::OK;
    }

    /**
     * Changes a user’s password.
     *
     * @param string $usernameOrEmail The user’s username or email address
     * @return int
     */
    public function actionSetPassword(string $usernameOrEmail): int
    {
        $user = Craft::$app->getUsers()->getUserByUsernameOrEmail($usernameOrEmail);

        if (!$user) {
            $this->stderr("No user exists with a username/email of “{$usernameOrEmail}”." . PHP_EOL, Console::FG_RED);
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $user->setScenario(User::SCENARIO_PASSWORD);

        if ($this->password) {
            $user->newPassword = $this->password;
            if (!$user->validate()) {
                $this->stderr('Unable to set new password on user: ' . $user->getFirstError('newPassword') . PHP_EOL, Console::FG_RED);
                return ExitCode::UNSPECIFIED_ERROR;
            }
        } else if ($this->interactive) {
            $this->passwordPrompt([
                'validator' => $this->createAttributeValidator($user, 'newPassword'),
            ]);
        }

        $this->stdout('Saving the user ... ');
        Craft::$app->getElements()->saveElement($user, false);
        $this->stdout('done' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }
}
