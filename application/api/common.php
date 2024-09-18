<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006-2016 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// +----------------------------------------------------------------------
use think\Db;
use think\captcha\Captcha;
use \app\model\Member as Member;
use \app\model\Order as Order;

/**
 * 从数据库获取配置项的值
 * @author Dreamer
 * @param $name 配置名称
 * @return bool|mixed
 */
function get_config($name)
{
    $name = trim($name);
    $config = \think\Db::name('admin_config')->where(['name' => $name])->find();
    if (!$config || empty($config['value'])) {
        return false;
    } else {
        return $config['value'];
    }
}
if (!function_exists('parse_sql')) {
    /**
     * 分割sql语句
     * @param  string $content sql内容
     * @param  bool $limit 如果为1，则只返回一条sql语句，默认返回所有
     * @param  array $prefix 替换前缀
     * @return array|string 除去注释之后的sql语句数组或一条语句
     */
    function parse_sql($sql = '', $limit = 0, $prefix = [])
    {
        // 被替换的前缀
        $from = '';
        // 要替换的前缀
        $to = '';

        // 替换表前缀
        if (!empty($prefix)) {
            $to = current($prefix);
            $from = current(array_flip($prefix));
        }

        if ($sql != '') {
            // 纯sql内容
            $pure_sql = [];

            // 多行注释标记
            $comment = false;

            // 按行分割，兼容多个平台
            $sql = str_replace(["\r\n", "\r"], "\n", $sql);
            $sql = explode("\n", trim($sql));

            // 循环处理每一行
            foreach ($sql as $key => $line) {
                // 跳过空行
                if ($line == '') {
                    continue;
                }

                // 跳过以#或者--开头的单行注释
                if (preg_match("/^(#|--)/", $line)) {
                    continue;
                }

                // 跳过以/**/包裹起来的单行注释
                if (preg_match("/^\/\*(.*?)\*\//", $line)) {
                    continue;
                }

                // 多行注释开始
                if (substr($line, 0, 2) == '/*') {
                    $comment = true;
                    continue;
                }

                // 多行注释结束
                if (substr($line, -2) == '*/') {
                    $comment = false;
                    continue;
                }

                // 多行注释没有结束，继续跳过
                if ($comment) {
                    continue;
                }

                // 替换表前缀
                if ($from != '') {
                    $line = str_replace('`' . $from, '`' . $to, $line);
                }
                if ($line == 'BEGIN;' || $line == 'COMMIT;') {
                    continue;
                }
                // sql语句
                array_push($pure_sql, $line);
            }

            // 只返回一条语句
            if ($limit == 1) {
                return implode($pure_sql, "");
            }

            // 以数组形式返回sql语句
            $pure_sql = implode($pure_sql, "\n");
            $pure_sql = explode(";\n", $pure_sql);
            return $pure_sql;
        } else {
            return $limit == 1 ? '' : [];
        }
    }
}

/**
 * 从数据库获取配置组的信息
 * @author Dreamer
 * @param $name 配置组名称
 * @return bool|mixed
 */
function get_config_by_group($group)
{
    $group = trim($group);
    $config = \think\Db::name('admin_config')->field("name,value")->where(['group' => $group])->select();

    if (!$config) return null;

    $returnData = [];
    foreach ($config as $v) {
        $returnData[$v['name']] = $v['value'];
    }

    return $returnData;
}

/**
 * 获取菜单
 * @author frs
 * @return mixed
 */
function getMenu($pid = 0)
{
    //$field = 'id,pid,name,url,type,target,color';
    $field = '*';
    $current = 0;
    if (empty($pid)) {
        $menu = Db::name('menu')->where(array('pid' => 0, 'status' => 1))->order('sort asc')->field($field)->select();
        foreach ($menu as $k => $v) {
            if ($v['type'] == 2) {
                $url = json_decode($v['url'], true);
                $urls = getModuleUrl($url);
                $menu[$k]['current'] = matchUrl($urls, $v['id']) ? 1 : 0;
                $menu[$k]['url'] = $urls;
            } else {
                $spos = strpos($v['url'], 'http://');
                if ($spos === false && $spos != 0) {
                    $v['url'] .= 'http://' . $v['url'];
                }
                $menu[$k]['current'] = matchUrl($v['url'], $v['id']) ? 1 : 0;
            }
            if ($menu[$k]['current'] == 1) $current = 1;
            $sublist = Db::name('menu')->where(array('pid' => $v['id'], 'status' => 1))->order('sort asc')->field($field)->select();
            if (!empty($sublist)) {
                foreach ($sublist as $key => $val) {
                    if ($val['type'] == 2) {
                        $url = json_decode($val['url'], true);
                        $urls = getModuleUrl($url);
                        if (matchUrl($urls, $val['id'])) {
                            $sublist[$key]['current'] = 1;
                            $menu[$k]['current'] = 1;
                            $current = 1;
                        } else {
                            $sublist[$key]['current'] = 0;
                        }
                        $sublist[$key]['url'] = $urls;
                    } else {
                        $sublist[$key]['current'] = 0;
                        $spos = strpos($val['url'], 'http://');
                        if ($spos === false && $spos != 0) {
                            $val['url'] .= 'http://' . $val['url'];
                        }
                        if (matchUrl($val['url'], $val['id'])) {
                            $sublist[$key]['current'] = 1;
                            $menu[$k]['current'] = 1;
                            $current = 1;
                        }
                    }
                }
                $menu[$k]['sublist'] = $sublist;
            }
        }
        if (empty($current)) {
            //如果匹配不上，再读取session保存的数据进行匹配
            $controller = lcfirst(request()->controller());
            $action = request()->action();
            $allowType = ['images', 'novel', 'video'];
            if (in_array(lcfirst($controller), $allowType)) {
                $current_menu = session('current_menu');
                if ($controller == $current_menu['controller']) {
                    $parent_menu = db::name('menu')->where(array('id' => $current_menu['id']))->find();
                    $mate_id = empty($parent_menu['pid']) ? $current_menu['id'] : $parent_menu['pid'];
                    foreach ($menu as $k => $v) {
                        if ($v['id'] == $mate_id) {
                            $menu[$k]['current'] = 1;
                            $current = 1;
                            $matchData = array(
                                'controller' => $controller,
                                'action' => $action,
                                'id' => $mate_id,
                            );
                            session('current_menu', $matchData);
                        }
                    }
                }
                if (empty($current)) {
                    $where['url'] = '{"cid":"' . $controller . '"}';
                    $menu_info = db::name('menu')->where($where)->field('id')->find();
                    foreach ($menu as $k => $v) {
                        if ($v['id'] == $menu_info['id']) {
                            $menu[$k]['current'] = 1;
                            $current = 1;
                            $matchData = array(
                                'controller' => $controller,
                                'action' => $action,
                                'id' => $menu_info['id'],
                            );
                            session('current_menu', $matchData);
                        }
                    }
                }
            }
        }
    } else {
        $menu = Db::name('menu')->where(array('pid' => $pid, 'status' => 1))->field($field)->select();
        foreach ($menu as $k => $v) {
            if ($v['type'] == 2) {
                $url = json_decode($v['url'], true);
                $urls = getModuleUrl($url);
                $menu[$k]['current'] = matchUrl($urls, $v['id']) ? 1 : 0;
                $menu[$k]['url'] = $urls;
            }
        }
    }
    return $menu;
}

/**
 * 根据url判断是否是当前选中
 * @author frs
 * @param url 链接
 * @param mid 菜单id
 * @return match 是否匹配 1 or 0
 */
function matchUrl($url, $mid = 0)
{
    $pageURL = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    $origin_url = empty($_SERVER['HTTP_REFERER']) ? $pageURL : $_SERVER['HTTP_REFERER'];
    /*
    $pageURL = $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
    $pos = strpos($pageURL, 'http://');
    if ($pos === false) $pageURL = 'http://' . $pageURL;
    */
    $url = !empty($url) ? $url : $_SERVER["SERVER_NAME"] . $_SERVER["REQUEST_URI"];
    $urlArray = array(
        'http://' . $url,
        $url . '/',
        $url . 'html',
        $url . 'php',
        'https://' . $url,
        $url,
        'http://' . $_SERVER['SERVER_NAME'] . $url,
        'https://' . $_SERVER['SERVER_NAME'] . $url,
    );
    $match = in_array($pageURL, $urlArray) ? 1 : 0;
    /* if(empty($match)){
         if($origin_url != $pageURL) $match = in_array($origin_url, $urlArray) ? 1 : 0;
     }*/
    if (!empty($match)) {
        $matchData = array(
            'controller' => lcfirst(request()->controller()),
            'action' => request()->action(),
            'id' => $mid,
        );
        session('current_menu', $matchData);
    }
    return $match;
}

/**
 * 根据模块信息获取url
 * @author frs
 * @param int $cid 分类id
 * @param int $type 资源类型
 * @return url
 */
function getModuleUrl($param = '')
{
    $cid = !empty($param['cid']) ? $param['cid'] : 0;
    $base_class = ['video', 'images', 'novel'];
    if (!in_array($cid, $base_class)) {
        $module = 'video';
        switch ($param['type']) {
            case 1:
                $module = 'video';
                break;
            case 2:
                $module = 'images';
                break;
            case 3:
                $module = 'novel';
                break;
        }
        $url = url("$module/lists", array('cid' => $cid));
    } else {
        $url =  'http://' . $_SERVER['SERVER_NAME'] . "/$cid/lists.html";
    }
    return $url;
}

