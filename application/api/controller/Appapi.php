<?php

/**
 * Api接口层
 * LastDate:    2017/11/27
 */
namespace app\api\controller;
use app\common\model\communityPost;
use app\common\model\communityPostComment;
use app\common\model\Shortvideo;
// 新增
use app\common\model\Order;
use app\common\model\RechargePackage;
use think\Cache;
use think\Exception;
use think\Request;
use think\Db;
use UploadUtils\Uploader as UploadUtil;
use aop\AopClient;
use aop\AlipayTradeAppPayRequest;
use think\Controller;
use phpmailer\SendEmail;
use sms\Sms;

class Appapi extends Controller
{
    private $allowFileType = ['video', 'image', 'ico'];
    private $uper = null;
    private $config;
    private $apiFilenNme;
    public function ceshi()
    {
        echo 1;
    }

    public function __construct(Request $request)
    {
        parent::__construct($request);
        header("Access-Control-Allow-Origin: *");
        header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS, HEAD');
        header('Access-Control-Allow-Headers: X-Requested-With,X_Requested_With');
        $this->config = Db::name('admin_config')->column('name,value', 'name');
        // 与APP通讯 KEY 值
        $syskey = $this->config['app_key'];
        // 验证appKey是否正确
        $appkey = $request->param('appkey/s', '');
        // 过滤这些接口方法
        $noAuthAct = ['__appmsg', 'pay', 'download', 'down_app', 'privacy', 'addvideo', 'userupload', 'synchronization', 'finishupload', 'ceshi'];
        //todo 记得打开
        if (!in_array(strtolower($request->action()), $noAuthAct)) {
            if ($appkey != $syskey) die(json_encode(['Code' => '201', 'Msg' => '非法请求，请联系平台管理员'], JSON_UNESCAPED_UNICODE));
        }
        $this->apiFilenNme = $request->controller();
    }

    /* 非法访问 */
    public function _empty()
    {
        $returnData = ['Code' => '201', 'Msg' => '非法访问,请求地址有误,请检查后再试'];
        die(json_encode($returnData, JSON_UNESCAPED_UNICODE));
    }

