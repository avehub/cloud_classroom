<?php


namespace App\Models;

class CourseRating extends Model
{

    /**
     * 无有效评价时的默认评分
     *
     * 与数据表 kg_course_rating 各评分字段的默认值保持一致，
     * 避免"没有评价"被当成"0 分"写入或展示。
     */
    const DEFAULT_RATING = 5.0;

    /**
     * 课程编号
     *
     * @var int
     */
    public $course_id = 0;

    /**
     * 综合评分
     *
     * @var float
     */
    public $rating = 5.00;

    /**
     * 维度1评分
     *
     * @var float
     */
    public $rating1 = 5.00;

    /**
     * 维度2评分
     *
     * @var float
     */
    public $rating2 = 5.00;

    /**
     * 维度3评分
     *
     * @var float
     */
    public $rating3 = 5.00;

    /**
     * 创建时间
     *
     * @var int
     */
    public $create_time = 0;

    /**
     * 更新时间
     *
     * @var int
     */
    public $update_time = 0;

    public function initialize()
    {
        parent::initialize();

        $this->setSource('kg_course_rating');
    }

    public function beforeCreate()
    {
        $this->create_time = time();
    }

    public function beforeUpdate()
    {
        $this->update_time = time();
    }

}
