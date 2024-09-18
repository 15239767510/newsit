<?php

namespace systemPay;

use think\Exception;
use think\Request;
use think\Db;
use think\Log;
use Yansongda\Pay\Pay;

/**
 * Msvodx微信、支付宝 支付插件
 */
class thirdPayGateway extends BasePay
{
    private $paraConfig = null;
    private $request;
    private $exchange_rate = 1; // 汇率
    private $para;
    private $payId = 1;  // 支付渠道ID

    /**
     * 初始配置
     * wxPay constructor.
     */
    public function __construct($para = [], $payId = 1)
    {
        $this->request = Request::instance();
        $this->para = $para;
        $this->payId= $payId;//1
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
            $returnNrl = get_config('web_server_url').'/appapi/synchronization';
            if($this->para[1]=='app'){
                $notifyUrl .= '/app_wxpay_notify';
            }else{
                $notifyUrl .= '/wxpay_notify';
            }
            $this->paraConfig['wechat'] = [
                'app_id' => $config['appId'],
                'mch_id' => $config['mchId'],
                'key'    => $config['apiKey'],
                //'secret' => $config['AppSecret'],
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
            //$pay = new Pay($this->paraConfig);

            if($this->payId == 1){
                //支付宝
                $config_biz = [
                    'body'           => $params['orderSn'],
                    'subject'        => $params['body'],
                    'out_trade_no'   => $params['orderSn'],
                    'timeout_express'=> '60m',
                    'total_amount'   => $params['price'],
                    'product_code'   => 'QUICK_MSECURITY_PAY'
                ];
                $alipay = Pay::alipay($this->paraConfig['alipay'])->wap($config_biz);
                $res =  $alipay->send();// laravel 框架中请直接 `return $alipay`
            }else{
                //微信
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
            $wechatpay = Pay::wechat($this->paraConfig['wechat'])->wap($config_biz);
            return $wechatpay->send();
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

        try {
            $notifyData=$payer->driver($this->para[0])->gateway($this->para[1])->verify($data);
            if($notifyData){
                if($notifyData['return_code'] != 'SUCCESS') return false;
                $order = Db::name('order')->where(['order_sn'=>$notifyData['out_trade_no'],'status'=>0])->find();
                if(empty($order)) return false;
                if($order['price'] != $notifyData['total_fee']/100) return false;
                file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', "收到异步通知\r\n", FILE_APPEND);
                file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', '订单号：' . $notifyData['out_trade_no'] . "\r\n", FILE_APPEND);
                file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', '订单金额：' . $notifyData['total_fee'] . "\r\n\r\n", FILE_APPEND);
                return $notifyData;
            }
            return false;
        }catch (\Exception $exception){
            file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', "创建验证失败:".$exception->getMessage()."\r\n", FILE_APPEND);
            file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', $data."\r\n", FILE_APPEND);
            return false;
        }
    }

    /* 支付宝H5 */
    public function verifyH5($data){
        $data['fund_bill_list'] = htmlspecialchars_decode($data['fund_bill_list']);
        $alipayer = Pay::alipay($this->paraConfig['alipay']);
        //$alipayer = new Pay($this->paraConfig);
        try{
            $resdata = $alipayer->verify(); // 是的，验签就这么简单！
            // 请自行对 trade_status 进行判断及其它逻辑进行判断，在支付宝的业务通知中，只有交易通知状态为 TRADE_SUCCESS 或 TRADE_FINISHED 时，支付宝才会认定为买家付款成功。
            // 1、商户需要验证该通知数据中的out_trade_no是否为商户系统中创建的订单号；
            // 2、判断total_amount是否确实为该订单的实际金额（即商户订单创建时的金额）；
            // 3、校验通知中的seller_id（或者seller_email) 是否为out_trade_no这笔单据的对应的操作方（有的时候，一个商户可能有多个seller_id/seller_email）；
            // 4、验证app_id是否为该商户本身。
            // 5、其它业务逻辑情况
            //Log::debug('Alipay notify', $data->all());
        } catch (\Exception $e) {
           return false;
            // $e->getMessage();
        }
        return $alipayer->success()->send();// laravel 框架中请直接 `return $alipay->success()`
    }


    /* wechatH5 */
    public function verifyWechatH5($data){
        $data = file_get_contents("php://input");
        $pay = Pay::wechat($this->paraConfig['wechat']);

        try{
            $notifyData = $pay->verify(); // 是的，验签就这么简单！
            if($notifyData){
                if($notifyData['return_code'] != 'SUCCESS') return false;
                $order = Db::name('order')->where(['order_sn'=>$notifyData['out_trade_no'],'status'=>0])->find();
                if(empty($order)) return false;
                if($order['price'] != $notifyData['total_fee']/100) return false;
                file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', "收到异步通知\r\n", FILE_APPEND);
                file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', '订单号：' . $notifyData['out_trade_no'] . "\r\n", FILE_APPEND);
                file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', '订单金额：' . $notifyData['total_fee'] . "\r\n\r\n", FILE_APPEND);
                //Log::debug('Wechat notify', $data->all());
                //return $notifyData;
                return $pay->success()->send();// laravel 框架中请直接 `return $pay->success()`
            }
            return false;
        } catch (\Exception $e) {
            file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', "创建验证失败:".$e->getMessage()."\r\n", FILE_APPEND);
            file_put_contents(ROOT_PATH . 'wxapppay_notify.txt', $data."\r\n", FILE_APPEND);
            return false;
        }
    }
}