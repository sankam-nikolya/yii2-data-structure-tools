<?php

namespace DevGroup\DataStructure\Properties\actions;

use DevGroup\AdminUtils\events\ModelEditAction;
use DevGroup\DataStructure\models\Property;
use DevGroup\DataStructure\models\PropertyGroup;
use Yii;
use yii\web\NotFoundHttpException;

/**
 * EditProperty is a universal action that can be used to handle creating and editing properties.
 *
 * @package DevGroup\DataStructure\Properties\actions
 */
class EditProperty extends BaseAction
{
    public $listGroupPropertiesActionId = 'list-group-properties';
    public $listPropertyGroupsActionId = 'list-property-groups';
    public $viewFile = 'edit-property';

    const EVENT_BEFORE_INSERT = 'before-insert';
    const EVENT_BEFORE_UPDATE = 'before-update';
    const EVENT_AFTER_INSERT  = 'after-insert';
    const EVENT_AFTER_UPDATE  = 'after-update';

    const EVENT_FORM_BEFORE_SUBMIT = 'form-before-submit';
    const EVENT_FORM_AFTER_SUBMIT  = 'form-after-submit';

    /**
     * Runs action
     * @param             $propertyGroupId
     * @param null|string $id
     *
     * @return string|\yii\web\Response
     * @throws bool
     */
    public function run($propertyGroupId, $id = null)
    {
        /** @var PropertyGroup $propertyGroup */
        $propertyGroup = PropertyGroup::loadModel(
            $propertyGroupId,
            false,
            true,
            86400,
            new NotFoundHttpException("PropertyGroup model with specified id not found")
        );

        /** @var Property $model */
        $model = Property::loadModel(
            $id,
            true,
            true,
            86400,
            new NotFoundHttpException("PropertyGroup model with specified id not found")
        );
        $model->applicable_property_model_id = $propertyGroup->applicable_property_model_id;


        if ($model->isNewRecord === false) {
            // populate translations relation as we need to save all
            $model->translations;
        }


        if (Yii::$app->request->isPost && $model->load(Yii::$app->request->post())) {
            foreach (Yii::$app->request->post('PropertyTranslation', []) as $language => $data) {
                foreach ($data as $attribute => $translation) {
                    $model->translate($language)->$attribute = $translation;
                }
            }

            $event = new ModelEditAction($model);
            $this->controller->trigger(
                $model->isNewRecord ? self::EVENT_BEFORE_INSERT : self::EVENT_BEFORE_UPDATE,
                $event
            );


            if ($event->isValid === true && $model->save()) {
                if ($id === null) {
                    // That was new record - link it to property group
                    $propertyGroup->link(
                        'properties',
                        $model,
                        [
                            'sort_order_group_properties' => count($propertyGroup->properties),
                        ]
                    );
                }

                $event = new ModelEditAction($model);
                $this->controller->trigger($id === null ? self::EVENT_AFTER_INSERT : self::EVENT_AFTER_UPDATE, $event);

                if ($event->isValid === true) {
                    Yii::$app->session->setFlash('success', Yii::t('app', 'PropertyGroup saved.'));

                    return $this->controller->redirect([
                        $this->listGroupPropertiesActionId,
                        'id' => $propertyGroup->id,
                    ]);
                }
            } else {
                Yii::$app->session->setFlash('error', Yii::t('app', 'Error occurred while saving property.'));
            }
        }

        return $this->render([
            'model' => $model,
            'listGroupPropertiesActionId' => $this->listGroupPropertiesActionId,
            'listPropertyGroupsActionId' => $this->listPropertyGroupsActionId,
            'propertyGroup' => $propertyGroup,
        ]);
    }
}