/**
 * 生成验证码
 * @author Dreamer
 * @param string $id 验证码id
 * @param int $len 验证码长度
 * @param array $conf 验证码配置数组
 * @return \think\Response 返回验证码
 */
function create_captcha($id = '', $len = 4, $conf = [])
{
    $config = [
        'fontSize' => 16,
        'length' => $len,
        'imageH' => 30,
        'imageW' => 120,
        'useNoise' => true,
        'useCurve' => false,
        'fontttf' => '4.ttf',
        'bg' => [255, 255, 255],
    ];

    $config = count($conf) > 0 ? array_merge($config, $conf) : $config;

    $verify_obj = new Captcha($config);
    return $verify_obj->entry($id);
}


/**
 * 验证码验证
 * @author Dreamer
 * @param $code 用户输入的验证码
 * @param string $id 验证码的id
 * @return bool true:验证正确 ， falsh：验证失败
 */
function verify_captcha($code, $id = '')
{
    $captcha_obj = new Captcha();
    if ($captcha_obj->check($code, $id)) {
        return true;
    } else {
        return false;
    }
}


/**
 * 会员密码加密算法,如果要改动的话，客户端后台算法方法也要修改
 * @author Dreamer
 * @param $pwd
 * @return string
 */
function encode_member_password($pwd)
{
    return md5(md5($pwd));
}

/**
 * 生成用户登录验证令牌
 * @author rusheng
 * @param $user_info
 * @return string
 */
function get_token($user_info)
{
    return md5(md5($user_info['id'] . $user_info['username'] . $user_info['password'] . time()));
}

/**
 * 检验用户登录状态
 * @author rusheng
 * @param $user_info
 * @return string
 */
function check_is_login()
{
    $user_id = session('member_id');
    $access_token = session('access_token');
    //验证登陆
    if (intval($user_id) <= 0) {
        $data = ['resultCode' => 2, 'error' => lang('IsMeLoginN')];
        return $data;
        die;
    }
    $user_info = db::name('member')->where(array('id' => $user_id, 'access_token' => $access_token))->find();
    if (!$user_info) {
        $data = ['resultCode' => 3, 'error' => lang('MemberTip1')];
        session('member_id', '0');
        session('member_info', '');
        session('access_token', '');
        return $data;
        die;
    }
    $data = ['resultCode' => 1, 'message' => lang('IsMeLoginY')];
    return $data;
}

/**
 * 验证用户名和密码正确性
 * @author Dreamer
 * @param $user 用户名
 * @param $pwd  密码
 * @return bool true:验证成功，false:验证失败
 */
function check_member_password($user, $pwd)
{
    if (empty(trim($user)) || empty(trim($pwd))) return false;
    if (get_config('register_validate')) {
        $userType = get_str_format_type($user);
    } else {
        $userType = 'string';
    }

    $where['password'] = encode_member_password($pwd);
    switch ($userType) {
        case 'string':
            $where['username'] = $user;
            break;

        case 'email':
            $where['email'] = $user;
            break;

        case 'mobile':
            $where['tel'] = $user;
            break;
    }

    $memberInfo = \think\Db::name('member')->where($where)->find();
    if (!$memberInfo) {
        return false;
    }
    if ($memberInfo['status'] == 0) return ['rs' => -1, 'msg' => lang('IsDisabled')];
    $access_token = get_token($memberInfo);
    \think\Db::name('member')->where($where)->update(array('access_token' => $access_token));
    $sessionUserInfo = [
        'id' => $memberInfo['id'],
        'username' => $memberInfo['username'],
        'nickname' => $memberInfo['nickname'],
        'email' => $memberInfo['email'],
        'tel' => $memberInfo['tel'],
        'sex' => $memberInfo['sex'],
        'is_agent' => $memberInfo['is_agent'],
        'headimgurl' => $memberInfo['headimgurl'],
        'is_permanent' => $memberInfo['is_permanent']
    ];
    //写入session
    session('access_token', $access_token);
    session('member_id', $memberInfo['id']);
    session('member_info', $sessionUserInfo);

    return true;
}


/**
 * 会员退出登陆
 * @author Dreamer
 * @return bool
 */
function member_logout()
{
    session('member_id', null);
    session('member_info', null);
    return true;
}


/**
 * 根据字符串获取字符串的字符类型
 * @author Dreamer
 * @param $str
 * @return string
 */
function get_str_format_type($str)
{
    if (preg_match("/^[0-9a-zA-Z]+@(([0-9a-zA-Z]+)[.])+[a-z]{2,30}$/i", $str)) {
        return 'email';
    }

    if (preg_match("/^13[0-9]{1}[0-9]{8}$|15[0-9]{1}[0-9]{8}$|17[0-9]{1}[0-9]{8}$|18[0-9]{1}[0-9]{8}$/", $str)) {
        return 'mobile';
    }
    return 'string';
}


/**
 * 视频付费等检测
 * @author  $Dreamer
 * @param $videoInfo
 * @return  array()  result=>1:正常观看  2:需要扣金币观看，且金币够扣  3:需扣金币观看，且金币不够扣  4:视频收费，但未登陆
 */
function check_video_auth($videoInfo)
{
    $memberInfo = get_member_info();

    /* 视频免费-----------------start--------------------------------------------------------------- */
    if ($videoInfo['gold'] == 0 || empty($videoInfo['gold']) || $videoInfo['user_id'] === session('member_id')) return ['result' => 1];
    /* 视频免费-----------------end----------------------------------------------------------------- */

    /* 视频收费-----------------start--------------------------------------------------------------- */
    //会员为vip
    if (isset($memberInfo) && $memberInfo['isVip']) return ['result' => 1];

    //会员非vip
    if (isset($memberInfo) && $memberInfo['isVip'] == false) {
        //检测是否在重复消费周期内，如果是则免费观看，否则 "试看" 或 "扣除金币观看"
        $buyTimeExists = get_config('message_validity');
        $buyTimeExists = 60 * 60 * $buyTimeExists;
        $watchHistory = Db::name('video_watch_log')
            ->where(['user_id' => $memberInfo['id'], 'video_id' => $videoInfo['id'], 'is_try_see' => 0])
            ->order('id desc')
            ->find();

        if ($watchHistory && $watchHistory['view_time'] > (time() - $buyTimeExists)) {
            //消费周期内，免费看
            return ['result' => 1];
        }

        //如果不在消费周期内，则 "试看" 或 "扣除金币观看"
        if ($memberInfo['money'] >= $videoInfo['gold']) {
            return ['result' => 2, 'msg' => lang('Common1'), 'memberInfo' => $memberInfo];
        }

        //无观看记录，且金币不够支付
        return ['result' => 3, 'msg' => lang('Common2'), 'memberInfo' => $memberInfo];
    }

    //未登陆
    //$videoConfig=get_config_by_group('video');  //video相关配置
    //return ['result'=>2,'msg'=>'当前系统允许试看','look_at_measurement'=>get_config('look_at_measurement'),'look_at_num'=>get_config('look_at_num')];
    return ['result' => 4, 'msg' => lang('Common3')];

    /* 视频收费-----------------end--------------------------------------------------------------- */
}

/**
 * 获取试看剩余次数
 * @return array|bool|int|mixed  返回数组则为试看的秒数，如int则为剩余次数
 */
function get_remainder_try_see()
{
    //video相关配置  look_at_measurement:试看单位 look_at_num:1为部 2为秒 look_at_on:是否启动试看(1为支持，0为不支持)
    $videoConfig = get_config_by_group('video');
    $todayBegin = strtotime(date('Y-m-d'));

    $where = [];
    if (session('member_id')) {
        #$where['user_id'] = session('member_id');
    } else {
        #$where['user_ip'] = request()->ip();
    }
    //以 user_ip 来统计试看次数。取消登陆前试看n次，登陆后也可试看n次 $dreamer 2018/3/13
    $where['user_ip'] = request()->ip();

    $where['view_time'] = ['>=', $todayBegin];

    if (isset($videoConfig['look_at_on']) && !$videoConfig['look_at_on']) return false;
    if (isset($videoConfig['look_at_on']) && $videoConfig['look_at_on'] && isset($videoConfig['look_at_measurement'])) {
        if (request()->isMobile()) $videoConfig['look_at_measurement'] = 1; //手机端只能按部试看
        switch ($videoConfig['look_at_measurement']) {
            case 1: //部
                //查询浏览日志，是否超过限制(以天为结算)
                $rowCount = Db::name('video_watch_log')->where($where)->count();
                $data = ($rowCount >= $videoConfig['look_at_num']) ? 0 : ($videoConfig['look_at_num'] - $rowCount);
                if (request()->isMobile()) $data = $videoConfig['look_at_num_mobile'] - $rowCount;
                $data = $data >= 0 ? $data : 0;
                return $data;
                break;
            case 2: //秒
                return ['look_at_num' => $videoConfig['look_at_num']];
                break;
        }
    }

    return false;
}


/**
 * 插入观看日志
 * @author  $Dreamer
 */
