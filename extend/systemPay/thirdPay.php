<?php
/**
 * Msvodx通过第三支付插件
 * Date:    2018/06/28
 * Author:  $frs
 *
 */

namespace systemPay;

use think\Db;
use think\Exception;

class thirdPay{
	
    /**
     * 发起支付
     * @param Request $request
     */
    public function sendPayQrcode($params) {
    	if(empty($params)||!isset($params)) return $this->returnResult('数据异常，请稍后再试');
    	$payDb = Db::name('payment');
    	$pay = $payDb->where(['id'=>$params['third']])->find();
        $config = json_decode($pay['config'], true);
        $return = json_decode($pay['return_config'], true);
        $status = explode('-', $return['code']);

        error_reporting(E_ALL & ~E_NOTICE);
        date_default_timezone_set('Asia/Shanghai');
        header("Content-type: text/html; charset=utf-8");
        
        // 请求参数
        $data = [];
        foreach($config as $ok => $ov){
        	switch (strtolower($ov['value'])) {
        		case 'money':
        			$data[$ov['name']] = $params['price'];
        			break;
        		case 'ordersn':
        			$data[$ov['name']] = $params['orderSn'];
        			break;
        		case 'paycode':
        			$data[$ov['name']] = $params['payType'];
        			break;
        		case 'goodname':
        			$data[$ov['name']] = $params['buyType']==1?"购买金币":"购买VIP";
        			break;
        		case 'ip':
        			$data[$ov['name']] = $this->getClientIP(0, true);
        			break;
        		case 'backfun':
        			$data[$ov['name']] = get_config('web_server_url').'/pay_notify/third_notify/payid/'.$params['add_time'];
        			break;
        		case 'payid':
        			$data[$ov['name']] = $params['third'];
        			break;
        		default:
        			$data[$ov['name']] = $ov['value'];
        			break;
        	}
        }
        // 加密方式
        $sign = $params['sign'];
        if (!empty($sign)) {
	        //$sign = "fxsign|fxid|fxddh|fxfee|fxnotifyurl|key";
            if (strpos($sign, '-') !== false) {
                $sign = explode('-', $sign);
                if (count($sign) > 2) {
                    $str  = '';
                    foreach($sign as $tk => $tv){
                        $t = strtolower($tv);
                        switch ($t) {
                            case 'time':
                                $str .= time(); 
                                break;
                            default:
                                $str .= $data[$tv]; 
                                break;
                        }
                    }
                    $data[$sign[0]] = md5($str);
                } else {
                    $fun = trim($sign[1]);
                    if (!function_exists($fun)) {
                        $data = [
                            'code' => 0,
                            'msg'  => '自定义签名函数('.$fun.')不存在，请定义',
                        ];
                        return $data;
                    }
                    $data[$sign[0]] = $fun($data);
                }
            }
	        //$data[$sign[0]] = $str;
        }
        if ($params['method'] == 'GET') {
            $resData = [
                'code' => 1,
                'money'=> $params['price'],
                'url'  => $pay['gateway'] .'?'. http_build_query($data)
            ];
            return $resData;
        }
        if ($params['res_type'] == 2) {
            $resData = [
                'code' => 1,
                'money'=> $params['price'],
                'url'  => $this->buildRequestForm($data, $pay['gateway'].'?_input_charset=utf-8')
            ];
            return $resData;
        }
        //return $data;
        $row = $this->getHttpContent($pay['gateway'], "POST", $data);
        if (!$row) return ['code' => 0, 'msg' => "支付失败，请联系平台客服"];
        //$arr = explode('|',$pay['pay_code']);
        $res = json_decode($row, true);
        if (empty($res)) {
        	$res = $row;
        	if(strpos($res, '{') !== false) $res = strstr($res, '{', true);
        	return ['code' => 0, 'msg'  => $res];
        } 
        //return $res;die;
    	// 请求成功
    	if ($res[$status[0]]==$status[1]) {
    		$resData = [
    			'code' => 1,
                'money'=> $params['price'],
                'url'  => $res[$return['r_url']]
            ];
    	// 请求失败
    	} else {
    		$resData = [
    			'code' => 0,
                'msg'  => $res[$return['msg']],
                'url'  => ''
            ];
    	}
    	return $resData;
    }

    /**
     * 建立请求，以表单HTML形式构造（默认）
     * @param $para 请求参数数组
     * @param $button_name 确认按钮显示文字
     * @return 提交表单HTML文本
     */
    function buildRequestForm($para, $action) {
        $sHtml = "<form id='alipaysubmit' name='alipaysubmit' action='".$action."' method='POST'>";
        while (list ($key, $val) = each ($para)) {
            $sHtml .= "<input type='hidden' name='".$key."' value='".$val."'/>";
        }
        $sHtml .= "<input type='submit' value='支付中...' style='border:none;background:#fff;'></form>";
        $sHtml .= "<script>document.forms['alipaysubmit'].submit();</script>";
        return $sHtml;
    }

    /**
     * 验证通知数据的合法性
     * @param $param
     */
    function verify($param = []){
    	$data = false;
    	if($param){
    		try {
                $pDb = Db::name('payment');
		    	$pay = $pDb->field("id,return_fun,pay_code,return_type")->where(['add_time'=>$param['payid']])->find();
		    	if ($pay) {
                    //$arr = explode('|',$pay['pay_code']);
		    		//$con = json_decode($pay['config'], true);
			        $res = json_decode($pay['return_fun'], true);
			        $oks = $res['return_str'];
			        $sta = explode('-', $res['order_status']);
			        $sta = $param[$sta[0]]==$sta[1] ? 1 : 0;
			        $data = [
			        	'orderSn' => $param[$res['order_sn']],
			        	'money'   => $param[$res['order_money']],
			        	'status'  => $sta,
			        	'string'  => $oks,
			        	'payTime' => time()
			        ];
                    /*if($pay['return_type']==2 && $param['price']<$param['money']){
                        $data = false;
                    }*/
		    	}
            } catch (Exception $e) {
                $data = false;
            }
    	}
        return $data;
    }

	/* 模拟请求 */ 
    function getHttpContent($url, $method = 'GET', $postData = []) {
        $data = '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'];
        $header = array(
            "User-Agent: $user_agent"
        );
        if (!empty($url)) {
            try {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HEADER, false);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15); //30秒超时
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
                //curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_jar);
                if(strstr($url, 'https://')){
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // https请求 不验证证书和hosts
                    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
                }
                if (strtoupper($method) == 'POST') {
                    $curlPost = is_array($postData) ? http_build_query($postData) : $postData;
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $curlPost);
                }
                $data = curl_exec($ch);
                curl_close($ch);
            } catch (Exception $e) {
                $data = '';
            }
        }
        return $data;
    }
	
	/* 模拟客户机IP */
    function getClientIP($type = 0, $adv = false) {
        global $ip;
        $type = $type ? 1 : 0;
        if ($ip !== NULL)
            return $ip[$type];
        if ($adv) {
            if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $arr = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $pos = array_search('unknown', $arr);
                if (false !== $pos)
                    unset($arr[$pos]);
                $ip = trim($arr[0]);
            }elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
                $ip = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (isset($_SERVER['REMOTE_ADDR'])) {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        // IP地址合法验证
        $long = sprintf("%u", ip2long($ip));
        $ip = $long ? array(
            $ip,
            $long) : array(
            '0.0.0.0',
            0);
        return $ip[$type];
    }

}
