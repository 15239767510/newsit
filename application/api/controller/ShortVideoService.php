<?php

namespace app\api\controller;

use app\common\controller\Api;
use think\Db;

/**
 * 首页接口
 */
class ShortVideoService extends Api
{

    public $videoDb;
    public $page;
    public $sign;

    public function __construct($page = 1)
    {
        $this->videoDb = Db::name('shortvideo v');
        $this->page = $page;
        $this->sign = empty(create_yzm_play_sign()) ? '' : '?sign=' . trim(create_yzm_play_sign());
    }

    /**
     * 首页视频
     * @param $users
     * @param $limit
     */
    public function homeVideo($uid)
    {

        $list = $this->videoDb
            ->join('member m', 'v.user_id = m.id', 'RIGHT')
            ->field('v.id,v.title,v.thumbnail cover,v.url,v.gold,v.good likeSum,v.tag,m.headimgurl,m.nickname')
            ->where(['v.status' => 1, 'v.is_check' => 1])
            ->page($this->page, 6)->select();
        foreach ($list as &$item) {
            if (strpos($item['url'], '.m3u8') !== false && strpos($item['url'], 'sign') === false) $item['url'] .= $this->sign; //默认
            $item['isPlay'] = false; //默认
            $item['like'] = empty($uid) ? 0 : $this->checkLike($uid, $item['id']); // 用户是否已点赞，0未点，1已点
            $item['comment'] = $this->getCommentCount($item['id']); // 评论数量
            $item['isBuy'] = empty($uid) ? false : $this->checkBuyVideo($uid, $item['id']); // 用户是否已购买，如果用户为VIP，则直接为true即可
            $item['commentPage'] = 1; //默认
            $item['muted'] = true; //默认
            $item['showCover'] = true; //默认
            $tagList = Db::name('shorttag')->field('id,name')->where(['id' => ['IN', $item['tag']]])->limit(4)->select();
            $item['tagList'] = $tagList;
            $item['mode'] = 'aspectFill'; // 默认 aspectFill
            $item['objectFit'] = 'cover'; // 默认 cover
            unset($item['tag']);
        }
        return $list;
    }

    /**
     * 根据ID获取视频
     * @param $users
     * @param $limit
     */
    public function getVideoByVid($uid, $vid)
    {
        $list = $this->videoDb
            ->join('member m', 'v.user_id = m.id', 'RIGHT')
            ->field('v.id,v.title,v.thumbnail cover,v.url,v.gold,v.good likeSum,v.tag,m.headimgurl,m.nickname')
            ->where(['v.status' => 1, 'v.is_check' => 1, 'v.id' => $vid])
            ->find();
        if (strpos($list['url'], '.m3u8') !== false && strpos($list['url'], 'sign') === false) $list['url'] .= $this->sign; //默认
        $list['isPlay'] = 'true'; //默认
        $list['like'] = empty($uid) ? 0 : $this->checkLike($uid, $list['id']); // 用户是否已点赞，0未点，1已点
        $list['comment'] = $this->getCommentCount($list['id']); // 评论数量
        $list['isBuy'] = empty($uid) ? false : $this->checkBuyVideo($uid, $list['id']); // 用户是否已购买，如果用户为VIP，则直接为true即可
        $list['commentPage'] = 1; //默认
        $list['muted'] = true; //默认
        $list['showCover'] = true; //默认
        $tagList = Db::name('shorttag')->field('id,name')->where(['id' => ['IN', $list['tag']]])->limit(4)->select();
        $list['tagList'] = $tagList;
        unset($list['tag']);

        return $list;
    }

    /**
     * 所有视频
     * @param $users
     * @param $limit
     * @return \think\Paginator
     * @throws \think\exception\DbException
     */
    public function getAllVideo($limit)
    {
        $video = $this->videoDb
            ->join('member m', 'v.user_id = m.id', 'LEFT')
            ->field('v.id,v.title,v.thumbnail,v.url,v.gold,v.good,v.user_id,m.username,m.head_img,m.id member_id')
            ->where(['v.status' => 1, 'v.is_check' => 1])
            ->order($this->order)
            ->paginate($limit, $this->page)
            ->each(function ($item, $key) {
                if (strpos($item['url'], '.m3u8') !== false && strpos($item['url'], 'sign') === false) $item['url'] .= $this->sign;
                return $item;
            });
        return $video;
    }


    /**
     * 根据id获取视频
     * @param $vid
     */
    public function getVideoById($vid)
    {
        $where['v.status'] = 1;
        $where['v.is_check'] = 1;
        $where['id'] = $vid;
        $video = $this->videoDb
            ->where($where)
            ->find();
        return $video;
    }

