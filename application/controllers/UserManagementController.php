<?php


//LSYii_Controller
class UserManagementController extends LSMainController
{

    public function accessRules()
    {
        return array(
            array(
                'allow',
                'actions'=>array(),
                'users'=>array('*'), //everybody
            ),
            array(
                'allow',
                'actions'=>array('index', 'addEditUser', 'applyedit'),
                'users'=>array('@'), //only login users
            ),
            array('deny'),
        );
    }

    public function actionIndex(){

        if (!Permission::model()->hasGlobalPermission('users', 'read')) {
            return $this->renderPartial(
                '/admin/usermanagement/partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }
        if (isset($_GET['pageSize'])) {
            Yii::app()->user->setState('pageSize', Yii::app()->request->getParam('pageSize'));
        }
        App()->getClientScript()->registerPackage('usermanagement');
        App()->getClientScript()->registerPackage('bootstrap-select2');

        $aData = [];
        $model = new User('search');
        $model->setAttributes(Yii::app()->getRequest()->getParam('User'), false);
        $aData['model'] = $model;

        $aData['columnDefinition'] = $model->managementColums;
        $aData['pageSize'] = Yii::app()->user->getState('pageSize', Yii::app()->params['defaultPageSize']);
        $aData['formUrl'] = App()->createUrl('usermanagement/index');

        $aData['massiveAction'] = $this->renderPartial('massiveAction/_selector',
            [],
            true,
            false
            );

        //this is really important, so we have the aData also before rendering the content
        $this->aData = $aData;

        return $this->render('index', [
            'model' => $aData['model'],
            'columnDefinition' => $aData['columnDefinition'],
            'pageSize' => $aData['pageSize'],
            'formUrl' => $aData['formUrl'],
            'massiveAction' => $aData['massiveAction'],
        ]);
    }

    /**
     * Open modal to edit, or create a new user
     *
     * @param integer $userid
     * @return string
     * @throws CException
     */
    public function actionAddEditUser($userid = null){

        if (($userid === null && !Permission::model()->hasGlobalPermission('users', 'create'))
            || ($userid !== null && !Permission::model()->hasGlobalPermission('users', 'update'))){
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")]]
            );
        }
        $oUser = $userid === null ? new User() : User::model()->findByPk($userid);
        $randomPassword = $this->getRandomPassword();
        return $this->renderPartial('partial/addedituser', ['oUser' => $oUser, 'randomPassword' => $randomPassword]);
    }

    /**
     * Stores changes to user, or triggers userCreateEvent
     *
     * @return string | JSON
     * @throws CException
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function actionApplyedit()
    {
        if (!Permission::model()->hasGlobalPermission('users', 'update')) {
            return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', ["data" => [
                'success' => false,
                'errors' => gT("You do not have permission to access this page."),
            ]]);
        }

        $test = $this->createAbsoluteUrl("/admin");

        $aUser = Yii::app()->request->getParam('User');
        $passwordTest = Yii::app()->request->getParam('password_repeat', false);
        if (!empty($passwordTest)) {

            if ($passwordTest !== $aUser['password']) {
                return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', ["data" => [
                    'success' => false,
                    'errors' => gT('Passwords do not match'),
                ]]);
            }

            $oPasswordTestEvent = new PluginEvent('checkPasswordRequirement');
            $oPasswordTestEvent->set('password', $passwordTest);
            $oPasswordTestEvent->set('passwordOk', true);
            $oPasswordTestEvent->set('passwordError', '');
            Yii::app()->getPluginManager()->dispatchEvent($oPasswordTestEvent);

            if (!$oPasswordTestEvent->get('passwordOk')) {
                return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', ["data" => [
                    'success' => false,
                    'errors' => gT('Passwords does not fulfill minimum requirement:') . '<br/>' . $oPasswordTestEvent->get('passwordError'),
                ]]);
            }
        }

        if (isset($aUser['uid']) && $aUser['uid']) {

            $oUser = $this->updateAdminUser($aUser);
            if ($oUser->hasErrors()) {
                return App()->getController()->renderPartial('/admin/super/_renderJson', [
                    "data" => [
                        'success' => false,
                        'errors'  => $this->renderErrors($oUser->getErrors()) ?? ''
                    ]
                ]);
            }
            return App()->getController()->renderPartial('/admin/super/_renderJson', [
                'data' => [
                    'success' => true,
                    'message' => gT('User successfully updated')
                ]
            ]);
        } else {
            $this->createAdminUser($aUser);
        }
    }

    /**
     * Opens the modal to add dummy users
     *
     * @return string
     */
    public function actionAdddummyuser()
    {
        return $this->renderPartial('partial/adddummyuser', []);
    }

    /**
     * Creates a batch of dummy users
     *
     *
     * @return string | JSON
     */
    public function actionRunadddummyuser()
    {
        if (!Permission::model()->hasGlobalPermission('users', 'create')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }
        $times = App()->request->getParam('times', 5);
        $passwordsize = (int) App()->request->getParam('passwordsize', 5);
        $passwordsize = $passwordsize < 8 || is_nan($passwordsize) ? 8 : $passwordsize;
        $prefix = App()->request->getParam('prefix', 'randuser_');
        $email = App()->request->getParam('email', User::model()->findByPk(App()->user->id)->email);

        $randomUsers = [];

        for (; $times > 0; $times--) {
            $name = $this->getRandomUsername($prefix);
            $password = $this->getRandomPassword($passwordsize);
            $oUser = new User;
            $oUser->users_name = $name;
            $oUser->full_name = $name;
            $oUser->email = $email;
            $oUser->parent_id = App()->user->id;
            $oUser->created = date('Y-m-d H:i:s');
            $oUser->modified = date('Y-m-d H:i:s');
            $oUser->password = password_hash($password, PASSWORD_DEFAULT);
            $save = $oUser->save();
            $randomUsers[] = ['username' => $name, 'password' => $password, 'save' => $save];
        }

        return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', ["data" => [
            'success' => true,
            'html' => $this->renderPartial('partial/createdrandoms', ['randomUsers' => $randomUsers, 'filename' => $prefix], true),
        ]]);
    }

    /**
     * Deletes a user after  confirmation
     *
     * @return void|string
     */
    public function actionDeleteconfirm()
    {
        if (!Permission::model()->hasGlobalPermission('users', 'delete')) {
            return $this->renderPartial('partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }
        $userId = Yii::app()->request->getPost('userid');
        if ($userId == Yii::app()->user->id) {

            Yii::app()->setFlashMessage(gT("you cannot delete yourself."), 'error');
            $this->redirect('index');
        }

        $oUser = User::model()->findByPk($userId);
        $oUser->delete();
        Yii::app()->setFlashMessage(gT("User successfully deleted."), 'success');
        $this->redirect('index');
        return;
    }

    /**
     * Show some user detail and statistics
     *
     * @return string
     */
    public function actionViewuser($userid)
    {
        if (!Permission::model()->hasGlobalPermission('users', 'read')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }
        $oUser = User::model()->findByPk($userid);

        $usergroups = array_map(function ($oUGMap) {
            return $oUGMap->group->name;
        }, UserInGroup::model()->findAllByAttributes(['uid' => $oUser->uid]));

        return $this->renderPartial('partial/showuser', ['usergroups' => $usergroups, 'oUser' => $oUser]);
    }

    /**
     * Opens a modal to edit user permissions
     *
     * @return string
     */
    public function actionUserpermissions()
    {
        if (!Permission::model()->hasGlobalPermission('users', 'update')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }

        $oRequest = Yii::app()->request;
        $userId = $oRequest->getParam('userid');
        $oUser = User::model()->findByPk($userId);

        // Check permissions
        $aBasePermissions = Permission::model()->getGlobalBasePermissions();
        if (!Permission::model()->hasGlobalPermission('superadmin', 'read')) {
            // if not superadmin filter the available permissions as no admin may give more permissions than he owns
            Yii::app()->session['flashmessage'] = gT("Note: You can only give limited permissions to other users because your own permissions are limited, too.");
            $aFilteredPermissions = array();
            foreach ($aBasePermissions as $PermissionName => $aPermission) {
                foreach ($aPermission as $sPermissionKey => &$sPermissionValue) {
                    if ($sPermissionKey != 'title' && $sPermissionKey != 'img' &&
                        !Permission::model()->hasGlobalPermission($PermissionName, $sPermissionKey)) {
                        $sPermissionValue = false;
                    }
                }
                // Only show a row for that permission if there is at least one permission he may give to other users
                if ($aPermission['create'] || $aPermission['read'] || $aPermission['update']
                    || $aPermission['delete'] || $aPermission['import'] || $aPermission['export']) {
                    $aFilteredPermissions[$PermissionName] = $aPermission;
                }
            }
            $aBasePermissions = $aFilteredPermissions;
        }

        /**
         * todo REFACTORING could this be removed??
         */
        $aAllSurveys = Survey::model()->findAll();
        $aMySurveys = array_filter($aAllSurveys, function ($oSurvey) {
            if (Permission::model()->hasGlobalPermission('superadmin', 'read')) {
                return true;
            }
            if ($oSurvey->owner_id == App()->user->id) {
                return true;
            }
            return array_reduce($oSurvey->permissions, function ($coll, $oPermission) {
                if ($oPermission->permission == 'surveysecurity' && $oPermission->update_p == 1 && $oPermission->uid == App()->user->id) {
                    return true;
                }
                return $coll;
            }, false);
        });

        return $this->renderPartial(
            'partial/editpermissions',
            [
                "oUser" => $oUser,
                "aBasePermissions" => $aBasePermissions,
            ]
        );
    }

    /**
     * Stores the changed permissions
     *
     * @return string | JSON
     */
    public function actionSaveuserpermissions()
    {
        if (!Permission::model()->hasGlobalPermission('users', 'update')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }
        $userId = Yii::app()->request->getPost('userid');
        $aPermissions = Yii::app()->request->getPost('Permission', []);
        $results = $this->applyPermissionFromArray($userId, $aPermissions);

        $oUser = User::model()->findByPk($userId);
        $oUser->modified = date('Y-m-d H:i:s');
        $oUser->save();

        return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', [
            "data" => [
                'success' => true,
                'html'    => $this->renderPartial('partial/permissionsuccess', ['results' => $results], true),
            ]
        ]);
    }

    /**
     * Opens a modal to edit user template permissions
     *
     * @return string
     */
    public function actionUserTemplatePermissions()
    {
        if (!Permission::model()->hasGlobalPermission('users', 'update')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }
        $aTemplateModels = Template::model()->findAll();
        $oRequest = Yii::app()->request;
        $userId = $oRequest->getParam('userid');
        $oUser = User::model()->findByPk($userId);

        $aTemplates = array_map(function ($oTemplate) use ($userId) {
            $oPermission = Permission::model()->findByAttributes(array('permission' => $oTemplate->folder, 'uid' => $userId, 'entity' => 'template'));
            $aTemplate = $oTemplate->attributes;
            $aTemplate['value'] = $oPermission == null ? 0 : $oPermission->read_p;
            return $aTemplate;
        }, $aTemplateModels);

        return $this->renderPartial(
            'partial/edittemplatepermissions',
            [
                "oUser" => $oUser,
                "aTemplates" => $aTemplates,
            ]
        );
    }

    /**
     * Stores the changed permissions
     *
     * @return string | JSON
     */
    public function actionSaveThemePermissions()
    {
        if (!(Permission::model()->hasGlobalPermission('users', 'update') && Permission::model()->hasGlobalPermission('templates', 'update'))) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }

        $userId = Yii::app()->request->getPost('userid');
        $aTemplatePermissions = Yii::app()->request->getPost('TemplatePermissions', []);

        $results = Permission::editThemePermissionsUser($userId, $aTemplatePermissions);

        return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', [
            "data" => [
                'success' => true,
                'html'    => $this->renderPartial('partial/permissionsuccess', ['results' => $results], true),
            ]
        ]);
    }

    /**
     * Opens the modal to add dummy users
     *
     * @return string
     */
    public function actionAddRole()
    {
        $userId = Yii::app()->request->getParam('userid');
        $oUser = User::model()->findByPk($userId);
        $aPermissiontemplates = Permissiontemplates::model()->findAll();
        $aPossibleRoles = [];
        array_walk(
            $aPermissiontemplates,
            function ($oPermissionRole) use (&$aPossibleRoles) {
                $aPossibleRoles[$oPermissionRole->ptid] = $oPermissionRole->name;
            }
        );
        $aCurrentRoles = array_map(function ($oRole) {
            return $oRole->ptid;
        }, $oUser->roles);

        return $this->renderPartial(
            'partial/addrole',
            [
                'oUser' => $oUser,
                'aPossibleRoles' => $aPossibleRoles,
                'aCurrentRoles' => $aCurrentRoles,
            ]
        );
    }

    /**
     * Save role of user
     *
     * @return string
     */
    public function actionSaveRole()
    {
        if (!Permission::model()->hasGlobalPermission('users', 'update')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }
        $iUserId = Yii::app()->request->getPost('userid');
        $aUserRoleIds = Yii::app()->request->getPost('roleselector', []);
        $results = [];

        $clearUser = Permissiontemplates::model()->clearUser($iUserId);
        foreach ($aUserRoleIds as $iUserRoleId) {
            if ($iUserRoleId == '') {
                continue;
            }
            $results[$iUserRoleId] = Permissiontemplates::model()->applyToUser($iUserId, $iUserRoleId);
        }
        if (empty($aUserRoleIds)) {
            $results['clear'] = $clearUser;
        }
        return $this->renderPartial('partial/json', [
            "data" => [
                'success' => true,
                'html'    => $this->renderPartial('partial/permissionsuccess', ['results' => $results], true),
            ]
        ]);
    }

    /**
     * Calls up a modal to import users via csv/json file
     *
     *@param string $importFormat - Importformat (csv/json) to render
     *@return string
     */
    public function actionRenderUserImport(string $importFormat = 'csv')
    {
        if (!Permission::model()->hasGlobalPermission('users', 'create')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }

        $importNote = sprintf(gT("Please make sure that your CSV contains the columns '%s' as well as '%s' , '%s' , '%s' and  '%s'"), '<b>users_name</b>', '<b>full_name</b>', '<b>email</b>', '<b>lang</b>', '<b>password</b>');
        $allowFileType = ".csv";

        if ($importFormat == 'json') {
            $importNote = sprintf(gT("Please make sure that your JSON Arrays contains the offsets '%s' as well as '%s' , '%s' , '%s' and  '%s'"), '<b>users_name</b>', '<b>full_name</b>', '<b>email</b>', '<b>lang</b>', '<b>password</b>');
            $allowFileType = ".json,application/json";
        }

        return $this->renderPartial('partial/importuser', [
            "note"         => $importNote,
            "importFormat" => $importFormat,
            "allowFile"    => $allowFileType
        ]);
    }

    /**
     * Creates users from an uploaded CSV / JSON file
     *
     * @param string importFormat - format of the imported file - Choice between csv / json
     * @return string
     */
    public function actionImportUsers(string $importFormat = 'csv')
    {
        if (!Permission::model()->hasGlobalPermission('users', 'create')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }

        $overwriteUsers = false;

        if (isset($_POST['overwrite'])) {
            $overwriteUsers = true;
        }

        switch ($importFormat) {
            case "csv":
                $aNewUsers = UserParser::getDataFromCSV($_FILES);
                break;
            case "json":
                $aNewUsers = UserParser::getDataFromJSON($_FILES);
                break;
        }

        $created = [];
        $updated = [];

        foreach ($aNewUsers as $aNewUser) {

            $oUser = User::model()->findByAttributes(['users_name' => $aNewUser['users_name']]);

            if ($oUser  !== null) {
                if ($overwriteUsers) {

                    $oUser->full_name = $aNewUser['full_name'];
                    $oUser->email = $aNewUser['email'];
                    $oUser->parent_id = App()->user->id;
                    $oUser->modified = date('Y-m-d H:i:s');
                    if ($aNewUser['password'] != ' ') {
                        $oUser->password = password_hash($aNewUser['password'], PASSWORD_DEFAULT);
                    }

                    $save = $oUser->save();
                    if ($save) {
                        $updated[] = [
                            'username' => $aNewUser['users_name'],
                            'full_name' => $aNewUser['full_name'],
                            'email' => $aNewUser['email'],
                        ];
                    }
                }
            } else {

                $password = $this->getRandomPassword(8);
                $passwordText = $password;
                if ($aNewUser['password'] != ' ') {
                    $password = password_hash($aNewUser['password'], PASSWORD_DEFAULT);
                }

                $save = $this->_createNewUser([
                    'users_name' => $aNewUser['users_name'],
                    'full_name' => $aNewUser['full_name'],
                    'password' => $password,
                    'email' => $aNewUser['email'],
                    'lang' => $aNewUser['lang'],
                ], false);

                if ($save) {
                    $created[] = [
                        'username' => $aNewUser['users_name'],
                        'full_name' => $aNewUser['full_name'],
                        'email' => $aNewUser['email'],
                        'password' => $passwordText,
                    ];
                }
            }
        }

        Yii::app()->setFlashMessage(gT("Users imported successfully."), 'success');
        $this->redirect('index');
    }


    /**
     * Export users with specific format (json or csv)
     * @param string $outputFormat json or csv
     * @param int $uid userId
     * @return mixed
     */
    public function actionExportUser(string $outputFormat, int $uid = 0)
    {
        //Check if user has permissions to export users
        if (!Permission::model()->hasGlobalPermission('users', 'export')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }

        if ($uid > 0) {
            $oUsers = User::model()->findByPk($uid);
        } else {
            $oUsers = User::model()->findAll();
        }

        $aUsers = array();
        $sTempDir = Yii::app()->getConfig("tempdir");
        $exportFile = $sTempDir . DIRECTORY_SEPARATOR . 'users_export.' . $outputFormat;

        foreach ($oUsers as $user) {
            $exportUser['uid'] = $user->attributes['uid'];
            $exportUser['users_name'] = $user->attributes['users_name'];
            $exportUser['full_name'] = $user->attributes['full_name'];
            $exportUser['email'] = $user->attributes['email'];
            $exportUser['lang'] = $user->attributes['lang'];
            $exportUser['password'] = '';
            array_push($aUsers, $exportUser);
        }

        switch ($outputFormat) {
            case "json":
                $json = json_encode($aUsers, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                $fp = fopen($exportFile, 'w');
                fwrite($fp, $json);
                fclose($fp);
                header('Content-Encoding: UTF-8');
                header("Content-Type:application/json; charset=UTF-8");
                break;

            case "csv":
                $fp = fopen($exportFile, 'w');

                //Add utf-8 encoding
                fprintf($fp, chr(0xEF) . chr(0xBB) . chr(0xBF));
                $header = array('uid', 'users_name', 'full_name', 'email', 'lang', 'password');
                //Add csv header
                fputcsv($fp, $header, ';');

                //add csv row datas
                foreach ($aUsers as $fields) {
                    fputcsv($fp, $fields, ';');
                }
                fclose($fp);
                header('Content-Encoding: UTF-8');
                header("Content-type: text/csv; charset=UTF-8");
                break;
        }
        //end file to download
        header("Content-Disposition: attachment; filename=userExport." . $outputFormat);
        header("Pragma: no-cache");
        header("Expires: 0");
        @readfile($exportFile);
        unlink($exportFile);
    }

    /**
     * Delete multiple users selected by massive action
     * @return void|string
     */
    public function actionDeleteMultiple()
    {
        if (!Permission::model()->hasGlobalPermission('users', 'delete')) {
            return $this->renderPartial(
                'partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }

        $aUsers = json_decode(App()->request->getPost('sItems'));
        $aResults = [];

        foreach ($aUsers as $user) {
            $aResults[$user]['title'] = '';
            $model = $this->loadModel($user);
            $aResults[$user]['title'] = $model->users_name;
            $aResults[$user]['result'] = $this->deleteUser($user);
            if (!$aResults[$user]['result'] && $user == Yii::app()->user->id) {
                $aResults[$user]['error'] = gT("You cannot delete yourself.");
            }
        }

        $tableLabels = array(gT('User id'), gT('Username'), gT('Status'));

        Yii::app()->getController()->renderPartial(
            'ext.admin.survey.ListSurveysWidget.views.massive_actions._action_results',
            array(
                'aResults'     => $aResults,
                'successLabel' => gT('Deleted'),
                'tableLabels' =>  $tableLabels
            )
        );
    }

    /**
     * render selected items for massive action modal
     *
     * @return void
     * @throws CHttpException
     * @throws CException
     */

    public function actionRenderSelectedItems()
    {

        $aUsers = json_decode(App()->request->getPost('$oCheckedItems'));
        $aResults = [];
        $gridid = App()->request->getParam('$grididvalue');

        foreach ($aUsers as $user) {
            $aResults[$user]['title'] = '';
            $model = $this->loadModel($user);

            if ($gridid == 'usermanagement--identity-gridPanel') {
                $aResults[$user]['title'] = $model->users_name;
            }

            $aResults[$user]['result'] = gT('Selected');
        }
        //set Modal table labels
        $tableLabels = array(gT('User id'), gT('Username'), gT('Status'));

        App()->getController()->renderPartial(
            'ext.admin.grid.MassiveActionsWidget.views._selected_items',
            array(
                'aResults'     => $aResults,
                'successLabel' => gT('Seleted'),
                'tableLabels'  => $tableLabels,
            )
        );
    }

    /**
     * Deletes a user
     *
     * @param int $uid
     * @param bool $recursive
     * @return boolean
     */
    public function deleteUser(int $uid, bool $recursive = true)
    {
        if (!Permission::model()->hasGlobalPermission('users', 'delete')) {
            return $this->getController()->renderPartial(
                '/admin/usermanagement/partial/error',
                ['errors' => [gT("You do not have permission to access this page.")], 'noButton' => true]
            );
        }

        if ($uid == Yii::app()->user->id) {
            return false;
        } else {
            $oUser = User::model()->findByPk($uid);
            return $oUser->delete($recursive);
        }
    }

    /**
     * Returns the data model based on the primary key given in the GET variable.
     * If the data model is not found, an HTTP exception will be raised.
     *
     * @param integer $id the ID of the model to be loaded
     *
     * @return User|null  object
     * @throws CHttpException
     */
    public function loadModel($id)
    {

        $model = User::model()->findByPk($id);

        if ($model === null) {
            throw new CHttpException(404, 'The requested page does not exist.');
        }

        return $model;
    }

    /**
     * Creates a random password through the core plugin
     *
     * @param integer $length Length of the password
     * @return string
     */
    protected function getRandomPassword($length = 8)
    {
        $oGetPasswordEvent = new PluginEvent('createRandomPassword');
        $oGetPasswordEvent->set('targetSize', $length);
        Yii::app()->getPluginManager()->dispatchEvent($oGetPasswordEvent);

        return $oGetPasswordEvent->get('password');
    }

    /**
     * Update admin-user
     *
     * @param array $aUser array with user details
     * @return object user - updated user object
     * @throws CException
     */
    public function updateAdminUser($aUser)
    {
        $oUser = User::model()->findByPk($aUser['uid']);
        //If the user id of the post is spoofed somehow it would be possible to edit superadmin users
        //Therefore we need to make sure no non-superadmin can modify superadmin accounts
        //Since this should NEVER be the case without hacking the software, this will silently just do nothing.
        if (
            !Permission::model()->hasGlobalPermission('superadmin', 'read', Yii::app()->user->id)
            && Permission::model()->hasGlobalPermission('superadmin', 'read', $oUser->uid)
        ) {
            throw new CException("This action is not allowed, and should never happen", 500);
        }

        $oUser->setAttributes($aUser);

        if (isset($aUser['password']) && $aUser['password']) {
            $oUser->password = password_hash($aUser['password'], PASSWORD_DEFAULT);
        }
        $oUser->modified = date('Y-m-d H:i:s');
        $oUser->save();

        return  $oUser;
    }

    /**
     * this method creates a new admin user
     *
     * @param array a$user
     * @return string
     * @throws CException
     * @throws \PHPMailer\PHPMailer\Exception
     */
    private function createAdminUser($aUser)
    {
        if (!isset($aUser['uid']) || $aUser['uid'] == null) {
            $sendMail = (bool) Yii::app()->request->getPost('preset_password', false);
            $newUser = $this->_createNewUser($aUser);
            $sReturnMessage = gT('User successfully created');
            $success = true;

            if ($sendMail) {
                $mailer = $this->_sendAdminMail($aUser,'registration');

                if ($mailer->getError()) {
                    $sReturnMessage = CHtml::tag("h4", array(), gT("Error"));
                    $sReturnMessage .= CHtml::tag("p", array(), sprintf(gT("Email to %s (%s) failed."), "<strong>" . $newUser['users_name'] . "</strong>", $newUser['email']));
                    $sReturnMessage .= CHtml::tag("p", array(), $mailer->getError());
                    $success = false;
                } else {
                    // has to be sent again or no other way
                    $sReturnMessage = CHtml::tag("h4", array(), gT("Success"));;
                    $sReturnMessage .= CHtml::tag("p", array(), sprintf(gT("Username : %s - Email : %s."), $newUser['users_name'], $newUser['email']));
                    $sReturnMessage .= CHtml::tag("p", array(), gT("An email with a generated password was sent to the user."));
                }
            }

            $display_user_password_in_html = Yii::app()->getConfig("display_user_password_in_html");
            $sReturnMessage .= $display_user_password_in_html ? CHtml::tag("p", array('class' => 'alert alert-danger'), 'New password set: <b>' . $aUser['password'] . '</b>') : '';

            $data = array();

            if ($success) {
                $data = [
                    'success' => $success,
                    'message' => $sReturnMessage
                ];
            } else {
                $data = [
                    'success' => $success,
                    'errors' => $sReturnMessage
                ];
            }

            return App()->getController()->renderPartial('/admin/super/_renderJson', [
                "data" => $data
            ]);
        }
    }

    /**
     * Create new user
     *
     * @param array $aUser array with user details
     * @param bool $sendMail - option to send mail to user when created
     * @return array returns all attributes from model user as an array
     * @throws CException
     */
    public function _createNewUser($aUser, $sendMail = true)
    {
        if (!Permission::model()->hasGlobalPermission('users', 'create')) {
            return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', [
                "data" => [
                    'success' => false,
                    'errors'  => gT("You do not have permissionfor this action."),
                ]
            ]);
        }

        $aUser['users_name'] = flattenText($aUser['users_name']);

        if (empty($aUser['users_name'])) {
            return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', [
                "data" => [
                    'success' => false,
                    'errors'  => gT("A username was not supplied or the username is invalid."),
                ]
            ]);
        }

        if (User::model()->find("users_name=:users_name", array(':users_name' => $aUser['users_name']))) {
            return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', [
                "data" => [
                    'success' => false,
                    'errors'  => gT("A user with this username already exists."),
                ]
            ]);
        }

        $event = new PluginEvent('createNewUser');
        $event->set('errorCode', AuthPluginBase::ERROR_NOT_ADDED);
        $event->set('errorMessageTitle', gT("Failed to add user"));
        $event->set('errorMessageBody', gT("Plugin is not active"));
        $event->set('preCollectedUserArray', $aUser);

        Yii::app()->getPluginManager()->dispatchEvent($event);

        if ($event->get('errorCode') != AuthPluginBase::ERROR_NONE) {
            return Yii::app()->getController()->renderPartial('/admin/super/_renderJson', [
                "data" => [
                    'success' => false,
                    'errors'  => $event->get('errorMessageTitle') . '<br/>' . $event->get('errorMessageBody'),
                    'debug'   => ['title' => $event->get('errorMessageTitle'), 'body' => $event->get('errorMessageBody'), 'code' => $event->get('errorCode'), 'event' => $event],
                ]
            ]);
        }
        $iNewUID = $event->get('newUserID');
        // add default template to template rights for user
        Permission::model()->insertSomeRecords(array('uid' => $iNewUID, 'permission' => getGlobalSetting('defaulttheme'), 'entity' => 'template', 'read_p' => 1, 'entity_id' => 0));
        // add default usersettings to the user
        SettingsUser::applyBaseSettings($iNewUID);

        return User::model()->findByPk($iNewUID)->attributes;
    }

    /**
     * Send the registration email to a new survey administrator
     * @TODO: make this user configurable by TWIG, or similar
     *
     * @param string $type   two types are available 'resetPassword' or 'registration', default is 'registration'
     * @param array $aUser
     * @param null $newPassword
     * @return LimeMailer if send is successfull
     * @throws CException
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function _sendAdminMail($aUser, $type = 'registration', $newPassword = null)
    {

        switch ($type) {
            case "resetPassword":
                $renderArray = [
                    'surveyapplicationname' => Yii::app()->getConfig("sitename"),
                    'emailMessage' => sprintf(gT("Hello %s,"), $aUser['full_name']) . "<br />"
                        . sprintf(gT("this is an automated email to notify that your login credentials for '%s' have been reset."), Yii::app()->getConfig("sitename")),
                    'credentialsText' => gT("Here are you're new credentials."),
                    'siteadminemail' => Yii::app()->getConfig("siteadminemail"),
                    'linkToAdminpanel' => $this->createAbsoluteUrl("/admin"),
                    'username' => $aUser['users_name'],
                    'password' => $aUser['rawPassword'],
                    'mainLogoFile' => LOGO_URL,
                    'showPasswordSection' => Yii::app()->getConfig("auth_webserver") === false && Permission::model()->hasGlobalPermission('auth_db', 'read', $aUser['uid']),
                    'showPassword' => (Yii::app()->getConfig("display_user_password_in_email") === true),
                ];
                $subject = "[" . Yii::app()->getConfig("sitename") . "] " . gT("Your login credentials have been reset");
                $emailType = "addadminuser";
                break;
            case 'registration':
            default:
                $renderArray = [
                    'surveyapplicationname' => Yii::app()->getConfig("sitename"),
                    'emailMessage' => sprintf(gT("Hello %s,"), $aUser['full_name']) . "<br />"
                        . sprintf(gT("this is an automated email to notify that a user has been created for you on the site '%s'.."), Yii::app()->getConfig("sitename")),
                    'credentialsText' => gT("You can use now the following credentials to log into the site:"),
                    'siteadminemail' => Yii::app()->getConfig("siteadminemail"),
                    'linkToAdminpanel' => $this->createAbsoluteUrl("/admin"),
                    'username' => $aUser['users_name'],
                    'password' => $aUser['password'],
                    'mainLogoFile' => LOGO_URL,
                    'showPasswordSection' => Yii::app()->getConfig("auth_webserver") === false && Permission::model()->hasGlobalPermission('auth_db', 'read', $aUser['uid']),
                    'showPassword' => (Yii::app()->getConfig("display_user_password_in_email") === true),
                ];
                $subject = "[" . Yii::app()->getConfig("sitename") . "] " . gT("An account has been created for you");
                $emailType = "addadminuser";
                break;
        }

        $body = Yii::app()->getController()->renderPartial('/admin/usermanagement/partial/usernotificationemail', $renderArray, true);

        $oCurrentlyLoggedInUser = User::model()->findByPk(Yii::app()->user->id);

        $mailer = new LimeMailer;
        $mailer->addAddress($aUser['email'], $aUser['full_name']);
        $mailer->Subject = $subject;
        $mailer->setFrom($oCurrentlyLoggedInUser->email, $oCurrentlyLoggedInUser->users_name);
        $mailer->Body = $body;
        $mailer->isHtml(true);
        $mailer->emailType = $emailType;
        $mailer->sendMessage();
        return $mailer;
    }

    /**
     * Resets the password for one user
     *
     * @param User $oUser User model
     * @param boolean $sendMail Send a mail to the user
     * @return array [success, uid, username, password]
     * @throws CException
     * @throws \PHPMailer\PHPMailer\Exception
     */
    public function resetLoginData(&$oUser, $sendMail = false)
    {
        $newPassword = $this->getRandomPassword(8);
        $oUser->setPassword($newPassword);
        $success = true;
        if ($sendMail == true) {
            $aUser = $oUser->attributes;
            $aUser['rawPassword'] = $newPassword;
            $success = $this->_sendAdminMail($aUser, 'resetPassword', $newPassword);
        }
        return [
            'success' => $success, 'uid' => $oUser->uid, 'username' => $oUser->users_name, 'password' => $newPassword,
        ];
    }

    /**
     * todo this should not be in a controller, find a better place for it (view)
     *
     *
     * @param array $errors
     *
     * @return string $errorDiv
     */
    private function renderErrors($errors)
    {
        $errorDiv = '<ul class="list-unstyled">';
        foreach ($errors as $key => $error) {
            foreach ($error as $errormessages) {
                $errorDiv .= '<li>' . print_r($errormessages, true) . '</li>';
            }
        }
        $errorDiv .= '</ul>';
        return (string) $errorDiv;
    }

    /**
     * Creates a random unique username using prefix
     *
     * todo this should be moved to model user ...
     *
     * @param string $prefix the prefix to be used
     * @return string
     */
    protected function getRandomUsername($prefix)
    {
        do {
            $rand = $this->getRandomString();
            $username = $prefix . '_' . substr($rand, rand(0, strlen($rand) - 6), 4);
            $oUser = User::model()->findByAttributes(['users_name' => $username]);
        } while ($oUser != null);
        return $username;
    }

    /**
     * Creates a random string
     *
     * todo this should be moved to model user ...see getRandomUsername
     *
     * @return string
     */
    protected function getRandomString()
    {
        if (is_callable('openssl_random_pseudo_bytes')) {
            $uiq = openssl_random_pseudo_bytes(128);
        } else {
            $uiq = decbin(rand(1000000, 9999999) * (rand(100, 999) . rand(100, 999) . rand(100, 999) . rand(100, 999)));
        }
        return hash('sha256', bin2hex($uiq));
    }

    /**
     * Adds permission to a users
     * Needs an array in the form of [PERMISSIONID][PERMISSION]
     *
     * todo REFACTORING this should be moved to model (user or permission)
     *
     * @param int $iUserId
     * @param array $aPermissionArray
     * @return array
     */
    protected function applyPermissionFromArray($iUserId, $aPermissionArray)
    {
        $oCriteria = new CDbCriteria();
        $oCriteria->compare('uid', $iUserId);
        $oCriteria->compare('entity_id', 0);
        //Kill all Permissions without entity.
        $aPermissionsCurrently = Permission::model()->deleteAll($oCriteria);
        $results = [];
        //Apply the permission array
        foreach ($aPermissionArray as $sPermissionKey => $aPermissionSettings) {
            $oPermission = new Permission();
            $oPermission->entity = 'global';
            $oPermission->entity_id = 0;
            $oPermission->uid = $iUserId;
            $oPermission->permission = $sPermissionKey;

            foreach ($aPermissionSettings as $sSettingKey => $sSettingValue) {
                $oPermissionDBSettingKey = $sSettingKey . '_p';
                $oPermission->$oPermissionDBSettingKey = $sSettingValue == 'on' ? 1 : 0;
            }

            $aPermissionData = Permission::getGlobalPermissionData($sPermissionKey);

            $results[$sPermissionKey] = [
                'descriptionData' => $aPermissionData,
                'success' => $oPermission->save(),
                'storedValue' => $oPermission->attributes
            ];
        }
        return $results;
    }
}