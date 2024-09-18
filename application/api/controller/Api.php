<?php

namespace app\api\controller;

use think\Controller;
use think\Db;
use think\Request;
use phpmailer\SendEmail;
use sms\Sms;

class Api extends Controller
{
    public function __construct(Request $request)
    {
        //$origin=$request->header('origin'); //"http://sp.msvodx.com"
        //$allowDomain=['msvodx.com','meisicms.com'];
        header("Access-Control-Allow-Origin: *");
        header('Access-Control-Allow-Headers: X-Requested-With,X_Requested_With');
        $noAuthAct = [
            'getatlas','getcaptcha','uploader_video_img','is_login','createqrcode','getversion','get_beat_log','syncaddvideo','addresourcefromgather','get_head_portrait','logouts'
        ];
        if (!in_array(strtolower($request->action()), $noAuthAct)) {
            if ($request->isPost() && $request->isAjax()) {
            } else {
                $returnData = ['statusCode' => '4001', 'error' => '请求方式错误'];
                die(json_encode($returnData, JSON_UNESCAPED_UNICODE));
            }

        }
    }

    public function _empty()
    {
        $returnData = ['statusCode' => '4001', 'error' => '请求接口不存在'];
        die(json_encode($returnData, JSON_UNESCAPED_UNICODE));
    }

    /* 获取心跳时间 */
    public function get_beat_log(Request $request)
    {
        $user_id = $request->param('user_id/d', 0);
        if(!empty($user_id)){
            $log = file_get_contents(ROOT_PATH.'beatlog/throb_time_'.$user_id.'.json');
            if(!empty($log)){
                die(json_encode(['code' => 1, 'msg' => '获取成功', 'data' => $log], JSON_UNESCAPED_UNICODE));
            }else{
                die(json_encode(['code' => 0, 'msg' => '获取失败'], JSON_UNESCAPED_UNICODE));
            }
        }
    }

    /* 检测登录 */
    public function is_login()
    {
        $user_id = session('member_id');
        $access_token = session('access_token');
        //验证登陆
        if (intval($user_id) <= 0) die(json_encode(['resultCode' => 4005, 'error' => '用户未登陆']));
        $user_info = Db::name('member')->where(array('id' => $user_id, 'access_token' => $access_token))->find();
        if (!$user_info) {
            session('member_id', '0');
            session('member_info', '');
            session('access_token', '');
            die(json_encode(['resultCode' => 4005, 'error' => '该用户已在其他地方登陆']));
        }
        die(json_encode(['resultCode' => 0, 'message' => '用户已经登录']));
    }

    /*  登陆接口 */
    public function login(Request $request)
    {
        if (get_config('verification_code_on')) {
            $verifyCode = $request->post('verifyCode/s', '', '');
            if (!captcha_check($verifyCode)) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:验证码错误']));
        }
        $userName = $request->post('userName/s', '', '');
        $password = $request->post('password/s', '', '');

        if (empty($userName) || empty($password)) die(json_encode(['statusCode' => 4004, 'error' => '参数格式不正确:用户名或密码不能为空']));

        if ($loginRs = check_member_password($userName, $password)) {
            if (is_array($loginRs) && isset($loginRs['rs']) && isset($loginRs['msg'])) {
                die(json_encode(['statusCode' => 4005, 'error' => $loginRs['msg']]));
            }
            $user_id = session('member_id');
            $login_reward = get_config('login_reward');
            if ($login_reward) {
                $today = strtotime(date('Y-m-d'));
                $yesterday = $today + (24 * 3600 - 1);
                $where = "user_id =  $user_id and (add_time between $today and $yesterday)";
                $result = Db::name('login_log')->where($where)->find();
                // if (empty($result)) {
                //     Db::name('member')->where(array('id' => $user_id))->setInc('money', $login_reward);
                //     $gold_log_data = array(
                //         'user_id' => $user_id,
                //         'gold' => $login_reward,
                //         'module' => 'login',
                //         'explain' => '登录奖励'
                //     );
                //     write_gold_log($gold_log_data);
                // }
            }
            $log_data = array(
                'user_id' => $user_id,
                'add_time' => time(),
                'ip' => $request->ip(),
            );
            Db::name('login_log')->insert($log_data);

            Db::name('member')->update(['last_time' => time(), 'last_ip' => $request->ip(), 'id' => $user_id, 'login_count' => '+1']);
            $member = model('Member')->get($user_id);
            $member->last_time = time();
            $member->last_ip = $request->ip();
            $member->login_count++;
            $member->save();

            die(json_encode(['statusCode' => 0, 'message' => '登陆成功']));
        }
        die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:用户名或密码不正确']));
    }

