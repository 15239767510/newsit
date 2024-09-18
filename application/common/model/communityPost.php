<?php
/**
 * Created by
 * User: $NAME$
 * Date: 2020/11/3$
 * Time: 16:03$
 */

namespace app\common\model;

use think\Model;
use think\Request;

class communityPost extends Model
{
    public function resources()
    {
        return $this->hasMany('communityPostResources', 'post_id', 'id');
    }



}