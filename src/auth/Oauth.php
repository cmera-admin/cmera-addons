<?php

namespace cmera\auth;

use app\common\model\Oauth2AccessToken;
use Firebase\JWT\JWT;
use cmera\auth\Send;
use think\Exception;
use think\facade\Db;
use think\facade\Request;

/**
 * API鉴权验证
 */
class Oauth
{
    use Send;

    /**
     * 认证授权 通过用户信息和路由
     * @param Request $request
     * @return \Exception|UnauthorizedException|mixed|Exception
     * @throws UnauthorizedException
     */
    final function authenticate()
    {      
        return $this->certification($this->getClient());
    }

    /**
     * 获取用户信息
     * @param Request $request
     * @return $this
     * @throws UnauthorizedException
     */
    public  function getClient()
    {
        //获取头部信息
        $authorization = config('api.authentication')?config('api.authentication'):'authentication';
        $authorizationHeader = Request::header($authorization); //获取请求中的authentication字段，值形式为USERID asdsajh..这种形式
        if(!$authorizationHeader){
            $this->error('Invalid authorization credentials','',401,'',$authorizationHeader?$authorizationHeader:[]);
        }
        try {
            //jwt
            if(config('api.is_jwt')) {
                $clientInfo = $this->jwtcheck($authorizationHeader);
                return $clientInfo;
            }
            $authorizationArr = explode(" ", $authorizationHeader);//explode分割，获取后面一窜base64加密数据
            $authorizationInfo  = explode(":", base64_decode($authorizationArr[1]));  //对base_64解密，获取到用:拼接的自字符串，然后分割，可获取appid、accesstoken、uid这三个参数
            $clientInfo['appid'] = $authorizationInfo[0];
            $clientInfo['access_token'] = $authorizationInfo[1];
            $clientInfo['uid'] = $authorizationInfo[2];
            return $clientInfo;
        } catch (\Exception $e) {
            $this->error($e->getMessage(),$authorizationHeader,401,'',[]);
        }
    }
    public function jwtcheck($authorizationHeader){
        try {
            JWT::$leeway = 60;//当前时间减去60，把时间留点余地
            $decoded = JWT::decode($authorizationHeader, md5(config('api.jwt_key')), ['HS256']); //HS256方式，这里要和签发的时候对应
            $jwtAuth = (array)$decoded;
            $clientInfo['access_token'] = $authorizationHeader;
            $clientInfo['uid'] = $jwtAuth['uid'];
            $clientInfo['appid'] = $jwtAuth['appid'];
        } catch(\Firebase\JWT\SignatureInvalidException $e) {  //签名不正确
            throw new \Exception ($e->getMessage());
        }catch(\Firebase\JWT\BeforeValidException $e) {  // 签名在某个时间点之后才能用
            throw new \Exception( $e->getMessage());
        }catch(\Firebase\JWT\ExpiredException $e) {  // token过期
            throw new \Exception( $e->getMessage());
        }catch(Exception $e) {  //其他错误
            throw new \Exception( $e->getMessage());
        }
        return $clientInfo;

    }
    /**
     * 获取用户信息后 验证权限
     * @return mixed
     */
    public  function certification($data = []){
        $AccessToken = Db::name('oauth2_access_token')->where('member_id',$data['uid'])
            ->where('tablename',$this->tableName)
            ->where('access_token',$data['access_token'])->order('id desc')->find();
        if(!$AccessToken){
            $this->error('access_token不存在或为空','',401);
        }
        $client = Db::name('oauth2_client')->find($AccessToken['client_id']);
        if(!$client || $client['appid'] !== $data['appid']){
            $this->error('appid错误','',401);//appid与缓存中的appid不匹配
        }
        return $data;
    }

    /**
     * 检测当前控制器和方法是否匹配传递的数组
     *
     * @param array $arr 需要验证权限的数组
     * @return boolean
     */
    public  function match($arr = [])
    {
        $request = Request::instance();
        $arr = is_array($arr) ? $arr : explode(',', $arr);
        if (!$arr) {
            return false;
        }
        $arr = array_map('strtolower', $arr);
        // 是否存在
        if (in_array(strtolower($request->action()), $arr) || in_array('*', $arr))
        {
            return true;
        }

        // 没找到匹配
        return false;
    }

    /**
     * 生成签名
     * _字符开头的变量不参与签名
     */
    public  function makeSign ($data = [],$app_secret = '')
    {
        unset($data['version']);
        unset($data['sign']);
        return $this->buildSign($data,$app_secret);
    }

    /**
     * 计算ORDER的MD5签名
     */
    private  function buildSign($params = [] , $app_secret = '') {
        ksort($params);
        $params['key'] = $app_secret;
        return strtolower(md5(urldecode(http_build_query($params))));
    }

}