function insert_watch_log($type, $id, $gold = 0, $isTrySee = false, $userid)
{

    $isTrySee = $isTrySee ? true : false;

    if (!in_array($type, ['atlas', 'video', 'novel']) || $id <= 0) return false;

    $resourceTable = [
        'atlas' => 'atlas_watch_log',
        'video' => 'video_watch_log',
        'novel' => 'novel_watch_log'
    ];

    $memberId = session('member_id');
    if (isset($_SERVER['HTTP_ALI_CDN_REAL_IP'])) {
        $ip = $_SERVER['HTTP_ALI_CDN_REAL_IP'];
    } else {
        $ip = \think\Request::instance()->ip();
    }


    $where = [
        'user_ip' => $ip,
        "{$type}_id" => $id
    ];

    global $whereUID;
    $whereUID = [
        'user_id' => $memberId,
        "{$type}_id" => $id
    ];

    if ($isTrySee) {
        //为了防止数据库冗余，试看情况下:: 故 （同资源id且同Ip） 或者 （同资源id同user_id），在4小时内不重复写入  $Dreamer
        $limitTime = ['>', time() - 4 * 60 * 60];
        $where['view_time'] = $whereUID['view_time'] = $limitTime;
        $where['is_try_see'] = 1;
    } else {
        $where['is_try_see'] = $whereUID['is_try_see'] = 0;

        $buyTimeExists = get_config('message_validity');
        $buyTimeExists = 60 * 60 * $buyTimeExists;
        $where['view_time'] = ['>', time() - $buyTimeExists];
        $whereUID['view_time'] = ['>', time() - $buyTimeExists];
    }


    /*

    SELECT * FROM `ms_video_watch_log` WHERE `user_ip` = '127.0.0.1' AND `video_id` = 644 AND `is_try_see` = 0 AND `view_time` > 1523847027
     OR ( `user_id` = 78 AND `video_id` = 644 AND `is_try_see` = 0 AND `view_time` > 1523847027 ) LIMIT 1

     */

    $db = Db::name($resourceTable[$type]);
    /*
    $watchLog = $db->where($where)->whereOr(function ($query) {
        global $whereUID;
        $query->where($whereUID);
    })->find();
    */

    $watchLog = null;

    if ($memberId > 0) {
        $watchLog = $db->where($whereUID)->find();
    } else {
        $watchLog = $db->where($where)->find();
    }

    if (!$watchLog) {

        $returnRs = true;
        $is_vip = 0;
        //扣除会员的gold
        if (!$isTrySee && $memberId > 0 && $gold > 0 && $userid != session('member_id')) {
            $memberModel = model('member')->get($memberId);
            $memberInfo = $memberModel->toArray();

            if ($memberInfo['is_permanent'] == 1 || $memberInfo['out_time'] > time()) {
                //如果是vip则不扣费
                $returnRs = true;
                $is_vip = 1;
            } elseif (isset($memberInfo['money']) && $memberInfo['money'] >= $gold) {
                $memberModel->money -= $gold;
                $decMoneyRs = $memberModel->save();
                //作者分成
                author_divide('video', $id);
                //消费记录金币变动记录
                Db::name('gold_log')->data(['user_id' => session('member_id'), 'gold' => "-$gold", 'add_time' => time(), 'module' => $type, 'explain' => lang('Common4')])->insert();
                $returnRs = ($decMoneyRs) ? true : false;
            }
        }

        if ($returnRs) {
            $insertData = ["{$type}_id" => $id, 'user_id' => session('member_id'), 'user_ip' => $ip, 'view_time' => time(), 'gold' => $gold, 'is_try_see' => $isTrySee];
            ($userid == session('member_id') && $userid > 0) ? $insertData['is_myself'] = 1 : $insertData['is_myself'] = 0;  //发布者自己观看视频的标识
            if ($insertData['is_myself'] != 1 && $is_vip != 1) {
                $db->data($insertData)->insert();
            }
            return true;
        } else {
            return false;
        }
    }

    return true;
}

/**
 * 用户消费作者参与分成
 * $type 1 视频 ，2 资讯 ，3 图片
 */
function author_divide($type, $project_id)
{
    if (!in_array($type, ['atlas', 'video', 'novel'])) return false;
    $resourceTable = [
        'atlas' => 'atlas',
        'video' => 'video',
        'novel' => 'novel'

    ];
    $project = Db::name($resourceTable[$type])->where(['id' => $project_id])->find();
    $num = (float)get_config($type . '_commission') * intval($project['gold']) * 0.01;
    $result = Db::name('member')->where(['id' => $project['user_id']])->setInc('money', $num);

    if ($result) {
        //写入
        $s = '';
        switch ($type) {
            case 'atlas':
                $s = lang('Common5');
                break;
            case 'video':
                $s = lang('Common6');
                break;
            case 'novel':
                $s = lang('Common7');
                break;
            default:
                $s = '';
        }

        $data['user_id'] = $project['user_id'];
        $data['gold'] = $num;
        $data['add_time'] = time();
        $data['module'] = $resourceTable[$type];
        $data['explain'] = $s;
        Db::name('gold_log')->insert($data);
    }
    return true;
}


/**
 * 插入观看日志 显示具体信息
 * @author
 */
function insert_watch_logshowmsg($type, $id, $user_id, $gold = 0, $isTrySee = false)
{
    $isTrySee = $isTrySee ? true : false;

    if (!in_array($type, ['atlas', 'video', 'novel']) || $id <= 0) return false;

    $resourceTable = [
        'atlas' => 'atlas_watch_log',
        'video' => 'video_watch_log',
        'novel' => 'novel_watch_log'
    ];

    $memberId = intval(session('member_id'));
    $ip = \think\Request::instance()->ip();

    $where = [
        'user_ip' => $ip,
        "{$type}_id" => $id
    ];

    global $whereUID;
    $whereUID = [
        'user_id' => $memberId,
        "{$type}_id" => $id
    ];

    if ($isTrySee) {
        //为了防止数据库冗余，试看情况下:: 故 （同资源id且同Ip） 或者 （同资源id同user_id），在4小时内不重复写入  $Dreamer
        $limitTime = ['>', time() - 4 * 60 * 60];
        $where['view_time'] = $whereUID['view_time'] = $limitTime;
    } else {
        $where['is_try_see'] = $whereUID['is_try_see'] = 0;

        $buyTimeExists = get_config('message_validity');
        $buyTimeExists = 60 * 60 * $buyTimeExists;
        $where['view_time'] = ['>', time() - $buyTimeExists];
        $whereUID['view_time'] = ['>', time() - $buyTimeExists];
    }

    $db = Db::name($resourceTable[$type]);
    $watchLog = $db->where($where)->whereOr(function ($query) {
        global $whereUID;
        $query->where($whereUID);
    })->find();

    if ($memberId <= 0 && $gold > 0) {
        return '1';
    }
    $memner_info = get_member_info($memberId);
    if (!$watchLog && $user_id != session('member_id') && !$memner_info['isVip']) { //判断是否是vip,是否是作者，是否观看记录在有效期内
        //扣除会员的gold
        if (!$isTrySee && $memberId > 0 && $gold > 0) {
            return '1';
        }
    }
    return '0';
}

//获取第三方登录
function get_sanfanlogin()
{
    $logininfo = Db::name('login_setting')->where(['status' => 1])->select();
    return $logininfo;
}

/**
 * 根据Id获取会员身份信息
 * @author  $Dreamer
 * @param int $memberId
 * @return array|null
 */
function get_member_info($memberId = 0)
{
    $memberId = $memberId == 0 ? session('member_id') : $memberId;
    if (!$memberId) return null;
    $memberInfo = Db::name('member')->where(['id' => $memberId])->find();
    if (!$memberInfo) return null;
    if ($memberInfo['is_permanent'] == 1 || $memberInfo['out_time'] > time()) {
        $memberInfo['isVip'] = true;
        if ($memberInfo['is_permanent'] == 1) $memberInfo['isEverVip'] = true;
    } else {
        $memberInfo['isVip'] = false;
    }
    return $memberInfo;
}

/**
 * 根据did获取会员身份信息
 * @author  $Dreamer
 * @param int $memberId
 * @return array|null
 */
function get_member_info_bydid($did = '')
{

    $memberInfo = Db::name('member')->where(['did' => $did])->select();

    if (!$memberInfo) return null;
    foreach ($memberInfo as $k => &$v) {
        if ($v['is_permanent'] == 1 || $v['out_time'] > time()) {
            $v['isVip'] = true;
            if ($v['is_permanent'] == 1) $v['isEverVip'] = true;
        } else {
            $v['isVip'] = false;
        }
    }
    return $memberInfo;
}





/**
 * 返回想要查询的值
 * @param $param array
 * @param db 要查询的数据库名称
 * @param  where 查询的条件
 * @param  field 查询的字段
 */
function get_field_values($param = '')
{
    $db = $param['db'];
    $where = $param['where'];
    $field = $param['field'];
    $type = empty($param['type']) ? 'array' : $param['type'];
    $data = Db::name($db)->where($where)->field($field)->select();
    if ($type == 'string') {
        $Result = '';
    } else {
        $Result = array();
    }
    foreach ($data as $k => $v) {
        if ($type == 'string') {
            if (empty($k)) {
                $Result .= $v[$field];
            } else {
                $Result .= ',' . $v[$field];
            }
        } else {
            $Result[] = $v[$field];
        }
    }
    return $Result;
}

function get_tag_list($type = '')
{
    $where = empty($type) ? 'status = 1' :  "status = 1 and type = $type";
    $list = Db::name('tag')->where($where)->order('sort asc')->column('*', 'id');
    return $list;
}