    /* 第三方登录绑定用户信息 */
    public function binding_third(Request $request)
    {
        $data['username'] = $request->post('username/s', '', '');
        $data['password'] = $request->post('password/s', '', '');
        $data['confirm_password'] = $request->post('confirm_password/s', '', '');
        if (empty($data['username']) || empty($data['password'])) die(json_encode(['statusCode' => 4004, 'error' => '参数格式不正确:用户名或密码不能为空']));
        if ($data['password'] != $data['confirm_password']) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:两次密码不一致']));

        $member_id = session('member_id');
        $member_infos = session('member_info');
        if(check_member_password($data['username'], $data['password'])){
            $member_info = Db::name('member')->where(array('id' => $member_id))->find();
            $openid = empty($member_info['openid']) ?  '' : $member_info['openid'];
            $unionid = empty($member_info['unionid']) ?  '' : $member_info['unionid'];
            $bindata = array(
                'openid' => $openid,
                'unionid' => $unionid,
            );
            if(!empty($member_info['nickname']) ){
                $bindata['nickname'] = empty($member_infos['nickname']) ? $member_info['nickname'] :  $member_infos['nickname'];
            }
            if(!empty($member_info['headimgurl']) ){
                $bindata['headimgurl'] = empty($member_infos['headimgurl']) ? $member_info['headimgurl'] :  $member_infos['headimgurl'];
            }
            $bindata['sex'] = empty($member_infos['sex']) ? $member_info['sex'] :  $member_infos['sex'];
            $member_id = session('member_id');
            Db::name('member')->where(array('id' => $member_id))->update($bindata);
            Db::name('member')->where(array('id' => $member_info['id']))->delete();
            die(json_encode(['statusCode' => 0, 'error' => '绑定成功', 'memberId' => $member_id]));
        }else{
            $check_member  = Db::name('member')->where(array('username' => $data['username']))->find();
            if(!empty($check_member )){
                die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:密码错误' ]));
            }
            $userdata['username'] = $data['username'];
            $result = $this->validate($data, 'Member.username_register');
            if ($result !== true) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:' . $result]));
            //添加账号处理
            $userdata['password'] = encode_member_password($data['password']);
            $userdata['last_time'] = $userdata['add_time'] = time();
            $userdata['pid'] = empty(session("cur_agent_id")) ? 0 : session("cur_agent_id");

            if (db::name('member')->where(['id' => $member_id])->update($userdata)) {
                $register_reward = get_config('register_reward');
                if ($register_reward) {
                    $gold_log_data = array(
                        'user_id' => $member_id,
                        'gold' => $register_reward,
                        'module' => 'register',
                        'explain' => '注册奖励'
                    );
                    write_gold_log($gold_log_data);
                    Db::name('member')->where(array('id' => $member_id))->setInc('money', $register_reward);
                }
                check_member_password($userdata['username'], $data['password']);
                die(json_encode(['statusCode' => 0, 'error' => '绑定成功', 'memberId' => $member_id]));
            } else {
                die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败']));
            }
        }
    }

