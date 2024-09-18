<?php

namespace app\api\controller;

use app\common\controller\Api;

/**
 * 首页接口
 */
class PayNotify extends Api
{

    protected $noNeedLogin = ['index', 'mobilelogin', 'register', 'resetpwd', 'changeemail', 'changemobile', 'third'];
    protected $noNeedRight = '*';

    /**
     * 首页
     *
     */
    public function index()
    {
        $this->success('请求成功');
    }
}