/**
 * 相关推荐数据
 * @author  $Dreamer
 * @param $param array
 * @param type  要查询的资源类型  image  novel  video
 * @param  cid 分类id
 * @param  limit  返回的数量，默认为8个
 * @param  field 查询的字段，如果该字段不存的话会根据资源类型读取默认的数据
 */
function get_recom_data($param = '')
{
    $ctype = [
        'image' => 2,
        'novel' => 3,
        'video' => 1
    ];
    $type = empty($param['type']) ? 'video' : $param['type'];
    $limit = empty($param['limit']) ? '8' : $param['limit'];
    //$default_where = ($type == 'video') ? 'status = 1 and pid=0 and is_check=1' : 'status = 1 and is_check=1';
    $default_where = ($type == 'video') ? 'status = 1 and is_check=1' : 'status = 1 and is_check=1';
    if (!empty($param['cid'])) {
        $params = array(
            'db' => 'class',
            'where' => array('status' => 1, 'type' => $ctype[$type], 'pid' => $param['cid']),
            'field' => 'id',
            'type' => 'string',
        );
        $sub_array = get_field_values($params);
        $default_where .= empty($sub_array) ? ' and class = ' . $param['cid'] : ' and (class = ' . $param['cid'] . ' or class in (' . $sub_array . '))';
    }

    $where = empty($param['where']) ? $default_where : $param['where'];
    $resourceTable = [
        'image' => 'atlas',
        'novel' => 'novel',
        'video' => 'video'
    ];

    $lang = empty(cookie('think_var')) ? '' : ',' . cookie('think_var');
    $fieldData = [
        'image' => 'id,title,thumbnail,good,play_time,add_time,gold,update_time' . $lang,
        'novel' => 'id,title,thumbnail,good,click,add_time,gold,update_time' . $lang,
        'video' => 'id,title,thumbnail,good,play_time,click,add_time,gold,update_time' . $lang
    ];
    $count = Db::name($resourceTable[$type])->where($where)->count();
    if ($count < $limit) {
        $data = Db::name($resourceTable[$type])->where($where)->field($fieldData[$type])->select();
    } else {
        $rand_num = rand(1, 99999);
        $start = $rand_num % $count;
        $result = Db::name($resourceTable[$type])->where($where)->field($fieldData[$type])->limit($start, $limit)->select();
        $data = empty($param['result']) ? $result : array_merge($param['result'], $result);
        if (count($result) < $limit) {
            $array = '';
            foreach ($data as $k => $v) {
                if (empty($k)) {
                    $array .= $v['id'];
                } else {
                    $array .= ',' . $v['id'];
                }
            }
            $param = array(
                'type' => $type,
                'limit' => $limit - count($result),
                'result' => $data,
                'where' => $default_where . ' and id not in (' . $array . ')',
            );
            $data = get_recom_data($param);
        }
    }
    shuffle($data);
    return $data;
}


/**
 * 检测用户是否为当前资源点过赞
 * @author  $Dreamer
 * @param $type
 * @param $id
 * @return bool
 */
function isGooded($type, $id)
{
    if (session('member_id') <= 0) return false;

    if ($id <= 0) return false;

    $allowType = ['atlas', 'novel', 'video'];
    if (!in_array($type, $allowType)) return false;

    $resourceTable = [
        'atlas' => 'atlas',
        'novel' => 'novel',
        'video' => 'video'
    ];

    $goodHistory = Db::name("{$type}_good_log")->where(["{$resourceTable[$type]}_id" => $id, 'user_id' => session('member_id')])->find();
    if (!$goodHistory) return false;
    return true;
}

/**
 * 判断当天是否已经点赞
 */
function isSign()
{
    if (session('member_id') <= 0) die(json_encode(['resultCode' => 4005, 'error' => lang('IsMeLoginN')]));
    $user_id = session('member_id');
    $today = strtotime(date('Y-m-d'));
    $tomorrow = $today + (24 * 3600 - 1);
    $where = "user_id = $user_id and (sign_time between $today and $tomorrow)";
    $result = Db::name('sign')->where($where)->find();
    if (empty($result)) return false;
    return true;
}

/**
 * 检测用户是否收藏过当前资源
 * @author  $Dreamer
 * @param   $type
 * @param   $id
 * @return  bool
 */
function isCollected($type, $id)
{

    if (session('member_id') <= 0) return false;

    if ($id <= 0) return false;

    $allowType = ['image', 'novel', 'video'];
    if (!in_array($type, $allowType)) return false;

    $resourceTable = [
        'image' => 'atlas',
        'novel' => 'novel',
        'video' => 'video'
    ];

    $goodHistory = Db::name("{$type}_collection")->where(["{$resourceTable[$type]}_id" => $id, 'user_id' => session('member_id')])->find();
    if (empty($goodHistory)) {

        return false;
    } else {
        return true;
    }
}


/**
 * 产生随机字符串
 * @param int $length
 * @return 产生的随机字符串
 */
function get_random_str($length = 32)
{
    $chars = "abcdefghijklmnpqrstuvwxyz123456789";
    $str = "";
    for ($i = 0; $i < $length; $i++) {
        $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
    }
    return $str;
}

/**
 * 返回想要的分类
 * @param $param array
 * @param resourceType 资源类型 视频 1 ，图片 2，资讯 3
 * @param  pid  默认为0
 */
function get_resource_class($param = '')
{
    $db = db::name('class');
    $pid = empty($param['pid']) ? 0 : $param['pid'];
    $resourceType = empty($param['resourceType']) ? 1 : $param['resourceType'];
    $where = "status = 1 and type = $resourceType";
    $order = 'sort asc';
    if (empty($pid)) {
        $where .= ' and pid = 0';
        $pid = 0;
        $Result = $db->where($where)->order($order)->select();
        foreach ($Result as $k => $v) {
            $Result[$k]['childs'] = $db->where(['pid' => $v['id']])->select();
        }
    } else {
        $where .= "and pid = $pid";
        $Result = $db->where($where)->order($order)->select();
    }
    return $Result;
}

/**
 * 操作跳转的快捷方法
 * @access protected
 * @param mixed $msg 提示信息
 * @param string $url 跳转的URL地址要带http格式的完整网址
 * @param integer $icon 1为正确 2为错误
 * @param integer $wait 跳转等待时间
 * @param integer $type 提示完成的回调处理 1为当前界面的处理 2为子层处理，关闭子层并且刷新父层
 * @return void
 */
function layerJump($msg = '', $icon = 1, $type = 1, $wait = 1, $url = 'null')
{
    $script = '';
    $script .= '<script>';
    $script .= "parent.layer.msg('$msg', {icon: $icon, time: $wait*1000},function(){";
    if ($type == 2) {
        $script .= 'window.parent.location.reload();';
        $script .= 'parent.layer.close(index);';
    } elseif ($type == 1) {
        if (empty($url) || $url == 'null') {
            $script .= 'location.reload();';
        } else {
            $script .= "window.location.href='$url';";
        }
    } else {
        $script .= 'history.go(-1);';
    }
    $script .= '});';
    $script .= '</script>';
    echo $script;
}

/**
 * 获取seo相关设置
 * @author $dreamer
 * @param int $byUid
 * @return mixed|null
 */
function get_seo_info_plus()
{
    $request = request();
    $tablePrefix = config('database.prefix');
    $curDomain = $request->host();


    $systemDomain = ['admin', 'system'];
    $seoInfo = null;


    $siteBaseInfo = null;
    $bannerwhere =  'position_id = 4 and status = 1 and begin_time < ' . time() . ' and end_time >' . time();
    $bannerinfo = Db::name('advertisement')->where($bannerwhere)->select();
    //获取基础站信息
    $baseConfig = cache('site_base_info');
    if (empty($baseConfig)) $baseConfig = get_config_by_group('base');
    if (empty($siteBaseInfo)) {
        $siteBaseInfo['banner'] = $bannerinfo;
        $siteBaseInfo['site_logo_mobile'] = $baseConfig['site_logo_mobile'];
        $siteBaseInfo['site_logo'] = $baseConfig['site_logo'];
        $siteBaseInfo['site_title'] = $baseConfig['site_title'];
        $siteBaseInfo['site_keywords'] = $baseConfig['site_keywords'];
        $siteBaseInfo['site_description'] = $baseConfig['site_description'];
        $siteBaseInfo['close_pay'] = 1;
        cache('site_base_info', $siteBaseInfo);
    }
    return $siteBaseInfo;
}

/**
 * 获取seo相关设置
 * @author $dreamer
 * @param int $byUid
 * @return mixed|null
 */
