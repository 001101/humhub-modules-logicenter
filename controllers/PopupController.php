<?php

/**
 * Connected Communities Initiative
 * Copyright (C) 2016  Queensland University of Technology
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 *
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2015 HumHub GmbH & Co. KG
 * @license https://www.humhub.org/licences GNU AGPL v3
 *
 */

class PopupController extends CController
{

    public $layout = "application.modules_core.user.views.layouts.main_auth";
    public $subLayout = "application.modules_core.user.views.auth._layout";

    public function actionLogin()
    {
        // If user is already logged in, redirect him to the dashboard
        if (!Yii::app()->user->isGuest) {
            $this->redirect(Yii::app()->user->returnUrl);
        }

        // Show/Allow Anonymous Registration
        $canRegister = HSetting::Get('anonymousRegistration', 'authentication_internal');
        $model = new AccountLoginForm;

        //TODO: Solve this via events!
        if (Yii::app()->getModule('zsso') != null) {
            ZSsoModule::beforeActionLogin();
        }

        // if it is ajax validation request
        if (isset($_POST['ajax']) && $_POST['ajax'] === 'account-login-form') {
            echo CActiveForm::validate($model);
            Yii::app()->end();
        }

        // collect user input data
        if (isset($_POST['AccountLoginForm'])) {
            $model->attributes = $_POST['AccountLoginForm'];
            // validate user input and redirect to the previous page if valid
            if ($model->validate() && ($model->login() || $model->secondLogin())) {
                $user = User::model()->findByPk(Yii::app()->user->id);
                if (Yii::app()->request->isAjaxRequest) {
                    $this->htmlRedirect(Yii::app()->user->returnUrl);
                } else {
                    $this->redirect(Yii::app()->user->returnUrl);
                }
            }
        }
        // Always clear password
        $model->password = "";

        $registerModel = new CustomAccountRegisterForm;

        // Registration enabled?
        if ($canRegister) {
            // if it is ajax validation request
            if (Yii::app()->request->isAjaxRequest) {
                $registerModel->attributes = $_POST['CustomAccountRegisterForm'];
                $registerModel->validate();

                $logic = strtolower(HSetting::GetText("logic_enter"));
                $ifRegular = $this->ifRegular(explode("then", $logic)[0]);
                $domain = $this->returnEmail($ifRegular);

                if(!is_null($domain)) {

                    if(!preg_match("/^[\w\W]*[.](" . str_replace(" ","|", $domain) . ")$/", $_POST['CustomAccountRegisterForm']['email'])) {
                        $registerModel->addError("AccountRegisterForm_email", "email only: " . $domain);
                    }

                    if($registerModel->hasErrors()) {
                        echo json_encode(
                            [
                                'flag' => "error",
                                'errors' => $this->implodeAssocArray($registerModel->getErrors()),
                            ]
                        );
                        Yii::app()->end();
                    }

                    echo json_encode(
                        [
                            'flag' => "next"
                        ]
                    );
                } else {
                    if($registerModel->hasErrors()) {
                        echo json_encode(
                            [
                                'flag' => "error",
                                'errors' => $this->implodeAssocArray($registerModel->getErrors()),
                            ]
                        );
                        Yii::app()->end();
                    }

                    $usEmail = $_POST['CustomAccountRegisterForm']['email'];
                    $user = new User;
                    $user->username = $usEmail;
                    $user->email = $usEmail;
                    $user->save();


                    $userPassword = new UserPassword;
                    $userPassword->user_id = $user->id;
                    $userPassword->setPassword($usEmail);
                    $userPassword->save();

                    $model = new AccountLoginForm;
                    $model->username = $usEmail;
                    $model->password = $usEmail;

                    if ($model->login()) {
                        echo json_encode(
                            [
                                'flag' => 'redirect',
                                'location' => Yii::app()->createUrl("/"),
                            ]
                        );
                        Yii::app()->end();
                    }
                }
            }
        }

        $manageReg = new ManageRegistration;
        if (Yii::app()->request->isAjaxRequest) {
        } else {
            echo $this->render('login', array('model' => $model,
                'registerModel' => $registerModel,
                'canRegister' => $canRegister,
                'manageReg' => $manageReg)
            );
        }
    }