    /* 注册 */
    public function register(Request $request)
    {
        $data['username'] = $request->post('username/s', '', '');
        $data['password'] = $request->post('password/s', '', '');
        $userdata['nickname'] = $data['nickname'] = $request->post('nickname/s', '', '');
        $data['verifyCode'] = trim($request->post('verifyCode/s', '', ''));         //邮箱or手机的验证码
        $data['confirm_password'] = $request->post('confirm_password/s', '', '');

        if (empty($data['username']) || empty($data['password'])) die(json_encode(['statusCode' => 4004, 'error' => '参数格式不正确:用户名或密码不能为空']));
        if ($data['password'] != $data['confirm_password']) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:两次密码不一致']));
        $register_validate = get_config('register_validate');
        if ($register_validate) {
            if (empty($data['verifyCode'])) die(json_encode(['statusCode' => 4004, 'error' => '参数格式不正确:验证码不能为空']));
            $userType = get_str_format_type($data['username']);
            if($register_validate == 1){
                if($userType != 'email') die(json_encode(['statusCode' => 4004, 'error' => '参数格式不正确:请输入正确的邮箱地址']));
                $userdata['username'] = $userdata['email'] = $data['email'] = $data['username'];
                $session_name = 'register_email_code';
            }else{
                if($userType != 'mobile') die(json_encode(['statusCode' => 4004, 'error' => '参数格式不正确:请输入正确的手机号码']));
                $userdata['username'] = $userdata['tel'] = $data['tel'] = $data['username'];
                $session_name = 'register_mobile_code';
            }
            $result = $this->validate($data, 'Member.' . $userType . '_register');
            if ($result !== true) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:' . $result]));
            //验证验证码
            $codeData = session($session_name);
            if (empty($codeData)) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:验证码错误']));
            if ($codeData['username'] != $data['username']) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:验证码错误']));
            if ($codeData['code'] != $data['verifyCode']) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:验证码错误']));
            if ($codeData['expiry_time'] < (time() - 60 * 30)) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:验证码过期']));
            session($session_name, null);
        } else {
            if (get_config('verification_code_on')) {
                $verifyCode = $request->post('verifyCode/s', '', '');
                if (!captcha_check($verifyCode)) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:验证码错误']));
            }
            $userdata['username'] = $data['username'];
            $result = $this->validate($data, 'Member.username_register');
            if ($result !== true) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败:' . $result]));

        }
        //添加账号处理
        $userdata['headimgurl'] = '/static/images/user_dafault_headimg.jpg';
        $userdata['password'] = encode_member_password($data['password']);
        $userdata['last_time'] = $userdata['add_time'] = time();
        $userdata['pid'] = empty(session("cur_agent_id")) ? 0 : session("cur_agent_id");
        if (db::name('member')->insert($userdata)) {
            $member_id = Db::name('member')->getLastInsID();
            $register_reward = get_config('register_reward');
            if ($register_reward) {
                $gold_log_data = array(
                    'user_id' => $member_id,
                    'gold' => $register_reward,
                    'module' => 'register',
                    'explain' => '注册奖励'
                );
                write_gold_log($gold_log_data);
                Db::name('member')->where(array('id' => $member_id))->setInc('money', $register_reward);
            }
            check_member_password($userdata['username'], $data['password']);

            if(session('cpa_uid')==4){
                if(function_exists('ad_cpa_member_reg')) ad_cpa_member_reg($member_id); //CPA
            }

            die(json_encode(['statusCode' => 0, 'error' => '注册成功', 'memberId' => $member_id]));
        } else {
            die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败']));
        }
    }

    /* 注册获取证码接口 */
    public function getRegisterCode(Request $request)
    {
        $username = $request->param('username/s', '', '');
        $reg_username = $request->param('reg_username/s', '用户名');
        $userinfo = db::name('member')->where(array('username' => $username))->find();
        if (!empty($userinfo)) die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败: 该'.$reg_username.'已经存在']));
        $userType = get_str_format_type($username);
        if ($userType == 'string') die(json_encode(['statusCode' => 4005, 'error' => '数据验证失败: '.$reg_username.'格式不正确']));
        if (empty($username)) die(json_encode(['statusCode' => 4003, 'error' => '缺少请求参数:'.$reg_username.'不能为空']));
        //$code = get_random_str(6);
        $code = rand(111111, 999999);
        $session_name = array(
            'email' => 'register_email_code',
            'mobile' => 'register_mobile_code',
        );
        $codeData = array(
            'username' => $username,
            'code' => $code,
            'expiry_time' => (time() + 5 * 60),
        );
        session($session_name[$userType], $codeData);
        switch ($userType) {
            case 'email':
//                    邮箱发送验证码处理
                $site_title = get_config('site_title');
                $SendEmail = new SendEmail();
                $param = array(
                    'email' => $username,
                    'username' => $username,
                    'subject' => $site_title . '注册验证邮件',
                    'body' => '亲爱的用户，您好!您注册验证码为<h2 style="color:green;">' . $code . '</h2>',
                );
                $msg = '请登录您的邮箱查看验证码';
                $SendEmail->send($param);
                break;
            case 'mobile':
                $Sms = new Sms();
                $sgt = get_config('sms_api_signature');
                $sgt = trim($sgt);
                $cms = str_replace('{s6}', '', get_config('sms_api_reg'));
                $cms = trim($cms);
                $param = array(
                    'tel' => $username,
                    'msg' => '【'.$sgt.'】'.$cms.$code
                );
                $Sms->send($param);
                //手机发送验证码处理
                $msg = '请查看您的手机短信验证码';
                break;
        }
        die(json_encode(['statusCode' => 0, 'error' => '发送成功，' . $msg]));
    }