function get_seo_info($byUid = 0, $domain = '')
{
    $siteBaseInfo = null;
    if ($byUid <= 0) {
        //默认域名直接从配置信息中取seo信息，否则从站群中取seo信息
        if (!empty($domain)) {

            $domain = str_replace(['http://', 'https://'], '', trim($domain));
            $siteBaseInfo = cache('site_base_info_' . md5($domain));

            if ($siteBaseInfo) return $siteBaseInfo;

            $websiteInfo = Db::name('website_group_setting')->where(['domain' => $domain])->find();
            if ($websiteInfo) {
                $siteBaseInfo = [
                    'site_logo_mobile' => $websiteInfo['site_logo_mobile'],
                    'site_logo' => $websiteInfo['logo_url'],
                    'site_title' => $websiteInfo['site_title'],
                    'site_keywords' => $websiteInfo['site_keywords'],
                    'site_description' => $websiteInfo['site_description'],
                    'site_statis' => $websiteInfo['site_statis'],
                    'friend_link' => $websiteInfo['friend_link'],
                    'site_icp' => $websiteInfo['site_icp'],
                ];
                cache('site_base_info_' . md5($domain), $siteBaseInfo);

                return $siteBaseInfo;
            }
        }

        $siteBaseInfo = cache('site_base_info');

        if (empty($siteBaseInfo)) {
            $baseConfig = get_config_by_group('base');
            $siteBaseInfo['site_logo_mobile'] = $baseConfig['site_logo_mobile'];
            $siteBaseInfo['site_logo'] = $baseConfig['site_logo'];
            $siteBaseInfo['site_title'] = $baseConfig['site_title'];
            $siteBaseInfo['site_keywords'] = $baseConfig['site_keywords'];
            $siteBaseInfo['site_description'] = $baseConfig['site_description'];
            cache('site_base_info', $siteBaseInfo);
        }
        return $siteBaseInfo;
    } else {
        $siteBaseInfo = cache("site_base_info_{$byUid}");
        if (empty($siteBaseInfo)) {
            $userSiteConfig = Db::name('member')->field('agent_config,is_agent')->where(['id' => $byUid])->find();
            if (isset($userSiteConfig['is_agent']) && $userSiteConfig['is_agent'] == 1) {
                $agent_config = preg_replace_callback('#s:(/d+):"(.*?)";#s', function ($match) {
                    return 's:' . strlen($match[2]) . ':"' . $match[2] . '";';
                }, $userSiteConfig['agent_config']);
                $userSiteConfig = unserialize($agent_config);
                $baseConfig = get_seo_info();
                $siteBaseInfo['site_logo_mobile'] = empty($userSiteConfig['site_logo_mobile']) ? $baseConfig['site_logo_mobile'] : $userSiteConfig['site_logo_mobile'];
                $siteBaseInfo['site_logo'] = empty($userSiteConfig['site_logo']) ? $baseConfig['site_logo'] : $userSiteConfig['site_logo'];
                $siteBaseInfo['site_title'] = empty($userSiteConfig['site_title']) ? $baseConfig['site_title'] : $userSiteConfig['site_title'];
                $siteBaseInfo['site_keywords'] = empty($userSiteConfig['site_keywords']) ? $baseConfig['site_keywords'] : $userSiteConfig['site_keywords'];
                $siteBaseInfo['site_description'] = empty($userSiteConfig['site_description']) ? $baseConfig['site_description'] : $userSiteConfig['site_description'];
                //cache("site_base_info_{$byUid}",$siteBaseInfo);
            } else {
                return false;
            }
        }

        return $siteBaseInfo;
    }
}

/**
 * 返回隐藏部分字符串后的邮箱地址
 * @author $dreamer
 * @param $mailUrl
 * @return string
 */
function hidden_mail_str($mailUrl)
{
    if (get_str_format_type($mailUrl) != 'email') {
        return '';
    }

    $mailStrArr = explode('@', $mailUrl);
    $frontStr = array_shift($mailStrArr);

    $mailBackStr = '@' . implode('', $mailStrArr);

    $frontStrLen = strlen($frontStr);

    if ($frontStrLen > 3) {
        return substr($frontStr, 0, 2) . "***" . substr($frontStr, $frontStrLen - 1, 1) . $mailBackStr;
    }
    return $frontStr . "***" . $mailBackStr;
}

/**
 * 检测是否为手机终端
 * $Dreamer
 */
function is_mobile()
{
    $_SERVER['ALL_HTTP'] = isset($_SERVER['ALL_HTTP']) ? $_SERVER['ALL_HTTP'] : '';
    $mobile_browser = '0';
    if (preg_match('/(up.browser|up.link|mmp|symbian|smartphone|midp|wap|phone|iphone|ipad|ipod|android|xoom)/i', strtolower($_SERVER['HTTP_USER_AGENT'])))
        $mobile_browser++;
    if ((isset($_SERVER['HTTP_ACCEPT'])) and (strpos(strtolower($_SERVER['HTTP_ACCEPT']), 'application/vnd.wap.xhtml+xml') !== false))
        $mobile_browser++;
    if (isset($_SERVER['HTTP_X_WAP_PROFILE']))
        $mobile_browser++;
    if (isset($_SERVER['HTTP_PROFILE']))
        $mobile_browser++;
    $mobile_ua = strtolower(substr($_SERVER['HTTP_USER_AGENT'], 0, 4));
    $mobile_agents = array(
        'w3c ',
        'acs-',
        'alav',
        'alca',
        'amoi',
        'audi',
        'avan',
        'benq',
        'bird',
        'blac',
        'blaz',
        'brew',
        'cell',
        'cldc',
        'cmd-',
        'dang',
        'doco',
        'eric',
        'hipt',
        'inno',
        'ipaq',
        'java',
        'jigs',
        'kddi',
        'keji',
        'leno',
        'lg-c',
        'lg-d',
        'lg-g',
        'lge-',
        'maui',
        'maxo',
        'midp',
        'mits',
        'mmef',
        'mobi',
        'mot-',
        'moto',
        'mwbp',
        'nec-',
        'newt',
        'noki',
        'oper',
        'palm',
        'pana',
        'pant',
        'phil',
        'play',
        'port',
        'prox',
        'qwap',
        'sage',
        'sams',
        'sany',
        'sch-',
        'sec-',
        'send',
        'seri',
        'sgh-',
        'shar',
        'sie-',
        'siem',
        'smal',
        'smar',
        'sony',
        'sph-',
        'symb',
        't-mo',
        'teli',
        'tim-',
        'tosh',
        'tsm-',
        'upg1',
        'upsi',
        'vk-v',
        'voda',
        'wap-',
        'wapa',
        'wapi',
        'wapp',
        'wapr',
        'webc',
        'winw',
        'winw',
        'xda',
        'xda-'
    );
    if (in_array($mobile_ua, $mobile_agents))
        $mobile_browser++;
    if (strpos(strtolower($_SERVER['ALL_HTTP']), 'operamini') !== false)
        $mobile_browser++;
    // Pre-final check to reset everything if the user is on Windows
    if (strpos(strtolower($_SERVER['HTTP_USER_AGENT']), 'windows') !== false)
        $mobile_browser = 0;
    // But WP7 is also Windows, with a slightly different characteristic
    if (strpos(strtolower($_SERVER['HTTP_USER_AGENT']), 'windows phone') !== false)
        $mobile_browser++;
    if ($mobile_browser > 0)
        return 1;
    else
        return 0;
}

/**
 * 获取当前主域名
 * $Dreamer
 */
function get_top_domain($domain)
{
    $protocol = ['http://', 'https://'];
    $domain = str_replace($protocol, '', $domain);
    $domainArr = explode('/', $domain);
    $domain = $domainArr[0];
    $domainArr = explode('.', $domain);
    array_shift($domainArr);
    $domain = implode('.', $domainArr);
    return $domain;
}

/**
 * 获取目录下的支付方式
 * @author  $dreamer
 * @date    2017/12/28
 */
function get_payment_list($path = '../extend/systemPay')
{

    $dir = @opendir($path);
    $___setPayment = true;
    $payLists = [];
    while (($file = @readdir($dir)) !== false) {
        if (preg_match('/^[a-zA-z]{1}.*?\.php$/', $file)) {
            include_once($path . DS . $file);
        }
    }
    @closedir($dir);
    foreach ($payLists as $key => $value) {
        asort($payLists[$key]);
    }

    return $payLists;
}

/**
 * 订单号生成
 * @author  $dreamer
 * @date    2017/12/28
 */
function create_order_sn()
{
    list($microSec, $sec) = explode(' ', microtime());
    $seed = $sec + (float)$microSec * 10000;
    srand($seed);
    $rand = rand(11111, 99999);
    return date('YmdHis') . $rand;
}

/**
 * 过滤数组中的空值项
 * @author  $dreamer
 * @date    2017/12/28
 */
function filterArray($arr)
{
    if (!is_array($arr)) return $arr;
    if (count($arr) <= 0) return $arr;
    $tmpArr = [];
    foreach ($arr as $key => $value) {
        if ($value == '') continue;
        $tmpArr[$key] = $value;
    }

    return $tmpArr;
}

/*----------------------------------------云转码相关函数----------------------start--------------------*/
/** 云转码播放密钥生成 **/
function create_yzm_play_sign()
{
    //$key = trim(get_config('yzm_play_secretkey'));
    $key = trim(get_config('yzm_api_secretkey'));
    if (empty($key)) return '';
    $time = time() . '000';
    $ip = request()->ip();
    $data = "timestamp=" . $time . "&ip=" . $ip;
    $padding = 16 - (strlen($data) % 16);
    $data .= str_repeat(chr($padding), $padding);
    $keySize = 16;
    $ivSize = 16;
    $rawKey = $key;
    $genKeyData = '';
    do {
        $genKeyData = $genKeyData . md5($genKeyData . $rawKey, true);
    } while (strlen($genKeyData) < ($keySize + $ivSize));
    $generatedKey = substr($genKeyData, 0, $keySize);
    $generatedIV = substr($genKeyData, $keySize, $ivSize);
    #return bin2hex(mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $generatedKey, $data, MCRYPT_MODE_CBC, $generatedIV));
    return bin2hex(openssl_encrypt($data, 'AES-128-CBC', $generatedKey, OPENSSL_RAW_DATA, $generatedIV)); //兼容 >=PHP7.1 $dreamer
}


