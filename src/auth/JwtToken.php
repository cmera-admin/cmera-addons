<?php


namespace cmera\auth;

use cmera\auth\Oauth;
use think\facade\Config;
use think\facade\Request;
use cmera\auth\Send;
use think\facade\Db;
use think\Lang;
use Firebase\JWT\JWT;
/**
 * 生成token
 */
class JwtToken
{
    use Send;

    /**
     * @var bool
     * 是否需要验证数据库账号
     */
    public $authapp = false;
    /**
     * 测试appid，正式请数据库进行相关验证
     */
    public $appid = 'CmeraAdmin';
    /**
     * appsecret
     */
    public $appsecret = '';
    public $key = '';

    /**
     * 构造方法
     * @param Request $request Request对象
     */
    public function __construct(Request $request)
    {
        header('Access-Control-Allow-Origin:*');
        header('Access-Control-Allow-Headers:Accept,Referer,Host,Keep-Alive,User-Agent,X-Requested-With,Cache-Control,Content-Type,Cookie,token');
        header('Access-Control-Allow-Credentials:true');
        header('Access-Control-Allow-Methods:GET, POST, PATCH, PUT, DELETE,OPTIONS');
        $this->request = Request::instance();
        $this->key = md5(Config::get('api.jwt_key'));
        $this->timeDif = Config::get('api.timeDif')??$this->timeDif;
        $this->refreshExpires =Config::get('api.timeDif')??$this->refreshExpires;
        $this->expires =Config::get('api.timeDif')??$this->expires;
        $this->responseType = Config::get('api.responseType')??$this->responseType;
        $this->responseType = Config::get('api.responseType')??$this->responseType;
        $this->authapp = Config::get('api.authapp')??$this->authapp;
    }

    /**
     * 生成token
     */
    public function accessToken(Request $request)
    {
        //参数验证
        $validate = new \cmera\auth\validate\Token;
        if($this->authapp){
            if (!$validate->scene('authappjwt')->check(Request::post())) {
                $this->error($validate->getError(), '', 500);
            }
        }else {
            if (!$validate->scene('jwt')->check(Request::post())) {
                $this->error($validate->getError(), '', 500);
            }
        }
        $this->checkParams(Request::post());  //参数校验
        //数据库已经有一个用户,这里需要根据input('mobile')去数据库查找有没有这个用户
        $memberInfo = $this->getMember(Request::post('username'), Request::post('password'));
        //虚拟一个uid返回给调用方
        try {
            $accessToken = $this->setAccessToken(array_merge($memberInfo, ['appid'=>Request::post('appid')]));  //传入参数应该是根据手机号查询改用户的数据
        } catch (\Exception $e) {
            $this->error($e->getMessage(), $e, 500);
        }
        $this->success('success', $accessToken);

    }
    /**
     * 设置AccessToken
     * @param $memberInfo
     * @return int
     */
    protected function setAccessToken($memberInfo,$refresh_token='')
    {
        $accessTokenInfo = [
            'expires_time'=>$this->expires,
            'refresh_token'=>$this->refreshExpires,
            'client' => $memberInfo,//用户信息
        ];
        $token =  Db::name('oauth2_access_token')->where('member_id',$memberInfo['id'])
            ->where('tablename',$this->tableName)
            ->order('id desc')->limit(1)
            ->find();
        if($token and $token['expires_time']>time()) {
            $accessTokenInfo['access_token'] = $token['access_token'];
            $accessTokenInfo['refresh_token'] = $token['refresh_token'];
            $accessTokenInfo['expires_time'] = $token['expires_time'];
            $accessTokenInfo['refresh_expires_time'] = $token['refresh_expires_time'];
        }else{
            $accessTokenInfo['access_token'] = $this->buildAccessToken($memberInfo,$this->expires);
            $accessTokenInfo['refresh_token'] = $this->getRefreshToken($memberInfo,$refresh_token,$this->refreshExpires);
        }
        $this->saveToken($accessTokenInfo);  //保存本次token
        return $accessTokenInfo;
    }