    /* 退出登陆接口 */
    public function logout(Request $request)
    {
        if(member_logout()){
            die(json_encode(['statusCode' => 0, 'message' => '退出成功']));
        }
    }

    /* 代理退出登陆接口 */
    public function logouts()
    {
        if(member_logout()){
            // die(json_encode(['statusCode' => 0, 'message' => '退出成功']));
            $this->redirect('/login/login');
        }
    }

    /**
     * 验证码接口
     */

    public function getCaptcha()
    {
        return create_captcha();
    }

    /** 刷新缓存 */
    function adminRefreshCache()
    {
        // clear cache data
        cache(null);
        // clear temp files
        array_map('unlink', glob(TEMP_PATH.'*.php'));
        // clear log files
        foreach (glob(LOG_PATH."/*") as $file) {
            array_map('unlink', glob($file."/*.*"));
            @rmdir($file);
        }
        // clear api cacheData
        array_map('unlink', glob(ROOT_PATH.'cache/*.json'));
        //
        die(json_encode(['statusCode' => 0, 'message' => '刷新成功']));
    }


    function get_login_status()
    {
        $data = check_is_login();
        die(json_encode($data));
    }

    /** 生成二维码 */
    function createQrCode()
    {
        $content = request()->param('content/s');
        if (empty(trim($content))) {
            $content = 'content is empty.';
        } else {
            $content = base64_decode($content);
        }
        return create_qr_cdoe($content);
    }

    /** 第三方数据采集入库接口  */
    function addResourceFromGather(Request $request)
    {
        $key = trim($request->param('key'));
        if (!isset($key) || empty($key)) exit('错误:采集密钥不能为空');
        $gatherConf = get_config_by_group('gather');
        $gatherIsOpen = $gatherConf['resource_gather_status'] ? true : false;
        if (!$gatherIsOpen) exit('采集接口已关闭');

        if ($key != $gatherConf['resource_gather_key']) exit('密钥错误');

        $thumbnail = trim($request->post('thumbnail/s', '')) ? trim($request->post('thumbnail/s', '')) : "/static/images/images_default.png";
        $class_id = (isset($gatherConf['resource_gather_video_classid'])&&$gatherConf['resource_gather_video_classid']>0)?$gatherConf['resource_gather_video_classid']:0;
        $area_id = (isset($gatherConf['resource_gather_area_id'])&&$gatherConf['resource_gather_area_id'] > 0) ? $gatherConf['resource_gather_area_id']:0;
        $gold = (isset($gatherConf['resource_gather_video_view_gold'])&&$gatherConf['resource_gather_video_view_gold']>0)?$gatherConf['resource_gather_video_view_gold']:0;
        $title = trim($request->post('title/s', ''));
        if (empty($title)) exit('错误:视频标题不能为空');
        //$type = get_config('thirdparty_type');
        $type =  $gatherConf['thirdparty_type'];
        $table = 'video';
        if ($type == 2) $table = 'shortvideo';
        $db = Db::name($table);

        if ($db->where(['title'=>$title])->find()) exit('错误:视频标题已存在，请勿重复发布');
        $url = trim($request->post('url/s', ''));
        if (empty($url)) exit('错误:视频播放地址不能为空');
        if ($db->where(['url'=>$url])->find()) exit('错误:视频播放地址已存在，请勿重复发布');
        //$str = empty($request->post('download_url'))?'':'@'.trim($request->post('download_url'));

        if ($type == 2) {
            $member_ids =Db::name('member')->where('robot',1)->column('id');
            if(empty($member_ids)) exit('请添加一批机器人账号！！');
            $member_id = rand_one($member_ids);
            $data = [
                'title' => $title,
                'url' => $url,
                'down_url' => $request->post('download_url'),
                'class_id' => $gatherConf['resource_gather_shortvideo_classid'] ? : 0,
                'thumbnail' => $thumbnail,
                'user_id' =>$member_id,
                'add_time' => time(),
                'gold' => (int)$gatherConf['resource_shortvideo_view_gold'],
                'is_check'=> 1,
                'status' => 1,
                'gather' => 1
            ];
        } else {
            $data = [
                'title' => $title //标题
                , 'info' => $request->post('info')//说明
                , 'short_info' => $request->post('short_info')//短说明
                , 'key_word' => $request->post('key_word')//关键词
                , 'url' => '2@'.$url //视频播放地址 Mp4/m3u8
                , 'download_url' => $request->post('download_url')//视频下载地址
                , 'add_time' => $curTime = time()
                , 'update_time' => $curTime
                , 'play_time' => $request->post('play_time')//播放时间
                , 'click' => $request->post('click/d', 1)//观看数
                , 'good' => $request->post('good/d', 1)//点赞数
                , 'thumbnail' => $thumbnail//封面图
                , 'user_id' => 0//上传者id
                , 'class' => $class_id
                , 'area_id' => $area_id
                , 'tag' => $request->post('tag/d', 0) //标签//标签
                , 'status' => 1
                , 'gold' => $gold
                , 'is_check' => 1
                , 'gather' => 1
            ];
        }
        $rs = $db->insert($data);
        if ($rs) exit('采集数据录入成功');
        exit('采集数据录入失败');
    }