//转换时间
function secondsToHour($seconds)
{
    if (intval($seconds) < 60) {
        $tt = "00:00:" . sprintf("%02d", intval($seconds % 60));
        return $tt;
    }
    if (intval($seconds) >= 60) {
        $h = sprintf("%02d", intval($seconds / 60));
        $s = sprintf("%02d", intval($seconds % 60));
        if ($s == 60) {
            $s = sprintf("%02d", 0);
            ++$h;
        }
        $t = "00";
        if ($h == 60) {
            $h = sprintf("%02d", 0);
            ++$t;
        }
        if ($t) {
            $t = sprintf("%02d", $t);
        }
        $tt = $t . ":" . $h . ":" . $s;
    }
    if (intval($seconds) >= 60 * 60) {
        $t = sprintf("%02d", intval($seconds / 3600));
        $h = sprintf("%02d", intval($seconds / 60) - $t * 60);
        $s = sprintf("%02d", intval($seconds % 60));
        if ($s == 60) {
            $s = sprintf("%02d", 0);
            ++$h;
        }
        if ($h == 60) {
            $h = sprintf("%02d", 0);
            ++$t;
        }
        if ($t) {
            $t = sprintf("%02d", $t);
        }
        $tt = $t . ":" . $h . ":" . $s;
    }
    if (!(int)$t) {
        $tt = $h . ":" . $s;
    }
    return $seconds > 0 ? $tt : '00:00:00';
}

//转换文件大小
function formatBytes($size)
{
    $units = array(' B', ' KB', ' MB', ' GB', ' TB');
    for ($i = 0; $size >= 1024 && $i < 4; $i++) $size /= 1024;
    return round($size, 2) . $units[$i];
}

/*----------------------------------------云转码相关函数----------------------end--------------------*/
/**
 * 写入金币/K币记录
 * @author  rusheng
 * @param user_id 用户id
 * @param gold 金币数
 * @param module 所属模块
 * @param explain 描述说明
 * @param $table 记录表
 * @date    2017/1/18
 */
function write_gold_log($param, $table = 'gold_log')
{
    $param['add_time'] = time();
    return db::name($table)->insert($param);
}

/** 获取目录或文件权限 */
function get_dir_chmod($dirName)
{
    $chmod = '';
    if (is_readable($dirName)) {
        $chmod = '可读,';
    }

    if (is_writable($dirName)) {
        $chmod .= '可写,';
    }

    if (is_executable($dirName)) {
        $chmod .= '可执行,';
    }

    return trim($chmod, ',');
}

/** 分解友情链接 */
function get_friend_link($baseConfig)
{
    if (!isset($baseConfig['friend_link']) || empty($baseConfig['friend_link'])) return false;

    $linksArr = explode("\n", $baseConfig['friend_link']);
    if (count($linksArr) < 1) return false;
    $linkList = [];
    foreach ($linksArr as $link) {
        $_arr = explode("|", $link);
        if (count($_arr) != 2) continue;
        $linkList[] = ['name' => $_arr[0], 'url' => str_replace(PHP_EOL, '', $_arr[1])];
    }
    return $linkList;
}

/** 兼容格式化时间 */
function safe_date($format = '', $timeStamp = '')
{

    $format = !empty($format) ? $format : 'Y/m/d';
    $timeStamp = !empty($timeStamp) ? $timeStamp : time();
    $date = new \DateTime('@' . $timeStamp);
    $date->setTimezone(new DateTimeZone('PRC'));

    return $date->format($format);
}

/**
 * 获取资源数据，为了方便前端获取数据
 * @author  rusheng
 * @param type 资源类型
 * @param limit 每页的数据条数
 * @param order 排序
 * @param where 查询条换
 * @param page 当前页数
 * @date    2018/2/2
 */
function get_content($param)
{
    $type = empty($param['type']) ? 'video' : $param['type'];
    $limit = empty($param['limit']) ? 20 : $param['limit'];
    $order = empty($param['order']) ? 'id desc' : $param['order'];
    $page = empty($param['page']) ? 1 : $param['page'];
    $start = ($page - 1) * $limit;
    $where = empty($param['where']) ? (($type == 'video') ? 'status = 1 and is_check=1  and pid = 0 ' : 'status = 1 and is_check=1 ') : $param['where'];
    $allowType = ['novel', 'video', 'atlas', 'image'];
    if (!in_array($type, $allowType)) return lang('Common8');
    $list = db::name($type)->where($where)->order($order)->limit($start, $limit)->select();
    return $list;
}

/**
 * 提成计算
 * @param memberId  初始传入消费者id
 * @param price 消费金额
 * @date    2020/4/13
 */
function cur_agent_divide($memberId, $price, $order_sn)
{
    if ($memberId > 0 && $price > 0) {
        $userDb = Db::name('member');
        // 查询订单信息
        $order = Order::get($order_sn);
        // 验证订单号是否存在
        if (!$order) return "订单不存在";
        // 验证金额，用户ID的真实性
        if ($order->price != $price || $order->user_id != $memberId) return '数据异常';
        // 验证是否已分成
        if ($order->is_divide) return "该订单已分成";
        // 上级提成比例
        //三级分销
        //$commission = get_config('recharge_reward');
        $level1 = get_config('level1');
        $level2 = get_config('level2');
        $level3 = get_config('level3');
        //上级会员ID
        $user1 = $userDb->field('id,pid')->where(['id' => $memberId])->find();
        $user1Id = $user1['pid']; //第一个分成的id
        $user2 = $userDb->field('id,pid')->where(['id' => $user1Id])->find();
        $user2Id = $user2['pid']; //第二个分成的id
        $user3 = $userDb->field('id,pid')->where(['id' => $user2Id])->find();
        $user3Id = $user3['pid']; //第三个分成的id

        if (intval($level1) > 0) {
            //一级分成
            $level1 = $level1 / 100;
            // 计算分成金额
            $comm_money = $price * $level1;
            //扣量
            $recharge_drop = $userDb->where(['id' => $user1Id])->find();
            if ($recharge_drop['recharge_drop'] !== '0.00') {
                $comm_money = $comm_money * $recharge_drop['recharge_drop'];
            }

            // 分成大于0
            if ($comm_money > 0) {
                $res1 = distribution($user1Id, $comm_money, $order_sn);
            }
        }

        if (intval($level2) > 0) {
            //二级分成
            $level2 = $level2 / 100;
            // 计算分成金额
            $comm_money = $price * $level2;
            // 分成大于0
            if ($comm_money > 0) {
                $res2 = distribution($user2Id, $comm_money, $order_sn);
            }
        }

        if (intval($level3) > 0) {
            //三级分成
            $level3 = $level3 / 100;
            // 计算分成金额
            $comm_money = $price * $level3;
            if ($comm_money > 0) {
                $res3 = distribution($user3Id, $comm_money, $order_sn);
            }
        }

        $order->is_divide = 1;
        $order->save();
    }
}

//分成
function distribution($memberId, $comm_money, $order_sn)
{
    //$memberId 需要分成的会员Id  $comm_money 分成金额   $order_sn订单号
    $userDb = Db::name('member');
    $user = $userDb->where(['id' => $memberId])->find();

    if ($memberId <= 0 || empty($user)) return 1;
    $res = $userDb->where(['id' => $memberId])->setInc('k_money', $comm_money);
    // 提成写入记录
    $insData = [
        'user_id' => $memberId,
        'point'   => $comm_money,
        'explain' => '下级充值分成',
        'module'  => 'Order',
        'add_time' => time(),
        'is_gold' => 2, // 1为金币 2为余额
        'type'    => 1  // 1为分成 2为提现
    ];
    //写入金币记录表
    return Db::name('account_log')->insert($insData);
}


/** 将内容生成二维码 */
function create_qr_cdoe($content)
{
    $coder = new Endroid\QrCode\QrCode($content);
    $coder->setErrorCorrectionLevel(Endroid\QrCode\ErrorCorrectionLevel::HIGH);
    header('Content-Type: ' . $coder->getContentType());
    echo $coder->writeString();
}

/** 判断微信端 */
function is_wechat()
{
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    if (strpos($user_agent, 'MicroMessenger') === false) {
        return false;
    } else {
        // 获取版本号
        #preg_match('/.*?(MicroMessenger\/([0-9.]+))\s*/', $user_agent, $matches);
        #echo '<br>Version:'.$matches[2];
        return true;
    }
}

/** 获取微信Openid */
function get_user_wechat_openid($appid, $secretKey)
{
    if (session('wx_openid')) return session('wx_openid');
    $request = request();
    $curUrl = $request->domain() . $request->url();
    if (!$request->param('code/s')) {
        $apiUrl = "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$appid}&redirect_uri={$curUrl}&response_type=code&scope=snsapi_base&state=test#wechat_redirect";
        header("Location:{$apiUrl}");
        exit;
    } else {
        $code = $request->param('code/s');
        $apiUrl = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$appid}&secret={$secretKey}&code={$code}&grant_type=authorization_code";

        try {
            $rs = (file_get_contents($apiUrl));
            $wxOpenid = json_decode($rs, true)['openid'];
            session('wx_openid', $wxOpenid);
        } catch (\Exception $exception) {
            return false;
        }
        return session('wx_openid');
    }
}