    /**
     * token 过期 刷新token
     */
    public function refresh()
    {
        $refresh_token = Request::post('refresh_token')?Request::post('refresh_token'):Request::get('refresh_token');
        $refresh_token_info = Db::name('oauth2_access_token')
            ->where('refresh_token',$refresh_token)
            ->where('tablename',$this->tableName)
            ->order('id desc')->find();
        if (!$refresh_token_info) {
            $this->error('refresh_token is error', '', 401);
        } else {
            if ($refresh_token_info['refresh_expires_time'] <time()) {
                $this->error('refresh_token is expired', '', 401);
            } else {    //重新给用户生成调用token
                $member =  Db::name($this->tableName)->where('status',1)->find($refresh_token_info['member_id']);
                $client =  Db::name('oauth2_client')
                    ->field('appid')->find($refresh_token_info['client_id']);
                $memberInfo = array_merge($member,$client);
                $accessToken = $this->setAccessToken($memberInfo,$refresh_token);
                $this->success('success', $accessToken);
            }
        }
    }

    /**
     * 参数检测和验证签名
     */
    public function checkParams($params = [])
    {
        //时间戳校验
        if (abs($params['timestamp'] - time()) > $this->timeDif) {
            $this->error('请求时间戳与服务器时间戳异常' . time(), '', 401);
        }
        if ($this->authapp && $params['appid'] !== $this->appid) {
            //appid检测，查找数据库或者redis进行验证
            $this->error('appid 错误', '', 401);
        }
    }

    /**
     * 生成AccessToken
     * @return string
     */
    protected function buildAccessToken($memberInfo,$expires)
    {
        $time = time(); //签发时间
        $expire = $time + $expires; //过期时间
        $token = array(
            'appid'=>$this->appid,
            'appsecret'=>$this->appsecret,
            "uid" => $memberInfo['id'],
            "iss" => "https://www.cmera.cc",//签发组织
            "aud" => "https://www.cmera.cc", //签发作者
            "iat" => $time,
            "nbf" => $time,
            "exp" => $expire,      //过期时间时间戳
        );
        return   JWT::encode($token,  $this->key);
    }
    /**
     * 获取刷新用的token检测是否还有效
     */
    public function getRefreshToken($memberInfo,$refresh_token,$expires)
    {
        if(!$refresh_token){
            return $this->buildAccessToken($memberInfo,$expires);
        }
        $accessToken =Db::name('oauth2_access_token')->where('member_id',$memberInfo['id'])
            ->where('refresh_token',$refresh_token)
            ->where('tablename',$this->tableName)
            ->field('refresh_token')
            ->find();
        return $accessToken?$refresh_token:$this->buildAccessToken();
    }
    /**
     * 存储token
     * @param $accessTokenInfo
     */
    protected function saveToken($accessTokenInfo)
    {
        $client = Db::name('oauth2_client')->where('appid',$this->appid)
            ->where('appsecret',$this->appsecret)->find();
        $accessToken =Db::name('oauth2_access_token')->where('member_id',$accessTokenInfo['client']['id'])
            ->where('tablename',$this->tableName)
            ->where('access_token',$accessTokenInfo['access_token'])
            ->find();
        if(!$accessToken){
            $data = [
                'client_id'=>$client['id'],
                'member_id'=>$accessTokenInfo['client']['id'],
                'tablename'=>$this->tableName,
                'group'=>isset($accessTokenInfo['client']['group'])?$accessTokenInfo['client']['group']:'api',
                'openid'=>isset($accessTokenInfo['client']['openid'])?$accessTokenInfo['client']['openid']:'',
                'access_token'=>$accessTokenInfo['access_token'],
                'expires_time'=>time() + $this->expires,
                'refresh_token'=>$accessTokenInfo['refresh_token'],
                'refresh_expires_time' => time() + $this->refreshExpires,      //过期时间时间戳
                'create_time' => time()      //创建时间
            ];
            Db::name('oauth2_access_token')->save($data);
        }
    }

    protected function getMember($membername, $password)
    {
        $member = Db::name($this->tableName)
            ->where('status',1)
            ->where('username', $membername)
            ->whereOr('mobile', $membername)
            ->whereOr('email', $membername)
            ->field('id,password')
            ->cache(3600)
            ->find();
        if ($member) {
            if (password_verify($password, $member['password'])) {
                $member['uid'] = $member['id'];
                unset($member['password']);
                return $member;
            } else {
                $this->error(lang('Password is not right'), '', 401);
            }
        } else {
            $this->error(lang('Account is not exist'), '', 401);
        }
    }
}