    /* 云转码入库 */
    public function syncAddVideo()
    {
        //是否开启日志输出
        $debug = true;

        $attachmentConfig = get_config_by_group('attachment');
        $videoConfig = get_config_by_group('video');
        $videoConfig = array_merge($attachmentConfig, $videoConfig);

        //参数设置
        $config = array();
        $config['videoweb'] = $videoConfig['yzm_upload_url'];           //post domain
        $config['weburl'] = $videoConfig['yzm_video_play_domain'];     //thumb domain
        $config['videourl'] = $videoConfig['yzm_video_play_domain'];   //play domain
        $config['key'] = $videoConfig['yzm_api_secretkey'];            //API密钥
        $config['istime'] = "1"; //是否开启时间转换 转码后默认时间格式为 秒 是否需要转换为 00:00:00 时间格式入库(0为不转换,1为转换)
        $config['issize'] = "1"; //是否开启文件大小转换 转码后默认时间格式为 byt 是否需要转换为 Gb Mb Kb形式(0为不转换,1为转换)
        $config['isurl'] = "0"; //是否开启url转码 即中文链接会进行转码后入库 (0为不转换,1为转换)
        //参数设置结束
        $task = file_get_contents("php://input");
        $logDir = dirname(__FILE__) . "../../../syncAddVideo.log";

        file_put_contents($logDir, "\r\n" . str_repeat('--', 50).'1' . $task, FILE_APPEND);

        $type = get_config('warehousing_type');
        try {
            if ($task) {
                //logError('视频数据:'.$task);
                $arr = json_decode(str_replace('\\', '/', $task), true);
                if ($debug) file_put_contents($logDir, "\r\n".'---2---'. var_export($arr, 1), FILE_APPEND);

                $taskid = $arr['shareid']; //old: taskid

                if (!$taskid) {
                    file_put_contents($logDir, "\r\n" . str_repeat('--', 50) .'---3---'. "shareid参数错误", FILE_APPEND);
                    die();
                }
                $table = 'video';
                if($type == 2) $table = 'shortvideo';

                $vDb = Db::name($table);
                if(!empty($taskid)){
                    if(Db::name('video')->where(['vid'=>$taskid])->find()) die("视频已存在");
                    if(Db::name('shortvideo')->where(['vid'=>$taskid])->find()) die("视频已存在");
                    file_put_contents($logDir, "\r\n"."视频已存在", FILE_APPEND);
                }

                $json = file_get_contents($config['videoweb'] . "/api/gettask?id=" . $taskid . "&key=" . $config['key']);

                if ($debug) file_put_contents($logDir, "\r\n" .'---4---'. $config['videoweb'] . "/api/gettask?id=" . $taskid . "&key=" . $config['key'] . "\r\n" . $json, FILE_APPEND);

                //logError('视频对应数据:'.$json);
                if ($json) {
                    #if ($json == "key error.") {
                    if (!(stripos($json, 'key error') === false)) {
                        file_put_contents($logDir, "\r\nAPI密钥错误\r\n", FILE_APPEND);
                        die;
                    }
                    $varr = json_decode($json, true);

                    $videotime = $config['istime'] ? secondsToHour($varr[0]['metadata']['time']) : $varr[0]['metadata']['time'];
                    $videosize = $config['issize'] ? formatBytes($varr[0]['metadata']['length']) : $varr[0]['metadata']['length'];
                    $videoresolution = $varr[0]['metadata']['resolution']; //视频原始分辨率
                    $videopic = $varr[0]['output']['pic1'];
                    $orgfile = $varr[0]['orgfile'];
                    $rpath = addslashes(trim($varr[0]['rpath']));
                    $videopic = str_replace($varr[0]['outdir'], $config['weburl'], $videopic);
                    $videopic = str_replace('\\', '/', $videopic); //视频图片
                    $videorpath = $varr[0]['rpath']; //播放地址
                    $tarr = $arr = explode('.', $orgfile);
                    $title = addslashes(trim($tarr[0]));
                    if ($config['isurl']) {
                        $videorpath = str_replace('\\', '/', $videorpath);
                        $videorpath = urlencode($videorpath);
                        $videopic = urlencode($videopic);
                        $videopic = str_replace('%2F', '/', $videopic);
                        $videopic = str_replace('%3A', ':', $videopic);
                        $videopic = str_replace('+', ' ', $videopic);
                        $videorpath = str_replace('%2F', '/', $videorpath);
                        $videorpath = str_replace('+', ' ', $videorpath);
                    }
                    $videorpath = str_replace('\\', '/', $videorpath);
                    //获取mp4的路径地址 "rpath":"\20170721\GR1ZtJBl"
                    $rpath_arr = explode("/", $videorpath);
                    $mp4_path = $config['videourl'] . $videorpath . "/mp4/" . end($rpath_arr) . ".mp4";

                    //$videorpath=$config['videourl']."/".$videorpath."/index.m3u8";
                    $videorpath = $config['videourl'] . $videorpath . "/index.m3u8";
                    $share = $config['videourl'] . "/share/" . $taskid;
                    //$str = empty($mp4_path)?'':'@'.trim($mp4_path);

                    if($type == 2){
                        $member_ids =Db::name('member')->where('robot',1)->column('id');
                        $member_id = rand_one($member_ids);
                        $videoData = [
                            'title' => $title,
                            'url' => $videorpath,
                            'down_url' => $mp4_path,
                            'class_id' => $videoConfig['sync_add_shortvideo_classid'],
                            'thumbnail' => $videopic,
                            'user_id' =>$member_id,
                            'add_time' => time(),
                            'gold' => (int)$videoConfig['sync_shortvideo_view_gold'],
                            'vid' => $taskid,
                            'is_check'=> 1,
                            'status' => 1
                        ];
                        if($debug) file_put_contents($logDir, "\r\n".'---5---' . var_export($videoData, 1), FILE_APPEND);
                        $vDb->insert($videoData);
                    }else{
                        $videoData = [
                            'title' => $title,
                            'url' => '2@'.$videorpath,
                            'download_url' => $mp4_path,
                            'play_time' => $videotime,
                            'class' => $videoConfig['sync_add_video_classid'],
                            'area_id' => $videoConfig['sync_video_area_id'],
                            'thumbnail' => $videopic,
                            'add_time' => time(),
                            'update_time' => time(),
                            'gold' => (int)$videoConfig['sync_video_view_gold'],
                            'vid' => $taskid,
                            'is_check'=> 1,
                            'status' => 1
                        ];
                        if($debug) file_put_contents($logDir, "\r\n" .'---6---'. var_export($videoData, 1), FILE_APPEND);
                        $vDb->insert($videoData);
                    }
                    file_put_contents($logDir, "\r\n" . '-------------------------OK----------------------------', FILE_APPEND);
                } else {
                    file_put_contents($logDir, "\r\n" . '非JSON!', FILE_APPEND);
                }
            } else {
                file_put_contents($logDir, "\r\n" . 'Hello MsvodX!', FILE_APPEND);
                die('Hello MsvodX!');
            }
        } catch (\Exception $exception) {
            file_put_contents($logDir, "\r\n" . '【同步数据发生错误】：' . $exception->getMessage(), FILE_APPEND);
        }
    }

