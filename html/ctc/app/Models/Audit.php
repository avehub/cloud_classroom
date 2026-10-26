<?php


namespace App\Models;

class Audit extends Model
{

    /**
     * 主键编号
     *
     * @var int
     */
    public $id = 0;

    /**
     * 用户编号
     *
     * @var int
     */
    public $user_id = 0;

    /**
     * 用户名称
     *
     * @var string
     */
    public $user_name = '';

    /**
     * 用户IP
     *
     * @var string
     */
    public $user_ip = '';

    /**
     * 请求路由
     *
     * @var string
     */
    public $req_route = '';

    /**
     * 请求路径
     *
     * @var string
     */
    public $req_path = '';

    /**
     * 请求参数
     *
     * @var array|string
     */
    public $req_data = '';

    /**
     * 创建时间
     *
     * @var int
     */
    public $create_time;

    public function initialize()
    {
        parent::initialize();

        $this->setSource('kg_audit');
    }

    public function beforeCreate()
    {
        if (is_array($this->req_data) && !empty($this->req_data)) {
            $this->req_data = kg_json_encode($this->sanitizeData($this->req_data));
        } else {
            $this->req_data = '';
        }

        $this->create_time = time();
    }

    protected function sanitizeData(array $data)
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey($key)) {
                $data[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->sanitizeData($value);
                continue;
            }

            if (!is_scalar($value)) {
                $value = kg_json_encode($value);
            }

            $value = (string)$value;

            if (kg_strlen($value) > 255) {
                $value = kg_substr($value, 0, 255);
            }

            $data[$key] = $value;
        }

        return $data;
    }

    protected function isSensitiveKey($key)
    {
        $key = strtolower((string)$key);

        $keywords = [
            'password',
            'passwd',
            'pwd',
            'secret',
            'private_key',
            'privatekey',
            'secret_key',
            'secretkey',
            'access_key',
            'accesskey',
            'auth_key',
            'app_key',
            'smtp_password',
            'volc_ak',
            'volc_sk',
            'key_anti',
            'token',
        ];

        foreach ($keywords as $keyword) {
            if (strpos($key, $keyword) !== false) {
                return true;
            }
        }

        return $key === 'key';
    }

}
