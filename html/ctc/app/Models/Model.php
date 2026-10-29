<?php


namespace App\Models;

class Model extends \Phalcon\Mvc\Model
{

    public function initialize()
    {
        $this->setup([
            'exceptionOnFailedSave' => true,
            'notNullValidations' => false,
        ]);

        $this->useDynamicUpdate(true);
    }

    /**
     * Phalcon 5 会把模型属性默认值 0 当成显式主键提交，导致自增主键
     * 写入后模型仍停留在 0（lastInsertId 不会回填），依赖新主键的后续
     * 逻辑（如分类 path/level、课程评分）都会拿到错误编号。
     *
     * 自增主键在未显式赋值时先置为 null，交给数据库生成并回填。
     */
    public function create(): bool
    {
        $this->prepareIdentityValue();

        return parent::create();
    }

    protected function prepareIdentityValue()
    {
        $metaData = $this->getModelsMetaData();

        $identityField = $metaData->getIdentityField($this);

        if (!$identityField) {
            return;
        }

        $value = $this->{$identityField};

        if ($value === null || $value === 0 || $value === '0' || $value === '') {
            $this->{$identityField} = null;
        }
    }

}