    /* 初始化参数 */
    public function appInit(Request $request)
    {
        $userdb = Db::name('member');
        // 分享者ID
        $pid = $request->param('pid/d', '');
        // 用户手机唯一标识
        $did = $request->param('did/s', '');
        // 手机系统 2为安卓，3为苹果
        $sys = $request->param('sys/s', 'android');
        switch ($sys) {
            case 'android':
                $sys = 2;
                break;
            case 'ios':
                $sys = 3;
                break;
            default:
                $sys = 1;
                if ($this->config['is_safari'] == 1 && !$this->is_wechat_browser()) {
                    die(json_encode(['Code' => 201, 'Msg' => '非法访问，请使用safari'], JSON_UNESCAPED_UNICODE));
                }
                break;
        }

        $ip = \think\Request::instance()->ip();
        $openDb = Db::name('open_log');
        $sevenDay = strtotime(date('Ymd', strtotime('-7 day')));
        $openDb->where("add_time<{$sevenDay}")->delete();

        $w = ['ip' => $ip, 'add_time' => [['EGT', strtotime(date('Ymd'))], ['ELT', strtotime(date('Ymd', strtotime('+1 day')))]]];
        if (!$openDb->where($w)->find()) {
            $openData = [
                'ip' => $ip,
                'add_time' => time()
            ];
            $openDb->insert($openData);
        }

        $reg_drop = $userdb->where(['id' => $pid])->find();
        if ($reg_drop['reg_drop'] && $reg_drop['reg_drop'] !== '0.00') {

            $randomNumber = mt_rand(1, 99);

            if ($randomNumber <= $reg_drop['reg_drop'] * 100) {
                $pid = 0;
            } else {
                $pid = $pid;
            }
        }

        if (!empty($did)) {
            // 分享奖励
            if (!empty($pid)) $this->__regReward($pid, $userdb, 2, $did, $sys);
            // 安装记录
            $insDb = Db::name('app_install');
            $res = $insDb->field('id,did')->where(['did' => $did])->find();
            // 未安装过则插入安装数据
            if (!$res) {
                $newData = [
                    'route' => $sys,
                    'did' => $did,
                    'to_ip' => \think\Request::instance()->ip(),
                    'add_time' => time()
                ];
                $insDb->insert($newData);
            }
            $logDb = Db::name('login_log');
            $where = ['did' => $did, 'add_time' => [['EGT', strtotime(date('Ymd'))], ['ELT', strtotime(date('Ymd', strtotime('+1 day')))]]];
            if (!$logDb->field('id')->where($where)->find()) {
                $log['did'] = $did;
                $log['route'] = $sys;
                $log['add_time'] = time();
                $log['ip'] = \think\Request::instance()->ip();
                $logDb->insert($log);
            }
        }
        // 缓存名称
        $cacheName = $request->action();
        // 读取缓存
        $isCache = $this->__getCache($cacheName);
        if ($isCache) {
            $data = $isCache;
        } else {
            // 启动广告列
            $adDb = Db::name('advertisement');
            $where = "position_id=1 and status=1 and end_time>" . time();
            $data['init'] = $adDb->field('id,content src,url')->where($where)->order("sort DESC")->select();
            $data['menu'] = $this->getTopMenu();
            $data['server'] = trim($this->config['message_server_address']);
            // 公告 只查询一条，sort最大值
            $notice = Db::name('notice')->where("status=1 and out_time>" . time())->order('sort DESC')->order('sort desc')->find();
            if ($notice) $notice['content'] = htmlspecialchars_decode($notice['content']);
            $data['notice'] = $notice;
            // 设置缓存
            $this->__setCache($cacheName, $data);
        }

        $domain = trim($this->config['domain_list']);
        $data['domain'] = [];
        if (!empty($domain)) {
            $domainList = explode(PHP_EOL, $domain);
            foreach ($domainList as $k => $v) {
                $url = trim($v);
                if (empty($a)) {
                    unset($domainList[$k]);
                } else {
                    $data['domain'][] = $url;
                }
            }
        }
        // 网关站状态
        $data['site_status'] = $this->config['site_status'];
        // 关闭提示
        $data['site_msg'] = strip_tags(html_entity_decode($this->config['site_msg']));
        // 倒计时
        $data['time'] = $this->config['app_start_time'];
        // APP自动生成
        $data['auto'] = $this->config['is_auto_acc'];
        // APPLOGO
        $data['appLogo'] = $this->config['app_logo'];
        // 允许并发下载视频数量
        $data['downNum'] = 1;
        // APP启动等待图  jpg,png,jpeg 格式 GIF会很卡后台需要要标明
        $data['loading'] = $this->config['loading'];  //后台上传
        if ($data['auto'] && !empty($did)) {
            // 查询手机是否已注册
            if ($is = $userdb->field('id,username')->where(['did' => $did, 'auto' => 1])->find()) {
                $uid = $is['id'];
                $user['username'] = $is['username'];
            } else {
                $res = $userdb->field('id')->order('id DESC')->find();
                $rpid = $userdb->field('id')->where(['id' => $pid])->find();
                // 账号规则解析
                $account_rules = $this->config['account_rules'];
                $b = '';
                if (strpos($account_rules, '|') !== false) {
                    $arr = explode('|', $account_rules);
                    $a = empty($arr[0]) ? '' : trim($arr[0]);
                    if (intval($arr[1]) > 0) $b = '_' . $this->__randString($arr[1]) . '_';
                } else {
                    $a = $account_rules;
                }
                // 随机生成账号
                $user['username'] = $a . $b . $res['id'];
                $user['nickname'] = $this->config['site_title'] . time();
                $user['password'] = enCode_member_password('123456');
                $user['headimgurl'] = $this->config['web_server_url'] . '/static/images/user_dafault_headimg.jpg';
                $user['pid'] = $rpid ? intval($pid) : 0;
                $user['did'] = $did;
                $user['last_time'] = $user['add_time'] = time();
                $user['route'] = $sys;
                $user['auto'] = 1;
                // 创建账号
                $uid = $userdb->insertGetId($user);
                // 注册奖励
                if (!empty($uid)) $this->__regReward($uid, $userdb, 1, $did, $sys);
            }
            // 返回账号信息
            $data['user_id'] = $uid;
            $data['username'] = $user['username'];
            $data['cactime'] = time();
        }
        //初始化观看次数
        $this->init_watch($did);
        //更新周下载次数
        if (!empty($did)) $this->downloadTimesRecordRes($did);
        die(json_encode(['Code' => 200, 'Msg' => '', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /*更新会员下载次数*/
    public function downloadTimesRecordRes($did = '')
    {
        $user_info = get_member_info_bydid($did);
        if (!$user_info) return false;
        $week_arr = get_week_arr();
        foreach ($user_info as $k => $v) {
            if ($v['isVip']) {
                //$start = strtotime('last  Monday');//本周一时间戳
                //$end = strtotime('next Monday');  //下周一的时间戳
                $start = $week_arr['start'];
                $end = $week_arr['end'];
                $tot = (int)$this->config['vip_down_num'];
                //永久会员
                if ($v['isEverVip'])  $tot = (int)$this->config['svip_down_num'];

                $downloadTimesRecord = Db::name('download_times_record')
                    ->where(['uid' => $v['id'], 'add_time' => ['between', [$start, $end]]])
                    ->find();

                if (empty($downloadTimesRecord)) {
                    //添加更新记录
                    Db::startTrans();
                    try {
                        $downloadTimesRecordRes = Db::name('download_times_record')->insert(['uid' => $v['id'], 'add_time' => time(), 'did' => $v['did']]);
                        $upNumberOfWeek = Db::name('member')->where(['id' => $v['id']])->update(['number_of_weeks' => $tot]); //更新下载次数
                        if ($downloadTimesRecordRes && $upNumberOfWeek) Db::commit();
                        Db::rollback();
                        //打印日志
                    } catch (Exception $e) {
                        Db::rollback();
                    }
                }
            }
        }
    }

    public function init_watch($did)
    {
        $userdb = Db::name('member');
        $member = $userdb->where(['status' => 1, 'did' => $did])->select();
        $start_today = strtotime(date("Y-m-d", time()));
        //$end_today=strtotime(date('Y-m-d',time()))+86400-1;
        if ($member) {
            foreach ($member as $v) {
                if ($v['init_watch_time'] < $start_today) {
                    //$user['init_watch'] => 奖励的次数  +  $this->config['init_watch']默认初始次数
                    $watch_num = $v['init_watch'] + (int)$this->config['init_watch'];
                    $userdb->where('id', $v['id'])->update(['watch' => $watch_num, 'init_watch_time' => time()]);
                }
            }
        }
    }

    /* 获取配置信息 */
    public function getConfig(Request $request)
    {
        // TYPE
        $type = $request->param('type/d', 1);
        switch ($type) {
                // 注册相关
            case '1':
                $data = [
                    // 1普通注册，2手机号注册
                    'regType' => $this->config['register_validate'],
                    // 验证码位数
                    'codeNum' => $this->config['code_number'],
                    // 短信间隔时间 毫秒
                    'timeLag' => $this->config['time_lag'] * 1000,
                ];
                break;
                // 登录相关
            case '2':

                break;
            default:
                // code...
                break;
        }
        // 是否开启验证码
        $data['codeType'] = $this->config['verification_code_on'];
        //
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 获取手机验证码 */
    public function getMobileCode(Request $request)
    {
        // 手机号
        $mobile = $request->param('mobile/d', 0);
        // 验证手机号格式是否正确
        if (!preg_match("/^1[345789]{1}\d{9}$/", $mobile)) die(json_encode(['Code' => 201, 'Msg' => '手机号有误'], JSON_UNESCAPED_UNICODE));
        // 业务处理

        $res = $this->__getMobileCode($mobile);

        $res = json_decode($res, true);
        if ($res['Code'] == 201) die(json_encode(['Code' => 201, 'Msg' => $res['Msg']], JSON_UNESCAPED_UNICODE));
        // 返回验证码表主键
        die(json_encode(['Code' => 200, 'Msg' => '短信发送成功', 'Data' => $res['Data']], JSON_UNESCAPED_UNICODE));
    }

    /* 请求手机验证码 */
    public function __getMobileCode($mobile)
    {
        $codeDb = Db::name('mobile_code');
        $wheres = "name in ('time_lag','sms_everyday','sms_account','sms_password','sms_gwids','sms_send_url','sms_signature','sms_template','code_number')";
        $config = Db::name('admin_config')->where($wheres)->column('name,value');
        // 查询上一次发送时间
        $pre = $codeDb->where(['mobile' => $mobile])->order('id DESC')->find();

        if ($pre) {
            // 短信间隔时间
            $lag = $config['time_lag'];
            // 时间差
            $sjc = time() - $pre['add_time'];
            if ($sjc < $lag) return json_encode(['Code' => 201, 'Msg' => '发送失败，短信间隔时间为' . $lag . '秒'], JSON_UNESCAPED_UNICODE);
        }

        // 检测手机号是否超出限制
        $where = ['mobile' => $mobile, 'add_time' => [['EGT', strtotime(date('Ymd'))], ['ELT', strtotime(date('Ymd', strtotime('+1 day')))]]];
        $ySendSum = $codeDb->where($where)->count();

        $sSendSum = intval($config['sms_everyday']);
        if ($ySendSum > $sSendSum) return json_encode(['Code' => 201, 'Msg' => '每个手机号每天只可发送' . $sSendSum . '次'], JSON_UNESCAPED_UNICODE);

        // 签名
        $ste = $config['sms_signature'];
        // 短信模板
        $tpl = $config['sms_template'];
        // 验证码个数
        $num = $config['code_number'];
        // 网关
        $url = $config['sms_send_url'];
        // 随机验证码
        $coe = mt_rand(0, 9);
        for ($i = 1; $i < $num; $i++) {
            $coe .= mt_rand(0, 9);
        }

        // 拼装短信内容
        $msg = $ste . strstr($tpl, '{', true) . $coe . ltrim(strstr($tpl, '}'), '}');

        $post_data['account'] = $config['sms_account'];
        $post_data['pswd'] = $config['sms_password'];
        $post_data['mobile'] = $mobile;
        $post_data['msg'] = $msg;
        $post_data['needstatus'] = "true";
        $post_data['resptype'] = 'json';
        $o = "";
        foreach ($post_data as $k => $v) {
            $o .= "$k=" . urlencode($v) . "&";
        }
        //请求参数
        $post_data = substr($o, 0, -1);

        $res = sendRequest($url, $post_data);

        $res = json_decode($res, true);
        if ($res['result'] == 0) {
            // 生成记录
            $data['mobile'] = $mobile;
            $data['code'] = $coe;
            $data['add_time'] = time();
            $logId = $codeDb->insertGetId($data);
            return json_encode(['Code' => 200, 'Msg' => '短信发送成功', 'Data' => $logId], JSON_UNESCAPED_UNICODE);
        } else {
            return json_encode(['Code' => 201, 'Msg' => '发送失败，请联系管理员'], JSON_UNESCAPED_UNICODE);
        }
    }

    /* 请求手机验证码 */
    public function __getMobileCode1($mobile)
    {
        $codeDb = Db::name('mobile_code');
        $wheres = "name in ('time_lag','sms_everyday','sms_account','sms_password','sms_gwids','sms_send_url','sms_signature','sms_template','code_number')";
        $config = Db::name('admin_config')->where($wheres)->column('name,value');
        // 查询上一次发送时间
        $pre = $codeDb->where(['mobile' => $mobile])->order('id DESC')->find();
        if ($pre) {
            // 短信间隔时间
            $lag = $config['time_lag'];
            // 时间差
            $sjc = time() - $pre['add_time'];
            if ($sjc < $lag) return json_encode(['Code' => 201, 'Msg' => '发送失败，短信间隔时间为' . $lag . '秒'], JSON_UNESCAPED_UNICODE);
        }
        // 检测手机号是否超出限制
        $where = ['mobile' => $mobile, 'add_time' => [['EGT', strtotime(date('Ymd'))], ['ELT', strtotime(date('Ymd', strtotime('+1 day')))]]];
        $ySendSum = $codeDb->where($where)->count();
        $sSendSum = intval($config['sms_everyday']);
        if ($ySendSum > $sSendSum) return json_encode(['Code' => 201, 'Msg' => '每个手机号每天只可发送' . $sSendSum . '次'], JSON_UNESCAPED_UNICODE);
        // 平台账号
        $acc = $config['sms_account'];
        // 平台账号
        $pwd = $config['sms_password'];
        // 网关ID
        $gwid = $config['sms_gwids'];
        // 签名
        $ste = $config['sms_signature'];
        // 短信模板
        $tpl = $config['sms_template'];
        // 验证码个数
        $num = $config['code_number'];
        // 网关
        $url = $config['sms_send_url'];
        // 随机验证码
        $coe = mt_rand(0, 9);
        for ($i = 1; $i < $num; $i++) {
            $coe .= mt_rand(0, 9);
        }
        // 拼装短信内容
        $msg = $ste . strstr($tpl, '{', true) . $coe . ltrim(strstr($tpl, '}'), '}');
        // 请求参数
        $param = "?type=send&username=" . $acc . "&password=" . $pwd . "&gwid=" . $gwid . "&mobile=" . $mobile . "&message=" . $msg . "&rece=json";
        // 发起请求
        $res = $this->__getUrl($url . $param);
        $res = json_decode($res, true);
        if ($res['returnstatus'] == 'success') {
            // 生成记录
            $data['mobile'] = $mobile;
            $data['code'] = $coe;
            $data['add_time'] = time();
            $logId = $codeDb->insertGetId($data);
            return json_encode(['Code' => 200, 'Msg' => '短信发送成功', 'Data' => $logId], JSON_UNESCAPED_UNICODE);
        } else {
            return json_encode(['Code' => 201, 'Msg' => '发送失败，请联系管理员'], JSON_UNESCAPED_UNICODE);
        }
    }

    /* APP心跳检测接口 测试 */
    public function heartbeat(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        $routes = $request->param('sys/d', 1);
        $userDb = Db::name('member');
        if (!empty($userId)) {
            /* 数据库记录 */
            $userDb->where(['id' => $userId])->update(['route' => $routes, 'throb_time' => time()]);
        }
        $data = [
            'status' => (int)$this->config['site_status'],
            'safari' => (int)$this->config['is_safari']
        ];
        die(json_encode(['Code' => 200, 'Msg' => '心跳', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 长视频分类筛选  */
    public function getFilterData(Request $request)
    {
        // 缓存名称
        $cacheName = $request->action();
        // 读取缓存
        $isCache = $this->__getCache($cacheName);
        if ($isCache) {
            $data = $isCache;
        } else {
            $data = [];
            $all = ['id' => 0, 'name' => '全部'];
            // 分类
            $class_list = Db::name('class')->field('id,name')->where(['status' => 1, 'type' => 1, 'pid' => 0])->order('sort DESC')->select();
            if ($class_list) {
                array_unshift($class_list, $all);
                $class_list = ['type' => 'class', 'name' => '分类', 'items' => $class_list];
                $data[] = $class_list;
            }
            // 标签
            $tag_list = Db::name('tag')->field('id,name')->where(['status' => 1, 'type' => 1])->order("sort DESC")->select();
            if ($tag_list) {
                array_unshift($tag_list, $all);
                $tag_list = ['type' => 'tag', 'name' => '标签', 'items' => $tag_list];
                $data[] = $tag_list;
            }
            // 地区
            $area_list = Db::name('arealist')->field('id,name')->where(['status' => 1])->order("sort DESC")->select();
            if ($area_list) {
                array_unshift($area_list, $all);
                $area_list = ['type' => 'area', 'name' => '区域', 'items' => $area_list];
                $data[] = $area_list;
            }
            // 设置缓存
            $this->__setCache($cacheName, $data);
        }
        return json_encode(['Code' => 200, 'Msg' => '', 'Data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /* 长视频分类筛选视频 */
    public function getFilterVideo(Request $request)
    {
        // 页码
        $page = $request->param('page/d', 1);
        if ($page < 1) $page = 1;
        // 每页显示
        $limit = 12;
        // 分类
        $cid = $request->param('cid/d', 0);
        // 标签
        $tag_id = $request->param('tag_id/d', 0);
        // 地区
        $area_id = $request->param('area_id/d', 0);
        // 排序
        $orderCode = $request->param('orderCode/s', 'lastTime');
        // 缓存名称
        $cacheName = 'video-' . $page . '-' . $cid . '-' . $tag_id . '-' . $area_id . '-' . $orderCode;
        // 读取缓存
        $isCache = $this->__getCache($cacheName);
        if ($isCache) {
            $data = $isCache;
        } else {
            // 含专题集
            $where = "status=1 and is_check=1 and type=0";
            if (!empty($cid)) $where .= " and class = {$cid}";
            // 标签
            if (!empty($tag_id)) $where .= " and FIND_IN_SET({$tag_id}, tag)";
            // 区域 new add
            if (!empty($area_id)) $where .= " and FIND_IN_SET({$area_id}, area_id)";
            // 排序
            switch ($orderCode) {
                case 'lastTime':
                    $order = "id desc";
                    break;
                case 'hot':
                    $order = "click desc";
                    break;
                case 'reco':
                    $order = "reco desc";
                    break;
                case 'good':
                    $order = "good desc";
                    break;
                default:
                    $order = "id desc";
                    break;
            }
            $video_list = Db::name('video')
                ->where($where)
                ->field('id,title,thumbnail,play_time,gold')
                ->order($order)
                ->page($page, $limit)
                ->select();
            if ($video_list) {
                foreach ($video_list as $k => $v) {
                    $video_list[$k]['thumbnail'] = htmlspecialchars_decode($v['thumbnail']);
                    $video_list[$k]['title'] = mb_convert_encoding($v['title'], "UTF-8", "UTF-8");
                }
            }
            $data = ['videolist' => $video_list, 'page' => $page];
            // 设置缓存
            if ($video_list) $this->__setCache($cacheName, $data);
        }
        die(json_encode(['Code' => 200, 'Msg' => '', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 长视频 */
    public function getClassData(Request $request)
    {
        //$sign = empty(create_yzm_play_sign()) ? '' : '?sign=' . trim(create_yzm_play_sign())
        // 一级分类ID
        $class_id = $request->param('class_id/d', 0);
        // 长视频顶级分类  一级分类
        $topClass = Db::name('topcategory')->field('id,name,is_display')->where(['status' => 1, 'type' => 1])->order('sort desc')->select();

        if (empty($class_id)) {
            $class_id = $topClass[0]['id']; //默认一级分类
            $type = $topClass[0]['is_display'];
        } else {
            $topClassRes =  Db::name('topcategory')->field('is_display')->where(['status' => 1, 'type' => 1, 'id' => $class_id])->order('sort desc')->find();
            $type = $topClassRes['is_display'];
        }

        // 初始值设置为空数组
        $data['banner'] = $data['menu'] = $data['tag'] = [];
        // 显示轮播图与导航
        if ($type == 1) {
            $data['banner'] = $this->getBanner();
            $data['menu'] = $this->getTopMenu();
            // 显示标签
        } else {
            $tagDb = Db::name('tag')->where(['status' => 1]);
            //            $tagIds = $tagDb->column('id');
            //            $selNum = count($tagIds) > 7 ? 7 : count($tagIds);
            //            $randTagIds = array_random($tagIds, $selNum);
            //            $tag = [];
            //            if (!empty($randTagIds)) $tag = $tagDb->field("id,name,icon cover")->where(['id' => ['IN', $randTagIds]])->order('sort desc')->select();
            //            // 显示标签，随机查询7个
            $tag = [];
            $tag = $tagDb->field("id,name,icon cover")->order('sort desc')->limit(7)->select();
            $data['tag'] = $tag;
        }

        $data['classList'] = $topClass;
        // 根据一级分类ID查询出所有分类信息
        $topClass = Db::name('topcategory')->field('id,cid')->where(['status' => 1, 'type' => 1, 'id' => $class_id])->order('sort desc')->find();
        $list = [];
        // 根据二级分类ID查询出该对应分类视频
        $cid = explode(',', $topClass['cid']);

        $class = Db::name('class')->where(['status' => 1])->column('id,name', 'id');

        if (!empty($cid)) {

            foreach ($cid as $k => $v) {
                if (empty($class[$v])) break;
                $video = $this->getClassVideoData($v);
                $video = json_decode($video, true);
                if (isset($video['Code']) && $video['Code'] == '201') die(json_encode(['Code' => 201, 'Msg' => $video['Msg'], 'Data' => $data], JSON_UNESCAPED_UNICODE));
                $list[] = [
                    'c_id' => $v,
                    'c_name' => $class[$v],
                    'c_page' => 1, //默认1 不需要改动
                    'c_list' => $video ?: [], // 分类下视频，每栏显示 6个根据后台设定,如果分类下没有数据，返回空数组即可
                ];
            }
        }
        $data['videoList'] = $list;
        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 长视频分类视频 */
    public function getClassVideoData($cid, $page = 1, $count = 0)
    {
        // 二级分类ID
        $cid = (int)$cid;
        if (empty($cid)) die(json_encode(['Code' => 201, 'Msg' => '参数错误~~'], JSON_UNESCAPED_UNICODE));
        // 当前页
        $page = (int)$page;
        // 每页多少条 如果没有指定多少条则读后台配置的数量 这个6从后台读取出来
        $count = $count ?: $this->config['class_resource_num'];
        $video = Db::name('video')->field('id,title,thumbnail cover,gold')->where(['status' => 1, 'class' => $cid])->order('id desc')->paginate($count)->toArray();
        $cList = []; // 视频以ID为降序来排序
        $cList = $video['data'];
        return json_encode($cList, JSON_UNESCAPED_UNICODE);
    }

    /* 所有标签或根据标签获取视频 */
    public function getTagData(Request $request)
    {
        // 标签ID, 当tid为0时，则查询出所有标签否则查询标签关联的视频
        $tid = $request->param('tid/d', 0);
        $list = [];
        // 所有标签
        if (empty($tid)) {
            $list = Db::name('tag')->field('id,name,icon cover')->where('status', 1)->order('sort desc,id desc')->select();
            $data['list']  = $list;
            // 标签视频
        } else {
            // 当前页 $tid 不为0时有值
            $page = $request->param('page/d', 1);
            $list = Db::name('video')->field('id,title,thumbnail cover,gold')->where(['status' => 1])->where("find_in_set($tid,tag)")->paginate(20)->toArray();
            $data['list'] = $list['data'];
        }

        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 短视频 */
    public function getSvodClassData(Request $request)
    {
        // 一级分类ID  $sign = empty(create_yzm_play_sign()) ? '' : '?sign=' . trim(create_yzm_play_sign())
        $class_id = $request->param('class_id/d', 0);
        // 页码
        $page = $request->param('page/d', 1);
        // 查询$class_id分类排版类型1显示轮播图与分类视频 2显示该大分类下视频 (每个大分类可以设置1或2排版)
        // 顶级分类  一级分类
        $topClass = Db::name('topcategory')->field('id,name,is_display')->where(['status' => 1, 'type' => 2])->order('sort desc')->select();
        // 查询$class_id分类排版类型1显示轮播图 2显示标签 (每个大分类可以设置1或2排版)
        $type = 1;
        if ($topClass) {
            if (empty($class_id)) {
                $class_id = $topClass[0]['id']; //默认一级分类
                $type = $topClass[0]['is_display'];
            } else {
                $topClassRes =  Db::name('topcategory')->field('is_display')->where(['status' => 1, 'type' => 2, 'id' => $class_id])->order('sort desc')->find();
                $type = $topClassRes['is_display'];
            }
        }


        $data['type'] = $type;
        // 初始值设置为空数组
        $data['banner'] = $data['clist'] = $data['vlist'] = [];
        // 短视频顶级分类  一级分类
        $data['classList'] = $topClass;
        // 显示轮播图与导航
        if ($type == 1) {
            $data['banner'] = $this->getBanner();
            // 根据一级分类ID查询出所有分类信息
            $topClass = Db::name('topcategory')->field('id,cid')->where(['status' => 1, 'type' => 2, 'id' => $class_id])->order('sort desc')->find();
            $list = [];
            // 根据二级分类ID查询出该对应分类视频
            $cid = explode(',', $topClass['cid']);
            $class = Db::name('shortclass')->where(['status' => 1])->column('id,name', 'id');
            if (!empty($cid)) {
                foreach ($cid as $k => $v) {
                    if (empty($class[$v])) break;
                    $video = $this->getSvodClassVideoData($v);
                    $video = json_decode($video, true);
                    if (isset($video['Code']) && $video['Code'] == '201') die(json_encode(['Code' => 201, 'Msg' => $video['Msg'], 'Data' => $data], JSON_UNESCAPED_UNICODE));
                    $list[] = [
                        'c_id' => $v,
                        'c_name' => $class[$v],
                        'c_page' => 1, //默认1 不需要改动
                        'c_list' => $video ?: [], // 分类下视频，每栏显示 6个根据后台设定,如果分类下没有数据，返回空数组即可
                    ];
                }
            }
            $data['clist'] = $list;
            // 显示标签
        } else {
            // 每页显示12条
            // 根据二级分类ID查询出该对应分类视频
            $video = $this->getSvodClassVideoData($class_id, 2);
            $video = json_decode($video, true);
            $data['vlist'] = $video;
        }
        // 长视频顶级分类  一级分类

        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 短视频分类视频 */
    public function getSvodClassVideoData($cid, $type = 1, $page = 1, $count = 0)
    {
        // 当前页
        //if (strpos($video['url'], '.m3u8') !== false && strpos($video['url'], 'sign') === false) $video['url'] .= $sign;
        $page = (int)$page;
        // $type大于1时则为一级分类ID 否则为二级分类ID
        $cid = (int)$cid;
        $sign = empty(create_yzm_play_sign()) ? '' : '?sign=' . trim(create_yzm_play_sign());
        if (empty($cid)) die(json_encode(['Code' => 201, 'Msg' => '参数错误~~'], JSON_UNESCAPED_UNICODE));
        // 每页多少条 如果没有指定多少条则读后台配置的数量 这个6从后台读取出来
        $count = $count ?: $this->config['class_resource_num'];
        $cList = []; // 视频以ID为降序来排序

        if ($type > 1) {
            //一级分类
            //$cid 一级分类id
            $topClass = Db::name('topcategory')->field('id,cid')->where(['status' => 1, 'type' => 2, 'id' => $cid])->order('sort desc')->find();
            // 根据二级分类ID查询出该对应分类视频
            $video = Db::name('shortvideo v')
                ->join('member m', 'v.user_id = m.id', 'RIGHT')
                ->field('v.id,v.title,v.thumbnail cover,v.gold,v.url')
                ->where(['v.status' => 1, 'v.is_check' => 1, 'v.class_id' => ['in', $topClass['cid']]])
                ->order('v.id desc')
                ->paginate(12)
                ->each(function ($item, $key) use ($sign) {
                    if (strpos($item['url'], '.m3u8') !== false && strpos($item['url'], 'sign') === false) $item['url'] .= $sign;
                    return $item;
                })
                ->toArray();
            // 根据一级分类ID查询出所有分类信息和视频
            $cList = $video['data'];
        } else {
            // 二级分类
            $video = Db::name('shortvideo v')
                ->join('member m', 'v.user_id = m.id', 'RIGHT')
                ->field('v.id,v.title,v.thumbnail cover,v.gold,v.url')
                ->where(['v.status' => 1, 'v.is_check' => 1, 'v.class_id' => $cid])
                ->order('v.id desc')
                ->paginate($count)
                ->each(function ($item, $key) use ($sign) {
                    if (strpos($item['url'], '.m3u8') !== false && strpos($item['url'], 'sign') === false) $item['url'] .= $sign;
                    return $item;
                })
                ->toArray();

            // 根据一级分类ID查询出所有分类信息和视频
            $cList = $video['data'];
        }

        return json_encode($cList, JSON_UNESCAPED_UNICODE);
    }

    /* 获取轮播图 */
    public function getBanner()
    {
        $where = "position_id=4 and status=1 and end_time>" . time();
        return Db::name('advertisement')->field('id,content images_url,url,titles info')->where($where)->order("sort DESC")->select();
    }

    /* 首页短视频 */
    public function getSvodMain(Request $request)
    {
        // 用户ID，0为未登录状态
        $uid = $request->param('uid/d', 0);
        $did = $request->param('did/s', '0');
        $userInfo = get_member_info($uid);

        // 用户信息
        $data['user'] = [
            'isVip'  => !empty($userInfo) ? $userInfo['isVip'] : false, //用户是否为VIP
            'freeTot' => !empty($userInfo) ? ((int)$userInfo['init_watch'] + (int)$this->config['init_watch']) : 0, //免费总次数
            'free'   => !empty($userInfo) ? $userInfo['watch'] : 0, //免费观看次数
        ];
        // 查询视频数据规则：先获得总页数，再随机在总页数里选择页码查询数据，并去掉已获取的页码数，当所有页码已查询完毕时，重新执行上面的逻辑
        $cache = cache('pageArr' . $did);

        if (empty($cache)) {
            $count = Db::name('shortvideo v')->join('member m', 'v.user_id = m.id', 'RIGHT')->where('v.status', 1)->count();
            $total = ceil($count / 6); //总页数
            $pageArr = range(1, $total);
            $pageKey = array_rand($pageArr);
            $page = $pageArr[$pageKey];

            unset($pageArr[$pageKey]);
            Cache::set('pageArr' . $did, $pageArr, 3600); //半小时有效期
        } else {
            //Cache::rm('pageArr'.$did);
            $pageKey = array_rand($cache);
            $page = $cache[$pageKey];
            unset($cache[$pageKey]);
            Cache::set('pageArr' . $did, $cache, 3600); //半小时有效期
        }
        //Cache::rm('pageArr'.$did);
        $shortVideoService = new ShortVideoService($page);
        $list = $shortVideoService->homeVideo($uid);
        if (empty($list)) return json_encode(['Code' => 201, 'Msg' => '还没有视频可以看!可以联系管理员试试~~', 'Data' => $list], JSON_UNESCAPED_UNICODE);
        // 视频列表 再随机重新排序视频数据
        $data['list'] = $list;
        return json_encode(['Code' => 200, 'Msg' => '', 'Data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /* 扣除用户免费次数 */
    public function updateFree(Request $request)
    {
        // 用户ID，0为未登录状态
        $uid = $request->param('uid/d', 0);
        $users = get_member_info($uid);
        // 短视频ID
        $vid = $request->param('vid/d', 0);
        if (!$users)   return json_encode(['Code' => 201, 'Msg' => '登录超时或在其它地方登录'], JSON_UNESCAPED_UNICODE);
        if ($users['watch'] < 1)    return json_encode(['Code' => 201, 'Msg' => '免费次数不足'], JSON_UNESCAPED_UNICODE);

        if (empty($vid)) return json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE);
        $video = Db::name('shortvideo')->find($vid);
        if (!$video) return json_encode(['Code' => 201, 'Msg' => '视频不存在'], JSON_UNESCAPED_UNICODE);
        Db::startTrans();
        try {
            $result = Db::name('member')->where(['id' => $users['id']])->setDec('watch'); //次数-1
            if ($result > 0) {
                Db::commit();
            } else {
                throw new Exception('扣除失败');
            }
            // 返回剩余次数
            $data = $users['watch'] - 1;
            return json_encode(['Code' => 200, 'Msg' => '扣除成功', 'Data' => $data], JSON_UNESCAPED_UNICODE);
        } catch (\Exception $e) {
            Db::rollback();
            return json_encode(['Code' => 201, 'Msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
    }

    /* 金币购买短视频 */
    public function goldBuyVideo(Request $request)
    {
        // 用户ID，0为未登录状态
        $uid = $request->param('uid/d', 0);
        // 短视频ID
        $vid = $request->param('vid/d', 0);
        if (empty($uid) || empty($vid)) return json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE);

        $shortVideo = new ShortVideoService();
        $res  = $shortVideo->buyVideo($uid, $vid);

        if ($res['code'] == 200) return json_encode(['Code' => 200, 'Msg' => $res['msg']], JSON_UNESCAPED_UNICODE);
        return json_encode(['Code' => 201, 'Msg' => $res['msg']], JSON_UNESCAPED_UNICODE);
    }

    /* 短视频点赞 */
    public function likeSvodVideo(Request $request)
    {
        // 用户ID，0为未登录状态
        $uid = $request->param('uid/d', 0);
        $userInfo = get_member_info($uid);
        if (empty($userInfo)) return json_encode(['Code' => 201, 'Msg' => '请先登录'], JSON_UNESCAPED_UNICODE);
        // 短视频ID
        $vid = $request->param('vid/d', 0);
        if (empty($vid)) return json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE);

        $like = Db::name('shortlike')->where(['resources_id' => $vid, 'user_id' => $uid])->find();

        if ($like) {
            $status = $like['status'] ? 0 : 1;
            if ($status == 0) {
                //点赞数加1
                Db::name('shortvideo')->where('id', $vid)->setInc('good');
            } else {
                Db::name('shortvideo')->where('id', $vid)->setDec('good');
            }
            $res = Db::name('shortlike')->where(['id' => $like['id'], 'type' => 1])->setField('status', $status);
            $text = $status == 0 ? '点赞成功' : '取消点赞';
            //$text = '取消点赞'; //点赞成功 or 取消点赞
        } else {
            $insert_date['user_id'] = $uid;
            $insert_date['resources_id'] = $vid;
            $insert_date['type'] = 1;
            $insert_date['status'] = 0;
            $insert_date['add_time'] = time();
            $res = Db::name('shortlike')->insertGetId($insert_date);
            Db::name('shortvideo')->where('id', $vid)->setInc('good');
            $text = '点赞成功'; //点赞成功 or 取消点赞
        }
        if (!$res) return $this->toJson('操作失败', 201);
        // 点赞取反，如果用户已点赞则取消，否则为点赞成功
        return json_encode(['Code' => 200, 'Msg' => $text], JSON_UNESCAPED_UNICODE);
    }

    /* 短视频评论列表 */
    public function svodVideoComment(Request $request)
    {
        // 用户ID，0为未登录状态
        $uid = $request->param('uid/d', 0);
        // 短视频ID
        $vid = $request->param('vid/d', 0);
        if (empty($vid)) return json_encode(['Code' => 201, 'Msg' => '参数错误',], JSON_UNESCAPED_UNICODE);
        // 页码  每页显示10条
        $page = $request->param('page/d', 1);

        $list = Db::name('shortcomment ')
            ->field('id,send_user uid,nickname,add_time,content,cover')
            ->order('id desc')
            ->where(['status' => 1, 'resources_id' => $vid])
            ->page($page, 10)
            ->select();
        if ($list) {
            $data['list'] = array_values($list);
        } else {
            $data['list'] = [];
        }
        // 点赞取反，如果用户已点赞则取消，否则为点赞成功
        return json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /* 短视频评论 */
    public function sendVideoComment(Request $request)
    {
        // 用户ID，0为未登录状态
        $uid = $request->param('uid/d', 0);
        $userInfo = get_member_info($uid);
        if (empty($uid)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 短视频ID
        $vid = $request->param('vid/d', 0);
        // 评论内容  需要过滤一些特殊字符，JS,HTML等
        $content = $request->param('content/s', '', 'htmlspecialchars,addslashes,strip_tags');
        if (empty($vid) || empty($content)) die(json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE));
        $comment_examine_on = $this->config['comment_examine_on'];
        $insert['send_user'] = $uid;
        $insert['content'] = $content;
        $insert['resources_id'] = $vid;
        $insert['status'] = empty($comment_examine_on) ? 1 : 0;
        $insert['add_time'] = time();
        $insert['nickname'] = $userInfo['nickname'];
        $insert['cover'] = $userInfo['headimgurl'];
        $res = Db::name('shortcomment')->insertGetId($insert);
        $data = [];
        $text = '评论失败';
        if ($res) {
            // 评论成功返回信息
            $text = '评论成功,请等待审核';
            if (empty($comment_examine_on)) {
                $data = [
                    'id' => $res, //评论ID
                    'uid' => $uid, // 评论用户ID
                    'nickname' => $userInfo['nickname'], //评论用户昵称
                    'add_time' => time(),  // 评论时间
                    'content'  => $content, //评论内容纯文字
                    'cover'    => $userInfo['headimgurl'], //头像
                ];
                $text = '评论成功';
            }
            return json_encode(['Code' => 200, 'Msg' => $text, 'Data' => $data], JSON_UNESCAPED_UNICODE);
        } else {
            return json_encode(['Code' => 201, 'Msg' => $text, 'Data' => $data], JSON_UNESCAPED_UNICODE);
        }
    }

    /* 短视频标签视频  */
    public function tagSvodVideo(Request $request)
    {
        // 标签ID
        $tid = $request->param('tid/d', 0);
        if (empty($tid)) die(json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE));
        // 分页
        $page = $request->param('page/d', 1);
        // 每页多少
        $count = 12;
        $cList = [];
        $sign = empty(create_yzm_play_sign()) ? '' : '?sign=' . trim(create_yzm_play_sign());
        // 根据标签查询视频，以ID降序
        $cList = Db::name('shortvideo')
            ->field('id,title,thumbnail cover,gold,url')
            ->where(['status' => 1, 'is_check' => 1])
            ->where("find_in_set($tid,tag)")
            ->page($page, $count)
            ->select();
        if ($cList) {
            foreach ($cList as $k => &$v) {
                if (strpos($v['url'], '.m3u8') !== false && strpos($v['url'], 'sign') === false) $v['url'] .= $sign;
            }
            $cList = array_values($cList);
        }

        return json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $cList], JSON_UNESCAPED_UNICODE);
    }

    /* 短视频播放  */
    public function playSvodVideo(Request $request)
    {
        // 用户ID
        $uid = $request->param('uid/d', 0);
        $userInfo = get_member_info($uid);
        // 短视频ID
        $vid = $request->param('vid/d', 0);
        if (empty($vid)) die(json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE));
        // 用户信息
        $data['user'] = [
            'isVip'  =>  !empty($userInfo) ? $userInfo['isVip'] : false, //用户是否为VIP
            'freeTot' => !empty($userInfo) ? ((int)$userInfo['init_watch'] + (int)$this->config['init_watch']) : 0, //免费总次数
            'free'   =>  !empty($userInfo) ? $userInfo['watch'] : 0, //免费观看次数
        ];
        $shortVideo = new ShortVideoService();
        $video = $shortVideo->getVideoByVid($uid, $vid);
        $data['item'] = $video;
        return json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE);
    }

    /* 短视频购买记录  */
    public function getSvodBuyLog(Request $request)
    {
        // 用户ID，0为未登录状态
        $uid = $request->param('uid/d', 0);
        $userInfo = get_member_info($uid);
        if (empty($userInfo)) die(json_encode(['Code' => 201, 'Msg' => '会员不存在或已被禁用!'], JSON_UNESCAPED_UNICODE));
        // 页码
        $sign = empty(create_yzm_play_sign()) ? '' : '?sign=' . trim(create_yzm_play_sign());

        $page = $request->param('page/d', 1);
        $list = Db::view('shortvideo_buy_log l', 'gold,add_time')
            ->view('shortvideo v', 'id,title,thumbnail,url', 'l.video_id = v.id')
            ->where('l.user_id', $uid)
            ->page($page, 12)
            ->select();

        foreach ($list as $k => &$v) {
            if (strpos($v['url'], '.m3u8') !== false && strpos($v['url'], 'sign') === false) $v['url'] .= $sign;
        }
        $data['list'] = $list;
        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }


    /* 会员周下载量 */
    public function vipWeekDowns(Request $request)
    {
        // 设备ID
        $did = $request->param('did/s', '');
        // 用户ID
        $uid = $request->param('uid/d', 0);
        // 视频ID
        $vid = $request->param('vid/d', 0);
        $videoInfo = Db::name('video')->where('status', 1)->find($vid);
        $userInfo = get_member_info($uid);
        if (empty($videoInfo)) die(json_encode(['Code' => 201, 'Msg' => '资源不存在,下载失败!'], JSON_UNESCAPED_UNICODE));
        if (!$userInfo['isVip']) die(json_encode(['Code' => 201, 'Msg' => '权限不足，会员才能下载'], JSON_UNESCAPED_UNICODE));
        if ($userInfo['number_of_weeks'] < 1) die(json_encode(['Code' => 201, 'Msg' => '本周下载次数不足'], JSON_UNESCAPED_UNICODE));
        $decRes = Db::name('member')->where('id', $uid)->setDec('number_of_weeks'); //下载次数减一
        if ($decRes) die(json_encode(['Code' => 200, 'Msg' => '下载成功'], JSON_UNESCAPED_UNICODE));
        // 记录，并返回成功
        die(json_encode(['Code' => 201, 'Msg' => '下载失败!请联系管理员！！'], JSON_UNESCAPED_UNICODE));
    }

    public function strToUtf8($str)
    {
        $encode = mb_detect_encoding($str, array("ASCII", 'UTF-8', "GB2312", "GBK", 'BIG5', 'LATIN1'));
        if ($encode != 'UTF-8') {
            $name = mb_convert_encoding($str, 'UTF-8', $encode);
        }
    }

    /* 获取热门搜索 */
    public function getHotSearch(Request $request)
    {
        $did = $request->param('did/s', '');
        if (empty($did)) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出APP后重试'], JSON_UNESCAPED_UNICODE));
        // 是否允许普通用户搜索
        $data['isSearchs'] = intval($this->config['is_search']);
        $logDb = Db::name('search_log');
        $where = 'status=1 and type=0';
        // 热门搜索
        $data['hotList'] = Db::name('video')->field('id,title,search_num,update_time')->where($where)->limit(10)->order('search_num DESC')->select();
        $data['logList'] = [];
        // 历史搜索
        if (!empty($did)) $data['logList'] = $logDb->field('id,content')->where(['did' => $did])->order('id DESC')->select();
        die(json_encode(['Code' => 200, 'Msg' => $did, 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 搜索视频 */
    public function searchVideo(Request $request)
    {
        if ($request->isPost()) {
            $did = trim($request->param('did/s', ''));
            $uid = trim($request->param('uid/d', 0));
            $key = trim($request->param('key/s', ''));
            $page = $request->param('page/d', 1);
            if ($page < 1) $page = 1;
            if (empty($key)) die(json_encode(['Code' => 201, 'Msg' => '请输入搜索关键字'], JSON_UNESCAPED_UNICODE));
            if (empty($did)) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出APP后重试'], JSON_UNESCAPED_UNICODE));
            // 是否允许普通用户搜索
            $isSearchs = intval($this->config['is_search']);
            // 免费搜索次数
            $searchSum = intval($this->config['search_sum']);
            // 搜索间隔 秒
            $searchInt = intval($this->config['search_int']);
            $userDb = Db::name('member');
            $logsDb = Db::name('search_log');
            $logxDb = Db::name('search_log_sum');
            // 支持普通用户或游客使用搜索功能
            if ($page == 1) {
                //if(empty($uid)) die(json_encode(['Code' => 201, 'Msg' => '禁止游客使用搜索功能，请先登录'], JSON_UNESCAPED_UNICODE));
                $u = $userDb->field('id,is_permanent,out_time')->where(['id' => $uid])->find();
                // 非永久VIP
                if (!$u['is_permanent']) {
                    // 非VIP
                    if ($u['out_time'] < time()) {
                        if ($isSearchs) {
                            $where = ['did' => $did, 'add_time' => [['EGT', strtotime(date('Ymd'))], ['ELT', strtotime(date('Ymd', strtotime('+1 day')))]]];
                            $s = $logxDb->where($where)->count();
                            if ($s >= $searchSum) {
                                die(json_encode(['Code' => 201, 'Msg' => '普通用户每天只能使用' . $searchSum . '次搜索功能'], JSON_UNESCAPED_UNICODE));
                            }
                        } else {
                            die(json_encode(['Code' => 201, 'Msg' => 'VIP才能使用搜索功能'], JSON_UNESCAPED_UNICODE));
                        }
                    }
                }
                // 搜索间隔验证
                $l = $logsDb->field('id,add_time')->where(['did' => $did])->order('id DESC')->find();
                if ($l) {
                    $t = time() - $l['add_time'];
                    if ($t < $searchInt) die(json_encode(['Code' => 201, 'Msg' => '搜索频率太快，请' . ($searchInt - $t) . '秒后再试'], JSON_UNESCAPED_UNICODE));
                }
                // 是否存在搜索记录
                $y = $logsDb->field('id,add_time,content')->where(['did' => $did, 'content' => $key])->find();
                // 写入搜索记录
                $data = [
                    'user_id' => $uid,
                    'content' => $key,
                    'did' => $did,
                    'add_time' => time()
                ];
                if (!$y) $logsDb->insert($data);
                $logxDb->insert($data);
            }
            // 每页显示
            $limit = 12;
            $videoDb = Db::name('video');
            $where = "status = 1 and title like '%{$key}%'";
            // 搜索视频
            $video = $videoDb->where($where)->page($page, $limit)->order('search_num DESC')->select();
            if (count($video) > 0) {
                $videoDb->where($where)->setInc('search_num', 1);
                foreach ($video as $k => $v) {
                    // 加粗加颜色
                    $title = str_replace($key, "<span style='color:red;'>$key</span>", $v['title']);
                    // 替换
                    $video[$k]['title'] = $title;
                }
                $list['video'] = $video;
                $list['count'] = $videoDb->where($where)->count();
                die(json_encode(['Code' => 200, 'Msg' => '搜索完成', 'Data' => $list], JSON_UNESCAPED_UNICODE));
            } else {
                $list['video'] = '';
                $list['count'] = 0;
                die(json_encode(['Code' => 202, 'Msg' => '暂未找到与之相关的视频', 'Data' => $list], JSON_UNESCAPED_UNICODE));
            }
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '请以POST方式提交'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 清空搜索记录 */
    public function emptySearch(Request $request)
    {
        if ($request->isPost()) {
            $did = $request->param('did/s', '');
            if (empty($did)) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出APP后重试'], JSON_UNESCAPED_UNICODE));
            $logsDb = Db::name('search_log');
            if ($logsDb->where(['did' => $did])->delete()) {
                die(json_encode(['Code' => 200, 'Msg' => '搜索记录已清空'], JSON_UNESCAPED_UNICODE));
            } else {
                die(json_encode(['Code' => 201, 'Msg' => '搜索记录不存在或已清空'], JSON_UNESCAPED_UNICODE));
            }
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '请以POST方式提交'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 专题详情 */
    public function albumInfo(Request $request)
    {
        // 缓存名称
        $cacheName = $request->action();
        // 读取缓存
        $isCache = $this->__getCache($cacheName);
        if ($isCache) {
            $data = $isCache;
        } else {
            // 专题集ID
            $albumId = $request->param('albumId/d', 0);
            if (empty($albumId)) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请关闭APP再试'], JSON_UNESCAPED_UNICODE));
            // 获取专题信息
            $videoDb = Db::name('actor_list');
            $videoDb->where(['id' => $albumId])->setInc('click');
            $where = ['id' => $albumId, 'status' => 1];
            $data = $videoDb->field("id,img bg,username,english_name userifno,thumbnail userTx,info Introduction")
                ->where($where)
                ->find();
            $data['Introduction'] = htmlspecialchars_decode($data['Introduction']);
            // 作品列表
            $data['list'] = Db::view('video v', "id,thumbnail img,title,class,gold,click,add_time addTime,sort vSort,is_foreshow")
                ->view('class c', 'name playTime', 'v.class=c.id')
                ->where("v.type=0 and v.is_check=1 and v.status=1 and FIND_IN_SET({$albumId}, v.actor_id)")
                ->order('v.sort ASC')
                ->select();
            if (count($data['list']) > 0) {
                foreach ($data['list'] as $k => $v) {
                    $click = $v['click'];
                    if ($click > 9999) $click = round(($click / 10000), 2) . 'w';
                    $data['list'][$k]['palyCount'] = $click;
                    $data['list'][$k]['img'] = htmlspecialchars_decode($v['img']);
                }
            }
            // 设置缓存
            $this->__setCache($cacheName, $data);
        }
        //
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 视频详情
     * @param videoId  视频ID
     * @param userId 用户ID
     * @requestType 请求方式get
     * @date    2019/5/9
     */
    public function detail(Request $request)
    {

        $videoId = $request->param('videoId/d', 0);
        $subId = $request->param('subId/d', 0);

        $userId = $request->param('userId/d', 0);
        $did = $request->param('did/s', '');

        $sys = $request->param('sys/s', 'web');
        if ($sys == 'web') {
            if ($this->config['is_safari'] == 1 && !$this->is_wechat_browser()) {
                die(json_encode(['Code' => 201, 'Msg' => '非法访问，请使用safari'], JSON_UNESCAPED_UNICODE));
            }
        }

        if (empty($videoId)) die(json_encode(['Code' => 201, 'Msg' => '非法操作'], JSON_UNESCAPED_UNICODE));

        $member = Db::name('member')->field('id,username,headimgurl,nickname,is_permanent,out_time,watch,init_watch')->find($userId);

        if ($member['is_permanent'] == 1 || $member['out_time'] > time()) {
            $member['isVip'] = true;
            if ($member['is_permanent'] == 1) $member['isEverVip'] = true;
        } else {
            $member['isVip'] = false;
        }
        //$video = Db::name('video')->where(array('id'=>$videoId))->field('title,url,thumbnail,info,short_info,gold,click,class,img')->find();
        $videoDb = Db::name('video');
        //$video = $videoDb->where(array('status'=>1, 'is_check'=>1, 'id'=>$videoId))->find();
        $video = Db::view('video v', "*")
            ->view('class c', 'name className', 'v.class=c.id', 'LEFT')
            ->where("v.id={$videoId} and v.is_check=1 and v.status=1")
            ->find();

        if (empty($video['className'])) $video['className'] = '未分类';

        if (!$video) die(json_encode(['Code' => 201, 'Msg' => '该视频不存在或已删除'], JSON_UNESCAPED_UNICODE));
        // 增加视频或视频集点击量
        $videoDb->where(['id' => $video['id']])->setInc('click');

        // 剧集
        $sDb = Db::name('server_line');
        $sLine = $sDb->field('id,name,pid')->where("status='1' and pid=0")->order('sort DESC')->select();
        $data['hDvd'] = $hDvd = $myList = [];
        $i = $data['isHDvd'] = 0;

        $sign = '';
        if (empty($video['gather'])) {
            $sign = empty(create_yzm_play_sign()) ? '' : '?sign=' . trim(create_yzm_play_sign());
        }
        // 服务器组
        if ($sLine) {
            $arr = json_decode($video['url_config'], true);
            foreach ($sLine as $k => $v) {
                $line = $sDb->field('id,name,pid')->where(['status' => 1, 'pid' => $v['id']])->order('sort DESC')->select();
                //$data['abc'] = $line;
                if (!$line) {
                    unset($sLine[$k]);
                } else {
                    $sLine[$k]['list'] = $line;
                    foreach ($sLine[$k]['list'] as $tk => $tv) {
                        $listStr = isset($arr[$v['id']][$tv['id']]) ? trim($arr[$v['id']][$tv['id']]) : '';
                        //$sLine[$k]['list'][$tk]['list'] = $listStr;
                        if (empty($listStr)) {
                            unset($sLine[$k]['list'][$tk]);
                        } else {
                            $lists = explode(PHP_EOL, $listStr);
                            $i = 0;
                            $arrList = [];
                            foreach ($lists as $sk => $sv) {
                                if (empty($v)) {
                                    // 清除空值
                                    unset($lists[$sk]);
                                } else {
                                    if (strpos($sv, '@') !== false) {
                                        // 剧集(1花絮,2正片,3预告)@视频地址@观看金币(0为免费视频)@MP4下载地址,如有多行则表示剧集
                                        $zsList = explode('@', $sv);
                                        switch ($zsList[0]) {
                                            case '1':
                                                $i++;
                                                $zs = $wz = '花絮';
                                                break;
                                            case '3':
                                                $wz = 3;
                                                $zs = ($sk + 1 - $i);
                                                break;
                                            default:
                                                $wz = 2;
                                                $zs = ($sk + 1 - $i);
                                                break;
                                        }
                                        $arrList[$sk]['id'] = $sk;
                                        $arrList[$sk]['videoId'] = $videoId;
                                        $arrList[$sk]['text'] = $wz;
                                        $arrList[$sk]['number'] = $zs;
                                        $n_url = trim($zsList[1]) . $sign;
                                        $arrList[$sk]['url'] = $n_url;
                                        $arrList[$sk]['down_url'] = isset($zsList[2]) ? trim($zsList[2]) : '';
                                        // 当前所选
                                        /*if($subId==$sk){
                                            $video['url'] = $n_url;
                                        }*/
                                        $sLine[$k]['list'][$tk]['list'] = $arrList;
                                    } else {
                                        unset($sLine[$k]['list'][$tk]);
                                    }
                                }
                            }
                        }
                    }
                    if (!$sLine[$k]['list']) unset($sLine[$k]);
                }
            }
            $hDvd = $sLine;
            if ($hDvd) $data['isHDvd'] = 1;
            // 无服务器组
        } else {
            $data['isHDvd'] = 0;
            $arrOne = explode(PHP_EOL, $video['url']);
            if (strpos($arrOne[0], '@') !== false) {
                $one = explode('@', $arrOne[0]);
                // 默认播放地址
                $video['url'] = $one[1];
            }
            foreach ($arrOne as $k => $v) {
                if (empty($v)) {
                    // 清除空值
                    unset($arrOne[$k]);
                } else {
                    if (strpos($v, '@') !== false) {
                        // 剧集(1花絮,2正片,3预告)@视频地址@观看金币(0为免费视频)@MP4下载地址,如有多行则表示剧集
                        $zsList = explode('@', $v);
                        switch ($zsList[0]) {
                            case '1':
                                $i++;
                                $zs = $wz = '花絮';
                                break;
                            case '3':
                                $wz = 3;
                                $zs = ($k + 1 - $i);
                                break;
                            default:
                                $wz = 2;
                                $zs = ($k + 1 - $i);
                                break;
                        }
                        $hDvd[$k]['id'] = $k;
                        $hDvd[$k]['videoId'] = $videoId;
                        $hDvd[$k]['text'] = $wz;
                        $hDvd[$k]['number'] = $zs;
                        $n_url = trim($zsList[1]) . $sign;
                        $hDvd[$k]['url'] = $n_url;
                        $down = isset($zsList[2]) ? trim($zsList[2]) : '';
                        $hDvd[$k]['down_url'] = $down;
                        // 当前所选
                        if ($subId == $k) {
                            $video['url'] = $n_url;
                            $video['download_url'] = $down;
                        }
                    }
                }
            }
        }
        $data['hDvd'] = $hDvd;
        $data['subId'] = $subId;
        // 点赞数量
        $click = (int)$video['good'];
        if ($click > 9999) $click = round(($click / 10000), 2) . 'w';
        $data['likeSum'] = $click;
        // 标签
        $data['tagList'] = [];
        if (!empty($video['tag'])) {
            $taglist = Db::name('tag')->field('name tagName')->where("status=1 and type=1 and id in ({$video['tag']})")->order('sort desc')->select();
            if ($taglist) {
                $result = array_reduce($taglist, function ($result, $value) {
                    return array_merge($result, array_values($value));
                }, []);
                $data['tagList'] = implode("、", $result);
            }
        }
        // 演员列表
        $data['actorList'] = '';
        if (!empty($video['actor_id'])) {
            $actorList = Db::name('actor_list')->field('username')->where("status=1 and id in ({$video['actor_id']})")->order('reco DESC')->select();
            if ($actorList) {
                $result = array_reduce($actorList, function ($result, $value) {
                    return array_merge($result, array_values($value));
                }, []);
                $data['actorList'] = implode("、", $result);
            }
        }
        // 相关视频
        $idList = explode(',', $video['actor_id']);
        //$data['a'] = $video['actor_id'];
        $data['simi'] = [];
        if (!empty($video['actor_id'])) {
            $arro = [];
            $actorDb = Db::name('actor_list');
            foreach ($idList as &$item) {
                if (!$actorDb->where(['id' => $item, 'status' => 1])->find()) continue;
                $simi = $videoDb->where("id<>{$videoId} and is_check=1 and status=1 and FIND_IN_SET({$item}, actor_id)")
                    ->field('id,thumbnail,title,url')
                    ->order('sort ASC')
                    ->select();
                foreach ($simi as $key => $val) {
                    $simi[$key]['thumbnail'] = htmlspecialchars_decode($val['thumbnail']);
                    $simi[$key]['title'] = mb_convert_encoding($val['title'], "UTF-8", "UTF-8");
                }
                // 植入广告数据
                $arro = array_merge($arro, $simi);
            }
            $data['simi'] = array_values($this->__removeArr($arro, 'id'));
        }
        // 广告列表
        $data['adList'] = Db::view('advertisement a', 'id,content,titles,url')
            ->view('advertisement_position b', 'height', 'a.position_id=b.id')
            ->where("b.id=6 and a.status=1 and a.end_time>" . time())
            ->order("a.sort DESC")
            ->select();
        $video['url'] = trim($video['url']);
        // 视频密钥
        if (strpos($video['url'], '.m3u8') !== false && strpos($video['url'], 'sign') === false) $video['url'] .= $sign;

        $CUS = [
            '240' => '流畅画质',
            '480' => '普通画质',
            '720' => '高清画质',
            '1080' => '超清画质'
        ];
        //$video['videoline'] = $videoline;
        $video['videoline'] = [];
        /* 添加线路播放地址e */
        // 视频介绍内容输出反转义
        //$video['info'] = html_entity_decode($video['info']);
        $video['info'] = htmlspecialchars_decode($video['info']);
        $video['info'] = str_replace("&middot;", '·', $video['info']);
        $video['info'] = str_replace("&mdash;", '—', $video['info']);
        $data['videoCut'] = !empty($video['img']) ? json_decode($video['img'], true) : null;
        unset($video['img']);
        $data['isVip'] = $member['isVip'];
        $data['member'] = $member;
        $now = time();
        $adDb = Db::name('advertisement');
        $beforewhere = "status=1 and position_id = 2 and begin_time <= {$now} and end_time > {$now}";
        $pausewhere = "status=1 and position_id = 3 and begin_time <= {$now} and end_time > {$now}";
        $beforelist = $adDb->where($beforewhere)->select();
        $pauselist = $adDb->where($pausewhere)->select();
        $adSetting = get_config_by_group('video');
        $data['isdownload'] = empty($video['download_url']) ? 0 : 1;
        $data['download'] = $adSetting['download'];
        if ($adSetting['download'] != 1) {
            $data['download'] = ($data['isVip'] != 1) ? 0 : 1;
        }

        if (!empty($beforelist)) {
            $beforenum = count($beforelist);
            $randnum = rand(0, $beforenum - 1);
            $beforeinfo = $beforelist[$randnum];
            $before['img'] = $beforeinfo['content'];
            $before['url'] = $beforeinfo['url'];
            if ($beforeinfo['type'] == 2) {
                $before['type'] = 'video';
            } else {
                $before['type'] = 'img';
            }
            $before['img'] .= "?sign=" . create_yzm_play_sign();
            $data['adTime'] = empty($adSetting['play_video_ad_time']) ? '0' : $adSetting['play_video_ad_time'];
        } else {
            $before = null;
            $data['adTime'] = 0;
        }


        if (!empty($pauselist)) {
            $pausenum = count($pauselist);
            $randnum = rand(0, $pausenum - 1);
            $pauseinfo = $pauselist[$randnum];
            $pause['img'] = $pauseinfo['content'];
            $pause['url'] = $pauseinfo['url'];
        } else {
            $pause['img'] = null;
            $pause['url'] = null;
        }

        $data['ad'] = array(
            'before' => $before,
            'pause' => $pause,
        );
        // 免费查看时长
        $data['feeLook'] = intval($this->config['look_second']);
        $data['videoInfo'] = $video;
        $data['isShowComments'] = $this->config['comment_on'];
        if (!empty($userId)) {
            $goodHistory = Db::name("video_good_log")->where(["video_id" => $videoId, 'user_id' => $userId])->find();
            $collectionHistory = Db::name("video_collection")->where(["video_id" => $videoId, 'user_id' => $userId])->find();
            $data['isLike'] = empty($goodHistory) ? 0 : 1;
            $data['isCollection'] = empty($collectionHistory) ? 0 : 1;
        } else {
            $data['isLike'] = 0;
            $data['isCollection'] = 0;
        }

        $params = array(
            'type' => 'video',
            'cid' => $video['class'],
            'limit' => 6,
        );
        $data['guess'] = get_recom_data($params);
        foreach ($data['guess'] as $k => $v) {
            $data['guess'][$k]['thumbnail'] = htmlspecialchars_decode($v['thumbnail']);
            $data['guess'][$k]['play_time'] = $v['play_time'] ?: '';
        }

        $buyTimeExists = $this->config['message_validity'];
        $data['buyTimeExists'] = $buyTimeExists;
        $rate = $this->config['gold_exchange_rate']; //金币兑换比例
        $price = $video['gold'] / $rate;
        $data['price'] = empty($price) ? 0 : $price;
        $data['alreadyBuy'] = 0;

        $memberId = empty($member['id']) ? 0 : intval($member['id']);
        $is_self = $memberId == $video['user_id'] ? true : false;

        if ($data['isVip'] == 1 || $is_self) {
            $data['alreadyBuy'] = 1;
        } else {
            $buyTimeExists = 60 * 60 * $buyTimeExists;
            $watchHistory = Db::name('video_buy_log')
                ->where(['user_id' => $userId, 'video_id' => $videoId])
                ->order('id desc')
                ->find();
            if ($watchHistory && $watchHistory['add_time'] > (time() - $buyTimeExists)) {
                //消费周期内，免费看
                $data['alreadyBuy'] = 1;
            } else {
                $watch_num = $this->config['watch_num'];
                if (!empty($watch_num) && !empty($member['id'])) {
                    //判断当天赠送免费次数有没有用完
                    $todaywhere = array(
                        'user_id' => $member['id'],
                    );
                    $today = Db::name('video_watch_log')->whereTime('view_time', 'd')->where($todaywhere)->count();
                    if ($watch_num > $today) {
                        $data['alreadyBuy'] = 1;
                    }
                }
                //判断是否拥有免费观影次数
                if (!empty($member['watch']) && ($data['alreadyBuy'] != 1) && ($video['gold'] > 0)) {
                    Db::startTrans();
                    try {
                        $res = Db::name('member')->where('id', $member['id'])->setDec('watch');
                        $data['alreadyBuy'] = 1;
                        if (!$res) {
                            throw new \Exception('观影次数不足');
                        }
                        $data['alreadyBuy'] = 1;
                        // 提交事务
                        Db::commit();
                    } catch (\Exception $e) {
                        //dump($e->getMessage());
                        // 回滚事务
                        Db::rollback();
                        $data['alreadyBuy'] = 0;
                        //注意：我们做了回滚处理，所以id为1039的数据还在
                    }
                }
            }
        }
        if (!empty($member['id'])) {
            //写入足迹
            $watchdata = array(
                'user_id' => $member['id'],
                'video_id' => $videoId
            );
            $watchinfo = Db::name('video_watch_log')->where($watchdata)->find();
            if (empty($watchinfo)) {
                $watchdata['view_time'] = time();
                $watchdata['user_ip'] = \think\Request::instance()->ip();
                $watchdata['did'] = $did;
                Db::name('video_watch_log')->insertGetId($watchdata);
            } else {
                $watchudata['view_time'] = time();
                Db::name('video_watch_log')->where($watchdata)->update($watchudata);
            }
        }
        $data['freeWacth'] = [
            'free' => !empty($member) ? $member['watch'] : 0,
            'count' => !empty($member) ? ((int)$member['init_watch'] + (int)$this->config['init_watch']) : 0 // 初始观看次数，奖励次数加系统赠送次数
        ];
        $data['watch'] = (int)$this->config['init_watch'] ?: 0;

        $resources_member = Db::name('member')->field('id,headimgurl,username,nickname')->where(['status' => 1])->find($video['user_id']);

        $data['author'] = [
            'id' => $resources_member['id'] ?: 0, // 上传者UID 如果是后台上传ID则为0
            'headimgurl' => $resources_member['headimgurl'] ?: '', //上传者头像
            'username' => $resources_member['nickname'] ?: '', //用户账号
        ];

        die(json_encode(['Code' => 200, 'Msg' => '', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 获取APP广告 id广告位ID */
    public function getAd(Request $request)
    {
        $adid = $request->param('id/d', 2);
        $nowT = time();
        $adDb = Db::name('advertisement');
        $where = "status=1 and position_id = {$adid} and begin_time <= {$nowT} and end_time > {$nowT}";
        // 广告列表
        $lists = $adDb->field('id,type,content,url,target,sort scale')->where($where)->select();
        // 概率总值
        $scale = $adDb->field('SUM(sort) scale')->where($where)->find();
        $data = [];
        if (!empty($lists)) {
            if ($scale['scale'] > 0) {
                $randnum = $this->__getRand($lists, $scale['scale'], 1);
            } else {
                $beforenum = count($lists);
                $randnum = rand(0, $beforenum - 1);
            }
            $beforeinfo = $lists[$randnum];
            $img = $beforeinfo['content'];
            $url = $beforeinfo['url'];
            if ($beforeinfo['type'] == 2) {
                $type = 'video';
            } else {
                $type = 'img';
            }
            $img .= "?sign=" . create_yzm_play_sign();
            $data['adInfo'] = [
                'img' => $img,
                'type' => $type,
                'url' => $url
            ];
            $data['adTime'] = (int)$this->config['play_video_ad_time'];
        } else {
            $data['adInfo'] = null;
            $data['adTime'] = 0;
        }
        // 返回数据
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 视频点赞
     * @param videoId  视频ID
     * @param userId 用户ID
     * @requestType 请求方式post
     * @date    2019/5/10
     */
    public function like(Request $request)
    {
        $videoId = $request->post('videoId/d', '', '');
        $userId = $request->post('userId/d', '', '');
        if (empty($videoId)) die(json_encode(['Code' => 201, 'Msg' => '视频id不能为空'], JSON_UNESCAPED_UNICODE));
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '用户id不能为空'], JSON_UNESCAPED_UNICODE));
        $goodHistory = Db::name("video_good_log")->where(["video_id" => $videoId, 'user_id' => $userId])->find();
        if ($goodHistory) die(json_encode(['Code' => 201, 'Msg' => '您已点过赞了，无需再次点赞'], JSON_UNESCAPED_UNICODE));


        $resource = model('video');
        $dataObj = $resource::get($videoId);
        if (!$dataObj) die(json_encode(['Code' => 201, 'Msg' => '数据验证失败:资源不存在.'], JSON_UNESCAPED_UNICODE));
        $dataObj->good += 1;
        $dataObj->save();


        //写入点赞日志表
        $goodLogData = [
            'add_time' => time(),
            'video_id' => $videoId,
            'user_id' => $userId,
        ];

        Db::name("video_good_log")->data($goodLogData)->insert();
        die(json_encode(['Code' => 200, 'Msg' => '点赞成功'], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 视频点赞
     * @param videoId  视频ID
     * @param userId 用户ID
     * @requestType 请求方式post
     * @date    2019/5/10
     */
    public function comment(Request $request)
    {
        $videoId = $request->post('videoId/d', '', '');
        $userId = $request->post('userId/d', '', '');
        $content = $request->post('content/s', '', '');
        $wheres = "name in ('comment_on','comment_examine_on')";
        $config = Db::name('admin_config')->where($wheres)->column('name,value');
        if ($config['comment_on'] != 1) die(json_encode(['Code' => 201, 'Msg' => '当前暂未支持评论'], JSON_UNESCAPED_UNICODE));
        if (empty($videoId)) die(json_encode(['Code' => 201, 'Msg' => '非法操作'], JSON_UNESCAPED_UNICODE));
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '请登录后再评论'], JSON_UNESCAPED_UNICODE));
        if (empty($content)) die(json_encode(['Code' => 201, 'Msg' => '内容不能为空'], JSON_UNESCAPED_UNICODE));
        $resourceType = 1;
        $resourceId = $videoId;
        $content = htmlspecialchars(trim($content), ENT_QUOTES);
        $to_user = 0;
        $to_id = 0;
        $insertData = [
            'add_time' => time(),
            'last_time' => time(),
            //'resources_type' => $resourceType,
            'resources_id' => $resourceId,
            'content' => $content,
            'send_user' => $userId,
            'to_user' => $to_user,
            'to_id' => $to_id,
        ];
        if (empty($config['comment_examine_on'])) {
            $data['to_id'] = $to_id;
            $data['comment_examine_on'] = 0;
            $insertData['status'] = 1;
            $message = '评论成功';
        } else {
            $data['comment_examine_on'] = 1;
            $insertData['status'] = 0;
            $message = '评论成功,待审核后才显示';
        }
        $insertData['status'] = empty($config['comment_examine_on']) ? 1 : 0;
        $insert_result = Db::name("comment")->insertGetId($insertData);

        die(json_encode(['Code' => 200, 'Msg' => $message], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 购买视频
     * @param videoId  视频ID
     * @param userId 用户ID
     * @requestType 请求方式post
     * @date    2019/5/10
     */
    public function buy(Request $request)
    {
        $videoId = $request->post('videoId/d', 0);
        $userId = $request->post('userId/d', 0);
        $did = $request->post('did/s', '');
        if (empty($videoId)) die(json_encode(['Code' => 201, 'Msg' => '非法操作'], JSON_UNESCAPED_UNICODE));
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '请登录后再购买'], JSON_UNESCAPED_UNICODE));
        $correlation = array(
            'user_id' => $userId,
            'video_id' => $videoId,
        );
        $buyTimeExists = $this->config['message_validity'];
        $buyTimeExists = 60 * 60 * $buyTimeExists;
        $correlation['add_time'] = ['>', time() - $buyTimeExists];
        //$watch_log  = insert_watch_log('video', $videoId, $videoInfo['gold'], false, $userId);

        $watch_log = Db::name("video_buy_log")->where($correlation)->find();
        if (empty($watch_log)) {
            $videoInfo = Db::name("video")->field('gold,title,id')->where(array('id' => $videoId))->find();
            $memberModel = model('member')->get($userId);

            $memberInfo = db::name('member')->where(array('id' => $userId))->find();

            //$memberInfo = Db::name("member")->where(array('id'=>$userId))->find();
            if (empty($videoInfo)) die(json_encode(['Code' => 201, 'Msg' => '非法操作'], JSON_UNESCAPED_UNICODE));
            if (empty($memberInfo)) die(json_encode(['Code' => 201, 'Msg' => '用户不存在或登录超时'], JSON_UNESCAPED_UNICODE));

            if ($videoInfo['gold'] > $memberInfo['money']) die(json_encode(['Code' => 201, 'Msg' => '金币不足，请先充值'], JSON_UNESCAPED_UNICODE));
            $gold = $videoInfo['gold'];
            $memberModel->money -= $gold;
            $decMoneyRs = $memberModel->save();
            //消费记录金币变动记录
            $explain = "扣费观看《" . $videoInfo['title'] . "》";
            Db::name('account_log')->data(['user_id' => $userId, 'point' => "-$gold", 'add_time' => time(), 'module' => 'buyVideo', 'explain' => $explain])->insert();

            if (isset($_SERVER['HTTP_ALI_CDN_REAL_IP'])) {
                $ip = $_SERVER['HTTP_ALI_CDN_REAL_IP'];
            } else {
                $ip = \think\Request::instance()->ip();
            }
            $insertData = ["video_id" => $videoId, 'user_id' => $userId, 'add_time' => time(), 'gold' => $gold, 'did' => $did];
            Db::name("video_buy_log")->data($insertData)->insert();

            die(json_encode(['Code' => 200, 'Msg' => '购买成功'], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '您已经购买过了，无需重新购买'], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 注册接口
     * @param account  用户名or账号
     * @param pwd 密码
     * @param pid 分享代理ID
     * @param did 用户手机唯一ID
     * @date    2019/5/8
     */
    public function register(Request $request)
    {
        if ($request->isPost()) {
            $userdb = Db::name('member');
            $data['username'] = $request->post('account/s', '');
            $data['nickname'] = $this->config['site_title'] . time();
            $mobileCode = $request->post('mobileCode/d', 0);
            $codeId = $request->post('cid/d', 0);
            $data['password'] = $request->post('pwd/s', '');

            $data['pid'] = $request->post('pid/d', '');
            $data['did'] = $request->post('did/s', '');

            $data['route'] = $request->post('sys/s') == 'android' ? 2 : 3;
            // 1为普通注册，2为手机号验证
            $regType = intval($this->config['register_validate']);
            if ($regType == 1) {
                // 是否禁用中文注册0为禁用，1为启用
                $register_cn = intval($this->config['register_cn']);
                if (empty($data['username'])) die(json_encode(['Code' => 201, 'Msg' => '用户名不能为空'], JSON_UNESCAPED_UNICODE));
                if (!$register_cn) {
                    if (preg_match("/[\x7f-\xff]/", $data['username'])) die(json_encode(['Code' => 201, 'Msg' => '系统已禁止中文注册'], JSON_UNESCAPED_UNICODE));
                }
            } else {
                // 验证手机格式
                if (!preg_match("/^1[345789]{1}\d{9}$/", $data['username'])) die(json_encode(['Code' => 201, 'Msg' => '手机号有误'], JSON_UNESCAPED_UNICODE));
                // 验证手机验证码,过期时长等逻辑处理
                if (strlen($mobileCode) != $this->config['code_number']) die(json_encode(['Code' => 201, 'Msg' => '验证码格式有误'], JSON_UNESCAPED_UNICODE));
                $codeDb = Db::name('mobile_code');
                $regLog = $codeDb->where(['id' => $codeId])->find();
                if (!$regLog) die(json_encode(['Code' => 201, 'Msg' => '验证码错误，请检查'], JSON_UNESCAPED_UNICODE));
                // 是否过期
                $cTime = time() - $regLog['add_time'];
                // 系统设定时间
                $sTime = intval($this->config['overdue']) * 60;
                if ($cTime > $sTime) die(json_encode(['Code' => 201, 'Msg' => '验证码已过期，请重新发送'], JSON_UNESCAPED_UNICODE));
                // 是否匹配
                if ($regLog['code'] != $mobileCode) die(json_encode(['Code' => 201, 'Msg' => '请输入正确的验证码'], JSON_UNESCAPED_UNICODE));
                // 插入数据
                $data['tel'] = $data['username'];
            }
            if (empty($data['password'])) die(json_encode(['Code' => 201, 'Msg' => '密码不能为空'], JSON_UNESCAPED_UNICODE));
            $info = $userdb->where(['username' => $data['username']])->find();
            if (!empty($info)) die(json_encode(['Code' => 201, 'Msg' => '该用户已经存在'], JSON_UNESCAPED_UNICODE));
            $data['headimgurl'] = $this->config['web_server_url'] . '/static/images/user_dafault_headimg.jpg';
            $data['password'] = enCode_member_password($data['password']);
            $data['last_time'] = $data['throb_time'] = $data['add_time'] = time();
            // 推荐人ID是否为空
            if (!empty($data['pid'])) {
                // 推荐人是否存在
                if (!$userdb->field('id')->where(['id' => $data['pid']])->find()) {
                    // 不存在
                    $data['pid'] = '';
                }
            }
            // 验证用户手机唯一标识
            if (!empty($data['did']) && $userdb->field('did')->where(['did' => $data['did']])->find()) {
                // 开启注册限制
                if ($this->config['is_reg_limit']) die(json_encode(['Code' => 201, 'Msg' => '该手机已经注册过账号'], JSON_UNESCAPED_UNICODE));
                //$data['did'] = '';
            }
            $member_id = $userdb->insertGetId($data);
            // 注册奖励
            if (!empty($member_id)) $this->__regReward($member_id, $userdb, 1, $data['did'], $data['route']);
            //初始化观看次数
            $this->init_watch($data['did']);
            die(json_encode(['Code' => 200, 'Msg' => '注册成功', 'Data' => ['member_id' => $member_id, 'time' => time()]], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '请以POST方式提交'], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 登陆接口
     * @param account  用户名or账号
     * @param pwd 密码
     * @date    2019/5/8
     */
    public function login(Request $request)
    {
        if ($request->isPost()) {
            $username = $request->post('account/s', '', '');
            $password = $request->post('pwd/s', '', '');
            if (empty($username)) die(json_encode(['Code' => 201, 'Msg' => '请输入登录账号'], JSON_UNESCAPED_UNICODE));
            if (empty($password)) die(json_encode(['Code' => 201, 'Msg' => '请输入登录密码'], JSON_UNESCAPED_UNICODE));
            $where['username'] = $username;
            $where['password'] = enCode_member_password($password);
            $userDb = Db::name('member');
            $info = $userDb->where($where)->find();
            if (!$info) die(json_encode(['Code' => 201, 'Msg' => '账号或登录密码错误'], JSON_UNESCAPED_UNICODE));
            if ($info['status'] == 0) die(json_encode(['Code' => 201, 'Msg' => '账号不存在或已被禁用'], JSON_UNESCAPED_UNICODE));
            $data['throb_time'] = $data['last_time'] = time();
            $userDb->where(['id' => $info['id']])->update($data);
            die(json_encode(['Code' => 200, 'Msg' => '登录成功', 'Data' => ['member_id' => $info['id'], 'time' => time()]], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '请以POST方式提交'], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 微信登陆接口
     * @param account  用户名or账号
     * @param pwd 密码
     * @date    2019/28/8
     */
    public function wechatLogin(Request $request)
    {
        $unionid = $request->post('unionid/s', '');
        $openid = $request->post('openid/s', '');
        $nickname = $request->post('nickname/s', '');
        $headimgurl = $request->post('headimgurl/s', '');
        $sex = $request->post('sex', '');

        $userdb = Db::name('member');
        //先判断用户是否存在
        $memberinfo = $userdb->where(['unionid' => $unionid])->find();
        if (empty($openid)) die(json_encode(['Code' => 201, 'Msg' => '登入失败，没获取到openid！'], JSON_UNESCAPED_UNICODE));
        if (empty($memberinfo)) {
            $memberinfo = $userdb->where(['openid' => $openid])->find();
            if ($memberinfo) {
                $userdb->where(['openid' => $openid])->update(['unionid' => $unionid]);
            }
        }

        //用户信息入库
        if (empty($memberinfo)) {
            $userdata['pid'] = $request->post('pid/d', '');
            $userdata['did'] = $request->post('did/s', '');
            // 推荐人ID是否为空
            if (!empty($userdata['pid'])) {
                // 判断上级代理是否存在
                if (!$userdb->field('id')->where(array('id' => $userdata['pid'], 'is_agent' => 1))->find()) {
                    // 不存在
                    $userdata['pid'] = '';
                }
            }
            // 验证用户手机唯一标识
            if (!empty($userdata['did']) && $userdb->field('did')->where(array('did' => $userdata['did']))->find()) {
                $userdata['did'] = '';
            }
            $userdata['username'] = 'wx_' . date('ymdHis') . rand(000, 999);
            $userdata['nickname'] = $nickname;
            $userdata['headimgurl'] = $headimgurl;
            $userdata['add_time'] = time();
            $userdata['sex'] = $sex;
            $userdata['last_ip'] = $request->ip();
            $userdata['openid'] = $openid;
            $userdata['unionid'] = $unionid;
            $info = ['id' => 1, 'username' => $nickname, 'password' => ''];
            $userdata['access_token'] = get_token($info);

            $uid = $userdb->insertGetId($userdata);
            // 奖励业务处理 第四个参数1为注册其它为分享
            $reg_award = $this->config['app_reg_award'];
            // 该手机为第一次注册并且注册奖励大于0
            if (!empty($userdata['did']) && !empty($reg_award)) $this->__awardUser($userdb, $uid, $reg_award, 1);
            return json_encode(['Code' => 200, 'Msg' => '登录成功', 'Data' => array('member_id' => $uid)], JSON_UNESCAPED_UNICODE);
        } else if ($memberinfo) {
            $userdata = [
                'nickname' => $nickname,
                'sex' => $sex,
                'headimgurl' => $headimgurl,
            ];
            $userdb->where(['openid' => $openid])->update($userdata);
            return json_encode(['Code' => 200, 'Msg' => '登录成功', 'Data' => array('member_id' => $memberinfo['id'])], JSON_UNESCAPED_UNICODE);
        } else {
            return json_encode(['Code' => 201, 'Msg' => '登录失败,获取不到微信信息', 'Data' => 'null'], JSON_UNESCAPED_UNICODE);
        }
    }

    /* 个人信息 */
    public function getUserInfo(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $user_info = get_member_info($userId);

        if ($user_info) {
            $user_infox['avatar'] = $user_info['headimgurl'];
            $user_infox['username'] = $user_info['username'];
            $user_infox['nickname'] = $user_info['nickname'];
            $user_infox['sex'] = $user_info['sex'];
            //$user_infox['tel'] = empty($user_info['tel'])?'':substr($user_info['tel'],0,6).'####'.substr($user_info['tel'],-1,1);
            $user_infox['tel'] = $user_info['tel'];
            $user_infox['isVip'] = $user_info['isVip'];
            $user_infox['vipEndDate'] = $user_info['out_time'];
            $user_infox['vipEndTime'] = date('Y年m月d日', $user_info['out_time']);
            $user_infox['money'] = $user_info['k_money'];
            $user_infox['corn'] = $user_info['money'];
            $user_infox['watch'] = $user_info['watch'];  // 今日剩余观看次数
            $user_infox['watch_count'] = $user_info['init_watch'] + (int)$this->config['init_watch']; // 奖励观看次数 + 系统初始赠送次数 与短视频一样
            // 下载次数， VIP会员与永久会员次数不一样
            $tot = 0;
            if ($user_info['isVip']) {
                //普通会员
                $tot = (int)$this->config['vip_down_num'];
                //永久会员
                if ($user_info['is_permanent'])  $tot = (int)$this->config['svip_down_num'];
            }

            $user_infox['down'] = [
                'sum' => $user_info['number_of_weeks'] ?: 0,  // 本周剩余下载次数，每周一0点0分恢复
                'tot' => $tot, // 总次数，读取后台设置的，VIP会员或永久会员，普通用户为0
            ];
            // 会员分享 奖励说明
            $user_infox['share_reward_text'] = $this->config['share_reward_text'];
            // 充值金币说明 后台设置
            $user_infox['rec_text'] = $this->config['rec_text'];

            if ($user_info['is_permanent'] == 1) $user_infox['vipEndDate'] = 0;
            $user_infox['is_permanent'] = $user_info['is_permanent'];
            // 今日签到
            $user_infox['sign'] = false;
            $where = ['user_id' => $userId, 'add_time' => [['EGT', strtotime(date('Ymd'))], ['ELT', strtotime(date('Ymd', strtotime('+1 day')))]]];
            // true已签到
            if (Db::name('sign')->where($where)->find()) $user_infox['sign'] = true;
            $type = ['未知', '金币', 'VIP', '观看次数', '抽奖次数'];
            // 签到奖励类型
            $sys = $this->config['sign_reward_type'];
            // 奖励值
            $point = (int)$this->config['sign_reward'];
            $user_infox['signTip'] = '可获得' . $type[$sys] . '+' . $point;
            $user_infox['signPint'] = $point;
            die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $user_infox], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 修改密码
     * @param userId  用户UID
     * @param oldPwd 旧密码
     * @param newPwd 新密码
     * @param confirm 确认密码
     * @date  6/10/2019
     */
    public function changePwd(Request $request)
    {
        if ($request->isPost()) {
            $userid = $request->post('userId/d', '');
            $password = $request->post('oldPwd/s', '');
            $new = $request->post('newPwd/s', '');
            $confirm = $request->post('confirm/s', '');
            if (empty($userid)) die(json_encode(['Code' => 201, 'Msg' => '用户不存在或登录超时'], JSON_UNESCAPED_UNICODE));
            if (empty($password)) die(json_encode(['Code' => 201, 'Msg' => '原始密码不能为空'], JSON_UNESCAPED_UNICODE));
            $userdb = Db::name('member');
            $user = $userdb->field('password')->where(array('id' => $userid))->find();
            if (!$user) die(json_encode(['Code' => 201, 'Msg' => '用户不存在或登录超时'], JSON_UNESCAPED_UNICODE));
            if (strlen($new) < 6 || strlen($new) > 20) die(json_encode(['Code' => 201, 'Msg' => '新密码只能是6~20位字母或数字'], JSON_UNESCAPED_UNICODE));
            if ($new == $password) die(json_encode(['Code' => 201, 'Msg' => '新密码不能跟原始密码一致'], JSON_UNESCAPED_UNICODE));
            if ($new != $confirm) die(json_encode(['Code' => 201, 'Msg' => '两次密码输入不一致'], JSON_UNESCAPED_UNICODE));
            $md5pwd = encode_member_password($password);
            if ($md5pwd != $user['password']) die(json_encode(['Code' => 201, 'Msg' => '原始密码错误'], JSON_UNESCAPED_UNICODE));
            $row = $userdb->where(array('id' => $userid))->update(array('password' => encode_member_password($new)));
            //member_logout();
            if ($row) {
                die(json_encode(['Code' => 200, 'Msg' => '密码修改成功'], JSON_UNESCAPED_UNICODE));
            } else {
                die(json_encode(['Code' => 201, 'Msg' => '密码修改失败'], JSON_UNESCAPED_UNICODE));
            }
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '请以POST方式提交'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 修改手机号，头像，昵称 */
    public function editInfo(Request $request)
    {
        if ($this->request->isPost()) {
            $data = $request->post();
            if (empty($data['userId'])) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            if (empty($data['content'])) die(json_encode(['Code' => 201, 'Msg' => '修改内容不能为空'], JSON_UNESCAPED_UNICODE));
            if ($data['type'] == 1) {
                // 昵称
                $updata['nickname'] = $data['content'];
            } elseif ($data['type'] == 2) {
                if (!preg_match("/^1[345789]{1}\d{9}$/", $data['content'])) {
                    die(json_encode(['Code' => 201, 'Msg' => '手机号有误，请重新输入'], JSON_UNESCAPED_UNICODE));
                }
                // 手机号
                $updata['tel'] = $data['content'];
            } else {
                // 头像
                $updata['headimgurl'] = $data['content'];
            }
            Db::name('member')->where(['id' => $data['userId']])->update($updata);
            die(json_encode(['Code' => 200, 'Msg' => '信息更新成功'], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '非法的操作'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 获取评论 */
    public function commentList(Request $request)
    {
        // 视频id
        $videoId = $request->param('videoId/d', '0', '');
        // 评论最后一条ID
        $lastId = $request->param('lastId/d', '0', '');

        $limit = $request->post('limit/d', 10);

        $commentdb = Db::name("comment");
        $where = "status = 1 and  resources_type = 1 and resources_id = $videoId and to_id = 0";
        $count = $commentdb->where($where)->count();
        $order = 'last_time desc';
        $last_info = $commentdb->where(array('id' => $lastId))->find();
        if (!empty($last_info)) {
            $where .= " and comment.last_time < " . $last_info['last_time'];
        }
        //echo  $where;exit;
        $field = 'id,send_user,content,last_time';
        $list = Db::view('comment', $field)
            ->view('member', 'username,headimgurl,nickname', 'comment.send_user=member.id', 'LEFT')
            ->where('comment.' . $where)
            ->limit($limit)
            ->order($order)
            ->select();

        //$list = Db::name("comment")->where($where)->order($order)->limit($start,$limit)->field($field)->select();
        //
        /* foreach ($list as $k => $v){
             $where1 = "to_id = ".$v['id'];
             $list[$k]['list'] =  Db::view('comment', $field)
                 ->view('member', 'username,headimgurl,nickname', 'comment.send_user=member.id', 'LEFT')
                 ->where('comment.' . $where1)
                 ->order('last_time asc')
                 ->select();
         }*/
        foreach ($list as &$item) {
            $item['username'] = $item['nickname'];
            //            $item['nickname'] = assembly_name($item['nickname']);
        }

        $data = array(
            'count' => $count,
            'list' => $list
        );
        if (!empty($list)) {
            die(json_encode(['Code' => 200, 'Msg' => '加载完成', 'Data' => $data], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 200, 'Msg' => '没用更多评论了', 'Data' => null], JSON_UNESCAPED_UNICODE));
        }
    }

    public function thirdPartyLogin(Request $request)
    {
        $logininfo = Db::name('app_login_setting')->where(['status' => 1])->field('login_code,login_name,config')->select();
        if (empty($logininfo)) {
            $data = array(
                'status' => 0,
                'list' => 'null'
            );
        } else {
            $data = array(
                'status' => 1,
                'list' => $logininfo
            );
        }
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    public function giftlist()
    {
        $data = array();
        $where = empty($data['where']) ? "status = 1" : $data['where'];
        $orders = empty($data['orders']) ? 'sort DESC' : $data['orders'];
        $field = empty($data['field']) ? 'id,name,images,price,info' : $data['field'];
        $list = Db::name('gift')->where($where)->order($orders)->field($field)->select();
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $list], JSON_UNESCAPED_UNICODE));
    }

    /* 视频打赏 */
    public function gift(Request $request)
    {
        //判断用户参数是否合法
        $itemid = $request->post('giftId/d');
        $projectid = $request->post('videoId/d');
        $user_id = $userId = $request->post('userId/d');
        $type = 1;

        if (empty($itemid)) die(json_encode(['Code' => 201, 'Msg' => 'giftId不能为空'], JSON_UNESCAPED_UNICODE));
        if (empty($projectid)) die(json_encode(['Code' => 201, 'Msg' => 'videoId不能为空'], JSON_UNESCAPED_UNICODE));
        if (empty($user_id)) die(json_encode(['Code' => 201, 'Msg' => 'userId不能为空'], JSON_UNESCAPED_UNICODE));
        //判断礼物是否存在
        $gift_info = Db::name('gift')->where(['id' => $itemid, 'status' => 1])->field('id,name,images,price,info')->find();
        if (empty($gift_info)) die(json_encode(['Code' => 201, 'Msg' => "打赏的礼物不存在！"]));
        //判断用户金币是否足够
        $user_info = model('member')->get($user_id);
        if (intval($user_info->money) < intval($gift_info['price'])) die(json_encode(['Code' => 201, 'Msg' => '你的金币不足'], JSON_UNESCAPED_UNICODE));
        //打赏记录入库
        $gift_data = [
            'user_id' => $user_id,
            'gratuity_time' => time(),
            'content_type' => $type,
            'content_id' => $projectid,
            'gift_info' => json_encode($gift_info),
            'price' => intval($gift_info['price'])
        ];
        $result = Db::name('gratuity_record')->insert($gift_data);
        if ($result) {
            $user_info->money = $user_info->money - $gift_info['price'];
            $user_info->save();
        }
        //统计该视频的打赏
        $gratuity = Db::name('gratuity_record')->where(['content_type' => $type, 'content_id' => $projectid])->select();
        $count = Db::name('gratuity_record')->where(['content_type' => $type, 'content_id' => $projectid])->field(" count(distinct user_id) as count ")->find();
        $gold_log_data = array(
            'user_id' => $user_id,
            'gold' => '-' . intval($gift_info['price']),
            'module' => 'reward',
            'explain' => $projectid
        );
        write_gold_log($gold_log_data);
        $count_price = 0;
        foreach ($gratuity as $k => $v) {
            $json_relust = json_decode($v['gift_info']);
            $count_price = $json_relust->price + $count_price;
        }
        $returndata = ['countprice' => $count_price, 'counts' => $count['count']];
        die(json_encode(['Code' => 200, 'Data' => $returndata, 'Msg' => "谢谢你,打赏成功"], JSON_UNESCAPED_UNICODE));
    }

    /* 签到 */
    public function userSign(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $userDb = Db::name('member');
        // 判断用户是否存在
        $info = $userDb->field('id,out_time')->where(['id' => $userId])->find();
        if (!$info) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 用户是否已签到
        $signDb = Db::name('sign');
        $where = ['user_id' => $userId, 'add_time' => [['EGT', strtotime(date('Ymd'))], ['ELT', strtotime(date('Ymd', strtotime('+1 day')))]]];
        if ($signDb->where($where)->find()) die(json_encode(['Code' => 201, 'Msg' => '今日已签到，每天只能签到一次'], JSON_UNESCAPED_UNICODE));
        // 签到奖励类型
        $type = $this->config['sign_reward_type'];
        // 奖励值
        $point = $this->config['sign_reward'];
        // 奖励值不能空或0的情况下执行下面逻辑
        if (!empty($point)) {
            $where = ['id' => $userId];
            // 给于奖励
            switch ($type) {
                    // 奖励金币
                case '1':
                    $row = $userDb->where($where)->setInc('money', $point);
                    // 金币记录
                    $data = [
                        'user_id' => $userId,
                        'point' => $point,
                        'explain' => '签到奖励',
                        'module' => 'appSign',
                        'add_time' => time(),
                        'is_gold' => 1, // 1为金币 2为余额
                        'type' => 0  // 1为分成 2为提现
                    ];
                    $msg = '金币';
                    break;
                    // 奖励VIP天数
                case '2':
                    // 会员到期时间
                    $out_time = $info['out_time'];
                    // 该会员是否到期
                    if ($out_time > time()) {
                        // 未到期，则从到期时间计算
                        $out_time = strtotime("+{$point} days", $out_time);
                    } else {
                        // 已到期，则从当前时间开始计算
                        $out_time = strtotime("+{$point} days");
                    }
                    $user['out_time'] = $out_time;
                    $row = $userDb->where($where)->update($user);
                    $msg = 'VIP天数';
                    break;
                    // 奖励观看次数
                case '3':
                    $row = $userDb->where($where)->setInc('watch', $point);
                    Db::name('member')->where('id', $userId)->setInc('init_watch', $point);
                    $msg = '观看次数';
                    break;
                    // 免费抽奖次数
                default:
                    $row = $userDb->where($where)->setInc('lottery', $point);
                    $msg = '抽奖次数';
                    break;
            }
        }
        // 写入记录
        if ($row) {
            // 签到记录
            $signDb->insert(['user_id' => $userId, 'type' => $type, 'point' => $point, 'add_time' => time()]);
            // 金币记录
            if ($type == 1) Db::name('account_log')->insert($data);
        }
        die(json_encode(['Code' => 200, 'Msg' => '签到成功，' . $msg . ' +' . $point], JSON_UNESCAPED_UNICODE));
    }

    /* 分享与宣传 */
    public function myShare(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $userDb = Db::name('member');
        // 判断用户是否存在
        $info = $userDb->field('id,out_time')->where(['id' => $userId])->find();
        if (!$info) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 缓存名称
        $cacheName = $request->action() . '_' . $userId;
        // 读取缓存
        $isCache = $this->__getCache($cacheName);
        if ($isCache) {
            $data = $isCache;
        } else {
            $data = [
                // 规则说明，支持HTML标签
                'rule' => htmlspecialchars_decode($this->config['shart_rule']),
                // 二维码海报
                'poster' => $this->__create_share_qr($userId)
            ];
            // 设置缓存
            $this->__setCache($cacheName, $data);
        }
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 分享记录 */
    public function shareLog(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $userDb = Db::name('member');
        $userIn = $userDb->field('id')->where(['id' => $userId])->find();
        if (!$userIn) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 奖励类型
        $type = ['未知', '金币', 'VIP', '观看次数', '抽奖次数'];
        // 分享记录
        $shareDb = Db::name('share_log');
        $where = ['pid' => $userId];
        $shareLog = $shareDb->where($where)->select();
        foreach ($shareLog as $k => $v) {
            $did = $userDb->field("id,did")->where(['did' => $v['did']])->find();
            $shareLog[$k]['isReg'] = $did ? '用户充值可拿提成' : '未注册';
            $shareLog[$k]['addTime'] = date('m-d', $v['add_time']);
            $shareLog[$k]['type'] = $type[$v['type']];
        }
        $today = ['pid' => $userId, 'add_time' => [['EGT', strtotime(date('Ymd'))], ['ELT', strtotime(date('Ymd', strtotime('+1 day')))]]];
        $data = [
            'today' => $shareDb->where($today)->count(),
            'total' => $shareDb->where($where)->count(),
            'list' => $shareLog
        ];
        //
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 上传图片 */
    public function upload(Request $request)
    {
        //var_dump($request);exit;
        $this->uper = UploadUtil::instance();
        $fileType = $request->post('fileType/s', '');
        $fileName = $request->post('fileName/s', '');
        $uploadpath = "XResource/" . date("Ymd");
        if (empty($fileType)) die(json_encode(['Code' => 201, 'Msg' => '请选择需要上传的图片'], JSON_UNESCAPED_UNICODE));
        if (!in_array($fileType, $this->allowFileType)) die(json_encode(['Code' => 201, 'Msg' => '不被支持的图片格式'], JSON_UNESCAPED_UNICODE));
        $web_server_url = $this->config['web_server_url'];
        //var_dump($_FILES);exit;
        $uploadname = time() . rand(99999999, 999999999) . "." . $this->__getExtension($_FILES["fileName"]["name"]);
        if (!is_dir($uploadpath)) $res = mkdir($uploadpath, 0777, true);
        if (move_uploaded_file($_FILES["fileName"]["tmp_name"], $uploadpath . "/" . $uploadname)) {
            die(json_encode(['Code' => 200, 'Msg' => '上传成功', 'Data' => $web_server_url . '/' . $uploadpath . "/" . $uploadname], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '上传失败'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 银行卡列表 */
    public function bankLists(Request $request)
    {
        // 缓存名称
        $cacheName = $request->action();
        // 读取缓存
        $isCache = $this->__getCache($cacheName);
        if ($isCache) {
            $data = $isCache;
        } else {
            $bankDb = Db::name('bank_list');
            $data = $bankDb->field('id value,bank_name label,logo')->where(['status' => 1])->order("sort DESC")->select();
            // 设置缓存
            $this->__setCache($cacheName, $data);
        }
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 获取用户提现银行卡 */
    public function userBankList(Request $request)
    {
        //收款方式（1.支付宝2银行卡 3微信）
        $type = $request->param('type/d', 1);
        $userId = $request->param('userId/d', 0);
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        //获取用户提现方式
        $momey_account = Db::name('draw_money_account')->where(['user_id' => $userId, 'type' => $type])
            ->order('id', 'desc')
            ->field('id,title,account,img,account_name,bank')
            ->select();
        $accountArr = [];
        foreach ($momey_account as $k => $v) {
            if ($type == 1) {
                $accountArr[$k]['id'] = $v['id'];
                $accountArr[$k]['alipayAccount'] = $v['account'];
            }
            if ($type == 2) {
                $accountArr[$k]['carId'] = $v['id'];
                $accountArr[$k]['bankName'] = $v['bank'];
                $accountArr[$k]['cardNum'] = substr($v['account'], -4); //$v['account'];
            }
            if ($type == 3) {
                $accountArr[$k] = $v;
            }
            $accountArr[$k]['icon'] = $v['img'];
        }
        die(json_encode(['Code' => 200, 'Msg' => '成功', 'Data' => $accountArr], JSON_UNESCAPED_UNICODE));
    }

    /* 设置默认提现银行账户 */
    public function setDefaultBank(Request $request)
    {
        if ($request->isPost()) {
            $userId = $request->param('userId/d', 0);
            $cardId = $request->param('cardId/d', 0);
            if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            if (empty($cardId)) die(json_encode(['Code' => 201, 'Msg' => '请选择提现到账银行卡'], JSON_UNESCAPED_UNICODE));
            $usesDb = Db::name('member');
            // 判断用户是否存在
            $user = $usesDb->where(['id' => $userId])->find();
            if (!$user) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            $bankDb = Db::name('draw_money_account');
            $where = ['user_id' => $userId, 'id' => $cardId];
            $info = $bankDb->field('id')->where($where)->find();
            if (!$info) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出APP后再试'], JSON_UNESCAPED_UNICODE));
            $bankDb->where(['user_id' => $userId])->update(['is_default' => 0]);
            $bankDb->where($where)->update(['is_default' => 1]);
            die(json_encode(['Code' => 200, 'Msg' => '操作成功'], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '非法的操作'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 删除银行卡 */
    public function delBank(Request $request)
    {
        if ($this->request->isPost()) {
            $data = $request->post();
            if (empty($data['userId'])) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            $userId = $data['userId'];
            $bankId = $data['cardId'];
            $usesDb = Db::name('member');
            $user = $usesDb->where(['id' => $userId])->find();
            if (!$user) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            $where['id'] = $bankId;
            $where['user_id'] = $userId;
            $bankDb = Db::name('draw_money_account');
            $bankInfo = $bankDb->where($where)->field("id")->find();
            if (empty($bankInfo)) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请刷新后再试'], JSON_UNESCAPED_UNICODE));
            $result = $bankDb->where($where)->delete();
            if ($result) {
                die(json_encode(['Code' => 200, 'Msg' => '删除成功'], JSON_UNESCAPED_UNICODE));
            } else {
                die(json_encode(['Code' => 201, 'Msg' => '删除失败'], JSON_UNESCAPED_UNICODE));
            }
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '非法的操作'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 添加提现银行卡 */
    public function addBank(Request $request)
    {
        if ($this->request->isPost()) {
            $data = $request->post();
            $bankId = $data['bankId'];
            $userId = $data['userId'];
            if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            $usesDb = Db::name('member');
            $user = $usesDb->where(['id' => $userId])->find();
            if (!$user) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '非法的操作'], JSON_UNESCAPED_UNICODE));
        }
        $bank = $data['bankName'];
        $account_name = $data['cardUser'];
        $bankaccount = $data['cardNum'];
        $banklogo = $data['banklogo'];
        if (empty($account_name)) die(json_encode(['Code' => 201, 'Msg' => '请输入用户姓名'], JSON_UNESCAPED_UNICODE));
        if (empty($bank)) die(json_encode(['Code' => 201, 'Msg' => '请输入银行卡名称'], JSON_UNESCAPED_UNICODE));
        if (empty($bankaccount)) die(json_encode(['Code' => 201, 'Msg' => '请输入银行卡账户'], JSON_UNESCAPED_UNICODE));

        $indata['user_id'] = $userId;
        $indata['title'] = '银行卡' . substr($bankaccount, 0, 4) . '****' . substr((int)$bankaccount, -4);
        $indata['type'] = 2;
        $indata['account'] = $bankaccount;
        $indata['account_name'] = $account_name;
        $indata['img'] = $banklogo;
        $indata['bank'] = $bank;
        $result = Db::name('draw_money_account')->insert($indata);
        if ($result) {
            die(json_encode(['Code' => 200, 'Msg' => '添加成功'], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '添加失败'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 余额提现页 */
    public function balance(Request $request)
    {
        // money余额  corn金币
        $userId = $request->param('userId/d', 0);
        if ($userId == 0) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $user_info = Db::name('member')->field("id,k_money,money")->where(['id' => $userId])->find();
        if (!$user_info) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $where = ['user_id' => $userId, 'is_default' => 1];
        $bank = Db::name('draw_money_account')->field('id,account_name,account')->where($where)->find();
        $isAnchor = Db::name('anchor')->where(['user_id' => $userId, 'status' => 1])->find();
        $isAnchor = empty($isAnchor) ? false : true;

        $data = [
            'bankId' => $bank['id'],
            'bankName' => $bank['account_name'],
            'corn' => $user_info['money'],   // 金币
            'money' => $user_info['k_money'], // 余额
            'isTx' => $this->config['is_withdrawals'],  // 提现开关，false为关闭提现
            'closeMsg' => $this->config['withdrawals_close_hint'],  // 提现功能关闭后提示
            'ratio' => $this->config['money_exchange_rate'],     // 1元等于多少余额，兑换比例
            'minimum' => $this->config['min_withdrawals'], // 最低提现数
            'bankSn' => substr($bank['account'], -4, 4), // 卡号
            'isAnchor' => $isAnchor, // 是否主播 
        ];
        die(json_encode(['Code' => 200, 'Msg' => "成功", 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 余额提现处理 */
    public function getmoney(Request $request)
    {
        //userId用户ID    cardId银行卡ID   money提现金额
        if ($this->request->isPost()) {
            $data = $request->post();
            $userId = $data['userId'];
            $cardId = $data['cardId'];
            $money = $data['money'];
            if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            if (empty($cardId)) die(json_encode(['Code' => 201, 'Msg' => '请选择到账银行卡'], JSON_UNESCAPED_UNICODE));
            if (empty($money)) die(json_encode(['Code' => 201, 'Msg' => '请输入提现金额'], JSON_UNESCAPED_UNICODE));
            $is_withdrawals = $this->config['is_withdrawals'];
            if (!$is_withdrawals) die(json_encode(['Code' => 201, 'Msg' => "提现功能暂未开启，请联系平台客服"], JSON_UNESCAPED_UNICODE));
            $userDb = Db::name('member');
            // 判断提现最低限额
            $user_info = $userDb->field("id,k_money,money")->where(['id' => $userId])->find();
            if (!$user_info) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            $min_withdrawals = $this->config['min_withdrawals'];
            if (intval($money) < intval($min_withdrawals)) {
                die(json_encode(['Code' => 201, 'Msg' => "提现失败，最低提现额度为：" . intval($min_withdrawals)], JSON_UNESCAPED_UNICODE));
            }
            // 判断用户提现帐户是否存在
            $money_account = Db::name('draw_money_account')->where(['id' => $cardId, 'user_id' => $userId])->find();
            if (empty($money_account)) die(json_encode(['Code' => 201, 'Msg' => "收款账户不存在，请先添加"], JSON_UNESCAPED_UNICODE));
            if (intval($user_info['k_money']) < intval($money)) {
                die(json_encode(['Code' => 201, 'Msg' => "提现失败：你的账户余额不足"], JSON_UNESCAPED_UNICODE));
            }
            $result = $userDb->where(['id' => $userId])->setDec('k_money', $money);
            // 汇率
            $txMoney = $money / $this->config['money_exchange_rate'];
            if ($result) {
                // 余额记录
                $log_data = [
                    'user_id' => $userId,
                    'point' => '-' . $money,
                    'explain' => '余额提现',
                    'module' => 'draw_money',
                    'add_time' => time(),
                    'is_gold' => 2, // 1为金币 2为余额
                    'type' => 2  // 1为分成 2为提现
                ];
                Db::name('account_log')->insert($log_data);
                // 提现记录
                $log_tx = [
                    'user_id' => $userId,
                    'point' => $money,
                    'money' => $txMoney,
                    'add_time' => time(),
                    'status' => 0,
                    'info' => json_encode($money_account)
                ];
                Db::name('draw_money_log')->insert($log_tx);
                die(json_encode(['Code' => 200, 'Msg' => "提现申请已提交成功"], JSON_UNESCAPED_UNICODE));
            } else {
                die(json_encode(['Code' => 201, 'Msg' => "未知错误"], JSON_UNESCAPED_UNICODE));
            }
            //die(json_encode(['Code' => 200, 'Msg' => '用户ID：'.$userId.'银行卡ID：'.$cardId.'提现金额：'.$money], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '非法的操作'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 提现记录 */
    public function depositLog(Request $request)
    {
        // 用户ID
        $userId = $request->param('userId/d', 0);
        $userDb = Db::name('member');
        $userInfo = $userDb->where(['id' => $userId])->find();
        if (!$userInfo) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 当前页数
        $page = $request->param('page/d', 1);
        // 每页显示条数
        $pageSize = 10;
        $log_list = Db::name('draw_money_log')->where(['user_id' => $userId])->page($page, $pageSize)->order('id DESC')->select();
        foreach ($log_list as $k => $v) {
            $data[$k]['id'] = $v['id'];
            $data[$k]['userId'] = $v['user_id'];
            $data[$k]['money'] = $v['money'];
            $data[$k]['point'] = $v['point'];
            $data[$k]['addTime'] = date('Y/m/d H:i', $v['add_time']);
            $data[$k]['updateTime'] = date('Y/m/d H:i', $v['update_time']);
            $data[$k]['status'] = $v['status'];
            $data[$k]['msg'] = $v['status'] > 0 ? ($v['status'] == 1 ? '已打款' : $v['msg']) : '处理中';
            // 银行卡信息
            $bank = json_decode($v['info'], true);
            $data[$k]['banklogo'] = $bank['img'];
            $data[$k]['bankName'] = $bank['bank'];
            $data[$k]['bankSn'] = substr($bank['account'], -4, 4);
            $data[$k]['bankUsername'] = $bank['account_name'];
        }
        die(json_encode(['Code' => 200, 'Msg' => "获取成功", 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 账户明细=========================================update:11.3 */
    public function detailedList(Request $request)
    {
        // 用户ID
        $userId = $request->param('userId/d', 0);
        // 类型 1为金币2余额
        $type = $request->param('type/d', 1);
        $userInfo = Db::name('member')->field('id,money,k_money')->where(['id' => $userId])->find();
        if (!$userInfo) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 当前页数
        $page = $request->param('page/d', 1);
        // 每页显示条数
        $pageSize = 10;
        $where = [
            'user_id' => $userId,
            'is_gold' => $type
        ];
        $log_list = Db::name('account_log')->where($where)->page($page, $pageSize)->order('id DESC')->select();
        $list = [];
        foreach ($log_list as $k => $v) {
            $list[$k]['id'] = $v['id'];
            $list[$k]['userId'] = $v['user_id'];
            $list[$k]['orderSn'] = date('Ymd', $v['add_time']) . $v['id'];
            $list[$k]['point'] = $v['point'] > 0 ? '+' . $v['point'] : $v['point'];
            $list[$k]['addTime'] = date('Y-m-d H:i', $v['add_time']);
            $list[$k]['type'] = $v['is_gold'];
            $list[$k]['remarks'] = $v['explain'];
        }
        $data['list'] = $list;
        // 余额数或金币数
        $typePoint = ['', 'money', 'k_money'];
        $data['point'] = $userInfo[$typePoint[$type]];
        die(json_encode(['Code' => 200, 'Msg' => "获取成功", 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 我的收藏 */
    public function collectionList(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        $type = $request->param('type/d', 1);
        switch ($type) {
            case '2':
                // 免费
                $where = ' and video.gold = 0';
                break;
            case '3':
                // VIP
                $where = ' and video.gold > 0';
                break;
            default:
                // 全部
                $where = '';
                break;
        }
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $video_info = Db::view('video_collection', 'id,video_id')
            ->view('video', 'good,click,title,add_time,class,play_time,thumbnail,reco,gold,play_time', 'video_collection.video_id=video.id')
            ->view('class', 'name as videoKind', 'video.class=class.id')
            ->where('video.status=1 and video.is_check=1 and video_collection.user_id=' . $userId . $where)
            ->paginate(100);
        $dataArr = [];
        if ($video_info) {
            foreach ($video_info as $k => $v) {
                $dataArr[$k]['videoId'] = $v['video_id'];
                $dataArr[$k]['videoTitle'] = $v['title'];
                $dataArr[$k]['videoImgUrl'] = htmlspecialchars_decode($v['thumbnail']);
                $dataArr[$k]['videoSendDate'] = $v['add_time'];
                $dataArr[$k]['videoKind'] = $v['videoKind'];
                $dataArr[$k]['gold'] = $v['gold'];
                $dataArr[$k]['play_time'] = $v['play_time'];
            }
            $Code = 200;
            $Msg = "成功";
        } else {
            $Code = 201;
            $Msg = "您还没收藏视频哦";
        }
        die(json_encode(['Code' => $Code, 'Msg' => $Msg, 'Data' => $dataArr], JSON_UNESCAPED_UNICODE));
    }

    /* 添加收藏视频 */
    public function addCollection($userId, $videoId)
    {
        $id = $videoId;
        $member_id = $userId;

        $db = Db::name('video');
        $collect_db = Db::name('video_collection');
        //判断存如视频id是否存在
        $result_video = $db->where(['status' => 1, 'id' => $id])->find();
        if (empty($result_video)) {
            die(json_encode(['Code' => 201, 'Msg' => '当前视频不存在'], JSON_UNESCAPED_UNICODE));
        }
        //判断视频是否已经收藏
        $result_collect = $collect_db->where(['user_id' => $member_id, 'video_id' => $id])->find();
        if ($result_collect) {
            die(json_encode(['Code' => 201, 'Msg' => '该视频已经收藏过了'], JSON_UNESCAPED_UNICODE));
        }
        //插入用户收藏日志
        $collect_data = [
            'user_id' => $member_id,
            'video_id' => $id,
            'collection_time' => time()
        ];
        $insert_result = $collect_db->data($collect_data)->insert();
        if ($insert_result) {
            die(json_encode(['Code' => 200, 'Msg' => '收藏成功'], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '收藏失败'], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 删除已收藏视频 */
    public function deleteCollection(Request $request)
    {
        if ($this->request->isPost()) {
            $data = $request->post();
            if (empty($data['userId'])) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            if (empty($data['videoId'])) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出APP重试1'], JSON_UNESCAPED_UNICODE));
            $userId = $data['userId'];
            $IDvideoId = $data['videoId'];
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出APP重试2'], JSON_UNESCAPED_UNICODE));
        }
        $colDb = Db::name('video_collection');
        $where = ["video_id" => $IDvideoId, "user_id" => $userId];
        // 先看这条记录是不是它收藏的
        $result = $colDb->where($where)->find();
        if (empty($result)) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出APP重试3'], JSON_UNESCAPED_UNICODE));
        if ($colDb->where($where)->delete()) die(json_encode(['Code' => 200, 'Msg' => '删除成功'], JSON_UNESCAPED_UNICODE));
        die(json_encode(['Code' => 201, 'Msg' => '删除失败'], JSON_UNESCAPED_UNICODE));
    }

    /* 我的足迹 */
    public function getWatchLog(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        $type = $request->param('type/d', 1); // 1看过，2赞过，3买过
        $userDb = Db::name('member');
        $userInfo = $userDb->where(['id' => $userId])->find();
        if (!$userInfo) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 当前页数
        $page = $request->param('page/d', 1);
        if (intval($page) < 1) $page = 1;
        // 每页显示条数
        $pageSize = 10;
        // 查询条件
        $where = ['w.user_id' => $userId];
        // 读取数据
        if ($type == 1) {
            // 观看记录
            $data['list'] = Db::view('video_watch_log w', '*,view_time addTime')
                ->view('video v', 'id videoId,title videoTitle,thumbnail,gold,click,play_time playTime', 'w.video_id=v.id')
                ->where($where)
                ->page($page, $pageSize)
                ->order('w.view_time DESC')
                ->select();
        } elseif ($type == 2) {
            // 点赞记录
            $data['list'] = Db::view('video_good_log w', '*,add_time addTime')
                ->view('video v', 'id videoId,title videoTitle,thumbnail,good likes,gold,play_time playTime', 'w.video_id=v.id')
                ->where($where)
                ->page($page, $pageSize)
                ->order('w.id DESC')
                ->select();
        } else {
            // 购买记录
            /*$data['list'] = Db::view('video_buy_log w',"*,video_id videoId,add_time addTime")
                     ->view('video v',',title videoTitle,thumbnail,good likes,gold,play_time playTime','w.video_id=v.id')
                     //->field("count(*) as count")
                     ->group('w.video_id')
                     ->where($where)
                     ->page($page, $pageSize)
                     ->order('w.id DESC')
                     ->select();*/
            $subQuery = Db::name("video_buy_log w")
                ->field('*,video_id videoId,add_time addTime')
                ->where($where)
                ->page($page, $pageSize)
                ->order('w.id DESC')
                ->buildSql();
            $data['list'] = Db::table($subQuery . "a")
                ->group('video_id')
                ->order('id DESC')
                ->select();
        }
        // 视频有效期
        $buyValidity = $this->config['message_validity'] * 60 * 60;
        //$data['sql'] = Db::name('video_buy_log')->getLastSql();
        $videoDb = Db::name('video');
        foreach ($data['list'] as $k => $v) {
            if ($type == 3) {
                $field = "title,thumbnail,good likes,play_time playTime";
                $info = $videoDb->field($field)->where(['id' => $v['videoId']])->find();

                $data['list'][$k]['playTime'] = empty($info['playTime']) ? "★" : $info['playTime'];
                $data['list'][$k]['thumbnail'] = htmlspecialchars_decode($info['thumbnail']);
                $data['list'][$k]['videoTitle'] = $info['title'];

                $endTime = (int)(($v['addTime'] + $buyValidity - time()));
                $data['list'][$k]['addTime'] = ($endTime > 0) ? $this->__intToTime($endTime) : '需重新购买';
                $data['list'][$k]['endMsg'] = ($endTime > 0) ? '付费有效期剩余' : '已过期';
            } else {
                $data['list'][$k]['playTime'] = empty($v['playTime']) ? "★" : $v['playTime'];
                $data['list'][$k]['thumbnail'] = htmlspecialchars_decode($v['thumbnail']);
            }
            unset($data['list'][$k]['did']);
        }
        //print_r($data);
        die(json_encode(['Code' => 200, 'Msg' => "获取成功", 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 一键清除我的足迹 */
    public function delWatchLog(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        $type = $request->param('type/d', 1); // 1为足迹，2为赞过
        $userDb = Db::name('member');
        $userInfo = $userDb->where(['id' => $userId])->find();
        if (!$userInfo) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        Db::name('video_watch_log')->where(['user_id' => $userId])->delete();
        die(json_encode(['Code' => 200, 'Msg' => "清除所有足迹成功"], JSON_UNESCAPED_UNICODE));
    }

    /* 充值套餐 */
    public function getChargeData(Request $request)
    {
        // 缓存名称
        $cacheName = $request->action();
        // 读取缓存
        $isCache = $this->__getCache($cacheName);
        if ($isCache) {
            $data = $isCache;
        } else {
            $exchange_rate = $this->config['gold_exchange_rate'];
            //$custom_recharge = $this->config['custom_recharge'];
            $custom_recharge = false;
            // VIP套餐数据
            $rechargeList = Db::name('recharge_package')->where('status=1')->order('sort asc')->select();
            $rechargeArr = [];
            foreach ($rechargeList as $k => $v) {
                $rechargeArr[$k]['vipId'] = $v['id'];
                $rechargeArr[$k]['title'] = $v['name'];
                $rechargeArr[$k]['money'] = $v['price'];
                $rechargeArr[$k]['time'] = (int)$v['days'];
                $rechargeArr[$k]['type'] = (int)$v['permanent'];
                $rechargeArr[$k]['tips'] = $v['info'];
            }
            // 金币套餐数据
            $goldPackageList = Db::name('gold_package')->select();
            $goldArr = [];
            foreach ($goldPackageList as $k => $v) {
                $goldArr[$k]['cornId'] = $v['id'];
                $goldArr[$k]['title'] = $v['name'];
                $goldArr[$k]['money'] = $v['price'];
                $goldArr[$k]['corn'] = $v['gold'];
                $goldArr[$k]['tips'] = "";
                //$goldArr[$k]['cornCal']=$gold_exchange_rate;
            }
            $data = ['vip' => $rechargeArr, 'corn' => $goldArr, 'cornCal' => $exchange_rate, 'custom' => $custom_recharge];
            // 设置缓存
            $this->__setCache($cacheName, $data);
        }

        /* －－－－－－－－－－－－－ 新增或修改s －－－－－－－－－－－－－－－－－－－－－－－－ */
        $data['is_recharge'] = $this->config['is_recharge'];
        $data['recharge_text'] = trim($this->config['recharge_text']);
        /* －－－－－－－－－－－－－ 新增或修改e －－－－－－－－－－－－－－－－－－－－－－－－ */
        //
        die(json_encode(['Code' => 200, 'Msg' => "成功", 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 支付接口列表 */
    public function getPayList()
    {
        $data['list'] = Db::name('payment')
            ->field("id,pay_name payName,is_third_payment type,pay_logo payIcon,pay_code payCode")
            ->where(['status' => 1, 'is_app' => 1])
            ->order('id DESC')
            ->select();
        foreach ($data['list'] as &$item) {
            if (empty($item['type'])) {
                $item['type'] = $item['type'] ? 0 : 1;
            }
        }

        /*if($this->config['is_balance_pay']){
            $moneyInfo = [
                'id' => 0,
                'payName' => '余额支付',
                'type'    => 2, // 0为原生1为第三方2余额
                'payIcon' => $this->config['balance_pay_img'], // 图标
                'payCode' => 'system|k_money'
            ];
            //
        }*/
        die(json_encode(['Code' => 200, 'Msg' => "成功", 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 支付宝APP支付 */
    public function __alipayApp($order)
    {
        $aop = new AopClient;
        $con = Db::name('payment')->where(['id' => 1, 'status' => 1])->find();
        if (!$con) return false;
        $configs = json_decode($con['config'], true);
        foreach ($configs as $v) {
            $config[$v['name']] = $v['value'];
        }
        $aop->gatewayUrl = $con['gateway'];
        $aop->appId = $config['appId'];
        $aop->rsaPrivateKey = $config['privateKey'];
        $aop->charset = "UTF-8";
        $aop->signType = "RSA2";
        $aop->alipayrsaPublicKey = $config['publicKey'];
        $body = $order['order_sn'];
        $buyName = $order['buy_type'] == 1 ? '充值金币' : '购买VIP';
        $subject = $buyName . '(' . $order['order_sn'] . ')';
        $data = [
            'body' => $body,
            'subject' => $subject,
            'out_trade_no' => $order['order_sn'],
            'timeout_express' => '60m',
            'total_amount' => $order['price'],
            'product_code' => 'QUICK_MSECURITY_PAY'
        ];
        $bizcontent = json_encode($data);
        $request = new AlipayTradeAppPayRequest();
        // 异步通知地址
        $notifyUrl = $this->config['web_server_url'] . '/pay_notify/app_alipay_notify';
        $request->setNotifyUrl($notifyUrl);
        $request->setBizContent($bizcontent);
        //这里和普通的接口调用不同，使用的是sdkExecute
        $response = $aop->sdkExecute($request);
        //htmlspecialchars是为了输出到页面时防止被浏览器将关键参数html转义，实际打印到日志以及http传输不会有这个问题
        $data = htmlspecialchars($response); //就是orderString 可以直接给客户端请求，无需再做处理。
        return $response;
    }

    /* 微信、支付宝支付网关 */
    public function __publicPay($order, $payId)
    {
        // 支付宝APP支付使用原生网关
        if ($payId == 1 && $order['is_app']) return $this->__alipayApp($order);

        $payClass = '\\systemPay\\payGateway';
        $buyName = ($order['buy_type'] == 1) ? '充值金币' : '购买VIP';
        //
        $payParams['orderSn'] = $order['order_sn'];
        $payParams['price'] = $order['price'];
        $payParams['body'] = $buyName . '(' . $order['order_sn'] . ')';
        $payType = ['wap', 'app'];
        $gateway = ['', 'alipay', 'wechat'];
        //        $payment = Db::name('payment')->where('status',1)->find($payId);
        //        $pay_code = explode('|',$payment['pay_code']);
        try {
            $para = [$gateway[$payId], $payType[$order['is_app']]];
            $payer = new $payClass($para, $payId);
            $payerPayRs = $payer->createPayCode($payParams);
            return $payerPayRs;
            //die(json_encode(['Code' => 200, 'Msg' => '获取成功','Data' => $payerPayRs], JSON_UNESCAPED_UNICODE));
        } catch (\Exception $exception) {
            return $exception->getMessage();
        }
    }

    /**
     * 订单创建
     * @param Request $request
     */
    public function createOrder(Request $request)
    {
        // 来源 1 为APP 2 为WEB
        $source = $request->post('source/d', 1);
        $source = (int)$source == 1 ? 1 : 0;
        // 支付方式ID
        $payId = $request->post('payId/d', 0);
        // 购买类型1:金币，2:vip
        $buyType = $request->post('buyType/d', 1);
        $userId = $request->post('userId/d', 0);
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 验证用户ID是否存在
        $userInfo = Db::name('member')->field('is_permanent')->where(['id' => $userId])->find();
        if (!$userInfo) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        // 支付网关
        $config = Db::name('payment')->field('id,pay_code,is_third_payment')->where(['id' => $payId, 'status' => 1])->find();
        if (!$config) die(json_encode(['Code' => 201, 'Msg' => '暂无可用支付网关'], JSON_UNESCAPED_UNICODE));
        // 支付渠道标识
        //$payCode = $request->post('payCode/s', '');
        $payCode = trim($config['pay_code']);
        $payCodeArr = explode('|', $payCode);
        if (count($payCodeArr) < 2) die(json_encode(['Code' => 201, 'Msg' => '支付网关异常,请联系客服'], JSON_UNESCAPED_UNICODE));
        // 支付金额
        $price = $request->post('price/f', 0);
        if ($price <= 0) die(json_encode(['Code' => 201, 'Msg' => '支付金额必须大于0元'], JSON_UNESCAPED_UNICODE));
        $orderInfo = [
            'payment_code' => $payCodeArr[0],       // 支付方式的code
            'pay_channel' => $payCodeArr[1],       // 支付渠道：alipay qqpay wxpay
            'price' => $price,               // 金额
            'buy_type' => $buyType,             // 购买类型，1:金币，2:vip
            'user_id' => $userId,              // 会员Id
            'from_agent_id' => 0,                    // 当前代理商id
            'from_domain' => '',                   // 请求的来源网址
            'is_app' => $source,              // 1为APP订单
            'third_id' => $payId                // 入库前需验证ID的真实性
        ];
        switch ($buyType) {
                // gold
            case 1:
                $gold = $request->post('gold/d', 0);
                // 金币兑换比例
                $rate = $this->config['gold_exchange_rate'];
                $orderInfo['buy_glod_num'] = !empty($gold) ? $gold : (int)$orderInfo['price'] * $rate;  //购买的金币数
                break;
                // vip
            case 2:
                // 如果已是永久会员，则无需再充值
                if ($userInfo['is_permanent']) die(json_encode(['Code' => 201, 'Msg' => '您已是我站永久VIP,请勿重复购买'], JSON_UNESCAPED_UNICODE));
                // 套餐ID
                $packageId = $request->post('packId/d', 0);
                $packageInfo = RechargePackage::get($packageId);
                if (!$packageInfo) die(json_encode(['Code' => 201, 'Msg' => '您要购买的套餐不存在或已关闭'], JSON_UNESCAPED_UNICODE));
                // 如果是购买vip套餐，那么金额以套餐金额为准
                if ($packageInfo->price != $orderInfo['price']) $orderInfo['price'] = $packageInfo->price;
                // 购买的vip信息
                $orderInfo['buy_vip_info'] = $packageInfo->hidden(['status', 'sort'])->toJson();
                break;
        }
        $order_sn = create_order_sn();
        $orderInfo['order_sn'] = $order_sn;
        $orderInfo['body'] = md5($order_sn);
        $order = new Order();
        $order->save($orderInfo);
        $orderSn = $order->order_sn;
        if ($orderSn) {
            // 原生
            //if (!$config['is_third_payment']) {
            //                $res = $this->__publicPay($order, $payId);
            //                $orderInfo['alipayStrSign'] = $res;
            //                if ($payId == 1 && $source == 0) {
            //                    $path = ROOT_PATH . "paylog";
            //                    is_dir($path) or mkdir($path, 0777, true);
            //                    file_put_contents($path . '/pay_' . $orderInfo['order_sn'] . '.json', json_encode($res));
            //                }
            //}

            $return['webUrl'] = $this->config['web_server_url'];
            $return['order_sn'] = $order_sn;
            die(json_encode(['Code' => 200, 'Msg' => '下单成功', 'Data' => $return], JSON_UNESCAPED_UNICODE));
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '创建订单失败，请重试'], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * 收银台
     * @param Request $request
     */
    public function pay(Request $request)
    {
        // 订单号
        $orderSn = $request->param('orderSn/s', '');
        $order = Order::get($orderSn);
        if (!$order) die($this->__appMsg('订单不存在或已付款', 2));
        if ($order->status == 1) die($this->__appMsg('此订单已支付，无需再支付', 2));
        // 查询是否为原生接口
        $pay = Db::name('payment')->where(['id' => $order->third_id])->find();
        if (!$pay['status']) die($this->__appMsg('支付接口不存在，请联系客服', 2));
        //die($this->__appMsg('支付接口正在开发中...', 2));
        $payParams = [
            'orderSn' => $order->order_sn,
            'payType' => $order->pay_channel,
            'price' => $order->price,
            'third' => $order->third_id,
            'sign' => $pay['my_sign'],
            'buyType' => $order->buy_type,
            'add_time' => $pay['add_time'],
            'res_type' => $pay['return_type'],
            'method' => $pay['method'],
        ];
        //dd($payParams);
        // 第三方支付
        if ($pay['is_third_payment'] && $pay['is_app'] == 1) {
            $payClass = '\\systemPay\\thirdPay';
            try {
                $payer = new $payClass();
                $payRs = $payer->sendPayQrcode($payParams);
            } catch (\Exception $exception) {
                $this->error($exception->getMessage());
            }
            //$arr = explode('|', $pay['pay_code']);
            //print_r($payRs);die;
            if (isset($payRs['code']) && $payRs['code'] == 1) {
                $order->save(['real_pay_price' => $payRs['money']]);
                if ($pay['return_type'] == 2) {
                    echo $payRs['url'];
                    die;
                } else {
                    $this->redirect($payRs['url']);
                }
            } else {
                //$this->error($payRs['msg'], null, '', 5);
                die($this->__appMsg($payRs['msg'], 2));
            }
            // 原生支持处理
        } elseif ($pay['is_app'] == 1 && ($pay['pay_code'] == 'native|wxpay' || $pay['pay_code'] == 'native|alipay')) {
            //原生h5
            $payClass = '\\systemPay\\thirdPayGateway';
            $buyName = ($order['buy_type'] == 1) ? '购买金币' : '购买VIP';
            $payParams['orderSn'] = $order['order_sn'];
            $payParams['subject'] = md5($order['order_sn']);
            $payParams['price'] = $order['price'];
            $payParams['body'] = $buyName . $order['order_sn'];
            //$payType = ['wap','app'];
            //$gateway = ['', 'alipay', 'wechat']; 
            $payType = 'wap';
            $gateway = explode('|', $pay['pay_code'])[1] == 'wxpay' ? 'wechat' : 'alipay';
            try {
                $para = [$gateway, $payType];
                $payer = new $payClass($para, $order['third_id']);
                $payerPayRs = $payer->createPayCode($payParams);
                //$this->__jumpUrl($gateway, $payerPayRs);
            } catch (\Exception $exception) {
                return $exception->getMessage();
            }
        } else {
            if ($order->is_app) {
                die($this->__appMsg('请在APP内完成支付', 2));
            } else {
                $path = ROOT_PATH . "paylog";
                $inputSrt = file_get_contents($path . '/pay_' . $orderSn . '.json');
                $inputSrt = json_decode($inputSrt, true);
                $html = '
                    <!DOCTYPE html>
                    <html lang="en">
                    <head>
                    <meta charset="UTF-8" />
                    <!-- <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0" id="vp"> -->
                    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
                    <title>支付中...</title>
                    </head>
                    <body>
                        <div style="text-align:center;padding:80px 0 0 0;">
                            <img src="/static/images/loading.gif" style="height:50px;margin:auto"/>
                            <div style="margin-top:20px;font-size:14px;color:#666;">正在前往支付途中</div>
                            ' . $inputSrt . '
                        </div>
                    </body>
                    </html>';
                echo $html;
            }
        }
    }

    /* 获取APP最新版号，APP下载地址 @Date 2020 */
    public function getVersion()
    {
        $wheres = "name in ('apk_version_name','apk_version','apk_download','ios_version_name','ios_version','ios_download','is_force_update')";
        $config = Db::name('admin_config')->where($wheres)->column('name,value');
        /* －－－－－－－－－－－－－ 新增或修改 －－－－－－－－－－－－－－－－－－－－－－－－ */
        $data['is_force_update'] = empty($config['is_force_update']) ? false : true; //是否强制升级
        /* －－－－－－－－－－－－－ 新增或修改 －－－－－－－－－－－－－－－－－－－－－－－－ */
        // 安卓
        $data['android'] = [
            'versionCode' => $config['apk_version'],
            'version' => $config['apk_version_name'],
            'downUrl' => $config['apk_download']
        ];
        // 苹果
        $data['apple'] = [
            'versionCode' => $config['ios_version'],
            'version' => $config['ios_version_name'],
            'downUrl' => $config['ios_download']
        ];
        // 返回数据
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 关于我们 @Date 2020 */
    public function getAbout(Request $request)
    {
        // 缓存名称
        $cacheName = $request->action();
        // 读取缓存
        $isCache = $this->__getCache($cacheName);
        if ($isCache) {
            $data = $isCache;
        } else {
            $wheres = "name in ('app_info','video_email','ad_email','qq','wx')";
            $config = Db::name('admin_config')->where($wheres)->column('name,value');
            // 简介
            $data['appInfo'] = $config['app_info'];
            // 视频合作邮箱
            $data['videoEmail'] = $config['video_email'];
            // 广告合作邮箱
            $data['adEmail'] = $config['ad_email'];
            // 合作QQ
            $data['qq'] = $config['qq'];
            // 合作微信
            $data['wx'] = $config['wx'];
            // 设置缓存
            $this->__setCache($cacheName, $data);
        }
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 用于后台远程调用分成 */
    public function divideIntoUrl(Request $request)
    {
        $userId = $request->param('userId/d', 0);
        $price = $request->param('price/f', 0);
        $orderSn = $request->param('orderSn/s', '');
        if (empty($userId) || empty($price) || empty($orderSn) || $price < 1) die('非法操作，请刷新后再试01！');
        // 验证数据的真伪
        $orWhere = "order_sn='$orderSn' and user_id='$userId' and status=1";
        $isOrder = Db::name('order')
            ->field("price,is_divide")
            ->where($orWhere)
            ->find();
        if (empty($isOrder['price']) || $isOrder['price'] <> $price) {
            die('非法操作，请刷新后再试02！');
        } else {
            // 该订单是否已分成
            if ($isOrder['is_divide']) die('该订单是否已分成！');
            // 分成函数
            //app_divide_into($userId, $price, $orderSn);
            cur_agent_divide($userId, $price, $orderSn);
            echo 'success';
        }
    }

    private function is_wechat_browser()
    {
        $route = strtolower($_SERVER['HTTP_USER_AGENT']);
        $data = true;
        if (strpos($route, 'android')) return false;
        if (strpos($route, 'micromessenger') == false && strpos($route, 'qq/') == false) {
            if (strpos($route, 'safari') == false) $data = false;
        } else {
            //微信或QQ内打开
            $data = false;
        }
        return $data;
    }

    /* 路由判断，安卓与苹果手机访问 */
    public function download(Request $request)
    {
        $pid = $request->param('pid/d', '');
        if (empty($pid)) echo "<script>window.location.href='" . $this->config['web_server_url'] . "'</script>";
        $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        $android = (strpos($agent, 'android')) ? true : false;
        // 分享路由
        $route = $_SERVER['HTTP_USER_AGENT'];
        // 是否为微信或QQ内打开
        if (strpos($route, 'MicroMessenger') == false && strpos($route, 'QQ/') == false) {
            $url = empty($this->config['wx_skip']) ? $this->config['web_server_url'] : $this->config['wx_skip'];
            if (strpos($url, '@') !== false) {
                $randStr = get_random_str(10);
                if (strlen($randStr) > 3) $url = str_replace("@", $randStr, $url);
            }
            // 安卓
            //if($android){
            echo "<script>window.location.href='" . $url . '/' . $this->apiFilenNme . '/down_app/pid/' . $pid . "'</script>";
            // 否则都跳转至苹果页面
            //}else{
            //  echo "<script>window.location.href='".$this->config['web_server_url'].'/down_app/pid/'.$pid."'</script>";
            //}
        } else {
            $img = empty($this->config['wx_img']) ? '/tpl/appapi/img/ms.jpg' : $this->config['wx_img'];
            // 微信或QQ内打开
            echo "<!DOCTYPE html>
            <html>
                <head>
                    <meta http-equiv='Content-Type' content='text/html; charset=UTF-8'>
                    <meta name='viewport' content='maximum-scale=1.0,minimum-scale=1.0,user-scalable=0,width=device-width,initial-scale=1.0,user-scalable=no'>
                    <meta name='renderer' content='webkit'>
                    <meta http-equiv='X-UA-Compatible' content='IE=Edge,chrome=1'>
                    <title data='1'>" . $this->config['site_title'] . "</title>
                </head>
                <body style='background-color:#333333;'><br>
                    <div style='background-color:#333333;width:100%;'><img src='" . $img . "' width='100%'></div>
                </body>
            </html>";
        }
    }

    /* 获取分享者id */
    public function getSharePid(Request $request)
    {
        $userdb = Db::name('member');
        $request = Request::instance();
        $sharedata['ip'] = $request->ip();
        $shareinfo = Db::name('share_ip')->where(array('ip' => $sharedata['ip']))->find();

        $reg_drop = $userdb->where(['id' => $shareinfo['pid']])->find();
        if ($reg_drop['reg_drop'] && $reg_drop['reg_drop'] !== '0.00') {

            $randomNumber = mt_rand(1, 99);

            if ($randomNumber <= $reg_drop['reg_drop'] * 100) {
                $shareinfo['pid'] = 0;
            } else {
                $shareinfo['pid'] = empty($shareinfo['pid']) ? 0 : $shareinfo['pid'];
            }
        }

        $shareinfo['pid'] = empty($shareinfo['pid']) ? 0 : $shareinfo['pid'];
        Db::name('share_ip')->where(array('ip' => $sharedata['ip']))->delete();
        die(json_encode(['Code' => 200, 'Msg' => "成功", 'Data' => $shareinfo], JSON_UNESCAPED_UNICODE));
    }

    /* APP更新下载页 */
    public function down_app(Request $request)
    {
        $pid = $request->param('pid/d', '');
        $request = Request::instance();
        $sharedata['pid'] = $pid;
        $sharedata['ip'] = $request->ip();
        if (empty($pid)) echo "<script>window.location.href='" . $this->config['web_server_url'] . "'</script>";
        // 获取网站配置信息
        $w = "name in ('site_title','site_favicon','app_logo','app_info','web_server_url','site_description','site_keywords','apk_version_name','ios_version_name','apk_download','ios_download','ios_web_url')";
        $info = Db::name('admin_config')->where($w)->column('name, value');
        $shareinfo = Db::name('share_ip')->where(array('ip' => $sharedata['ip']))->find();
        if (empty($shareinfo)) {
            Db::name('share_ip')->insert($sharedata);
        } else {
            Db::name('share_ip')->where(array('ip' => $shareinfo['ip']))->update($sharedata);
        }
        $this->assign('pid', $pid);

        //$agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        //$android = (strpos($agent, 'android')) ? true : false;
        // 安卓
        //if($android){
        $config['apk'] = [
            'ver' => $info['apk_version_name'],
            'url' => $info['apk_download']
        ];
        $config['ios'] = [
            'ver' => $info['ios_version_name'],
            'url' => $info['ios_download']
        ];
        // 轻量版
        $config['url'] = [
            'ver' => $info['ios_version_name'],
            'url' => $info['ios_web_url']
        ];
        $config['web_title'] = $info['site_title'];
        $config['favicon'] = $info['site_favicon'];
        $config['web_logo'] = $info['app_logo'];
        $config['web_info'] = $info['app_info'];
        $config['web_url'] = $info['web_server_url'];
        $config['keywords'] = $info['site_description'];
        $config['description'] = $info['site_keywords'];;
        //}else{
        /*  $config['versions'] = $config['ios_version'];
            $config['download'] = $config['app_android'];
            $config['updates']  = str_replace('；','</br>',$config['app_update_ios']);
            $config['type_img'] = 'ios';
            $config['typename'] = 'iPhone';*/
        //}
        $this->assign('c', $config);
        return $this->fetch('tpl/appapi/download.html');
    }

    /* 服务协议、隐私政策 */
    public function privacy(Request $request)
    {
        $type = $request->param('type/d', 1);
        $sys = $request->param('sys/s', '');
        $html['top'] = $sys == 'android' ? $request->param('top/d', 0) : 0;
        switch ($type) {
                // 服务协议  协议内容需要以 html_entity_decode(string)函数转义一次
            case '1':
                $html['title'] = '服务协议';
                $html['content'] = html_entity_decode($this->config['services']);
                break;
                // 隐私政策 协议内容需要以 html_entity_decode(string)函数转义一次
            default:
                $html['title'] = '隐私政策';
                $html['content'] = html_entity_decode($this->config['privacy']);
                break;
        }
        $this->assign('html', $html);
        return $this->fetch('tpl/appapi/privacy.html');
    }

    /* 活动列表 */
    public function getGameList(Request $request)
    {
        $data = Db::name('activity')->field('*,thumbnail img')->where(['status' => 1])->order('sort DESC')->select();
        foreach ($data as $k => $v) {
            $data[$k]['status'] = $v['end_time'] < time() ? 0 : 1; // 0已过期 1进行中
        }
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 幸运大转盘 */
    public function getPrize(Request $request)
    {
        $userId = $request->param('userId/d', 0); // 用户ID
        $mold = $request->param('mold/d', 1);  // 模式 1 积分转盘抽奖  2现金转盘抽奖
        $type = $request->param('type/d', 1);  // 1为获取奖品列表，2为中奖处理
        $gameId = $request->param('gameId/d', 0); // 游戏ID
        $userDb = Db::name('member');
        // 判断用户是否存在
        $user = $userDb->field('id,money,lottery,out_time')->where(['id' => $userId])->find();
        if (!$user) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $where = "id={$gameId} and status=1 and end_time>" . time();
        $game = Db::name('activity')->where($where)->find();
        if (!$game) die(json_encode(['Code' => 201, 'Msg' => '活动未开启或已结束'], JSON_UNESCAPED_UNICODE));
        $payGold = json_decode($game['config'], true);
        $kcGold = 0;
        $gameBg = '';
        if ($gameId == 1) {
            foreach ($payGold as $k => $v) {
                if ($v['name'] == 'gold') $kcGold = $v['value'];
                if ($v['name'] == 'bg') $gameBg = $v['value'];
            }
        }
        $actDb = Db::name('act_prize');
        // 模拟数据
        switch ($type) {
            case '1':
                $data['lists'] = $actDb->where(['status' => 1, 'pid' => $gameId])->order("sort DESC")->select();
                $data['luckdraw'] = $user['lottery']; // 抽奖次数
                $data['gold'] = $user['money'];   // 我的金币数
                $data['payGold'] = $kcGold; // 支付金币可抽奖一次
                $data['luckMold'] = [1 => '免费抽奖', 2 => '金币抽奖'];
                $data['bg'] = $gameBg; // 活动页背景图
                break;
                // 中奖处理
            case '2':
                $logDb = Db::name('account_log');
                // 账户相关业务处理
                if ($mold == 1) {
                    // 扣除免费抽奖次数
                    $result = $userDb->where(['id' => $userId])->setDec('lottery');
                } else {
                    // 扣除抽奖金币数
                    $result = $userDb->where(['id' => $userId])->setDec('money', $kcGold);
                    // 金币记录
                    $datas = [
                        'user_id' => $userId,
                        'point' => '-' . $kcGold,
                        'explain' => '金币抽奖',
                        'module' => 'appDraw',
                        'add_time' => time(),
                        'is_gold' => 1, // 1为金币 2为余额
                        'type' => 0  // 1为分成 2为提现
                    ];
                    // 金币记录
                    if ($result) $logDb->insert($datas);
                }
                if ($result) {
                    // 所有奖品
                    $list = $actDb->field("id,name,scale")->where("status=1 and pid={$gameId} and scale>0")->select();
                    // 执行抽奖
                    $res = $this->__getRand($list, array_sum(array_column($list, 'scale')));
                    // 查询中奖奖品
                    $prize = $actDb->field("id,name,type,amount,img")->where(['status' => 1, 'id ' => $res])->find();
                    if (!$prize) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出后重试'], JSON_UNESCAPED_UNICODE));
                    $amount = $prize['amount'];
                    if (empty($prize['type'])) {
                        $tit = $prize['name'];
                        $msg = "没准下次就能中大奖哦！！！";
                    } else {
                        $tit = "中奖啦";
                        $msg = "恭喜获得大奖：[" . $prize['name'] . "] +" . $amount;
                    }
                    // 中奖处理
                    switch ($prize['type']) {
                            // 1金币2余额3抽奖次数4观看次数5VIP天数6永久会员7实物
                        case '1':
                            $row = $userDb->where(['id' => $userId])->setInc('money', $amount);
                            // 金币记录
                            $moy = [
                                'user_id' => $userId,
                                'point' => $amount,
                                'explain' => '中奖获得金币',
                                'module' => 'appDraw',
                                'add_time' => time(),
                                'is_gold' => 1, // 1为金币 2为余额
                                'type' => 0  // 1为分成 2为提现
                            ];
                            // 金币记录
                            if ($row) $logDb->insert($moy);
                            break;
                        case '2':
                            $row = $userDb->where(['id' => $userId])->setInc('k_money', $amount);
                            // 金币记录
                            $moy = [
                                'user_id' => $userId,
                                'point' => $amount,
                                'explain' => '中奖获得余额',
                                'module' => 'appDraw',
                                'add_time' => time(),
                                'is_gold' => 2, // 1为金币 2为余额
                                'type' => 3  // 1为分成 2为提现 3金币抽奖
                            ];
                            // 金币记录
                            if ($row) $logDb->insert($moy);
                            break;
                        case '3':
                            $row = $userDb->where(['id' => $userId])->setInc('lottery', $amount);
                            break;
                        case '4':
                            $row = $userDb->where(['id' => $userId])->setInc('watch', $amount);
                            break;
                        case '5':
                            // 会员到期时间
                            $out_time = $user['out_time'];
                            // 该会员是否到期
                            if ($out_time > time()) {
                                // 未到期，则从到期时间计算
                                $out_time = strtotime("+{$amount} days", $out_time);
                            } else {
                                // 已到期，则从当前时间开始计算
                                $out_time = strtotime("+{$amount} days");
                            }
                            $row = $userDb->where(['id' => $userId])->update(['out_time' => $out_time]);
                            break;
                        case '6':
                            $row = $userDb->where(['id' => $userId])->update(['is_permanent ' => 1]);
                            break;
                        default:
                            // code...
                            break;
                    }
                    // 抽奖记录
                    $data = [
                        'user_id' => $userId,
                        'pid' => $res,
                        'aid' => $gameId,
                        'point' => $amount,
                        'exp' => $prize['name'],
                        'img' => $prize['img'],
                        'type' => $prize['type'],
                        'mold' => $mold,
                        'createtime' => time()
                    ];
                    Db::name('act_prize_log')->insert($data);
                    // 返回给前端
                    $data = [
                        // 奖品列表ID
                        "id" => $res,
                        // 1免费抽奖，2金币抽奖
                        "mold" => $mold,
                        // 中奖标题
                        "title" => $tit,
                        // 中奖内容
                        "content" => $msg
                    ];
                } else {
                    die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出后重试'], JSON_UNESCAPED_UNICODE));
                }
                break;
                // 我的奖品列表
            default:
                $data['lists'] = Db::name('act_prize_log')->field('id,exp,point,createtime,img')->where(['user_id' => $userId])->order("id DESC")->select();
                foreach ($data['lists'] as $k => $v) {
                    $data['lists'][$k]['name'] = $v['exp'] . ' +' . $v['point'];
                    if (empty($v['point'])) $data['lists'][$k]['name'] = $v['exp'];
                }
                break;
        }
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 注册与分享奖励 type＝1为注册，2为分享 3为上传奖励*/
    public function __regReward($uid, $userDb, $routeType = 1, $did = '', $route = 2)
    {
        $isUser = $userDb->field('id,out_time')->where(['id' => $uid])->find();
        // 用户不存在
        if (!$isUser) return false;
        if ($routeType == 1) {
            $reward = (int)$this->config['register_reward'];
            $type = (int)$this->config['register_reward_type'];
            $explain = ['注册奖励', 'appRegister'];
        } elseif ($routeType == 2) {
            $reward = (int)$this->config['share_reward'];
            $type = (int)$this->config['share_reward_type'];
            $explain = ['分享奖励', 'appShare'];
            //分享获得金额
            $reward_gold = (int)$this->config['share_reward_gold'];
            $type_gold = 8;
            $explain_gold = ['分享金额', 'appSharegold'];
            // 每台手机只能被分享一次
            if (Db::name('share_log')->where(['did' => $did])->find()) return false;
        } elseif ($routeType == 3) {
            $reward = (int)$this->config['upload_reward'];
            $type = (int)$this->config['upload_reward_type'];
            $explain = ['上传奖励', 'appUpload'];
        }
        if (!empty($reward)) {
            $field = ['', 'money', 'out_time', 'watch', 'lottery'];
            // 注册奖励
            switch ($type) {
                    // VIP天数
                case '2':
                    // 该会员是否到期
                    if ($isUser['out_time'] > time()) {
                        // 未到期，则从到期时间计算
                        $out_time = strtotime("+{$reward} days", $isUser['out_time']);
                    } else {
                        // 已到期，则从当前时间开始计算
                        $out_time = strtotime("+{$reward} days");
                    }
                    $user['out_time'] = $out_time;
                    $userDb->where(['id' => $uid])->update($user);
                    break;
                    // 金币,观看次数,抽奖次数
                default:
                    $userDb->where(['id' => $uid])->setInc($field[$type], $reward);
                    break;
            }
            if ($type == 1) {
                // 金币记录
                $log = [
                    'user_id' => $uid,
                    'point' => $reward,
                    'explain' => $explain[0],
                    'module' => $explain[1],
                    'add_time' => time(),
                    'is_gold' => 1, // 1为金币 2为余额
                    'type' => 0  // 1为分成 2为提现 3金币抽奖
                ];
                Db::name('account_log')->insert($log);
            }
            if ($type == 3) {
                Db::name('member')->where('id', $uid)->setInc('init_watch', $reward);
            }
            if ($routeType == 2) {
                // 分享记录
                $log = [
                    'pid' => $uid,
                    'point' => $reward,
                    'type' => $type,  // 奖励类型
                    'to_ip' => \think\Request::instance()->ip(),
                    'did' => $did,
                    'route' => $route,
                    'add_time' => time()
                ];
                Db::name('share_log')->insert($log);
            }
        }
        if ($reward_gold > 0) {
            $res = $userDb->where(['id' => $uid])->setInc('k_money', $reward_gold);
            $log = [
                'user_id' => $uid,
                'point' => $reward_gold,
                'explain' => $explain_gold[0],
                'module' => $explain_gold[1],
                'add_time' => time(),
                'is_gold' => 2, // 1为金币 2为余额
                'type' => $type_gold  // 1为分成 2为提现 3金币抽奖
            ];
            Db::name('account_log')->insert($log);
        }
    }

    /* APP内H5提示 */
    public function __appMsg($msg = '未知错误', $type = 1)
    {
        switch ($type) {
            case '2':
                // 不带返回按钮
                $html = '
                <!DOCTYPE html>
                <html lang="en">
                <head>
                <meta charset="UTF-8" />
                <!-- <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0" id="vp"> -->
                <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
                <title>温馨提示</title>
                </head>
                <body>
                    <div style="text-align:center;padding:80px 0 0 0;">
                        <img src="/tpl/appapi/img/err.png" style="height:100px;margin:auto"/>
                        <div style="margin-top:20px;font-size:14px;color:#666;">' . $msg . '</div>
                        <a style="margin:20px auto;font-size:14px;color:#666;background:#009688;color:#fff;border-radius:5px;padding:5px 20px;display:inline-block;" href="javascript:;">请联系平台客服</a>
                    </div>
                </body>
                </html>';
                break;
                // 带返回按钮
            default:
                $html = '
                <!DOCTYPE html>
                <html lang="en">
                <head>
                <meta charset="UTF-8" />
                <!-- <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0" id="vp"> -->
                <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
                <title>温馨提示</title>
                </head>
                <body>
                    <div style="text-align:center;padding:80px 0 0 0;">
                        <img src="/tpl/appapi/img/err.png" style="height:100px;margin:auto"/>
                        <div style="margin-top:20px;font-size:14px;color:#666;">' . $msg . '</div>
                        <a style="margin:20px auto;font-size:14px;color:#666;background:#009688;color:#fff;border-radius:5px;padding:5px 20px;display:inline-block;" href="javascript:history.go(-1)">返回</a>
                    </div>
                </body>
                </html>';
                break;
        }
        return $html;
    }

    /* 生成二维码并保存 */
    public function __create_share_qr($pid = 0)
    {
        if (!empty($pid)) {
            vendor("phpqrcode.phpqrcode");
            // 读取配置信息
            $wheres = "name in ('share_qr_size','share_qr_x','share_qr_y','share_background','web_server_url','wx_skip')";
            $config = Db::name('admin_config')->where($wheres)->column('name,value');
            $webUrl = $config['wx_skip'];
            if (empty($webUrl) || $webUrl == '#') $webUrl = "http://v.msvodx.com";
            if (strpos($webUrl, '@') !== false) {
                $randStr = get_random_str(10);
                if (strlen($randStr) > 3) $webUrl = str_replace("@", $randStr, $webUrl);
            }
            //return $webUrl;
            $urls = $webUrl . '/' . $this->apiFilenNme . '/download/pid/' . $pid;
            // 纠错级别：L、M、Q、H
            $level = 'L';
            // 二维码显示位置 x轴
            $qr_x = intval($config['share_qr_x']);
            // 二维码显示位置 y轴
            $qr_y = intval($config['share_qr_y']);
            // 点的大小，1到10, 请结合海报大小来调
            $size = intval($config['share_qr_size']);
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
            // 远程海报地址
            $bigImgPath = trim($config['share_background']);
            $bigImgPath = empty($bigImgPath) ? 'qr/img/QR.jpg' : $bigImgPath;
            $resImg = file_get_contents($bigImgPath);
            if (!$resImg) $resImg = $this->__getUrl($bigImgPath);
            // 用户二维码
            $qCodePath = "qr/qr/uid_$pid.jpg";
            // 抓取海报并创建画布
            $bigImg = imagecreatefromstring($resImg);
            // 读取本地二维码并创建画布
            $qCodeImg = imagecreatefromstring(file_get_contents($qCodePath));
            list($qCodeWidth, $qCodeHight, $qCodeType) = getimagesize($qCodePath);
            // 二维码显示位置 239 x轴 580 为y轴
            imagecopymerge($bigImg, $qCodeImg, $qr_x, $qr_y, 0, 0, $qCodeWidth, $qCodeHight, 100);
            list($bigWidth, $bigHight, $bigType) = getimagesize($bigImgPath);
            $shareBg = 'qr/uid_' . $pid . '.jpg';
            if (file_exists($shareBg)) unlink($shareBg);
            // 生成新的海报并保存在本地
            imagejpeg($bigImg, $shareBg);
            // 返回路经
            return $webUrl . '/' . $shareBg;
        }
    }

    /*********************************************社区功能*******************************************/
    //htmlspecialchars_decode();
    /*
     * id user_id title  content cover（封面） type(1图文 2 视频) add_time  is_open(1全部可看 0仅自己可看) top(置顶0) order(排序0) status  community_post表 帖子
       id post_id resources_url type(1 image 2 video) add_time  community_post_resources表 帖子资源表
       //id name status order add_time Post_Class表 帖子分类表
    */
    /* －－－－－－－－－－－－－ 新增或修改s －－－－－－－－－－－－－－－－－－－－－－－－ */
    /* 发帖 */
    public function posteds()
    {
        $filter = $this->config['community_content_filter'];
        $filter = explode('#', $filter);

        $content = input('param.content');
        $res = sensitive($filter, $content);
    }

    public function posted($param)
    {
        $userId = $param['userId'];
        $resource_url = $param['imgs'];

        $filter = $this->config['community_content_filter'];
        $filter = TrimArray(explode(PHP_EOL, $filter));
        $res = sensitive($filter, $param['content']);

        $content = $res;
        $type = $param['type'];
        // 0发帖，其它数据表示编辑帖子，在未审核或审核拒绝状态可编辑
        $id = $param['id'];
        // 需要过滤一些特殊字符，后台设置需要过滤的文字，以* 替换
        $data['content'] = $content;
        //todo 限制图片
        if (empty($userId)) return ['Code' => 201, 'Msg' => '登录超时或未登录'];

        $data['user_id'] = $userId;
        $data['type'] = $type;
        $post_examine_on = $this->config['post_examine_on'];
        if ($post_examine_on == 1) $data['is_check'] = 0; //默认为1
        $data['add_time'] = time();
        $postId = Db::name('community_post')->insertGetId($data);
        $picData = [];
        if (is_array($resource_url) && !empty($resource_url) && $type == 1) {
            foreach ($resource_url as $v) {
                $picData[] = ['post_id' => $postId, 'type' => 1, 'resources_url' => $v];
            }
        } else if (!empty($resource_url) && $type == 2) {
            $picData[] = ['post_id' => $postId, 'type' => 1, 'resources_url' => $resource_url];
        }

        if (!empty($resource_url)) Db::name('community_post_resources')->insertAll($picData);

        $text = '发帖成功';
        if ($post_examine_on == 1) $text .= ',您的帖子正在审核';
        return ['Code' => 200, 'Msg' => $text];
    }

    /* 帖子的图片上传 */
    public function uploadImg(Request $request)
    {
        $this->uper = UploadUtil::instance();
        $web_server_url = $this->config['web_server_url'];
        $post = $request->post('');
        if (empty($post['userId'])) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        if (!$request->isPost()) die(json_encode(['Code' => 201, 'Msg' => '请选择需要上传的图片'], JSON_UNESCAPED_UNICODE));
        // 1 为发帖，2上传视频
        $uploadType = $request->post('uploadType/d', 1);
        if ($uploadType == 1) {
            $arryFile = $request->file("imgs");
            $fileName = 'post';
        } else {
            $arryFile = $request->file("all");
            $fileName = 'post_' . $post['userId'];
        }
        $max = (int)$this->config['upload_maxVideo'];
        if ($uploadType == 1) {
            $max = rtrim(strtolower(ini_get('upload_max_filesize')), 'm');
        }
        $size = 1024 * 1024 * $max;
        $allExt = ['size' => $size, 'ext' => 'jpg,png,jpeg,bmp,gif,mp4,wmv,avi,mov,flv'];
        $allPath = ROOT_PATH . 'public' . DS . 'upload' . DS . $fileName . DS . date('Y') . DS . date('m-d');

        $pathImg = [];

        if ($arryFile && $post['isData'] == 1) {

            if (count($arryFile) > 9) die(json_encode(['Code' => 201, 'Msg' => '最多上传9张图片'], JSON_UNESCAPED_UNICODE));
            foreach ($arryFile as $File) {
                //移动文件到框架应用更目录的public/upload/  ->validate(['size'=>15678,'ext'=>'jpg,png,gif'])
                $info = $File->validate($allExt)->move($allPath, md5(microtime(true)));
                if ($info) {
                    $pathImg[] = $web_server_url . "/upload/" . $fileName . "/" . date('Y') . '/' . date('m-d') . '/' . $info->getFilename();
                } else {
                    //错误提示用户
                    die(json_encode(['Code' => 201, 'Msg' => $File->getError()], JSON_UNESCAPED_UNICODE));
                }
            }
        }
        $post['imgs'] = $pathImg;
        $res = $uploadType == 1 ? $this->posted($post) : $this->saveVideoData($post);
        die(json_encode(['Code' => $res['Code'], 'Msg' => $res['Msg']], JSON_UNESCAPED_UNICODE));
    }

    public function saveVideoData($data)
    {
        $userdb = Db::name('member');
        $shortvideo = new Shortvideo();
        $shortvideo->data([
            'title' => $data['title'],
            'info' => $data['info'],
            'thumbnail' => $data['imgs'][0],
            'url' => $data['imgs'][1],
            'down_url' => $data['imgs'][1],
            'gold' => $data['gold'],
            'add_time' => time(),
            'user_id' => $data['userId'],
            'status' => 1,
            'class_id' => $data['cid'],
            'is_check' => $this->config['resource_examine_on'] ? 0 : 1 //0是待处理 1已审核
        ]);

        $shortvideo->save();
        //是否需要审核
        if ($this->config['video_reexamination'] == 0) {
            //不需要审核直接奖励
            $res = $this->__regReward($data['userId'], $userdb, $routeType = 3); //奖励
            return ['Code' => 200, 'Msg' => '上传成功'];
        }

        return ['Code' => 200, 'Msg' => '上传成功，正在审核中...'];
    }

    /* 获取评论 */
    public function getPostComment(Request $request)
    {
        //帖子id
        $post_id = $request->post('post_id', '');

        if (empty($post_id)) die(json_encode(['Code' => 201, 'Msg' => '话题不存在或已被删除'], JSON_UNESCAPED_UNICODE));
        $comments = communityPostComment::with([
            'comments' => function ($query) {
                $query->field('content,add_time,id,to_id')->where(['status' => 1]);
            },
            'member' => function ($query) {
                $query->field('username,nickname,id,headimgurl')->where(['status' => 1]);
            }
        ])->field('content,add_time,id,send_user')
            ->where(['post_id' => $post_id, 'to_id' => 0, 'status' => 1])
            ->order('id desc')
            ->select();
        foreach ($comments as &$item) {
            $item = $item->toArray();
            $item['member']['username'] = $item['member']['nickname'];
        }


        die(json_encode(['Code' => 200, 'Data' => $comments], JSON_UNESCAPED_UNICODE));
    }

    /* 获取上传配置 */
    public function getUploadConfig()
    {
        $list = Db::name('class')->field('id,name')->where(['status' => 1, 'pid' => 0])->order('sort ASC')->select();
        $data = [
            'type' => $this->config['video_save_server_type'] == 'yunzhuanma' ? 2 : 1, //1web服务器2云转码
            'uploadUrl' => $this->config['video_save_server_type'] == 'yunzhuanma' ? $this->config['web_server_url'] . '/appapi/addVideo' : '', //如果是云转码则此参数为H5上传页面
            'explain' => htmlspecialchars_decode($this->config['upload_desc']), //上传规则，支持HTML
            'maxVideoSize' => (int)$this->config['upload_maxVideo'], // 单位M，上传最大限制 0表示不限制
            'uploadSwitch' => $this->config['upload_switch'] ? 1 : 0,  //上传开关
            'classList' => $list //分类列表
        ];
        die(json_encode(['Code' => 200, 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }
    /* －－－－－－－－－－－－－ 新增或修改e －－－－－－－－－－－－－－－－－－－－－－－－ */

    /* 帖子评论 */
    public function sendPostComment(Request $request)
    {
        $userId = $request->post('userId', '');
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        $post_id = $request->post('post_id', '');
        $post = communityPost::get($post_id);
        if (empty($post)) die(json_encode(['Code' => 201, 'Msg' => '话题不存在或已被删除'], JSON_UNESCAPED_UNICODE));

        $pid = $request->param('pid/d', 0);  //大于0则为回复评论ID，属子评论，
        $content = $request->param('content/s', '');
        $data['send_user'] = $userId;
        $data['content'] = $content;
        $data['post_id'] = $post_id;
        $data['add_time'] = time();
        $post_comment_examine_on = $this->config['post_comment_examine_on'];

        if ($post_comment_examine_on == 1) $data['status'] = 0; //需要审核status设置为0  默认为1
        if ($pid > 0) {
            $data['to_id'] = $pid; //默认为0
            $touser_id = Db::name('community_post_comment')->where('id', $pid)->value('send_user');
            $data['to_user'] = $touser_id ?: 0; //默认为0
        }
        $res = Db::name('community_post_comment')->insert($data);
        if (!$res) die(json_encode(['Code' => 201, 'Msg' => '评论失败!请重试'], JSON_UNESCAPED_UNICODE));
        $text = '评论成功!';
        if ($post_comment_examine_on == 1) $text = '评论成功,您的评论正在审核!';
        die(json_encode(['Code' => 200, 'Msg' => $text], JSON_UNESCAPED_UNICODE));
    }

    /* 点赞帖子 */
    public function likePost(Request $request)
    {
        $userId = $request->post('userId', '');
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));

        $type = isset($this->param['type']) ? $this->param['type'] : 1; //1帖子点赞，2评论点赞(暂时不做)
        if ($type == 1) {
            $postid = $request->post('postid', ''); //视频ID
            if (empty($postid)) die(json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE));
            $post = communityPost::get($postid);
            if (empty($post)) die(json_encode(['Code' => 201, 'Msg' => '话题不存在或已被删除'], JSON_UNESCAPED_UNICODE));
            $resources_id = $postid;
        }

        $like = Db::name('community_post_like')->where(['resources_id' => $resources_id, 'user_id' => $userId, 'type' => $type])->find();

        if ($like) {
            $status = $like['status'] ? 0 : 1;
            if ($type == 1) {
                if ($status == 0) {
                    //点赞数加1
                    Db::name('community_post')->where('id', $postid)->setInc('good');
                } else {
                    Db::name('community_post')->where('id', $postid)->setDec('good');
                }
            }
            $res = Db::name('community_post_like')->where(['id' => $like['id'], 'type' => $type])->setField('status', $status);

            $text = $status == 0 ? '点赞成功' : '取消点赞';
        } else {
            $insert_date['user_id'] = $userId;
            $insert_date['resources_id'] = $resources_id;
            $insert_date['type'] = $type;
            $insert_date['status'] = 0;
            $insert_date['add_time'] = time();
            $res = Db::name('community_post_like')->insertGetId($insert_date);
            if ($type == 1) {
                Db::name('community_post')->where('id', $postid)->setInc('good');
            }
            $text = '点赞成功'; //点赞成功 or 取消点赞
        }
        if (!$res) die(json_encode(['Code' => 201, 'Msg' => $text], JSON_UNESCAPED_UNICODE));
        die(json_encode(['Code' => 200, 'Msg' => $text], JSON_UNESCAPED_UNICODE));
    }

    /* －－－－－－－－－－－－－ 新增或修改s －－－－－－－－－－－－－－－－－－－－－－－－ */
    /* 社区首页 */
    public function communityHomepage(Request $request)
    {
        $userId = $request->param('uid/d', 0); //用户ID 如果未登录则值为false
        $order = $request->param('order/d', 1); // 1我的(需要登录) 2最新(以发布时间排序)，3热门(以回帖量排序)  order为3或2，至顶帖排最上面
        $sort = 'c.top desc,c.add_time desc';
        $where = '1=1 and c.status = 1 and c.is_check=1';
        if ($order == 1) {
            if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            $where .= " and user_id = " . $userId;
        }

        $communityPost = Db::view('community_post c', 'id,content as text,user_id,add_time,status,top as isTop');

        $data_list = $communityPost
            ->view('member m', 'headimgurl,username,nickname', 'm.id = c.user_id', 'LEFT')
            ->where($where)
            ->order($sort)
            ->paginate(10, false, ['query' => $this->request->get()])
            ->each(function ($item, $key) use ($userId, $order) {
                $item['allImg'] = Db::name('community_post_resources')->where('post_id', $item['id'])->column('resources_url');
                $html = '';

                if (!empty($item['allImg'])) {
                    foreach ($item['allImg'] as $k => $v) {
                        if ($k > 2) break;
                        $html .= "<img src='{$v}'>";
                    }
                }

                if (empty($item['username'])) {
                    $item['username'] = '会员不存在或已删除';
                    $item['headimgurl'] = $this->config['web_server_url'] . '/static/images/user_dafault_headimg.jpg';
                }

                $item['text'] = htmlspecialchars_decode($item['text']);
                $item['uid'] = $item['user_id'];
                $item['isTop'] = $item['isTop'] == 1 ? true : false;
                $item['type'] = 0; //帖子
                $item['html'] = $html;
                $item['username'] = $item['nickname'];
                $item['comment'] = $this->getCommentCount($item['id']);
                if ($order != 1) {
                    $item['isMe'] = $this->isMe($userId, $item['id']);
                    unset($item['status']);
                }
                return $item;
            })->toArray();


        if ($order == 3) {
            $topData = [];
            $hotData = [];
            foreach ($data_list['data'] as $v) {
                if ($v['isTop']) {
                    array_push($topData, $v);
                } else {
                    array_push($hotData, $v);
                }
            }

            $top_Data = array_column($topData, 'comment');
            array_multisort($top_Data, SORT_DESC, $topData);
            $hot_Data = array_column($hotData, 'comment');
            array_multisort($hot_Data, SORT_DESC, $hotData);

            $data_list['data'] = array_merge($topData, $hotData);
        }


        //广告
        if ($order != 1 && !empty($data_list['data'])) {
            $ad_list = Db::view('advertisement a', 'id,content img,url')
                ->view('advertisement_position b', 'height', 'a.position_id=b.id')
                ->where("b.id=7 and a.status=1 and a.end_time>" . time())
                ->select();
            if (!empty($ad_list)) {
                $r = $ad_list[array_rand($ad_list, 1)];
                $type = ['type' => 1];
                $ad_data = array_merge($r, $type);
                $ad[] = $ad_data;
                $this->array_insert($data_list['data'], 5, $ad);
            }
        }

        $data['list'] = $data_list['data'];

        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 获取话题评论 */
    public function getCommentCount($post_id)
    {
        $count = communityPostComment::where(['post_id' => $post_id, 'status' => 1])->count();
        return $count;
    }

    private function array_insert(&$array, $position, $insert_array)
    {
        $first_array = array_splice($array, 0, $position);
        $array = array_merge($first_array, $insert_array, $array);
        return $array;
    }

    /* 话题是否为自己发布的 */
    public function isMe($uid, $post_id)
    {
        $data = Db::name('community_post')->where(['id' => $post_id, 'user_id' => $uid])->find();
        if ($data) return true;
        return false;
    }

    /* 获取广告信息 */
    public function getAdInfo(Request $request)
    {
        // 广告位ID
        $pid = $request->post('position_id/d', 0);
        // 广告数量
        $limit = $request->post('limit/d', 1);
        if (empty($pid)) die(json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE));
        $data = Db::view('advertisement a', 'id,content,titles,url')
            ->view('advertisement_position b', 'height', 'a.position_id=b.id')
            ->where("b.id={$pid} and a.status=1 and a.end_time>" . time())
            ->limit($limit)
            ->order("RAND()")
            ->select();
        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 个人主页 */
    public function homePage(Request $request)
    {
        // 作者ID
        $uid = $request->param('uid/d', 0);
        if (empty($uid)) die(json_encode(['Code' => 201, 'Msg' => '数据异常，请退出后重试'], JSON_UNESCAPED_UNICODE));
        // 1作者上传的作品，2作者发帖动态
        $type = $request->param('type/d', 1);
        $where = '1=1 and c.status = 1 and c.is_check=1 and user_id = ' . $uid;
        $sort = 'c.top desc,c.add_time desc';
        $communityPost = Db::view('community_post c', 'id,content as text,add_time,status,top as isTop');
        switch ($type) {
            case '1':
                $video = Db::name('video')
                    ->where(['status' => 1, 'is_check' => 1, 'user_id' => $uid])
                    ->field('id,thumbnail,title,add_time,click')
                    ->order('add_time desc')
                    ->select();
                //                'click' => rand(10,99), //点击次数
                //                'add_time' => '1604050798', //上传时间
                $list = $video;
                break;
            default:
                $data_list = $communityPost
                    ->view('member m', 'headimgurl,username,nickname', 'm.id = c.user_id', 'LEFT')
                    ->where($where)
                    ->order($sort)
                    ->select();
                foreach ($data_list as &$item) {
                    $item['allImg'] = Db::name('community_post_resources')->where('post_id', $item['id'])->column('resources_url');
                    $html = '';
                    if (!empty($item['allImg'])) {
                        foreach ($item['allImg'] as $v) {
                            $html .= "<img src='{$v}'>";
                        }
                    }
                    if (empty($item['username'])) {
                        $item['username'] = '会员不存在或已删除';
                        $item['headimgurl'] = $this->config['web_server_url'] . '/static/images/user_dafault_headimg.jpg';
                    }
                    $item['username'] = $item['nickname'];
                    $item['uid'] = $uid;
                    $item['html'] = $html;
                    $item['comment'] = $this->getCommentCount($item['id']);
                    $item['isMe'] = $this->isMe($uid, $item['id']);
                }

                $list = $data_list;
                break;
        }
        $data['list'] = $list;
        $commentSum = Db::name('community_post c')->where($where)->count();
        $videoSum = Db::name('video')->where(['status' => 1, 'is_check' => 1, 'user_id' => $uid])->count();
        $userInfo = Db::name('member')->field('headimgurl,username,nickname')->where(['status' => 1])->find($uid);
        if (empty($userInfo)) {
            $userInfo['username'] = '会员不存在或已删除';
            $userInfo['headimgurl'] = $this->config['web_server_url'] . '/static/images/user_dafault_headimg.jpg';
        }

        $data['info'] = [
            'headimgurl' => $userInfo['headimgurl'], //作者头像
            'username' => $userInfo['nickname'], // 作者账号
            'videoSum' => $videoSum, //作品数量
            'commentSum' => $commentSum //发帖数量
        ];

        die(json_encode(['Code' => 200, 'Msg' => '获取成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* －－－－－－－－－－－－－ 新增或修改e －－－－－－－－－－－－－－－－－－－－－－－－ */

    public function addVideo()
    {
        $type = input('param.type', '1');
        $userId = input('param.uid', '0');
        $info = htmlspecialchars_decode($this->config['upload_desc']);
        $url = $this->config['web_server_url'];
        $classList = Db::name('shortclass')->field('id,name')->where(['status' => 1, 'pid' => 0])->order('sort ASC')->select();
        $this->assign('type', $type);
        $this->assign('userId', $userId);
        $this->assign('url', $url);
        $this->assign('classList', $classList);
        $this->assign('info', $info);
        return $this->fetch();
    }

    /* 完成上传 */
    public function finishUpload()
    {
        $is_check = $this->config['resource_examine_on'];
        $this->assign('is_check', $is_check);
        return $this->fetch();
    }

    /* 用户上传作品 */
    public function userUpload(Request $request)
    {

        $data = input('param.')['info'];

        $video = new Shortvideo;
        $video->data([
            'title' => !empty($data['title']) ? $data['title'] : '',
            'info' => !empty($data['info']) ? $data['info'] : '',
            'thumbnail' => !empty($data['thumbnail']) ? $data['thumbnail'] : '',
            'url' => !empty($data['url']) ? $data['url'] : '',
            'down_url' => !empty($data['down_url']) ? $data['down_url'] : '',
            'gold' => !empty($data['gold']) ? $data['gold'] : 0,
            'add_time' => time(),
            'user_id' => !empty($data['userId']) ? $data['userId'] : 0,
            'status' => 1,
            'vid' => $data['vid'] ?: '',
            'is_check' => $this->config['resource_examine_on'] ? 0 : 1, //0是待处理 1已审核
            'class_id' => !empty($data['class']) ? $data['class'] : '',
        ]);

        $insert = $video->save();
        $userdb = Db::name('member');
        //判断

        if ($this->config['resource_examine_on'] == 0) {
            //不需要审核直接奖励
            $res = $this->__regReward($data['userId'], $userdb, $routeType = 3); //奖励
            if ($insert) die(json_encode(['Code' => 200, 'Msg' => '上传成功', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        }

        if ($insert) die(json_encode(['Code' => 200, 'Msg' => '上传成功,请等待管理员审核', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        die(json_encode(['Code' => 201, 'Msg' => '哎呀!出错了', 'Data' => ''], JSON_UNESCAPED_UNICODE));
    }

    private function __jumpUrl($gateway, $url)
    {
        if ($gateway == 'wechat') {
            $html = '
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta http-equiv="Content-Type" content="text/html"; charset="UTF-8">
            <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0;" name="viewport" />
            <meta http-equiv="refresh" content="1; url=' . $url . '">
            <title>支付中...</title>
            </head>
            <body>
                <div style="text-align:center;padding:80px 0 0 0;">
                    <img src="/static/images/loading.gif" style="height:50px;margin:auto"/>
                    <div style="margin-top:20px;font-size:14px;color:#666;">正在前往支付途中</div>
                </div>
            </body>
            </html>';
        } else {
            $html = '
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8" />
            <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0;" name="viewport" />
            
            <title>支付中...</title>
            </head>
            <body>
                <div style="text-align:center;padding:80px 0 0 0;">
                    <img src="/static/images/loading.gif" style="height:50px;margin:auto"/>
                    <div style="margin-top:20px;font-size:14px;color:#666;">正在前往支付途中</div>
                    ' . $url . '
                </div>
            </body>
            </html>';
        }

        echo $html;
    }

    public function synchronization()
    {
        $app_name = $this->config['site_title'];
        $domain = $this->config['web_server_url'];
        $returnapp = $this->config['return_app'];
        ///static/images/success-ok.png
        $html = '
            <!DOCTYPE html>
            <html lang="en">
            <head>
            <meta charset="UTF-8" />
            <meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=0" name="viewport" />
            <title>支付完成</title>
            </head>
            <body>
                <div style="text-align:center;padding:80px 0 0 0;">
                   
                    <img src="" style="height:50px;margin:auto"/>
                    <div style="margin-top:20px;font-size:14px;color:#666;">操作已完成<br/>此页数据并不能做付款依据</div>
                </div>
                
                <a href="javascript:openApp(' . "'{$returnapp}://')" . '" class="dl-btn" id="open" style="display: block;height: 38px;width:70px;line-height: 38px;padding: 0 18px;
                background-color: #ee29c7;color: #fff;white-space: nowrap;text-align: center;font-size: 14px;border: none;text-decoration: none;border-radius: 2px;cursor: pointer;margin: 15px auto;">返回app</a>
            </body>
            </html>
            <script type="text/javascript"> 
                function openApp(url) {
                    //Android
                    if(/(Android)/i.test(navigator.userAgent)){
                        var timeout, t = 1000, hasApp = true;
                        setTimeout(function () {
                            if (!hasApp) {
                                //没有安装微信
//                                var r=confirm("您没有安装 ' . $app_name . ',请先安装' . $app_name . '");
//                                if (r==true){
//                                    location.href="' . $domain . '/Appapi/down_app"
//                                }
                            }else{
                                //安装微信
                            }
                            document.body.removeChild(ifr);
                        }, 8000)
                
                        var t1 = Date.now();
                        var ifr = document.createElement("iframe");
                        ifr.setAttribute("src", url);
                        ifr.setAttribute("style", "display:none");
                        document.body.appendChild(ifr);
                        timeout = setTimeout(function () {
                            var t2 = Date.now();
                            if (!t1 || t2 - t1 < t + 100) {
                               hasApp = false;
                            }
                        }, t);
                    }else{
                        var iOSUrl = url;//填URL Schemes
                        var u = navigator.userAgent;
                        var isIOS = !!u.match(/\i[^;]+;(U;)? CPU.+Mac OS X/);
                    
                        //判断是否是iOS
                        if(isIOS){
                            window.location.href = iOSUrl;
                            //location.href = "javascript:history.back();" ;
                            var loadDataTime = Date.now();
                            setTimeOut(function(){
                                var timeOutDateTime = Date.now();
                                if(timeOutDateTime - loadDataTime < 1000){
                                    //window.location.href = "' . $domain . '/Appapi/down_app/pid/1";//填下载App落地页
                                }
                            }, 8000)
                        }
                    }
                }
        </script>  
            ';
        echo $html;
    }

    //*******************************直播*****************************///
    /* 直播列表 */
    public function getAnchorList()
    {
        // 类型，0关注的主播 1推荐2最新3人气
        $type = input('param.type', 2);
        // 页码
        $page = input('param.page', 1);
        // 用户ID , type为0时，必传参数
        $userId = input('param.userId', 0);

        if ($type == 0 && empty(get_member_info($userId))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
        // 登录用户是否为主播
        $isAnchor = Db::name('anchor')->where(['user_id' => $userId, 'status' => 1])->find();
        $data['isAnchor'] = empty($isAnchor) ? false : true;
        // 注意：如果type为0时则为我关注的主播列表，包含未开播的，值为1,2,3时只显示已开播的主播
        // 模拟数据
        $isVip = false;
        $member = Db::name('member')->where(['id' => $userId, 'status' => 1])->find();
        if ($member['is_permanent'] == 1 || $member['out_time'] > time()) $isVip = true;
        $where['a.status'] = 1;
        $where['a.live_status'] = 1;

        if ($type == 0) {
            if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
            $followAnthorId = Db::name('follow_anthor')->where(['user_id' => $userId, 'status' => 1])->column('anchor_id');
            $where['a.id'] = ['IN', $followAnthorId];
            $where['live_status'] = ['IN', "0,1"];
            $order = 'a.add_time desc';
        } elseif ($type == 1) {
            //正在开播的
            $order = 'a.sort desc';
        } elseif ($type == 2) {
            $order = 'a.update_time desc,a.id desc';
        } elseif ($type == 3) {
            //在线人数
            //             $popularity = Db::name('live_online')->field("count(id) as popularity,live_stream_address")->group('live_stream_address')->order('anchor_id')->select();
            //
            //             $popularity = array_column($popularity,'popularity','live_stream_address');

        } else {
            //$type ==2
            $order = 'a.update_time desc';
        }
        $anchorfield = "id,user_id,live_stream_address,live_status,add_time,status,income_ratio,room_img,room_gold,room_name,title,update_time,is_trysee,trysee_time";
        if ($type == 0) {
            $anchor = Db::view('anchor a', $anchorfield)
                ->view('live_log l', 'live_stream_address', 'a.live_stream_address = l.live_stream_address and a.id = l.anchor_id', 'LEFT')
                ->view('member m ', 'username,headimgurl,nickname', 'm.id = a.user_id', 'LEFT')
                ->where($where)
                ->order($order)
                ->paginate(8)
                ->toArray()['data'];
        } else {
            $anchor = Db::view('anchor a', $anchorfield)
                ->view('live_log l', 'live_stream_address', 'a.live_stream_address = l.live_stream_address and a.id = l.anchor_id', 'LEFT')
                ->view('member m ', 'username,headimgurl,nickname', 'm.id = a.user_id', 'LEFT')
                ->where($where)
                ->where('l.end_time = 0')
                ->order($order)
                ->paginate(8)
                ->toArray()['data'];
        }
        //        if($type == 3) {
        //            foreach ($anchor as $k=>$v){
        //                if(array_key_exists($v['live_stream_address'],$popularity)){
        //                    $anchor[$k]['popularity'] = $popularity[$v['live_stream_address']];
        //                }else{
        //                    $anchor[$k]['popularity'] = 0;
        //                }
        //            }
        //            $anchor = sortArrByManyField($anchor,'popularity',SORT_DESC);
        //        }

        $vipViewing = $this->config['vip_viewing'];
        $data['vipFree'] = empty($vipViewing) ? false : true; //VIP进收费房是否收费
        $data['isVip'] = $isVip; //是否为VIP
        $list = [];

        foreach ($anchor as $k => $v) {
            $anchor_id = $v['id'];
            $list[] = [
                'id' => $v['user_id'], //用户ID
                'cover' => $v['room_img'], //直播间封面
                'title' => $v['title'], //直播间标题
                'number' => $this->getOnlineUser($v['id']), //在线人数
                'anchor' => mb_substr($v['nickname'], 0, 6), //主播昵称
                'gold' => $v['room_gold'],  //是否收费，0为免费房
                'isBuy' => $this->isBuyGoldRoom($userId, $anchor_id, $v['live_stream_address']),      //用户是否已付费（购买）
                'disable' => $this->isBlackList($userId, $anchor_id, $v['live_stream_address']),      //是否被拉入黑名单（踢出后自动进入黑名单列表）
                'status' => $v['live_status'] ? true : false,       //直播间是否已开播 true开启
                'trySee' => (!empty($v['is_trysee']) && $v['room_gold'] > 0) ? true : false, //是否支持试看，gold为收费的情况下有效
                'tryTime' => !empty($v['trysee_time']) ? $v['trysee_time'] : 0,  //试看时间，单位分钟
                'isTry' => $this->triedTime($v['trysee_time'], $userId, $v['live_stream_address']),  //用户已试看时间 分 不需要秒
                'head' => $v['headimgurl']
            ];
        }
        $list = sortArrByManyField($list, 'number', SORT_DESC);
        $data['list'] = $list;

        $data['server'] = $this->config['message_server_address']; //消息服务器地址
        //$data = Db::name('anchor')->where(['status'=>1,'live_status'=>1])->select();
        die(json_encode(['Code' => 200, 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 查询已试看时间 */
    public function triedTime($tryTime, $userId, $live_stream_address)
    {
        //$tryTime  时间分钟
        $tryTime = (int)$tryTime * 60;

        $surplusTime = Db::name('trysee')->where(['user_id' => $userId, 'live_stream_address' => $live_stream_address])->field('trysee_time')->find();
        if ($surplusTime) {
            if ($surplusTime['trysee_time'] > 0) {
                //剩余时间 $surplusTime['trysee_time']
                $sub = (int)$tryTime - (int)$surplusTime;  //已经试看的时间 分钟
            } else {
                $sub = $tryTime / 60;
            }
        } else {
            $sub = 0; //已经试看0分钟
        }
        return (int)$sub;
    }


    /* 用户购买收费房间 */
    public function buyGoldRoom()
    {
        // 用户ID
        $userId = input('param.userId', 0);
        // 主播ID
        $anchor_uid = input('param.anchorId', 0);
        if ($userId == $anchor_uid) die(json_encode(['Code' => 201, 'Msg' => '主播账号不能进入该直播间噢~~', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        $anchorId = $this->getAidByUid($anchor_uid);
        $memberDb = Db::name('member')->where(['status' => 1, 'id' => $userId]);
        $member = $memberDb->find();
        $anchorInfo = Db::name('anchor')->where(['id' => $anchorId, 'status' => 1, 'live_status' => 1])->find();

        $userwhere = ['user_id' => $userId, 'anchor_id' => $anchorInfo['id'], 'live_stream_address' => $anchorInfo['live_stream_address']];
        $isbacklist = Db::name('blacklist')->where($userwhere)->where('type', 2)->find();
        if ($isbacklist) die(json_encode(['Code' => 201, 'Msg' => '你已被踢出直播间', 'Data' => ''], JSON_UNESCAPED_UNICODE));

        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        if (empty(get_member_info($userId))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
        if (empty($anchorId)) die(json_encode(['Code' => 201, 'Msg' => '参数错误'], JSON_UNESCAPED_UNICODE));
        if (empty($anchorInfo)) die(json_encode(['Code' => 201, 'Msg' => '主播开小差了~~'], JSON_UNESCAPED_UNICODE));
        $vipViewing = $this->config['vip_viewing']; //vip是否免费进收费直播间   0为免费  1为收费
        if ($vipViewing) {
            if ($member['money'] < $anchorInfo['room_gold']) die(json_encode(['Code' => 201, 'Msg' => '金币不足，请充值'], JSON_UNESCAPED_UNICODE));
            $buyLiveLog = Db::name('buy_live_log')->where(['user_id' => $userId, 'anchor_id' => $anchorInfo['id'], 'live_stream_address' => $anchorInfo['live_stream_address']])->find();
            if ($buyLiveLog) die(json_encode(['Code' => 201, 'Msg' => '已购买'], JSON_UNESCAPED_UNICODE));
            Db::startTrans();
            try {
                $goldDec = Db::name('member')->where(['status' => 1, 'id' => $userId])->setDec('money', $anchorInfo['room_gold']); //扣除会员金币
                $insertData['user_id'] = $userId;
                $insertData['anchor_id'] = $anchorId;
                $insertData['add_time'] = time();
                $insertData['gold'] = $anchorInfo['room_gold'];
                $insertData['live_stream_address'] = $anchorInfo['live_stream_address'];
                $insertRes = Db::name('buy_live_log')->insert($insertData);
                $liveLog = Db::name('live_log')->where(['anchor_id' => $anchorId, 'live_stream_address' => $anchorInfo['live_stream_address']])->order('id desc')->find();
                if ($liveLog) $updateLiveLog = Db::name('live_log')->where(['id' => $liveLog['id']])->setInc('buy_live', $anchorInfo['room_gold']);
                $goldAccountLog = Db::name('account_log')->data(['user_id' => $userId, 'point' => "-" . $anchorInfo['room_gold'], 'add_time' => time(), 'module' => 'live', 'explain' => '观看直播'])->insert();
                if (!empty($goldDec) && !empty($insertRes) && !empty($updateLiveLog) && !empty($goldAccountLog)) {
                    Db::commit();
                    die(json_encode(['Code' => 200, 'Msg' => '付费成功'], JSON_UNESCAPED_UNICODE));
                } else {
                    Db::rollback();
                    die(json_encode(['Code' => 201, 'Msg' => '付费失败'], JSON_UNESCAPED_UNICODE));
                }
                //先用一个字段累计保存主播的购买金币总记录，主播结束直播时，统一结算
            } catch (Exception $e) {
                Db::rollback();
                die(json_encode(['Code' => 201, 'Msg' => '付费失败' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
            }
        }

        die(json_encode(['Code' => 200, 'Msg' => '付费成功'], JSON_UNESCAPED_UNICODE));
    }

    /* 主播申请 */
    public function regAnchor()
    {
        // 用户ID
        $userId = input('param.userId', 0);
        // 主播昵称
        $nickname = input('param.nikcname', '');
        // 申请备注
        $regDesc = input('param.regDesc', '');
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录'], JSON_UNESCAPED_UNICODE));
        if (empty(get_member_info($userId))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
        //if (empty($nickname)) die(json_encode(['Code' => 201, 'Msg' => '缺少参数错误'], JSON_UNESCAPED_UNICODE));
        $ischeck = $this->config['anchor_check']; //1为审核  0为不审核
        $insertData['user_id'] = $userId;
        $insertData['nickname'] = $nickname;
        $insertData['reg_desc'] = $regDesc;
        $insertData['add_time'] = time();
        $is_exist = Db::name('anchor')->where(['user_id' => $userId])->find();
        if ($is_exist) {
            if ($is_exist['0']) die(json_encode(['Code' => 200, 'Msg' => '请等待审核,请勿重复申请'], JSON_UNESCAPED_UNICODE));
            if ($is_exist['status'] == 2) die(json_encode(['Code' => 200, 'Msg' => '你已经申请,请勿重复申请'], JSON_UNESCAPED_UNICODE));
            die(json_encode(['Code' => 200, 'Msg' => '你已经是主播,请勿重复申请'], JSON_UNESCAPED_UNICODE));
        }
        $msg = '提交成功，1至3个工作日审核完成';
        if (empty($ischeck)) {
            $insertData['status'] = 1; //不需要审核直接成为主播
            $msg = "恭喜你！成功申请为主播";
        }

        $res = Db::name('anchor')->insert($insertData);

        if (empty($ischeck)) die(json_encode(['Code' => 202, 'Msg' => $msg], JSON_UNESCAPED_UNICODE));

        if ($res) die(json_encode(['Code' => 200, 'Msg' => $msg], JSON_UNESCAPED_UNICODE));
        die(json_encode(['Code' => 201, 'Msg' => '申请失败，请稍后再试'], JSON_UNESCAPED_UNICODE));
    }

    /* 根据主播id判断是否为主播 */
    public function isAnchorByAid($anchor_id)
    {
        $isAnchor = Db::name('anchor')->where(['id' => $anchor_id, 'status' => 1])->find();
        return $isAnchor;
    }

    /* 关注主播 */
    public function focusOnTheAnchor()
    {
        //主播ID todo
        $anchor_uid = input('param.aid', 0);
        $anchor_id = $this->getAidByUid($anchor_uid);
        //用户ID
        $user_id = input('param.uid', 0);
        //1为取消关注，2为关注
        $type = input('param.type', 2);

        if (empty($user_id) || empty($anchor_id)) die(json_encode(['Code' => 201, 'Msg' => '参数错误!!'], JSON_UNESCAPED_UNICODE));
        if (empty(get_member_info($user_id))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
        $isAnchor = $this->isAnchorByAid($anchor_id);
        if (empty($isAnchor)) die(json_encode(['Code' => 201, 'Msg' => '主播消失了'], JSON_UNESCAPED_UNICODE));
        $fans = Db::name('follow_anthor')->where(['user_id' => $user_id, 'anchor_id' => $anchor_id])->find();
        if ($fans) {
            //取消关注
            $status = $fans['status'] ? 0 : 1;
            $res = Db::name('follow_anthor')->where('id', $fans['id'])->setField('status', $status);
        } else {
            $insert_date['user_id'] = $user_id;
            $insert_date['anchor_id'] = $anchor_id;
            $insert_date['status'] = 1;
            $insert_date['add_time'] = time();
            $res = Db::name('follow_anthor')->insertGetId($insert_date);
        }
        if (!$res) die(json_encode(['Code' => 201, 'Msg' => '操作失败，请检查网络'], JSON_UNESCAPED_UNICODE));
        return die(json_encode(['Code' => 200, 'Msg' => '操作成功'], JSON_UNESCAPED_UNICODE));
    }

    /* 获取打赏礼物 */
    public function getGift()
    {
        //gift
        $data = Db::name('gift')->where(['status' => 1])->select();
        die(json_encode(['Code' => 200, 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 获取在线用户 */
    public function getOnlineUser($aid)
    {
        if (empty($aid)) return 0;
        $anchor = Db::name('anchor')->field('id,user_id,live_stream_address')->where(['status' => 1, 'live_status' => 1, 'id' => $aid])->find();
        if (empty($anchor)) return 0;
        $online_num = Db::name('live_online')->where(['anchor_id' => $anchor['id'], 'live_stream_address' => $anchor['live_stream_address']])->count();
        return $online_num;
    }

    /* 直播间封面上传 */
    public function roomUploadImg(Request $request)
    {
        $this->uper = UploadUtil::instance();
        $web_server_url = $this->config['web_server_url'];
        $post = $request->post('');
        if (!$request->isPost()) die(json_encode(['Code' => 201, 'Msg' => '请选择需要上传的图片'], JSON_UNESCAPED_UNICODE));
        $arryFile = $request->file("imgs");
        $fileName = 'room_img_id_' . $post['uid'];
        $max = (int)$this->config['upload_maxVideo'];
        $size = 1024 * 1024 * $max;
        $allExt = ['size' => $size, 'ext' => 'jpg,png,jpeg,bmp,gif'];
        $allPath = ROOT_PATH . 'public' . DS . 'upload' . DS . $fileName . DS . date('Y-m-d');

        $pathImg = [];

        if ($arryFile) {

            foreach ($arryFile as $File) {
                //移动文件到框架应用更目录的public/upload/  ->validate(['size'=>15678,'ext'=>'jpg,png,gif'])
                $info = $File->validate($allExt)->move($allPath, md5(microtime(true)));
                if ($info) {
                    $pathImg = $web_server_url . "/upload/" . $fileName . "/" . date('Y-m-d') . '/' . $info->getFilename();
                } else {
                    //错误提示用户
                    die(json_encode(['Code' => 201, 'Msg' => $File->getError()], JSON_UNESCAPED_UNICODE));
                }
            }
        }

        $post['playerImg'] = $pathImg;
        $res = $this->saveRoomData($post);

        die(json_encode(['Code' => $res['Code'], 'Msg' => $res['Msg']], JSON_UNESCAPED_UNICODE));
    }

    /* 保存房间信息 */
    public function saveRoomData($param)
    {
        //直播间标题
        $updateData['title'] = $param['roomName'];
        //直播间金币数，0为免费房
        $updateData['room_gold'] = !empty($param['roomGold']) ? $param['roomGold'] : 0;
        //直播间封面
        $updateData['room_img'] = $param['playerImg'];
        //用户ID
        $uid = $param['uid'];

        $anchor = Db::name('anchor')->where(['status' => 1, 'user_id' => $uid])->find();
        if (empty($anchor)) return ['Code' => 201, 'Msg' => '主播不存在'];
        //试看时间
        $updateData['trysee_time'] = $param['tryTime'] > 0 ? $param['tryTime'] : 0; //分钟
        $updateData['is_trysee'] = 0; //固定
        $updateData['live_status'] = 1;

        if ($updateData['room_gold'] > 0 && $param['tryTime'] > 0) {
            // 单位分 0为不试看，大于0则判断是否大于后台设置的最低试看时间
            $configTryseeTime = (int)$this->config['trysee'];
            $updateData['is_trysee'] = 1;
            $updateData['trysee_time'] = ($param['tryTime'] > $configTryseeTime) ? $param['tryTime'] : $configTryseeTime;
        }
        //验证用户是否为主播
        // ['Code' => 201, 'Msg' => '错误提示信息']
        $res = Db::name('anchor')->where('id', $anchor['id'])->update($updateData);

        //添加直播记录  ms_live_log
        $liveLogData = Db::name('live_log')->where(['anchor_id' => $anchor['id'], 'live_stream_address' => $anchor['live_stream_address']])->find();
        if (empty($liveLogData)) {
            $liveLog['anchor_id'] = $anchor['id'];
            $liveLog['start_time'] = time();
            $liveLog['live_stream_address'] = $anchor['live_stream_address'];
            $liveLog['income_ratio'] = $anchor['income_ratio'];
            $insRes = Db::name('live_log')->insert($liveLog);
            if (empty($insRes)) throw new Exception('添加直播记录失败');
        }
        if ($res) return ['Code' => 200, 'Msg' => '保存成功'];
        return ['Code' => 201, 'Msg' => '保存失败!网络错误'];
    }

    /* 开始直播 */
    public function startLiveBroadcast()
    {
        //用户ID
        $uid = input('param.uid', 0);
        $anchor = Db::view('anchor a', 'id,income_ratio')
            ->view('member m', 'headimgurl,nickname', 'a.user_id=m.id')
            ->where(['a.status' => 1, 'a.user_id' => $uid])
            ->find();
        if (empty($anchor)) die(json_encode(['Code' => 201, 'Msg' => '您还不是主播'], JSON_UNESCAPED_UNICODE));
        //验证用户是否为主播
        $anchorKey = md5($uid . time()) . substr(get_random_str(15), 0, 5);   //随机数不重复
        $severUrl = $this->config['streaming_server_address']; //读取后台配置
        $live_stream_address = $severUrl . '/' . $anchorKey;

        Db::startTrans();
        try {
            //更新主播表直播流地址
            $upAnchor = [
                'live_stream_address' => $live_stream_address,
                'update_time' => time()
            ];
            $upRes = Db::name('anchor')->where(['id' => $anchor['id']])->update($upAnchor);
            if (empty($upRes)) throw new Exception('更新直播流地址失败');
            Db::commit();
        } catch (Exception $e) {
            Db::rollback();
            die(json_encode(['Code' => 201, 'Msg' => '开启失败!!' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
        $data = [
            'startUrl' => $live_stream_address, //推流地址(推流服务器 + 主播随机KEY) 推流地址需要保存至主播数据表里
            'nikcname' => $anchor['nickname'], //主播昵称
            'headimgurl' => $anchor['headimgurl'] //头像
        ];
        die(json_encode(['Code' => 200, 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 进入直播间 */
    public function enterTheStudio()
    {
        //用户ID
        $uid = input('param.uid', 0);
        //主播ID的用户id
        $aid = input('param.aid', 0);
        if ($uid == $aid) die(json_encode(['Code' => 201, 'Msg' => '不能进入该直播间噢~~', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        if (empty($uid)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        $anchorfield = "id,user_id,live_stream_address,live_status,add_time,status,income_ratio,room_img,room_gold,room_name,title,update_time,is_trysee,trysee_time";
        $anchorInfo = Db::view('anchor a', $anchorfield)->view('member m', 'headimgurl,nickname', 'a.user_id=m.id')->where(['a.status' => 1, 'a.user_id' => $aid])->find();
        $aid = $anchorInfo['id'];

        $userwhere = ['user_id' => $uid, 'anchor_id' => $anchorInfo['id'], 'live_stream_address' => $anchorInfo['live_stream_address']];
        $anchorwhere = ['anchor_id' => $anchorInfo['id'], 'live_stream_address' => $anchorInfo['live_stream_address']];

        $isbacklist = Db::name('blacklist')->where($userwhere)->where('type', 2)->find();
        if ($isbacklist) die(json_encode(['Code' => 201, 'Msg' => '你已被踢出直播间', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        if (empty($anchorInfo['live_status'])) die(json_encode(['Code' => 202, 'Msg' => $aid . '直播间暂未开启', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        //返回200正常 201普通错误，202直播间未开启
        //if(empty($id))die(json_encode(['Code' => 201, 'Msg' => '系统错误', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        //记录 live_online user_id  anchor_id add_time   返回插入记录ID

        $online = Db::name('live_online')->where($userwhere)->find();
        if (empty($online)) {
            $onlineData = [
                'user_id' => $uid,
                'anchor_id' => $anchorInfo['id'],
                'live_stream_address' => $anchorInfo['live_stream_address'],
                'add_time' => time()
            ];
            //插入在线记录
            Db::name('live_online')->insert($onlineData);
        } else {
            $updateData = ['add_time' => time()];
            Db::name('live_online')->where(['id' => $online['id']])->update($updateData);
        }
        //直播记录
        $liveLog = Db::name('live_log')->where($anchorwhere)->where(['end_time' => 0])->find();
        if (empty($liveLog)) die(json_encode(['Code' => 202, 'Msg' => '直播已经结束', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        //用户剩余时间
        $userTry = Db::name('trysee')->where(['user_id' => $uid, 'live_stream_address' => $anchorInfo['live_stream_address']])->field('trysee_time')->find();
        $userTry = empty($userTry) ? (int)($anchorInfo['trysee_time'] * 60) : $userTry['trysee_time'];
        $isBuy = $this->isBuyGoldRoom($uid, $anchorInfo['id'], $anchorInfo['live_stream_address']);

        if (empty($userTry) && $anchorInfo['room_gold'] > 0 && !$isBuy && !empty($anchorInfo['trysee_time'])) {
            die(json_encode(['Code' => 201, 'Msg' => '试看结束！请购买进入~~',], JSON_UNESCAPED_UNICODE));
        } elseif ($anchorInfo['room_gold'] > 0 && !$isBuy && empty($anchorInfo['trysee_time'])) {
            die(json_encode(['Code' => 201, 'Msg' => '请购买进入~~',], JSON_UNESCAPED_UNICODE));
        }

        $key = explode('/', $anchorInfo['live_stream_address']);
        //判断是否在黑名单

        if (strpos($anchorInfo['live_stream_address'], '.m3u8') !== false) {
            $h5_url = $this->config['h5_server_address'] . '/' . $key[count($key) - 1] . '.m3u8';
        } else {
            $h5_url = $anchorInfo['live_stream_address'];
        }
        $anchorInfo = [
            'id' => $anchorInfo['user_id'],
            'nikcname' => $anchorInfo['nickname'],
            'headimgurl' => $anchorInfo['headimgurl'],
            'playerTime' => time() - $liveLog['start_time'], //开播时长，秒
            'anchorCover' => $anchorInfo['room_img'],
            'playerUrl' => $anchorInfo['live_stream_address'], //拉流地址
            'h5_server' => $h5_url,
            'isTry' => (!empty($anchorInfo['is_trysee']) && $anchorInfo['room_gold'] > 0) ? true : false, ////是否支持试看,免费费除外
            'tryTime' => $isBuy ? 0 : (int)($anchorInfo['trysee_time'] * 60),  //试看时间，单位秒
            'userTry' => (int)$userTry, //用户剩余时间, 主播开启了试看功能，需要判断用户试看时间是否正常，否则提示用户已试看结束，试看时间为0秒 试看需要登录
            'gold' => (int)$anchorInfo['room_gold'] ?: 0, //大于0则为收费房
        ];

        //get_member_info
        //获取在线会员
        //$onlineMember = Db::name('live_online')->where($anchorwhere)->column('user_id');
        $gratuityRecord = Db::name("gratuity_record")->field("sum(price) giveGold,user_id")->where($anchorwhere)->group('user_id')->select();

        if ($gratuityRecord) {
            foreach ($gratuityRecord as $k => $v) {
                $member_info = get_member_info($v['user_id']);
                $a[] = [
                    'id' => $member_info['id'], //ID
                    'headImg' => $member_info['headimgurl'], //头像
                    'giveGold' => $v['giveGold'], //打赏金币数
                    'username' => $member_info['nickname'], //用户账号，显示两头两尾中间以***
                    'isVip' => $member_info['isVip'], //是否为VIP

                ];
            }
            $giftOrder = arraySequence($a, 'giveGold');

            foreach ($giftOrder as $k => $v) {
                $giftOrder[$k]['ranking'] = $k + 1;
            }
        } else {
            $giftOrder = [];
        }


        // 礼物每页8格
        $gift = Db::name('gift')->where(['status' => 1])->select();

        $page = ceil(count($gift) / 8);

        $k = 1;
        $j = 8;
        for ($oi = 0; $oi < $page; $oi++) {
            $b = [];
            for ($i = $k; $i <= $j; $i++) {
                $b[] = [
                    'id' => $gift[$i - 1]['id'], //id
                    'img' => $gift[$i - 1]['images'], //礼物图片 images
                    'name' => $gift[$i - 1]['name'], //名称 name
                    'gold' => $gift[$i - 1]['price'] //所需金币 price
                ];
            }
            $k += 8;
            $j += 8;
            $j = $j >= count($gift) ? count($gift) : $j;
            $c[] = $b;
        }
        $giftList = $c;

        $user = get_member_info($uid);
        $isForbiddenSpeech = Db::name('blacklist')->field('id')->where($userwhere)->where(['type' => 1, 'status' => 0])->find();

        $userInfo = [
            'id' => $user['id'], //ID *************************************
            'isVip' => $user['isVip'], //是否为VIP
            'username' => $user['nickname'], //账号信息
            'gold' => $user['money'], //账户金币
            'isDisable' => !empty($isForbiddenSpeech) ? true : false,  //是否被禁言,根据直播间KEY判断，创建一个禁言表（直播间KEY，用户ID，禁言时间），禁言后需要插入一条禁言信息。
            'headImg' => $user['headimgurl'], //头像 *************************************
        ];

        $isFocus = Db::name('follow_anthor')->where(['user_id' => $uid, 'anchor_id' => $aid, 'status' => 1])->find();

        //获取在线会员
        $onlineMember = Db::view('live_online l', 'live_stream_address,anchor_id,user_id,id lid')
            ->view('member m', 'id,headimgurl,username,nickname', 'l.user_id = m.id')
            ->where($anchorwhere)
            ->select();
        $blacklist = Db::name('blacklist')->where($anchorwhere)->where(['type' => 1, 'status' => 0])->column('user_id');

        $onlines = [];
        if (!empty($onlineMember)) {
            foreach ($onlineMember as $k => $v) {
                $member_info = get_member_info($v['id']);
                $onlines[] = [
                    'id' => $member_info['id'], //ID
                    'headImg' => $member_info['headimgurl'], //头像
                    'isDisable' => in_array($member_info['id'], $blacklist), //是否已被禁言
                    'username' => $member_info['nickname'], //用户昵称，显示两头两尾中间以***
                    'isVip' => $member_info['isVip'], //是否为VIP

                ];
            }
        }

        $data = [
            'onlineNum' => $onlines, //  在线用户数 为优化效率已弃用在此处统计默认为0即可
            'anchorInfo' => $anchorInfo, //主播信息
            'isFocus' => !empty($isFocus) ? true : false, //用户是否已关注主播
            'giftList' => $giftList, //礼物列表
            'giftOrder' => $giftOrder, //直播间排赏排序榜
            'userInfo' => $userInfo, //用户信息
            // ****************************
            'sysNotice' => htmlspecialchars_decode($this->config['entry_prompt']) //系统公告，不支持HTML代码
        ];

        die(json_encode(['Code' => 200, 'Msg' => '查询成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 结束直播 异常退出就直接结算 */
    public function endLiveBroadcast()
    {
        //用户ID 验证用户是否为主播
        $uid = input('param.uid', 0);
        $member = get_member_info($uid);
        if (empty($member)) die(json_encode(['Code' => 201, 'Msg' => '系统错误,请联系管理员'], JSON_UNESCAPED_UNICODE));
        //结算后，写入主播直播记录表里，相关金币与余额写入，也需写入对应的表里 做到有据可查
        $anchor = Db::name('anchor')->where(['status' => 1, 'user_id' => $uid])->find();
        if (empty($member)) die(json_encode(['Code' => 201, 'Msg' => '您还不是主播'], JSON_UNESCAPED_UNICODE));
        //if(empty($anchor['live_status']) && empty($anchor['live_stream_address'])) die(json_encode(['Code' => 201, 'Msg' => '已结束直播,如还没结算成功,请联系管理员'], JSON_UNESCAPED_UNICODE));
        $info = json_encode($anchor);
        Db::startTrans();
        try {
            //anchor主播表 直播状态修改
            $where = ['id' => $anchor['id'], 'live_stream_address' => $anchor['live_stream_address'], 'live_status' => 1, 'status' => 1];
            $upLiveStatus = Db::name('anchor')->where($where)->update(['live_status' => 0, 'live_stream_address' => '']);
            //$upLiveStatus = 1;
            //直播记录表  income_ratio  settlement结算(统计)  = buy_live+total_reward    end_time
            $liveWhere = ['anchor_id' => $anchor['id'], 'live_stream_address' => $anchor['live_stream_address']];
            $liveLogive = Db::name('live_log')->where($liveWhere)->find();

            if (empty($liveLogive)) throw new Exception('直播结束');
            $settlement = math_add($liveLogive['buy_live'], $liveLogive['total_reward'], 0); //总收入金币 没有乘比列
            $point = math_mul(math_div($settlement, $this->config['gold_exchange_rate']), $anchor['income_ratio']);

            $upliveLog = [
                'anchor_live_info' => $info,
                'income_ratio' => $anchor['income_ratio'],
                'settlement' => $point,
                'end_time' => time()
            ];
            $upLiveLogRes = Db::name('live_log')->where($liveWhere)->update($upliveLog);

            //余额记录 account_log
            // 金币 余额 gold_exchange_rate 1余额= $this->config['gold_exchange_rate']

            //给主播添加收入余额
            if ($point > 0) {
                $accountData = [
                    'user_id' => $uid,
                    'is_gold' => 2, //1金币  2余额
                    'type' => 4, //0其它 1为分成，2为提现 3管理员操作（金币无此属性）4直播收入
                    'point' => $point,
                    'add_time' => time(),
                    'module' => 'live',
                    'explain' => '直播收入',
                    'income_ratio' => $anchor['income_ratio']
                ];
                $goldAccountLog = Db::name('account_log')->insert($accountData);
                $row = Db::name('member')->where(['id' => $uid])->setInc('k_money', $point);
            } else {
                $row = 1;
                $goldAccountLog = 1;
            }

            //奖赏礼物status 1   gratuity_record
            $cwhere = ['live_stream_address' => $anchor['live_stream_address'], 'anchor_id' => $anchor['id']];
            Db::name('gratuity_record')->where($cwhere)->update(['status' => 1]);
            //删除在线记录
            $delOnlineLog = Db::name('live_online')->where($cwhere)->delete();
            //删除黑名单数据
            $blacklist = Db::name('blacklist')->where($cwhere)->delete();
            if ($row && $goldAccountLog && $upLiveLogRes && $upLiveStatus) {
                Db::commit();
            } else {
                throw new Exception('结算出错~请联系管理员');
            }
        } catch (Exception $e) {
            Db::rollback();
            die(json_encode(['Code' => 201, 'Msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
        $goldRatio = math_mul($anchor['income_ratio'], 100, 0);
        //返回数据
        $data = [
            'liveTime' => secToTime(math_sub($upliveLog['end_time'], $liveLogive['start_time'])), //直播时长
            'roomGold' => $liveLogive['buy_live'] . '金币', //房间收费金币总数
            'giftGold' => $liveLogive['total_reward'] . '金币', //礼物道具金币总数
            'goldRatio' => $goldRatio . '%(金币)', //金币收益比例，比如，房间费+礼物费共100金币，比例为10%，则主播可得10金币
            'exchange' => $this->config['gold_exchange_rate'] . '金币可兑换1余额', //金币兑换余额比例
            'jsMoney' => $point . '余额', //实际结算余额数
            'descText' => '如有疑问请联系平台客服', //结算提示语
        ];
        die(json_encode(['Code' => 200, 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 退出直播间 */
    public function exitTheStudio()
    {
        //用户ID
        $uid = (int)input('param.uid', 0);
        //主播ID
        $anchor_uid = (int)input('param.aid', 0);

        $anchor = Db::name('anchor')->where(['user_id' => $anchor_uid])->find();

        $anchorwhere = ['anchor_id' => $anchor['id'], 'live_stream_address' => $anchor['live_stream_address']];

        //type  workman 1
        $type = input('param.type', 0);
        file_put_contents(ROOT_PATH . 'exit_live.txt', "退出直播间\r\n", FILE_APPEND);
        file_put_contents(ROOT_PATH . 'exit_live.txt', "uid-{$uid}--anchor_uid--{$anchor_uid}--type---{$type}" . "\r\n", FILE_APPEND);
        $onlineData = Db::name('live_online')->where(['user_id' => $uid, 'anchor_id' => $anchor['id'], 'live_stream_address' => $anchor['live_stream_address']])->find();
        if ($type) {
            Db::name('live_online')->where(['user_id' => $uid, 'anchor_id' => $anchor['id']])->delete();
            file_put_contents(ROOT_PATH . 'exit_live.txt', "退出成功1\r\n", FILE_APPEND);
        } else {
            //记录 live_online  删除记录
            Db::name('live_online')->where(['user_id' => $uid, 'anchor_id' => $anchor['id']])->delete();
            file_put_contents(ROOT_PATH . 'exit_live.txt', "退出成功2\r\n", FILE_APPEND);
        }
        //获取在线会员
        $onlineMember = Db::view('live_online l', 'live_stream_address,anchor_id,user_id,id lid')
            ->view('member m', 'id,headimgurl,username,nickname', 'l.user_id = m.id')
            ->where($anchorwhere)
            ->select();
        $blacklist = Db::name('blacklist')->where($anchorwhere)->where(['type' => 1, 'status' => 0])->column('user_id');

        $onlines = [];
        if (!empty($onlineMember)) {
            foreach ($onlineMember as $k => $v) {
                $member_info = get_member_info($v['id']);
                $onlines[] = [
                    'id' => $member_info['id'], //ID
                    'headImg' => $member_info['headimgurl'], //头像
                    'isDisable' => in_array($member_info['id'], $blacklist), //是否已被禁言
                    'username' => $member_info['nickname'], //用户账号，显示两头两尾中间以***
                    'isVip' => $member_info['isVip'], //是否为VIP
                ];
            }
        }
        die(json_encode(['Code' => 200, 'Msg' => '退出成功', 'Data' => $onlines], JSON_UNESCAPED_UNICODE));
    }

    /* 打赏主播 */
    public function rewardTheAnchor()
    {
        //主播的用户id
        $anchor_uid = input('param.aid', 0);
        $anchor_id = $this->getAidByUid($anchor_uid);
        //用户ID
        $user_id = input('param.uid', 0);
        //礼物道具ID
        $gift_id = input('param.gid', 0);
        $user = Db::name('member')->where(['id' => $user_id, 'status' => 1])->find();
        $anchorfield = "id,user_id,live_stream_address,live_status,add_time,status,income_ratio,room_img,room_gold,room_name,title,update_time,is_trysee,trysee_time";
        $anchor = Db::view('anchor a')->view('member m', 'nickname', 'a.user_id = m.id')->where(['a.id' => $anchor_id])->find();

        $gift = Db::name('gift')->where('status', 1)->find($gift_id);
        if (empty($user_id) || empty($user)) die(json_encode(['Code' => 201, 'Msg' => '请先登录'], JSON_UNESCAPED_UNICODE));
        if (empty($anchor)) die(json_encode(['Code' => 201, 'Msg' => '主播消失了~~~'], JSON_UNESCAPED_UNICODE));
        if (empty($gift)) die(json_encode(['Code' => 201, 'Msg' => '系统繁忙,请退出直播间重试~~~'], JSON_UNESCAPED_UNICODE));
        if ($user['money'] < $gift['price']) die(json_encode(['Code' => 201, 'Msg' => '账户金币不足，请充值'], JSON_UNESCAPED_UNICODE));
        //其它判断验证等等
        //die(json_encode(['Code' => 201, 'Msg' => '请先登录'], JSON_UNESCAPED_UNICODE));
        //die(json_encode(['Code' => 201, 'Msg' => '账户金币不足，请充值'], JSON_UNESCAPED_UNICODE));
        //打赏信息需要写入记录表，并累加至主播收益字段（与房费不同字段），结束直播时统一结算
        Db::startTrans();
        try {
            //gratuity_record
            $gratuityData['user_id'] = $user_id;
            $gratuityData['gratuity_time'] = time();
            $gratuityData['gift_info'] = json_encode($gift); //包含礼物名称，礼物费用
            $gratuityData['price'] = (int)$gift['price'];
            $gratuityData['status'] = 0;
            $gratuityData['gift_name'] = $gift['name'];
            $gratuityData['anchor_id'] = $anchor_id;
            $gratuityData['live_stream_address'] = $anchor['live_stream_address'];
            $gratuity_record = Db::name('gratuity_record')->insert($gratuityData);
            $goldDec = Db::name('member')->where(['status' => 1, 'id' => $user_id])->setDec('money', (int)$gift['price']); //扣除会员金币
            $goldAccountLog = Db::name('account_log')->data(['user_id' => $user_id, 'point' => "-" . (int)$gift['price'], 'add_time' => time(), 'module' => 'live', 'explain' => '打赏主播' . $anchor['nickname'] . '礼物' . $gift['name']])->insert();
            $addToAnchorRes = Db::name('live_log')->where(['anchor_id' => $anchor_id, 'live_stream_address' => $anchor['live_stream_address']])->setInc('total_reward', (int)$gift['price']);

            //打赏排序行，从多至少 ****************************
            $anchorwhere = ['anchor_id' => $anchor_id, 'live_stream_address' => $anchor['live_stream_address']];
            $gratuityRecord = Db::name("gratuity_record")->field("sum(price) giveGold,user_id")->where($anchorwhere)->group('user_id')->select();
            if ($gratuityRecord) {
                foreach ($gratuityRecord as $k => $v) {
                    $member_info = get_member_info($v['user_id']);
                    $a[] = [
                        'id' => $member_info['id'], //ID
                        'headImg' => $member_info['headimgurl'], //头像
                        'giveGold' => $v['giveGold'], //打赏金币数
                        'username' => $member_info['nickname'], //用户昵称，显示两头两尾中间以***
                        'isVip' => $member_info['isVip'] //是否为VIP
                    ];
                }
                $giftOrder = arraySequence($a, 'giveGold');
                foreach ($giftOrder as $k => $v) {
                    $giftOrder[$k]['ranking'] = $k + 1;
                }
            } else {
                $giftOrder = [];
            }

            $data['giveOrderList'] = $giftOrder;

            if (!empty($goldDec) && !empty($goldAccountLog) && !empty($gratuity_record) && !empty($addToAnchorRes)) {
                Db::commit();
                die(json_encode(['Code' => 200, 'Msg' => '打赏成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
            } else {
                Db::rollback();
                die(json_encode(['Code' => 201, 'Msg' => '网络错误'], JSON_UNESCAPED_UNICODE));
            }
            //先用一个字段累计保存主播的购买金币总记录，主播结束直播时，统一结算
        } catch (Exception $e) {
            Db::rollback();
            die(json_encode(['Code' => 201, 'Msg' => '失败' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }

    /* 主播禁言与恢复 */
    public function updateSendMs(Request $request)
    {
        //主播ID
        $anchor_uid = input('param.aid', 0);
        $aid = $this->getAidByUid($anchor_uid);
        //用户ID
        $uid = input('param.uid', 0);
        $user = Db::name('member')->where(['id' => $uid, 'status' => 1])->find();
        if (empty($uid) || empty($user)) die(json_encode(['Code' => 201, 'Msg' => '请先登录'], JSON_UNESCAPED_UNICODE));
        if (empty($aid)) die(json_encode(['Code' => 201, 'Msg' => '网络错误~~~'], JSON_UNESCAPED_UNICODE));
        //值，1禁言 2恢复
        $type = input('param.type', 1);
        //创建一个新表，用来保存禁言信息
        $anchor = Db::name('anchor')->where(['status' => 1, 'id' => $aid, 'live_status' => 1])->find();

        if (empty($anchor)) die(json_encode(['Code' => 201, 'Msg' => '网络错误~~~'], JSON_UNESCAPED_UNICODE));
        $blacklist = Db::name('blacklist')->where(['type' => 1, 'anchor_id' => $aid, 'live_stream_address' => $anchor['live_stream_address']])->find(); //type 1禁言 2踢出房间
        if (empty($blacklist)) {
            $insData = [
                'user_id' => $uid,
                'anchor_id' => $aid,
                'live_stream_address' => $anchor['live_stream_address'],
                'add_time' => time(),
                'type' => 1,
                'status' => 0 // 0禁言 1回复
            ];
            $insRes = Db::name('blacklist')->insert($insData);
        } else {
            !empty($blacklist['status']) ? $upData = ['status' => 0] : $upData = ['status' => 1]; // 0禁言 1恢复
            $insRes = Db::name('blacklist')->where(['type' => 1, 'anchor_id' => $aid, 'live_stream_address' => $anchor['live_stream_address']])->update($upData);
        }
        if (!empty($insRes) || !empty($insRes)) die(json_encode(['Code' => 200, 'Msg' => '操作成功'], JSON_UNESCAPED_UNICODE));
        die(json_encode(['Code' => 201, 'Msg' => '操作失败'], JSON_UNESCAPED_UNICODE));
    }

    /* 主播踢人 */
    public function anchorOutUser(Request $request)
    {
        //主播ID
        $anchor_uid = input('param.aid', 0);
        $aid = $this->getAidByUid($anchor_uid);

        //用户ID
        $uid = input('param.uid', 0);
        $user = Db::name('member')->where(['id' => $uid, 'status' => 1])->find();
        if (empty($uid) || empty($user)) die(json_encode(['Code' => 201, 'Msg' => '请先登录'], JSON_UNESCAPED_UNICODE));
        if (empty($aid)) die(json_encode(['Code' => 201, 'Msg' => '网络错误~~~'], JSON_UNESCAPED_UNICODE));
        //创建一个新表，用来保存禁言信息
        $anchor = Db::name('anchor')->where(['status' => 1, 'id' => $aid, 'live_status' => 1])->find();

        if (empty($anchor)) die(json_encode(['Code' => 201, 'Msg' => '网络错误~~~'], JSON_UNESCAPED_UNICODE));
        $blacklist = Db::name('blacklist')->where(['type' => 2, 'anchor_id' => $aid, 'live_stream_address' => $anchor['live_stream_address']])->find(); //type 1禁言 2踢出房间
        if (empty($blacklist)) {
            $insData = [
                'user_id' => $uid,
                'anchor_id' => $aid,
                'live_stream_address' => $anchor['live_stream_address'],
                'add_time' => time(),
                'type' => 2,
                'status' => 0 // 0踢人
            ];
            $insRes = Db::name('blacklist')->insert($insData);
            //删除在线记录
            $delOnline = Db::name('live_online')->where(['user_id' => $uid, 'anchor_id' => $aid])->delete();
        } else {
            die(json_encode(['Code' => 201, 'Msg' => '已被踢出直播间'], JSON_UNESCAPED_UNICODE));
        }
        if (!empty($insRes)) die(json_encode(['Code' => 200, 'Msg' => '操作成功'], JSON_UNESCAPED_UNICODE));
        die(json_encode(['Code' => 201, 'Msg' => '操作失败'], JSON_UNESCAPED_UNICODE));
    }

    /* 更新用户剩余试看时间 */
    public function updateTryTime(Request $request)
    {
        $data['user_id'] = $request->param('uid/d', 0); //用户ID
        $anchor_uid = $request->param('aid/d', 0); //主播ID
        $data['anchor_id'] = $this->getAidByUid($anchor_uid);
        $anchor = Db::name('anchor')->where(['status' => 1, 'live_status' => 1, 'id' => $data['anchor_id']])->find();
        $tryseeWhere = ['live_stream_address' => $anchor['live_stream_address']];
        $trysee = Db::name('trysee')->where($data)->where($tryseeWhere)->find();
        if (empty($trysee)) {
            $data['trysee_time'] = $request->param('time/d', 0); //剩余试看时间 单位秒
            $data['live_stream_address'] = $anchor['live_stream_address'];
            $res = Db::name('trysee')->insert($data);
        } else {
            $updata['trysee_time'] = $request->param('time/d', 0); //剩余试看时间 单位秒
            $res = Db::name('trysee')->where($data)->where($tryseeWhere)->update($updata);
        }
    }

    /* 专题列表 */
    public function getAlbumList(Request $request)
    {
        $page = $request->param('page/d', 1); //页码
        $order = $request->param('order/d', 1); //排序 1推荐2最新  默认为2
        switch ($order) {
            case '1':
                $order = 'reco DESC';
                break;
            default:
                $order = 'add_time DESC';
                break;
        }
        $data['list'] = Db::name('actor_list')
            ->where(['status' => 1])
            ->page($page, 6)
            ->order($order)
            ->select();
        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 查询用户是否为主播 */
    public function getUserIsAnchor()
    {
        // 用户ID
        $userId = input('param.userId', 0);
        if (empty($userId)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        if (empty(get_member_info($userId))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
        $anchorInfo = Db::name('anchor')->where(['user_id' => $userId])->find();
        if (empty($anchorInfo)) {
            //没有申请
            $data = [
                'isCheck' => false, //查询是否用户已提交申请，审核中
                'text' => '正在审核中，请稍后...'
            ];
        } else {
            //已申请 没审核
            $isCheck = empty($anchorInfo) ? false : true;
            $text = '';
            if ($anchorInfo['status'] == 1) {
                $text = '您已经是主播！！';
            } elseif ($anchorInfo['status'] == 2) {
                $text = '拒绝： 您不符合要求，申请已被拒绝,拒绝原因:' . $anchorInfo['msg'];
            } else {
                $anchor_check = $this->config['anchor_check'];
                if ($anchor_check) {
                    $text = '正在审核中，请稍后...';
                } else {
                    die(json_encode(['Code' => 202, 'Msg' => '', 'Data' => ''], JSON_UNESCAPED_UNICODE));
                }
            }
            $data = [
                'isCheck' => $isCheck, //查询是否用户已提交申请，审核中
                'text' => $text
            ];
        }
        // 开启审核： 正在审核中，请稍后...   拒绝： 您不符合要求，申请已被拒绝
        die(json_encode(['Code' => 200, 'Msg' => '查询成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 根据用户ID查询是否为主播 */
    public function isAnchor($userId)
    {
        // 用户ID
        $res = false;
        if (empty(get_member_info($userId))) return $res;
        $anchorInfo = Db::name('anchor')->where(['user_id' => $userId, 'status' => 1])->find();
        if (!empty($anchorInfo)) {
            return true;
        }
        return $res;
    }

    /* 是否购买 */
    public function isBuyGoldRoom($userId, $anchor_id, $live_stream_address)
    {

        $buyLiveLog = Db::name('buy_live_log')->where(['user_id' => $userId, 'anchor_id' => $anchor_id, 'live_stream_address' => $live_stream_address])->find();

        return $buyLiveLog ? true : false;
    }

    /* 是否黑名单 */
    public function isBlackList($userId, $anchor_id, $live_stream_address)
    {
        $buyLiveLog = Db::name('blacklist')->where(['user_id' => $userId, 'anchor_id' => $anchor_id, 'live_stream_address' => $live_stream_address, 'type' => 2])->find();
        return $buyLiveLog ? true : false;
    }

    /* 根据用户id 查询主播id */
    public function getAidByUid($uid)
    {
        $anchorInfo = Db::view('anchor a', '*')->view('member m', 'headimgurl', 'a.user_id=m.id')->where(['a.status' => 1, 'a.user_id' => $uid])->find();
        return !empty($anchorInfo) ? $anchorInfo['id'] : 0;
    }

    /* 图册 */
    public function getPicture(Request $request)
    {
        $uid = $request->param('uid/d', 0); //用户ID   当order 为1时需要验证用户登录
        $page = $request->param('page/d', 1); //页码
        $order = $request->param('order/d', 2); //排序 1我的2最新3热门(以观看量排序)  默认为2

        if ($order == 1) {
            if (empty($uid)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录', 'Data' => ''], JSON_UNESCAPED_UNICODE));
            if (empty(get_member_info($uid))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
            //$ids = Db::name('purchase_atlas')->where('user_id',$uid)->column('atlas_id');
            //我的  自己上传的后面需求
            $sort = 'add_time desc,id desc';
        } elseif ($order == 3) {
            $sort = 'click desc,id desc';
        } else {
            $sort = 'add_time desc,id desc';
        }

        $atlas = Db::name('atlas')->where(['status' => 1])->order($sort)->page("$page,10")->select();
        $list = [];
        foreach ($atlas as $k => $v) {
            $list[] = [
                'id' => $v['id'],
                'title' => $v['title'],
                'cover' => $v['cover'], //封面
                'gold' => $v['gold'], //金币， 0免费
                'isBuy' => empty($v['gold']) ? false : $this->isBuyAtlas($uid, $v['id']), //用户是否购买
            ];
        }
        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $list], JSON_UNESCAPED_UNICODE));
    }

    /* 查询 用户是否购买 */
    public function isBuyAtlas($uid, $aid)
    {
        if (empty($uid) || empty($aid)) return false;
        $res = Db::name('purchase_atlas')->where(['user_id' => $uid, 'atlas_id' => $aid])->find();
        if (empty($res)) return false;
        return true;
    }

    /* 图片详情 */
    public function pictureInfo(Request $request)
    {
        $uid = $request->param('uid/d', 0); //用户ID
        $zid = $request->param('zid/d', 0); //图片ID
        // 如果是收费内容，则需要判断用户ID是否已购买（需要登录），否则不需要登录 只需要验证图片ID是否正常
        $atlas = Db::name('atlas')->where(['id' => $zid])->find();

        if (empty($atlas)) die(json_encode(['Code' => 201, 'Msg' => '图册不存在', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        //if(empty($uid)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录', 'Data' => ''], JSON_UNESCAPED_UNICODE));
        Db::name('atlas')->where(['id' => $zid])->setInc('click');
        $list = [];
        $images = Db::name('image')->where(['atlas_id' => $atlas['id']])->select();
        foreach ($images as $k => $v) {
            $list[] = $v['url'];
        }
        $data['info'] = [
            'id' => $atlas['id'],
            'title' => $atlas['title'],
            'cover' => $atlas['cover'], //封面
            'date' => date('Y-m-d', $atlas['add_time']), //创建日期
            'gold' => $atlas['gold'], //金币， 0免费
            'isBuy' => empty($v['gold']) ? false : $this->isBuyAtlas($uid, $atlas['id']), //用户是否购买
            'watch' => $atlas['click'], //观看量
            'list' => $list, //图片列表
        ];

        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 购买图片信息 */
    public function buyPicture(Request $request)
    {
        $uid = $request->param('userId/d', 0); //用户ID   当order 为1时需要验证用户登录
        $zid = $request->param('zid/d', 0); //图片ID

        if (empty($uid)) die(json_encode(['Code' => 201, 'Msg' => '登录超时或未登录' . $uid, 'Data' => ''], JSON_UNESCAPED_UNICODE));
        if (empty(get_member_info($uid))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
        $atlas = Db::name('atlas')->where(['id' => $zid])->find();
        $isBuy = $this->isBuyAtlas($uid, $atlas['id']);
        if ($isBuy) die(json_encode(['Code' => 200, 'Msg' => '您已经购买！无需重新购买！'], JSON_UNESCAPED_UNICODE));

        Db::startTrans();
        try {
            $member = Db::name('member')->where(['status' => 1, 'id' => $uid])->find();
            if ($member['money'] < $atlas['gold']) die(json_encode(['Code' => 201, 'Msg' => '购买失败,金币不足'], JSON_UNESCAPED_UNICODE));
            $goldDec = Db::name('member')->where(['status' => 1, 'id' => $uid])->setDec('money', $atlas['gold']); //扣除会员金币
            $insert['user_id'] = $uid;
            $insert['atlas_id'] = $atlas['id'];
            $insert['gold'] = $atlas['gold'];
            $insert['add_time'] = time();
            $insertRes = Db::name('purchase_atlas')->insert($insert);

            $goldAccountLog = Db::name('account_log')->data(['user_id' => $uid, 'point' => "-" . $atlas['gold'], 'add_time' => time(), 'module' => 'atlas', 'explain' => '购买图册'])->insert();
            //拥有者添加金币 后续
            if (!empty($goldDec) && !empty($insertRes) && !empty($goldAccountLog)) {
                Db::commit();
                die(json_encode(['Code' => 200, 'Msg' => '购买成功'], JSON_UNESCAPED_UNICODE));
            } else {
                Db::rollback();
                die(json_encode(['Code' => 201, 'Msg' => '购买失败'], JSON_UNESCAPED_UNICODE));
            }
        } catch (Exception $e) {
            Db::rollback();
            die(json_encode(['Code' => 201, 'Msg' => '购买成失败' . $e->getMessage()], JSON_UNESCAPED_UNICODE));
        }
    }

    /* APP内 H5 Url */
    public function openH5Url(Request $request)
    {
        $uid = $request->param('uid/d', 0); //用户ID   当order 为1时需要验证用户登录
        $order = $request->param('order/d', 2); //排序 1我的2最新3热门  默认为2
        print_r($request->param());
    }

    /* 获取顶部导航 */
    public function getTopMenu()
    {
        // 如果后台未开启这个模块，则不显示 ,参数为固定值，后台可选择开启和关闭这些模块，但至少需要保留首页模块
        $data = Db::name('menu')->field('name,url,type,icon')->where('status', 1)->order('sort desc')->select();
        if (!$data) $data = [];
        return $data;
    }

    /* 我的团队 */
    public function getUserTeam(Request $request)
    {
        $uid = $request->param('uid/d', 0); // 用户ID
        $level = $request->param('level/d', 1); // 用户的下级层级
        $page = $request->param('page/d', 1); // 页码  查询下级会员数量以按页查询  每页显示20条
        $subordinateIds1 = Db::name('member')->where(['pid' => ['>', 0]])->where(['status' => 1, 'pid' => $uid])->column('id');
        $subordinateIds2 = Db::name('member')->where(['pid' => ['>', 0]])->where(['status' => 1, 'pid' => ['IN', $subordinateIds1]])->column('id');
        $subordinateIds3 = Db::name('member')->where(['pid' => ['>', 0]])->where(['status' => 1, 'pid' => ['IN', $subordinateIds2]])->column('id');
        // 下级用户数据
        if ($level == 1) {
            $subordinateIds = $subordinateIds1;
        } elseif ($level == 2) {
            $subordinateIds = $subordinateIds2;
        } elseif ($level == 3) {
            $subordinateIds = $subordinateIds3;
        } else {
            $subordinateIds = $subordinateIds1;
        }
        $data['level'] = $this->getTeamData($subordinateIds, $level, $page);
        // 当 page 值为1时，则加载下面数据
        if ($page == 1) {
            $user = Db::name('member')->field('username,nickname,headimgurl,k_money')->where(['status' => 1, 'id' => $uid])->find();
            $install = Db::name('share_log')->where(['pid' => $uid])->count();
            $subordinateAllIds = array_merge($subordinateIds1, $subordinateIds2, $subordinateIds3);
            $all = Db::name('account_log')->where(['user_id' => ['IN', $subordinateAllIds], 'type' => 2, 'is_gold' => 2])->sum('point');
            $today = Db::name('account_log')->where(['user_id' => ['IN', $subordinateAllIds], 'type' => 2, 'is_gold' => 2])->whereTime('add_time', 'today')->sum('point');
            $data['team'] = [
                'user' => [ //用户信息
                    'username' => $user['nickname'],
                    'headimgurl' => $user['headimgurl'], //头像
                    'k_money' => $user['k_money'], //账户余额
                ],
                'today' => $today, //今日 所有下级收益（分成）
                'all' => $all, //累计 所有下级收益（分成）
                'install' => $install, //一级（直推）安装数量  share_log
            ];
        }
        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 获取团队层级对应的下级会员 */
    private function getTeamData($subordinateIds, $level = 1, $page = 1)
    {
        $list = [];
        // 模拟数据
        $user = Db::name('member')->field("id,headimgurl,username,add_time")->where(['status' => 1, 'id' => ['IN', $subordinateIds]])->paginate(20)->toArray();
        foreach ($user['data'] as $k => $v) {
            $list[] = [
                'id' => $v['id'],
                'headimgurl' => $v['headimgurl'], //头像
                'username' => $v['nickname'] ?: $v['username'], //昵称
                'add_time' => $v['add_time'], //注册时间
            ];
        }
        $count = Db::name('member')->where(['status' => 1, 'id' => ['IN', $subordinateIds]])->count();
        $all = Db::name('account_log')->where(['user_id' => ['IN', $subordinateIds], 'type' => 2, 'is_gold' => 2])->sum('point');
        $today = Db::name('account_log')->where(['user_id' => ['IN', $subordinateIds], 'type' => 2, 'is_gold' => 2])->whereTime('add_time', 'today')->sum('point');
        $data = [  // 层级对应的数据
            'count' => $count, //注册人数（已注册的用户）
            'today' => $today, //今日收益
            'all' => $all, //累计收益
            'list' => $list //用户数据
        ];
        return $data;
    }

    /* 直播记录 */
    public function getPlayerLog(Request $request)
    {
        $uid = $request->param('uid/d', 0); // 用户ID 
        $page = $request->param('page/d', 1); // 页码
        if (empty(get_member_info($uid))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
        // 登录用户是否为主播
        $Anchor = Db::name('anchor')->where(['user_id' => $uid, 'status' => 1])->find();
        if (empty($Anchor)) die(json_encode(['Code' => 201, 'Msg' => '您还没有直播记录~~'], JSON_UNESCAPED_UNICODE));
        $live_log = Db::name('live_log')->where(['anchor_id' => $Anchor['id'], 'end_time' => ['gt', 0]])->order('end_time desc')->paginate(20)->each(function ($item, $key) {
            $item['anchor_live_info'] = json_decode($item['anchor_live_info'], true);
            return $item;
        })
            ->toArray();
        $data = [];
        foreach ($live_log['data'] as $k => $v) {
            $data[] = [
                'total' => $v['settlement'], // 收益总数，余额
                'gold' => $v['anchor_live_info']['room_gold'], //0免费房，大于0则收费房
                'playerTime' => $v['start_time'] - $v['end_time'], //直播时长
                'startTime' => $v['start_time'], //开播时间
                'title' => $v['anchor_live_info']['title'],  //直播标题
                'cover' => $v['anchor_live_info']['room_img'],  //封面
                'anchor_id' => $Anchor['id'],
                'live_stream_address' => $v['live_stream_address'],
                'info' => [  //收益明细
                    'roomGold' => $v['buy_live'],  // 房间收益 金币
                    'giftGold' => $v['total_reward'],   // 礼物打赏 金币
                    'countGold' => $v['buy_live'] + $v['total_reward'],  // 总收益 金币
                    'money' => $v['settlement'],  // 转换成余额收益
                    'bl' => $v['anchor_live_info']['income_ratio'] * 100,  //收益比例
                ]
            ];
        }
        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    /* 获取打赏礼物 */
    public function getPlayerGift(Request $request)
    {
        $uid = $request->param('uid/d', 0); // 用户ID 
        $page = $request->param('page/d', 1); // 页码
        $aid = $request->param('aid/d', 0); // 主播anchor_id
        $live_stream_address = $request->param('url/s', ''); // 流地址live_stream_address
        if (empty($aid) || empty($live_stream_address)) die(json_encode(['Code' => 201, 'Msg' => '参数错误~~'], JSON_UNESCAPED_UNICODE));
        if (empty(get_member_info($uid))) die(json_encode(['Code' => 201, 'Msg' => '会员不存在'], JSON_UNESCAPED_UNICODE));
        // 登录用户是否为主播
        $anchor = Db::name('anchor')->where(['user_id' => $uid, 'status' => 1])->find();
        if (empty($anchor)) die(json_encode(['Code' => 201, 'Msg' => '系统错误,请稍后再试~~'], JSON_UNESCAPED_UNICODE));
        $gratuity_record = Db::view('gratuity_record g')->view('member m', 'headimgurl,nickname', 'm.id = g.user_id')->where(['g.anchor_id' => $aid, 'g.live_stream_address' => $live_stream_address])->paginate(20)->toArray();
        $data = [];
        foreach ($gratuity_record['data'] as $k => $v) {
            $data[] = [
                'headimgurl' => $v['headimgurl'],
                'nickname' => $v['nickname'], //昵称 后增加
                'text' => '打赏' . $v['gift_name'] . 'x 1',
                'add_time' => $v['gratuity_time'],
                'gold' => $v['price'], //价值金币数
            ];
        }
        die(json_encode(['Code' => 200, 'Msg' => '请求成功', 'Data' => $data], JSON_UNESCAPED_UNICODE));
    }

    protected function __sendRequest($arg_0, $arg_1 = array())
    {
        $var_0 = curl_init();
        if (empty($arg_0)) {
            return false;
        }
        curl_setopt($var_0, CURLOPT_URL, $arg_0);
        curl_setopt($var_0, CURLOPT_POST, true);
        curl_setopt($var_0, CURLOPT_POSTFIELDS, http_build_query($arg_1));
        curl_setopt($var_0, CURLOPT_TIMEOUT, 5);
        curl_setopt($var_0, CURLOPT_RETURNTRANSFER, 1);
        $var_1 = curl_exec($var_0);
        curl_close($var_0);
        return json_decode($var_1, true);
    }
    public function __getUrl($arg_0)
    {
        $var_0 = curl_init();
        curl_setopt($var_0, CURLOPT_URL, $arg_0);
        curl_setopt($var_0, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($var_0, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($var_0, CURLOPT_TIMEOUT, 30);
        curl_setopt($var_0, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($var_0, CURLOPT_HEADER, 0);
        curl_setopt($var_0, CURLOPT_REFERER, $arg_0);
        curl_setopt($var_0, CURLOPT_ENCODING, 'gzip');
        curl_setopt($var_0, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.2; SV1; .NET CLR 1.1.4322)');
        $var_1 = curl_exec($var_0);
        curl_close($var_0);
        return $var_1;
    }
    public function __getCache($arg_0 = '')
    {
        if (empty($arg_0)) {
            return false;
        }
        $arg_0 = strtolower($arg_0);
        $var_0 = get_config('app_cache');
        $var_1 = get_config('app_cache_time') * 60;
        $var_2 = ROOT_PATH . 'cache/' . $arg_0 . '.json';
        if ($var_0) {
            if (file_exists($var_2)) {
                $var_3 = json_decode(file_get_contents($var_2), true);
                if ($var_1 > time() - $var_3['time']) {
                    return $var_3['data'];
                }
            }
        }
        return false;
    }
    public function __setCache($arg_0 = '', $arg_1 = array())
    {
        if (empty($arg_0) || count($arg_1) < 1) {
            return false;
        }
        $arg_0 = strtolower($arg_0);
        $var_0 = ROOT_PATH . 'cache/' . $arg_0 . '.json';
        $var_1 = ['time' => time(), 'data' => $arg_1];
        file_put_contents($var_0, json_encode($var_1));
        return true;
    }
    public function __getRand($arg_0, $arg_1, $arg_2 = '')
    {
        $var_0 = mt_rand(1, $arg_1);
        $var_1 = 1;
        $var_2 = 0;
        foreach ($arg_0 as $var_3 => $var_4) {
            $var_5 = $var_4['scale'] + $var_1;
            for ($var_6 = $var_1; $var_6 < $var_5; $var_6++) {
                if (empty($arg_2)) {
                    $var_7[$var_4['id']][] = $var_6;
                } else {
                    $var_7[$var_3][] = $var_6;
                }
                $var_1 = $var_6 + 1;
            }
        }
        foreach ($var_7 as $var_3 => $var_4) {
            if (in_array($var_0, $var_4)) {
                $var_8 = $var_3;
                break;
            }
        }
        return $var_8;
    }
    public function __randString($arg_0 = 8)
    {
        $arg_0 = intval($arg_0) < 2 ? 2 : $arg_0;
        $arg_0 = $arg_0 > 8 ? 8 : $arg_0;
        $var_0 = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $var_1 = array_rand($var_0, $arg_0);
        $var_2 = '';
        for ($var_3 = 0; $var_3 < $arg_0; $var_3++) {
            $var_2 .= $var_0[$var_1[$var_3]];
        }
        return $var_2;
    }
    public function __intToTime($arg_0)
    {
        $var_0 = $arg_0 / 60 / 60 / 24;
        $var_1 = strstr($var_0, '.');
        $var_2 = $var_1 * 24;
        $var_3 = strstr($var_2, '.');
        $var_4 = $var_3 * 60;
        $var_5 = (int) $var_0 > 0 ? (int) $var_0 . '天' : '';
        $var_6 = (int) $var_2 > 0 ? (int) $var_2 . '时' : '';
        $var_7 = (int) $var_4 > 0 ? (int) $var_4 . '分钟' : '';
        return $var_5 . $var_6 . $var_7;
    }
    public function __getExtension($arg_0)
    {
        $var_0 = strrpos($arg_0, '.');
        if (!$var_0) {
            return '';
        }
        $var_1 = strlen($arg_0) - $var_0;
        $var_2 = substr($arg_0, $var_0 + 1, $var_1);
        return $var_2;
    }
    public function __removeArr($arg_0, $arg_1)
    {
        $var_0 = [];
        foreach ($arg_0 as $var_1 => $var_2) {
            if (in_array($var_2[$arg_1], $var_0)) {
                unset($arg_0[$var_1]);
            } else {
                $var_0[] = $var_2[$arg_1];
            }
        }
        return $arg_0;
    }
    protected function beforeAction($arg_0, $arg_1 = array())
    {
        if (isset($arg_1['only'])) {
            if (is_string($arg_1['only'])) {
                $arg_1['only'] = explode(',', $arg_1['only']);
            }
            if (!in_array($this->request->action(), $arg_1['only'])) {
                return;
            }
        } elseif (isset($arg_1['except'])) {
            if (is_string($arg_1['except'])) {
                $arg_1['except'] = explode(',', $arg_1['except']);
            }
            if (in_array($this->request->action(), $arg_1['except'])) {
                return;
            }
        }
        call_user_func([$this, $arg_0]);
    }
}
