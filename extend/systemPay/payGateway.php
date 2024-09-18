<?php

namespace systemPay;

use think\Exception;
use think\Request;
use think\Db;
use Yansongda\Pay\Pay;

/**
 * Msvodx微信、支付宝 原生支付插件
 * Date:    2020/06/08
 * Author:  $Thirteen
 */
class payGateway extends BasePay
{
    private $paraConfig = null;
    private $request;
    private $exchange_rate = 1; // 汇率
    private $para;
    private $payId = 2;  // 支付渠道ID
    
    /**
     * 初始配置
     * wxPay constructor.
     */
    public function __construct($para = [], $payId = 2)
    {
        $this->request = Request::instance();
        $this->para = $para;
        $this->payId= $payId;
        $config = Db::name('payment')->where(['id'=>$this->payId])->find();
        $config = json_decode($config['config'],true);
        foreach($config as  $v){
            $config[$v['name']] = $v['value'];
        }
        $notifyUrl = get_config('web_server_url').'/pay_notify';
        if($this->payId==1){
            $returnNrl = get_config('web_server_url').'/appapi/synchronization';
            if($this->para[1]=='app'){
                $notifyUrl .= '/app_alipay_notify';
            }else{
                $notifyUrl .= '/alipay_notify';
            }
            $this->paraConfig['alipay'] = [
                'app_id'         => $config['appId'],         // 支付宝提供的 APP_ID
                'ali_public_key' => $config['publicKey'],     // 支付宝公钥
                'private_key'    => $config['privateKey'],    // 自己的私钥
                'return_url' => $returnNrl,         // 同步通知 url
                'notify_url' => $notifyUrl,         // 异步通知 url
            ];
        }else{
            $returnNrl = '';
            if($this->para[1]=='app'){
                $notifyUrl .= '/app_wxpay_notify';
            }else{
                $notifyUrl .= '/wxpay_notify';
            }
            $this->paraConfig['wechat'] = [
                'appid'  => $config['appId'],
                'app_id' => $config['appId'],
                'mch_id' => $config['mchId'],
                'key'    => $config['apiKey'],
                'secret' => $config['AppSecret'],
                'return_url' => $returnNrl, // H5支付同步地址
                'notify_url' => $notifyUrl // 异步
            ];
        }
    }

    /**
     * 创建支付代码
     * @param $params
     * @return array
     */
    public function createPayCode($params)
    {
        if(($rs=$this->checkCreatePyaCodeParams($params))===true) {
            $pay = new Pay($this->paraConfig);
            if($this->payId==1){
                $config_biz = [
                    'body'           => $params['orderSn'],
                    'subject'        => $params['body'],
                    'out_trade_no'   => $params['orderSn'],
                    'timeout_express'=> '60m',
                    'total_amount'   => $params['price'],
                    'product_code'   => 'QUICK_MSECURITY_PAY'
                ];
            }else{
                $config_biz = [
                    'spbill_create_ip' => $this->get_client_ip(),
                    'out_trade_no' => $params['orderSn'],
                    'total_fee' => $params['price'] * 100,    //单位：分 * 100
                    'body' => $params['body'],
                ];
                if($this->exchange_rate!=1){
                    $config_biz['total_fee'] = $config_biz['total_fee'] * $this->exchange_rate;
                    $config_biz['total_fee'] = ceil($config_biz['total_fee']);
                }
            }
            $payHtml = $pay->driver($this->para[0])->gateway($this->para[1])->pay($config_biz);
            //header('Location:'.$payHtml);exit;
            return $payHtml;
        } else {
            return $rs;
        }
    }
    
    public function get_client_ip($type = 0,$adv=false) {
        $type = $type ? 1 : 0;
        static $ip  = NULL;
        if ($ip !== NULL) return $ip[$type];
        if($adv){
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $pos = array_search('unknown',$arr);
                if(false !== $pos) unset($arr[$pos]);
                $ip = trim($arr[0]);
            }elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            }elseif (isset($_SERVER['REMOTE_ADDR'])) {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
        }elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // IP地址合法验证
        $long = sprintf("%u",ip2long($ip));
        $ip   = $long ? array($ip, $long) : array('0.0.0.0', 0);
        return $ip[$type];
    }
    
    /**
     * 回调数据合法性验证
     * @param $data
     */
    public function verify()
    {
        $data = file_get_contents("php://input");
        $payer= new Pay($this->paraConfig);
        /*  打印出xml对应的数组信息
            libxml_disable_entity_loader(true);
            dump(json_decode(json_encode(simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA), JSON_UNESCAPED_UNICODE), true));
        */
        try {
            if($notifyData=$payer->driver($this->para[0])->gateway($this->para[1])->verify($data)){
                //file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', "收到异步通知\r\n", FILE_APPEND);
                //file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', '订单号：' . $notifyData['out_trade_no'] . "\r\n", FILE_APPEND);
                //file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', '订单金额：' . $notifyData['total_fee'] . "\r\n\r\n", FILE_APPEND);
                return $notifyData;
            }
            //file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', "收到异步通知\r\n", FILE_APPEND);
            return false;
        }catch (\Exception $exception){
            //file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', "创建验证失败:".$exception->getMessage()."\r\n", FILE_APPEND);
            //file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', $data."\r\n", FILE_APPEND);
            return false;
        }
    }
    
    /* 支付宝H5 */
    public function verifyH5($data){
        $alipayer = new Pay($this->paraConfig);

        try{
            if($alipayer->driver($this->para[0])->gateway($this->para[1])->verify($data)) {
                file_put_contents(ROOT_PATH . 'alipay_notify.txt', "收到来自支付宝的异步通知\r\n", FILE_APPEND);
                file_put_contents(ROOT_PATH . 'alipay_notify.txt', '订单号：' . $data['out_trade_no'] . "\r\n", FILE_APPEND);
                file_put_contents(ROOT_PATH . 'alipay_notify.txt', '订单金额：' . $data['total_amount'] . "\r\n\r\n", FILE_APPEND);
                return true;
            } else {
                //file_put_contents(ROOT_PATH . 'alipay_notify.txt', "收到异步通知\r\n", FILE_APPEND);
                return false;
            }
        }catch(\Exception $exception){
            return false;
        }
    }
}