    /**
     * 获取标签名
     * @param $tag_ids
     */
    public function getTagNameByIds($tag_ids)
    {
        $tag = Db::name('shorttag')->field('id,name')->where('id', 'in', $tag_ids)->select();
        return $tag;
    }

    /**
     * 视频是否购买
     * @param $uid
     * @param $vid
     */
    public function checkBuyVideo($uid, $vid)
    {
        if (empty($uid)) return false; //没有uid直接返回false
        $userInfo = get_member_info($uid);
        if ($userInfo['isVip']) return true; //vip直接返回true
        $buy = false;
        $buyRes = Db::name('shortvideo_buy_log')->where(['user_id' => $uid, 'video_id' => $vid])->find();
        $selRes = Db::name('shortvideo')->where(['user_id' => $uid, 'id' => $vid])->find();
        if ($buyRes && $selRes) $buy = true;
        return $buy;
    }

    /**
     * 视频是否购买
     * @param $uid
     * @param $vid
     */
    public function buyVideo($uid, $vid)
    {
        $userInfo = get_member_info($uid);
        if (empty($userInfo)) return ['code' => 201, 'msg' => '会员不存在'];
        $video = Db::name('shortvideo')->where(['status' => 1, 'is_check' => 1, 'id' => $vid])->find();
        if (empty($video)) return ['code' => 201, 'msg' => '视频不存在'];
        $isBuy = $this->checkBuyVideo($uid, $vid);

        if (!$isBuy) {
            //还没购买
            if (($userInfo['money'] < $video['gold'])) return ['code' => 201, 'msg' => '金币不足!请充值!'];
            Db::startTrans();
            try {
                //插入购买记录
                $insertBuydata['video_id'] = $vid;
                $insertBuydata['user_id'] = $userInfo['id'];
                $insertBuydata['gold'] = $video['gold'];
                $insertBuydata['add_time'] = time();
                $buylogRes = Db::name('shortvideo_buy_log')->insertGetId($insertBuydata);
                //扣除金币
                $deductingRes = $this->deductingGold($userInfo, $video['gold']);
                if ($buylogRes > 0 && $deductingRes > 0) {
                    Db::commit();
                    return ['code' => 200, 'msg' => '购买成功'];
                } else {
                    throw new Exception('购买失败');
                }
            } catch (\Exception $e) {
                Db::rollback();
                return ['code' => 200, 'msg' => $e->getMessage()];
            }
        } else {
            //已经购买
            return ['code' => 201, 'msg' => '已经购买,无需重新购买'];
        }
    }

    /*扣除金币*/
    public function deductingGold($users, $gold)
    {
        //扣除金币 member  gold
        if ($users['money'] < $gold) return false;
        $result = Db::name('member')->where(['id' => $users['id']])->setDec('money', $gold);
        //插入消费记录 account_log
        if ($result) {
            // 余额记录
            $log_data = [
                'user_id' => $users['id'],
                'point' => '-' . $gold,
                'explain' => '视频消费金币',
                'add_time' => time(),
                'is_gold' => 2, // 1为余额 2为金币
                'type' => 3,  // 1分成2提现3消费4充值
                'module' => 'shortVideo',
            ];
            $account_id = Db::name('account_log')->insertGetId($log_data);
            return $account_id;
        }
        return false;
    }


    /**
     * 检测是否点赞
     * @param $uid
     * @param $resources_id 资源id
     */
    public function checkLike($uid, $resources_id, $type = 1)
    {
        if (empty($uid)) return 0; //没有uid直接返回false
        $like = 0;
        $res = Db::name('shortlike')->where(['user_id' => $uid, 'resources_id' => $resources_id, 'status' => 0, 'type' => $type])->find();
        if ($res) $like = 1;
        return $like;
    }

    /**
     * 统计资源点赞数
     * @param int $resources_id
     * @param int $type
     * @return bool|int|string|null
     */
    public function sumLike($resources_id = 1, $type = 1)
    {
        //type 1 视频   2 点赞  3广告
        $res = Db::name('shortlike')->where(['resources_id' => $resources_id, 'type' => $type, 'status' => 0])->count();
        return $res;
    }

    /**
     * 获取评论数
     * @param $vid
     * @param int $type
     */
    public function getCommentCount($vid, $type = 1)
    {
        if (empty($vid)) return 0; //没有uid直接返回false
        $count = Db::name('shortcomment')->where(['resources_id' => $vid, 'type' => $type, 'status' => 1])->count();
        return $count;
    }
}