/** 获取公告 */
function get_notice()
{
    $notice = Db::name('notice')->where(array('status' => 1, 'out_time' => array('gt', time())))->order('sort asc')->select();
    return $notice;
}

///** 发送请求 */
//function sendRequest($url, $data = [], $isPost = true)
//{
//    $ch = curl_init();
//    if (empty($url)) return false;
//    curl_setopt($ch, CURLOPT_URL, $url);
//    curl_setopt($ch, CURLOPT_POST, $isPost);
//    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
//    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
//    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
//    $response = curl_exec($ch);
//    curl_close($ch);
//    return $response;
//}

/** 发送请求 */
function sendRequest($url, $data = [], $isPost = true)
{

    $ch = curl_init();
    if (empty($url)) return false;
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, $isPost);
    if (is_array($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }

    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}




/** 写log  */
function write_log($content, $fileName = 'logs.log')
{
    if (empty(trim($content))) return false;
    $log = "--------" . date('Ymd H:i:s') . "----------------------------------------------------\r\n";
    $log .= $content;
    $log .= "\r\n";

    $filePath = ROOT_PATH . 'logs/';

    if (!is_dir(dirname($filePath))) {
        mkdir(dirname($filePath), 0777, true);
    }

    return file_put_contents($filePath . $fileName, $log, FILE_APPEND);
}

/* 查询自定义分类 type => 类型 1视频 2为图片 3为资讯  */
function custom_class($type = '1')
{
    return Db::name('arealist')->where(array('status' => 1, 'type' => $type))->order('sort asc')->select();
}

/* 查询自定义分类设置别名 type => 类型 1视频 2为图片 3为资讯  */
function custom_another($type = '1')
{
    return Db::name('classset')->where(array('status' => 1, 'type' => $type))->find();
}

/* 查询自定义分类ids 分类ID  */
function custom_classfind($ids = '1')
{
    return Db::name('arealist')->where(array('status' => 1, 'id' => $ids))->find();
}

/**
 * 
 * APP代理分成函数，此函数只处理APP代理商分成 必传参数 *
 * @author thirteen
 * @param  * int $user_id => 消费者UID 
 * @param  * float $price => 消费金额
 * @param  * str $order_sn=> 订单号
 * @date   6/5/2019
 * 
 **/
function app_divide_into($user_id = '', $price = '0', $order_sn)
{
    // 验证必填参数
    if (empty($user_id)) return "请传参数用户UID";
    // 如果金额为0 则不执行下面业务逻辑
    if ($price < 1) return false;
    // 查询订单信息
    $order = Order::get($order_sn);
    // 验证订单号是否存在
    if (!$order) return lang('SysPay2');
    // 验证金额，用户ID的真实性
    if ($order->price != $price || $order->user_id != $user_id) return '数据异常';
    // 验证是否已分成
    if (Db::name('app_divide_log')->field('order_sn')->where("order_sn='$order_sn'")->find()) return "该订单已分成";
    // 后台运行
    @ignore_user_abort();
    // 取消脚本运行时间的超时上限
    @set_time_limit(0);
    // 开启异常处理机制
    Db::startTrans();
    // 创建监控区域
    try {
        // 标记消费者ID
        $user_sd = $user_id;
        // 生产用户数据表
        $userdb = Db::name('member');
        // 查询消费者信息
        $isuser = $userdb->field('pid,divide')->where("id='$user_id'")->find();
        // 如果消费者不存在，则不执行下面业务逻辑
        if (!$isuser) return false;
        // 推荐人ID
        $one_pid = $isuser['pid'];
        // 验证用户是否存在推荐人
        if (!empty($one_pid)) {
            // 初始计数器
            $i = 0;
            // 循环处理
            while (true) {
                $i++;
                // 查询上级信息并且为代理 is_agent 代理标识，1为代理，divide为分成比例，为区别APP与电脑手机端。大于0则为APP
                $oneinfo = $userdb->field('pid,divide')->where("is_agent='1' and divide>'0' and id='$one_pid'")->find();
                // 上上级UID
                $two_pid = $oneinfo['pid'];
                // 用户分成比例
                $udivide = $oneinfo['divide'];
                // 上级是否存在
                if ($oneinfo) {
                    /* -------分成比例s------- */
                    if ($i == 1) {
                        // 直属分成比例
                        $agent_divide = $udivide;
                    } else {
                        // 1级以上代理分成比例 用户分成比例-下级分成比例
                        $agent_divide = $udivide - $pdivide;
                    }
                    /* -------分成比例e------- */
                    // 计算分成金额
                    $divide_price = $price * ($agent_divide / 100);
                    // 写入分成至代理帐户
                    $row = $userdb->where(array('id' => $one_pid))->setInc('k_money', $divide_price);
                    // 拼接记录
                    $log_data = array(
                        'user_id' => $one_pid,
                        'user_sd' => $user_sd,
                        'gold'    => $divide_price,
                        'price'   => $price,
                        'module'  => 'Order',
                        'order_sn' => $order_sn,
                        'explain' => '代理分成'
                    );
                    // 提成写入记录
                    if ($row) {
                        if (!write_gold_log($log_data, 'app_divide_log')) {
                            throw new \Exception('测试异常处理!');
                        }
                    } else {
                        throw new \Exception('测试异常处理!');
                    }
                    // 标记当前代理代成比例，以便于上级计算分成
                    $pdivide = $udivide;
                    // 标记上级用户ID，以便于下次使用
                    $one_pid = $two_pid;
                    // 上级不存在则终止业务循环
                } else {
                    break;
                }
            }
        }
        // 提交
        Db::commit();
    } catch (\Exception $e) {
        // echo "错误信息:" . $e->getMessage();
        Db::rollback();
    }
}

/* 生成二维码并保存 */
function create_share_qr($pid = 0, $size = 8)
{
    //if(!empty($pid)){
    vendor("phpqrcode.phpqrcode");
    $url = get_config('web_server_url');
    if (empty($url) || $url == '#') {
        $url = "http://v.msvodx.com/appapi/download/pid/0";
    }
    $urls = $url . '/appapi/download/pid/' . $pid;
    // 纠错级别：L、M、Q、H
    $level = 'L';
    // 读取后台配置
    $wheres = "name in ('app_qr_size','app_qr_x','app_qr_y','introduce')";
    $config = Db::name('admin_config')->where($wheres)->column('name,value');
    // 二维码显示位置 x轴
    $qr_x = intval($config['app_qr_x']);
    // 二维码显示位置 y轴
    $qr_y = intval($config['app_qr_y']);
    // 点的大小，1至10,，请结合海报大小来调
    $size = intval($config['app_qr_size']);
    if ($size > 12) $size = 12;
    if ($size < 1) $size = 1;
    // 二维码图片保存经经
    $path = "qr/qr";
    // 判断目录是否存在 不存在则创建
    is_dir($path) or mkdir($path, 0777, true);
    // 生成的文件名
    $fileName = $path . '/uid_' . $pid . '.jpg';
    // 生成二维码
    \QRcode::png($urls, $fileName, $level, $size, 2);
    // 拆分远程海报地址，兼容php.ini未开相关配置
    $bigs = empty($config['introduce']) ? 'http://v.msvodx.com/qr/img/QR.jpg' : $config['introduce'];
    $arrs = parse_url($bigs);
    // 保存除域外的地址并去掉第一个/ 
    $bigImgPath = substr($arrs['path'], 1);
    // 用户二维码
    $qCodePath  = "qr/qr/uid_$pid.jpg";
    $bigImg     = imagecreatefromstring(file_get_contents($bigImgPath));
    $qCodeImg   = imagecreatefromstring(file_get_contents($qCodePath));
    list($qCodeWidth, $qCodeHight, $qCodeType) = getimagesize($qCodePath);
    // 二维码显示位置 239 x轴 580 为y轴
    imagecopymerge($bigImg, $qCodeImg, $qr_x, $qr_y, 0, 0, $qCodeWidth, $qCodeHight, 100);
    list($bigWidth, $bigHight, $bigType) = getimagesize($bigImgPath);
    // 生成新的海报并保存在本地
    imagejpeg($bigImg, 'qr/uid_' . $pid . '.jpg');
    //}
}

function route_switch($file)
{
    $a = explode('//', $file);
    $b = explode('/', $a[1]);
    $b = explode('.', $b[1]);
    // }
    //print_r($b);
    return strtolower($b[0]);
}

/* 转换语言编码 */
function transfer_language($language = 'zh_cn', $key)
{
    $_lang = include APP_PATH . '/lang/' . $language . '.php';
    return $_lang[$key];
}