    /* 采集下载图片 */
    public function uploader_video_img(Request $request)
    {
        $img_urls = $request->param('img_urls/s', '');
        $img_name = $request->param('img_name/s', '');
        if(empty($img_urls)||empty($img_name)) exit('参数错误');
        $pathname = './XResource/video_thumb/'.date('Ymd');
        //$img_name = date('is').'_'.basename($img_urls);
        // 后台运行，不受前端断开连接影响
        ignore_user_abort(true);
        // 清除缓存区
        ob_end_clean();
        // 告诉前端可以了
        header("Connection: close");
        // 发送200状态码
        header("HTTP/1.1 200 OK");
        ob_start();
        // 兼容Windows服务器
        $sys = strtolower(php_uname('s'));
        if(strpos($sys, 'windows') !== false) echo str_repeat(" ", 4096);
        // 输出结果到前端
        echo json_encode(['code'=>1, 'msg'=>'success']);
        $size = ob_get_length();
        // 长度
        header("Content-Length: $size");
        // 输出当前缓冲
        ob_end_flush();
        // 输出PHP缓冲
        flush();
        // 下载图片
        //print_r($data);die;
        $this->__getImage($img_urls, $pathname, $img_name);
        //return $res['save_path'];
    }

    /*
    *功能：下载远程图片保存到本地
    *参数：文件url,保存文件目录,保存文件名称，使用的下载方式
    *当保存文件名称为空时则使用远程文件原来的名称
    */
    public function __getImage($url, $save_dir='', $filename='', $type=0)
    {
        if(trim($url)==''){
            return array('file_name'=>'','save_path'=>'','error'=>1);
        }
        if(trim($save_dir)==''){
            $save_dir='./';
        }
        if(trim($filename)==''){//保存文件名
            $ext=strrchr($url,'.');
            if($ext!='.gif' && $ext!='.jpg' && $ext!='.jpeg' && $ext!='.png' ){
                return array('file_name'=>'','save_path'=>'','error'=>3);
            }
            $filename=time().$ext;
        }
        if(0!==strrpos($save_dir,'/')){
            $save_dir.='/';
        }
        //创建保存目录
        if(!file_exists($save_dir)&&!mkdir($save_dir,0777,true)){
            return array('file_name'=>'','save_path'=>'','error'=>5);
        }
        /*if(file_exists($save_dir.$filename))
        {
            return array('file_name'=>'','save_path'=>'','error'=>6);
            exit;
        }*/
        //获取远程文件所采用的方法
        if($type){
            $ch=curl_init();
            $timeout=5;
            curl_setopt($ch,CURLOPT_URL,$url);
            curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);
            curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
            $img=curl_exec($ch);
            curl_close($ch);
        }else{
            ob_start();
            readfile($url);
            $img=ob_get_contents();
            ob_end_clean();
        }
        //$size=strlen($img);
        //文件大小
        $fp2=@fopen($save_dir.$filename,'a');
        fwrite($fp2,$img);
        fclose($fp2);
        unset($img,$url);
        return array('file_name'=>$filename,'save_path'=>$save_dir.$filename,'error'=>0);
    }

    /** 获取当前系统版本号 */
    public function getVersion()
    {
        try{
            $version=file_get_contents(ROOT_PATH.'public/version.lock');
            if(!empty($version)){
                die(json_encode(['statusCode' => 0, 'message' => '获取成功', 'data' => $version], JSON_UNESCAPED_UNICODE));
            }else{
                die(json_encode(['statusCode' => 400, 'message' => '获取失败', 'data' => '未知'], JSON_UNESCAPED_UNICODE));
            }
        }catch (\Exception $exception){
            die(json_encode(['statusCode' => 400, 'message' => '获取失败', 'data' => '未知'], JSON_UNESCAPED_UNICODE));
        }
    }

    /*获取头像*/
    public  function get_head_portrait()
    {
        $url = 'static/img';
        $imgArr = getFileName($url);
        return json_encode($imgArr);
    }

}
