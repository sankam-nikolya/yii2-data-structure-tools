<?php
namespace DevGroup\DataStructure\models;

use DevGroup\Multilingual\behaviors\MultilingualActiveRecord;
use DevGroup\Multilingual\traits\MultilingualTrait;
use DevGroup\TagDependencyHelper\CacheableActiveRecord;
use DevGroup\TagDependencyHelper\NamingHelper;
use DevGroup\TagDependencyHelper\TagDependencyTrait;
use Yii;
use yii\caching\TagDependency;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\helpers\ArrayHelper;

/**
 * Class StaticValue
 * @package DevGroup\DataStructure\models
 * @mixin MultilingualActiveRecord
 * @mixin CacheableActiveRecord
 *
 * @param integer $sort_order
 * @param integer $property_id
 * @param string  $name
 * @param string  $description
 * @param string  $slug
 */
class StaticValue extends ActiveRecord
{
    public static $valuesByPropertyId = [];

    use MultilingualTrait;
    use TagDependencyTrait;

    /**
     * @param Property|null $property
     * @param array $config
     */
    public function __construct(Property $property = null, $config = [])
    {
        if ($property !== null) {
            $this->property_id = $property->id;
        }
        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'multilingual' => [
                'class' => MultilingualActiveRecord::className(),
                'translationPublishedAttribute' => false,
            ],
            'CacheableActiveRecord' => [
                'class' => CacheableActiveRecord::className(),
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return '{{%static_value}}';
    }

    /**
     * Performs beforeSave event
     *
     * @param bool $insert
     *
     * @return bool
     */
    public function beforeSave($insert)
    {
        if (!$this->sort_order) {
            $property = Property::findById($this->property_id);
            $this->sort_order = count(static::valuesForProperty($property));
        }
        return parent::beforeSave($insert);
    }

    /**
     * Returns array of possible values for property.
     * Array consists of arrays with elements: name, description, slug and key is static_value.id
     *
     * @param \DevGroup\DataStructure\models\Property $property
     *
     * @return array
     */
    public static function valuesForProperty(Property $property)
    {
        $propertyId = $property->id;

        $table = static::tableName();
        $translationsTable = StaticValueTranslation::tableName();
        $db = static::getDb();
        $languageId = Yii::$app->multilingual->language_id;

        if (!isset(static::$valuesByPropertyId[$propertyId])) {
            static::$valuesByPropertyId[$propertyId] = Yii::$app->cache->lazy(
                function () use ($propertyId, $table, $translationsTable, $db, $languageId) {
                    $query = new Query();
                    $rows = $query
                        ->select([
                            'sv.id',
                            'svt.name',
                            'svt.description',
                            'svt.slug',
                        ])
                        ->from($table . ' sv')
                        ->innerJoin(
                            $translationsTable . 'svt',
                            'svt.model_id = sv.id AND svt.language_id=:language_id',
                            [
                                ':language_id' => $languageId,
                            ]
                        )
                        ->orderBy('sv.sort_order ASC')
                        ->all($db);
                    return ArrayHelper::map(
                        $rows,
                        'id',
                        function ($item) {
                            return [
                                'name' => $item['name'],
                                'description' => $item['description'],
                                'slug' => $item['slug'],
                            ];
                        }
                    );
                },
                "StaticValues:$propertyId:$languageId",
                86400,
                "StaticValues:$propertyId"
            );
        }
        return static::$valuesByPropertyId[$propertyId];
    }

    /**
     * @inheritdoc
     */
    public function afterSave($insert, $changedAttributes)
    {
        // clear static cache for current property_id static values
        static::$valuesByPropertyId[$this->property_id] = null;

        if (isset($changedAttributes['property_id'])) {
            // clear old static cache for old property_id static values
            static::$valuesByPropertyId[$changedAttributes['property_id']] = null;
        }

        TagDependency::invalidate(
            Yii::$app->cache,
            [
                'StaticValues:' . $this->property_id
            ]
        );
        parent::afterSave($insert, $changedAttributes);
    }
}