/* 获取多线路地址 */
function get_video_multiline($url, $CUS)
{
    $URL2ARR = parse_url($url);
    $DIRPATH = explode('/', $URL2ARR['path']);
    $PORT = empty($URL2ARR['port']) ? '' : ':' . $URL2ARR['port'];
    // 拼接入口M3U8 
    $INDEXM3U8_URL = $URL2ARR['scheme'] . '://' . $URL2ARR['host'] . $PORT . '/' . $DIRPATH[1] . '/' . $DIRPATH[2];
    // 请求M3U8首页
    //$M3U8_CONTENT = file_get_contents($INDEXM3U8_URL.'/index.m3u8?sign='.create_yzm_play_sign());
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $M3U8_CONTENT = curl_exec($ch);
    curl_close($ch);
    $BITJSON[] = [
        'title' => $CUS['480'],
        'url'  => $url
    ];
    if (empty($M3U8_CONTENT)) {
        return $BITJSON;
    }
    if (!strpos($M3U8_CONTENT, 'RESOLUTION') !== false) {
        return $BITJSON;
    }

    $url = $INDEXM3U8_URL . '/index.m3u8?sign=' . create_yzm_play_sign();
    $M3U8_CONTENT = ltrim($M3U8_CONTENT, '#EXTM3U');
    // 拆分字符串
    $ARR_O = explode('#EXT-X-STREAM-INF:PROGRAM-ID=1,', $M3U8_CONTENT);
    if ($ARR_O[0] == 'file not found') {
        return $BITJSON;
    }
    /*
	if(strpos('www.jb51.net','jb51') !== false){ 
	 echo '包含jb51'; 
	}else{
	 echo '不包含jb51'; 
	}*/
    foreach ($ARR_O as $k => $v) {
        if (!empty(trim($v))) {
            $ARR_T = explode(',', $v);
            $ARRT1 = explode('=', $ARR_T[0]);
            $ARRT1 = $ARRT1[1] / 1000;
            $ARRT2 = explode('=', $ARR_T[1]);
            $ARRT2 = explode('x', $ARRT2[1]);
            $ARR_LIST[$k]['BANDWIDTH'] = $ARRT1;
            $ARR_LIST[$k]['RESOLUTION'] = $ARRT2[0];
        }
    }

    if (!empty($ARR_LIST)) {
        unset($BITJSON);
        foreach ($ARR_LIST as $k => $v) {
            $BITJSON[] = [
                'title' => empty($CUS[$v['RESOLUTION']]) ? $v['RESOLUTION'] : $CUS[$v['RESOLUTION']]
                //,'url'  => $INDEXM3U8_URL.'/'.$v['BANDWIDTH'].'kb/hls/index.m3u8'
                ,
                'url'  => $INDEXM3U8_URL . '/' . $v['BANDWIDTH'] . 'kb/hls/index.m3u8?sign=' . create_yzm_play_sign()
            ];
        }
    }

    return $BITJSON;
}

function dd($data)
{
    return halt($data);
}
//去除数组空格
function TrimArray($Input)
{
    if (!is_array($Input))
        return trim($Input);
    return array_map('TrimArray', $Input);
}

/**
 * @todo敏感词过滤，返回结果
 * @paramarray $list 定义敏感词一维数组
 * @paramstring $string 要过滤的内容
 * @returnstring $log 处理结果
 */

function sensitive($list, $string)
{

    $count = 0; //违规词的个数

    $sensitiveWord = ''; //违规词

    $stringAfter = $string; //替换后的内容

    $pattern = "/" . implode("|", $list) . "/i"; //定义正则表达式

    if (preg_match_all($pattern, $string, $matches)) { //匹配到了结果


        $patternList = array_filter($matches[0]); //匹配到的数组

        $count = count($patternList);

        $sensitiveWord = implode(',', $patternList); //敏感词数组转字符串

        $replaceArray = array_combine($patternList, array_fill(0, count($patternList), '*')); //把匹配到的数组进行合并，替换使用

        $stringAfter = strtr($string, $replaceArray); //结果替换

    }


    if ($count == 0) {

        $log = $string;
    } else {

        $log = $stringAfter;
    }

    return $log;
}
/*组装名称*/
function assembly_name($str)
{
    if (mb_strlen($str) > 3) $str = mb_substr($str, 0, 2, 'utf-8') . '***' . mb_substr($str, -2, 2, 'utf-8');
    return $str;
}
/**
 * 二维数组按照指定字段进行排序
 * @params array $array 需要排序的数组
 * @params string $field 排序的字段
 * @params string $sort 排序顺序标志 SORT_DESC 降序；SORT_ASC 升序
 */
function arraySequence($array, $field, $sort = 'SORT_DESC')
{
    $arrSort = array();
    foreach ($array as $uniqid => $row) {
        foreach ($row as $key => $value) {
            $arrSort[$key][$uniqid] = $value;
        }
    }
    array_multisort($arrSort[$field], constant($sort), $array);
    return $array;
}
/**
 * 二维数组按照指定的多个字段进行排序
 *
 * 调用示例：sortArrByManyField($arr,'id',SORT_ASC,'age',SORT_DESC);
 */
function sortArrByManyField()
{
    $args = func_get_args();
    if (empty($args)) {
        return null;
    }
    $arr = array_shift($args);
    if (!is_array($arr)) {
        throw new Exception("第一个参数应为数组");
    }
    foreach ($args as $key => $field) {
        if (is_string($field)) {
            $temp = array();
            foreach ($arr as $index => $val) {
                $temp[$index] = $val[$field];
            }
            $args[$key] = $temp;
        }
    }
    $args[] = &$arr; //引用值
    call_user_func_array('array_multisort', $args);
    return array_pop($args);
}
/****高精度计算start***/
/**
 * 精确加法

 * @param [type] $a [description]

 * @param [type] $b [description]

 */
function math_add($a, $b, $scale = '2')
{

    return bcadd($a, $b, $scale);
}
/**

 * 精确减法

 * @param [type] $a [description]

 * @param [type] $b [description]

 */
function math_sub($a, $b, $scale = '2')
{

    return bcsub($a, $b, $scale);
}
/**

 * 精确乘法

 * @param [type] $a [description]

 * @param [type] $b [description]

 */
function math_mul($a, $b, $scale = '2')
{

    return bcmul($a, $b, $scale);
}
/**

 * 精确除法

 * @param [type] $a [description]

 * @param [type] $b [description]

 */
function math_div($a, $b, $scale = '2')
{

    return bcdiv($a, $b, $scale);
}
/**

 * 比较大小

 * @param [type] $a [description]

 * @param [type] $b [description]

 * 大于 返回 1 等于返回 0 小于返回 -1

 */
function math_comp($a, $b, $scale = '5')
{

    return bccomp($a, $b, $scale); // 比较到小数点位数

}
/****高精度计算end***/
function secToTime($times)
{
    $result = '00时00分钟';
    if ($times > 0) {
        $hour = floor($times / 3600);
        $minute = floor(($times - 3600 * $hour) / 60);
        $second = floor((($times - 3600 * $hour) - 60 * $minute) % 60);
        $result = $hour . '时' . $minute . '分钟';
    }
    return $result;
}
function array_unique_fb($array)
{

    foreach ($array as $v) {

        $v = implode(",", $v); //降维,也可以用implode,将一维数组转换为用逗号连接的字符串

        $temp[] = $v;
    }
    $temp = array_unique($temp); //去掉重复的字符串,也就是重复的一维数组
    foreach ($temp as $k => $v) {

        $temp[$k] = explode(",", $v); //再将拆开的数组重新组装

    }
    return $temp;
}
function assoc_unique($arr, $key)
{

    $tmp_arr = array();

    foreach ($arr as $k => $v) {

        if (in_array($v[$key], $tmp_arr)) { //搜索$v[$key]是否在$tmp_arr数组中存在，若存在返回true

            unset($arr[$k]);
        } else {

            $tmp_arr[] = $v[$key];
        }
    }
    //sort($arr); //sort函数对数组进行排序
    return $arr;
}
function array_random($arr, $num)
{
    $randkey = array_rand($arr, $num);
    $randVal = [];
    if (!empty($randkey)) {
        foreach ($randkey as $k => $v) {
            $randVal[] = $arr[$v];
        }
    }
    return $randVal;
}
function rand_one($arr = array())
{
    $len = sizeof($arr, 1);
    $j = rand(0, $len - 1);
    return $arr[$j];
}

function get_conversion_quantity($number)
{
    if ($number >= 10000) {
        # 判断是否超过w
        $newNum = sprintf("%.1f", $number / 10000) . 'w';
    } elseif ($number >= 1000) {
        # 判断是否超过k sprintf("%.2f",$num)
        //$newNum = sprintf("%.1f", $number / 1000) . 'k';
        $newNum = $number;
    } else {
        $newNum = $number;
    }
    return $newNum;
}



function get_week_arr()
{
    //获取今天是周几，0为周日
    $this_week_num = date('w');

    $timestamp = time();

    if ($this_week_num == 0) {
        $timestamp = $timestamp - 86400;
    }
    $this_week_arr =  [
        'start' => strtotime(date('Y-m-d', strtotime("this week Monday", $timestamp))),  //本周一
        'end'  =>  strtotime(date('Y-m-d', strtotime("this week Sunday", $timestamp) + 86400)) //下周一
    ];
    return $this_week_arr;
}

/**获取整个目录下的文件名*/
function getFileName($dir)
{
    $array = array();
    //1、先打开要操作的目录，并用一个变量指向它
    //打开当前目录下的目录pic下的子目录common。
    $handler = opendir($dir);
    //2、循环的读取目录下的所有文件
    /* 其中$filename = readdir($handler)是每次循环的时候将读取的文件名赋值给$filename，为了不陷于死循环，所以还要让$filename !== false。一定要用!==，因为如果某个文件名如果叫’0′，或者某些被系统认为是代表false，用!=就会停止循环 */
    while (($filename = readdir($handler)) !== false) {
        // 3、目录下都会有两个文件，名字为’.'和‘..’，不要对他们进行操作
        if ($filename != '.' && $filename != '..') {
            // 4、进行处理
            array_push($array, $filename);
        }
    }
    //5、关闭目录
    closedir($handler);
    return $array;
}