    protected function parseExpression($string)
    {
        $errors = [];
        $string = strtolower($string);
        $M_Reg = $_POST['ManageRegistration'];
        $string = preg_replace("/((&&|||) email_domain = [\'\"](.*?)[\'\"])/i", "", $string);
        preg_match_all("/(([a-z0-9_]*)[\s]{0,1}=[\s]{0,1}\"(.*?)\")/i", $string, $array, PREG_SET_ORDER);
        $return = $this->deleteZeroColumnInArray($array);

        foreach ($return as $item) {
            $expressionItem = trim($item[1]);
            $keyItem = trim($item[2]);
            $valueItem = trim($item[3]);

            if(isset($M_Reg[$keyItem]) && $keyItem != "email_domain" && $keyItem != "subject_area") {
                if(!in_array($M_Reg[$keyItem], explode(" ", $valueItem))) {
                    $errors[$keyItem] = $M_Reg[$keyItem] . " not in array " . '",["' . str_replace(' ', '","', $valueItem) . '"]';
                }
            }

            if(isset($M_Reg['subject_area']) && $keyItem == "subject_area") { // because it dependency and this array given
                foreach ($M_Reg['subject_area'] as $subjectItem) {
                    if(!in_array($subjectItem, explode(" ", $valueItem))) {
                        $errors['subject_area'][] = $subjectItem . ' not in ' . '["' . str_replace(' ', '","', $valueItem) . '"]';
                    }
                }
            }
        }

        return !empty($errors)?false:true;
    }


    public function actionSecondModal()
    {
        $this->validateRequredFields();
        $logic = strtolower(HSetting::GetText("logic_enter"));
        $logic_else = HSetting::GetText("logic_else");
        $ifRegular = $this->ifRegular(explode("then", $logic)[0]);
        $thenRegular = $this->thenRegular(explode("then", $logic)[1])[0][1];
        $if = '';
        $mailReg = '';

        $if = $this->parseExpression(explode("then", $logic)[0]);
        $domain = $this->returnEmail($ifRegular);
        if(!is_null($domain) && preg_match("/^[\w\W]*.(" . str_replace(" ","|", $mailReg) . ")$/", $_POST['email_domain'])) {

            $user = new User;
            $user->username = $_POST['email_domain'];
            $user->email = $_POST['email_domain'];
            $user->save();

            $userPassword = new UserPassword;
            $userPassword->user_id = $user->id;
            $userPassword->setPassword($_POST['email_domain']);
            $userPassword->save();

            if ($if) {
                $then = explode(",", $thenRegular);
                if(!empty($then)) {
                    foreach ($then as $circle) {
                        $space = Space::model()->findByAttributes(['name' => trim($circle)]);
                        if (!empty($space) && empty(SpaceMembership::model()->findAllByAttributes(['user_id' => $user->id, 'space_id' => $space->id]))) {
                            $newMemberSpace = new SpaceMembership;
                            $newMemberSpace->space_id = $space->id;
                            $newMemberSpace->user_id = $user->id;
                            $newMemberSpace->status = SpaceMembership::STATUS_MEMBER;
                            $newMemberSpace->save();
                        }
                    }
                }
            } else {
                $logic_else_string = explode(",", $logic_else);
                if(!empty($logic_else_string)) {
                    foreach ($logic_else_string as $circle) {
                        $space = Space::model()->findByAttributes(['name' => trim($circle)]);
                        if (!empty($space) && empty(SpaceMembership::model()->findAllByAttributes(['user_id' => $user->id, 'space_id' => $space->id]))) {
                            $newMemberSpace = new SpaceMembership;
                            $newMemberSpace->space_id = $space->id;
                            $newMemberSpace->user_id = $user->id;
                            $newMemberSpace->status = SpaceMembership::STATUS_MEMBER;
                            $newMemberSpace->save();
                        }
                    }
                }
            }

            $model = new AccountLoginForm;
            $model->username = $user->email;
            $model->password = $_POST['email_domain'];

            $this->addOthertoList();


            if ($model->validate() && $model->login()) {
                $profile = new Profile();
                $profile->user_id = Yii::app()->user->id;
                $profile->teacher_type = $_POST['ManageRegistration']['teacher_type'];
                $profile->save(false);

                echo json_encode(
                    [
                        'flag' => 'redirect'
                    ]
                );
                Yii::app()->end();
            }
        }

        echo json_encode(
            [
                'flag' => 'redirect',
            ]
        );
        Yii::app()->end();
    }

