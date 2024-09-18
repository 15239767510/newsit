<?php
/**
 * Created by
 * User: $NAME$
 * Date: 2020/11/3$
 * Time: 16:03$
 */

namespace app\common\model;

use think\Model;

class communityPostComment extends Model
{
    public function Comments(){
        return $this->hasMany('communityPostComment','to_id','id');
    }
/* －－－－－－－－－－－－－ 新增内容s －－－－－－－－－－－－－－－－－－－－－－－－ */ 
    public function Member(){
        return $this->hasOne('member','id','send_user');
    }

    public function getMemberAttr($value)
    {

        return ['username'=>'已禁用','id'=>0];
    }
/* －－－－－－－－－－－－－ 新增内容e －－－－－－－－－－－－－－－－－－－－－－－－ */ 
    public function getAddTimeAttr($value)
    {
        return date('Y年m月d日',$value);
    }

    public function getContentAttr($value)
    {
        return htmlspecialchars_decode($value);
    }

//    public function setContentAttr($value)
//    {
//        return htmlspecialchars($value);
//    }

}