<?php defined('APPLICATION') or die;

$PluginInfo['ReadOnly'] = array(
    'Name' => 'Read Only',
    'Description' => 'Let\'s you temporarely restrict some permissions.',
    'Version' => '0.3',
    'RequiredApplications' => array('Vanilla' => '>= 2.1'),
    'RequiredTheme' => false,
    'SettingsPermission' => array('Garden.Settings.Manage'),
    'SettingsUrl' => '/dashboard/settings/readonly',
    'MobileFriendly' => true,
    'HasLocale' => false,
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'http://vanillaforums.org/profile/44046/R_J',
    'License' => 'MIT'
);

/**
 * Vanilla Forums plugin that restricts permissions.
 *
 * Restrictions to restrict and the roles to apply that restrictions to
 * can be set in the settings screen.
 *
 * @package ReadOnly
 * @author Robin Jurinka
 * @license MIT
 */
class ReadOnlyPlugin extends Gdn_Plugin {
    /**
     * Set adding/editing content restriction for all user roles.
     *
     * @return void
     * @package ReadOnly
     * @since 0.1
     */
    public function setup() {
        if (!c('ReadOnly.Message')) {
            saveToConfig('ReadOnly.Message', 'Forum is in read only mode!');
        }
        // redirect('/dashboard/settings/readonly');
    }

    /**
     * Remove all config settings when plugin is disabled.
     *
     * @return void.
     * @package ReadOnly
     * @since 0.1
     */
    public function onDisable() {
        removeFromConfig('ReadOnly');
    }

    /**
     * Settings screen for role and restriction choice.
     *
     * @param object $sender SettingsController.
     * @return void.
     * @package ReadOnly
     * @since 0.1
     */
    public function settingsController_readOnly_create($sender) {
        // Define general settings properties.
        $sender->permission('Garden.Settings.Manage');
        $sender->addSideMenu('/dashboard/settings/plugins');
        $sender->setData('Title', t('ReadOnly Settings'));
        $sender->setData('Description', t(
            'ReadOnly Settings Description',
            'Choose which roles and actions should be restricted.<br/>You
            should inform your users about the read only state by '.anchor('adding a message', '/dashboard/message/add').' to the forum.'
        ));

        // Consolidate/prepare permissions.
        $permissionModel = Gdn::PermissionModel();
        $perms = $permissionModel->PermissionColumns();
        unset($perms['PermissionID']);

        $permissions = array();
        foreach ($perms as $key => $value) {
            $action = substr($key, strrpos($key, '.') + 1);
            $permissions[$action] .= $key.', ';
        }

        $permissionItems = array();
        foreach ($permissions as $key => $value) {
            $text = $key.'<span>'.trim($value, ', ').'</span>';
            $permissionItems[$text] = $key;
        }

        // Consolidate/prepare roles.
        $roleModel = new RoleModel();
        $roles = $roleModel->roles();
        $roleItems = array();
        foreach ($roles as $role) {
            $roleItems[$role['Name']] = $role['RoleID'];
        }

        // Build form info.
        $configurationModule = new configurationModule($sender);
        $configurationModule->initialize(array(
            'ReadOnly.Restrictions' => array(
                'Control' => 'CheckBoxList',
                'Description' => t('ReadOnly Settings Restrictions', 'Choose the actions that should be restricted. Below each action is a list of all the current permissions with that action."Add" and "Edit" is recommended.'),
                'Items' => $permissionItems,
                'LabelCode' => 'Restrictions'
            ),
            'ReadOnly.Roles' => array(
                'Control' => 'CheckBoxList',
                'Description' => t('Choose the roles that should <strong>not</strong> be restricted (Admin users will always have all permissions).'),
                'Items' => $roleItems,
                'LabelCode' => 'Roles'
            ),
            'ReadOnly.Message' => array(
                'Control' => 'TextBox',
                'LabelCode' => 'Message Text',
                'Description' => 'It is a good idea to '.anchor('inform your users', '/dashboard/message').' about the restrictions so that they now what\'s going on...',
                'Options' => array(
                    'MultiLine' => true
                )
            ),
            'ReadOnly.ShowAlert' => array(
                'Control' => 'Checkbox',
                'Description' => 'You can choose show or deactivate the message, however.',
                'LabelCode' => 'Show Message'
            )
        ));

        // Handle alert message.
        if ($sender->Request->isPostBack()) {
            $post = $sender->Request->getRequestArguments('post');

            $messageModel = new MessageModel();
            $messageID = c('ReadOnly.MessageID');
            $message = $messageModel->getID($messageID);

            if (!$post['ReadOnly-dot-Message']) {
                // Delete message when no text is given.
                if ($message) {
                    $messageModel->delete(array('MessageID' => $messageID));
                    removeFromConfig('ReadOnly.MessageID');
                }
            } else {
                // Check if message already exists.
                if ($message) {
                    // Set MessageID so that existing message gets updated
                    $formPostValues['MessageID'] = $messageID;
                }
                $formPostValues['Location'] = '[Base]';
                $formPostValues['AssetTarget'] = 'Content';
                $formPostValues['Content'] = $post['ReadOnly-dot-Message'];
                $formPostValues['CssClass'] = 'AlertMessage';
                $formPostValues['Enabled'] = $post['ReadOnly-dot-ShowAlert'];
                $formPostValues['AllowDismiss'] = false;
                $formPostValues['TransientKey'] = Gdn::session()->transientKey();
                saveToConfig(
                    'ReadOnly.MessageID',
                    $messageModel->save($formPostValues)
                );
            }
        }

        // Show form.
        $configurationModule->renderAll();
    }

    /**
     * Filters permission array based on config setting.
     *
     * @param object $sender Generally this is the UserModel.
     * @param mixed $args EventArguments, mainly the user.
     * @return void.
     * @package ReadOnly
     * @since 0.1
     */
    public function base_afterGetSession_handler($sender, $args) {
        // Admin user will never be restricted.
        if ($args['User']->Admin) {
            return;
        }

        $roles = c('ReadOnly.Roles');
        $roleModel = new RoleModel();
        $userRoles = $roleModel->GetByUserID($args['User']->UserID)->ResultArray();
        foreach ($userRoles as $userRole) {
            if (in_array($userRole, $roles)) {
                return;
            }
        }

        $restrictions = c('ReadOnly.Restrictions');
        // Go through all permissions of the session user.
        $permissions = $args['User']->Permissions;
        foreach ($permissions as $key => $permission) {
            // Split permission name in pieces.
            if (!is_array($permission)) {
                $suffix = substr($permission, strrpos($permission, '.') + 1);
            } else {
                $suffix = substr($key, strrpos($key, '.') + 1);
            }
            // Delete all restricted permissions.
            if (in_array($suffix, $restrictions)) {
                unset($permissions[$key]);
            }
        }
        // Overwrite the reduced permission array to the session user.
        $args['User']->Permissions = $permissions;
    }
}