    protected function implodeAssocArray($array)
    {
        $string = "<div class='errorsSignup'>";
        if(is_array($array) && !empty(array_filter($array))) {
            foreach ($array as $key => $value) {
                foreach ($value as $item) {
                    $string.=  $item . "<br />";
                }
            }
        }
        $string.="</div>";

        return $string;
    }

    protected function addOthertoList()
    {
        $data = $_POST['ManageRegistration'];
        $typeRever = array_flip(ManageRegistration::$type);
        $dependTeacherTypeId = "";
        $existTeacherTypeId = '';
        if(!empty($data) && is_array($data)) {
            foreach ($data as $key => $value) {
                if (isset($typeRever[$key]) && !empty($value) && $key != "subject_area") {
                    $manageItem = ManageRegistration::model()->findAll('name="' . trim($value) . '"');
                    if (empty($manageItem)) {
                        $manage = new ManageRegistration;
                        $manage->name = trim($value);
                        $manage->type = $typeRever[$key];
                        $manage->default = ManageRegistration::DEFAULT_DEFAULT;
                        $manage->save();
                    }
                }

                if($key == "teacher_type") {
                    $existTeacherTypeId = ManageRegistration::model()->find('name="' . trim($value) . '"');
                    if(!empty($existTeacherTypeId)) {
                        $dependTeacherTypeId = $existTeacherTypeId->id;
                    }
                }

                if(isset($typeRever[$key]) && !empty($value) && $key == "subject_area" && !empty($dependTeacherTypeId)) {
                    foreach ($value as $itemSubject) {
                        if (empty($itemSubject) && strtolower($itemSubject) != "other") {
                            $manage2 = new ManageRegistration;
                            $manage2->name = trim($itemSubject);
                            $manage2->type = ManageRegistration::TYPE_SUBJECT_AREA;
                            $manage2->default = ManageRegistration::DEFAULT_DEFAULT;
                            $manage2->depend = $dependTeacherTypeId;
                            $manage2->save(false);
                        }
                    }
                }
            }
        }
    }

    public function validateRequredFields()
    {
        $required = HSetting::model()->findAll("name='required_manage'");
        $data = $_POST['ManageRegistration'];
        $errors = [];
        foreach ($required as $requiredItem) {
            if(!empty($requiredItem->value) && $requiredItem->value_text == 1 && isset($data[$requiredItem->value]) && empty($data[$requiredItem->value]))
            {
                $errors[] = $requiredItem->value . " is required";
            }

            if(!isset($data[$requiredItem->value]) && $requiredItem->value_text == 1) {
                $errors[] = $requiredItem->value . " is required";
            }
        }

        if(!empty($errors)) {
            echo json_encode(['flag' => true, 'errors' => '<div class="errorMessage">' . implode("<br>", $errors) . '</div>']);
            Yii::app()->end();
        }
    }

    protected function ifRegular($string)
    {
        $array= [];
        preg_match_all("/(and|IF|or)?(.*?)[\s]?=[\s]?['|\"](.*?)['|\"][\s]/i", $string, $array, PREG_SET_ORDER);
        return $this->deleteZeroColumnInArray($array);
    }

