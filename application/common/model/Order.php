<?php

namespace app\common\model;

use think\Db;
use think\Model;

class Order extends Model
{
    protected $autoWriteTimestamp = true;
    protected $createTime = 'add_time';
    protected $updateTime = 'update_time';
    protected $pk = 'order_sn';

    public function getStatusAbcAttr($value)
    {
        $statusArr = [1 => '已支付', 0 => '未支付'];
        return $statusArr[$value];
    }


    public static function updateOrder($arr)
    {
        $order = self::get($arr['orderSn']);
        if(!$order) return ['code' => 0, 'msg' => '订单异常，订单号不存在'];
        if($order->status === 1) return ['code' => 0, 'msg' => '订单已完成支付，无需再进行支付'];
        if($arr['money'] < $order->price) return ['code' => 0, 'msg' => '订单金额异常']; //***
        
        Db::startTrans();
        try {
            $order->status = 1;
            $order->pay_time = $arr['payTime'];
            $order->real_pay_price = $arr['money'];
            $order->save();
			// 会员信息
            $memberModel = model('member');
            $member = $memberModel::get($order->user_id);
            if(!$member) throw new \Exception('充值会员信息不存在或异常');

            //购买类型，1:金币，2:vip
            if($order->buy_type == 1) {
                $member->money += $order->buy_glod_num;
                $member->save();
                $insData = [
	                'user_id' => $order->user_id,
	                'point'   => $order->buy_glod_num,
	                'explain' => '充值金币',
	                'module'  => 'Order',
	                'add_time'=> time(),
	                'is_gold' => 1, // 1为金币 2为余额
	                'type'	  => 0  // 1为分成 2为提现
	            ];
                //写入金币记录表
                Db::name('account_log')->insert($insData);
            } elseif ($order->buy_type == 2) {
                // 解析出购买的会员内容
                $buyVipInfo = \json_decode($order->buy_vip_info, true);
                if ($buyVipInfo['permanent'] != 1) {
                    //1.普通周期会员
                    if($member->out_time > time()) {
                        $member->out_time = strtotime("+{$buyVipInfo['days']} days", $member->out_time);
                    } else {
                        $member->out_time = strtotime("+{$buyVipInfo['days']} days");
                    }
                    $member->save();
                } else {
                    //2.永久会员
                    $member->is_permanent = 1;
                    $member->save();
                }
            }
            #throw  new \think\Exception('no success');
            // 充值分成  分销
            cur_agent_divide($order->user_id, $arr['money'], $arr['orderSn']);
            // 提交
            Db::commit();
        } catch (\Exception $e) {
            //echo "错误信息:$e";
            Db::rollback();
            return ['code' => 0, 'msg' => '订单数据异常，请联系平台客服'];
        }
        return ['code' => 1, 'msg' => '订单更新成功'];
    }


}