    protected function thenRegular($string)
    {
        $array= [];
        preg_match_all("/[\"|'](.*?)[\"|']/i", $string, $array, PREG_SET_ORDER);
        return $this->deleteZeroColumnInArray($array);
    }

    protected function deleteZeroColumnInArray($array)
    {
        $newArray = [];
        foreach ($array as $key => $value) {
            unset($value[0]);
            $newArray[] = $value;
        }

        return $newArray;
    }

    protected function returnEmail($array)
    {
        foreach ($array as $item) {
            if(trim($item[2]) == "email_domain")
            {
                return $item[3];
            }
        }

        return null;
    }

    public function actionGetDependTeacherType()
    {
        $name = trim($_POST['nameTeacherType']);
        $idByName = null;
        $list = '';
        $options = '';
        $i = 0;
        if(isset($_POST['type']) && $_POST['type'] == ManageRegistration::TYPE_TEACHER_TYPE && strtolower($_POST['nameTeacherType']) == "other") {
            $sql = 'SELECT t1.name FROM `manage_registration` t1 LEFT JOIN manage_registration t2 ON t1.depend = t2.id WHERE t1.type = 2 AND t2.name = "other"';
            $data = Yii::app()->db->createCommand($sql)->queryAll();
            $data = CHtml::listData($data, "name", "name");
            if (!empty($data)) {
                $list = $this->toUl($data);
                $options = $this->toOptions($data);
            } else {
                $list .= '<li data-original-index="' . $i . '"><a tabindex="' . $i . '" class="" style="" data-tokens="null"><span class="text">other</span><span class="glyphicon glyphicon-ok check-mark"></span></a></li>';
            }
        } else {
            $idByName = ManageRegistration::model()->find('name="' . $name . '" and type=' . ManageRegistration::TYPE_TEACHER_TYPE);
                if (!empty($idByName)) {
                    $list = $this->toUl(CHtml::listData(ManageRegistration::model()->findAll('name!="'.ManageRegistration::VAR_OTHER.'" AND depend=' . $idByName->id), 'name', 'name'));
                    $options = $this->toOptions(CHtml::listData(ManageRegistration::model()->findAll('name!="'.ManageRegistration::VAR_OTHER.'" AND depend=' . $idByName->id), 'name', 'name'));
                } else {
//                    $list .= '<li data-original-index="' . $i . '"><a tabindex="' . $i . '" class="" style="" data-tokens="null"><span class="text">other</span><span class="glyphicon glyphicon-ok check-mark"></span></a></li>';
                }
        }
        echo json_encode(['li' => $list, 'option' => $options]);
    }



    public function toOptions($array)
    {
        $options = '';
        foreach ($array as $option) {
            $options.="<option value='$option'>$option</option>";
        }

        if(LogicEntry::getStatusTypeManage(ManageRegistration::TYPE_SUBJECT_AREA)) {
            $options.="<option value='other'>other</option>";
        }
        $options.="<option value='other'>other</option>";

        return $options;
    }

    public function toUl($array)
    {
        $ul = '<li class="dropdown-header " data-optgroup="1"><span class="text">Select subject area(s)</span></li>';
        $i = 0;
        foreach ($array as $option) {
            $ul.='<li data-original-index="' . $i . '"><a tabindex="' . $i . '" class="" style="" data-tokens="null"><span class="text">' . $option . '</span><span class="glyphicon glyphicon-ok check-mark"></span></a></li>';
            $i++;
        }

        if(LogicEntry::getStatusTypeManage(ManageRegistration::TYPE_SUBJECT_AREA)) {
            $ul .= '<li data-original-index="' . ++$i . '"><a tabindex="' . ++$i . '" class="" style="" data-tokens="null"><span class="text">other</span><span class="glyphicon glyphicon-ok check-mark"></span></a></li>';
        }
        return $ul;
    }
}
