<?php
namespace cmera\helper;
class SignHelper{
    /**
     * 数据后台签名加密认证
     * @param  array  $data 被认证的数据
     * @return string       签名
     */
    public static function authSign($data) {
        //数据类型检测
        if(!is_array($data)){
            $data = (array)$data;
        }
        ksort($data); //排序
        $code = self::encryptValue(http_build_query($data)); //url编码并生成query字符串
        $sign = sha1($code); //生成签名
        return $sign;
    }

    /**
     * @param int $length
     * @return int[]
     *
     */
    public static function passwordOption($length=13){
        $options = [
            'cost' => $length,
        ];
        return $options;
    }
    /**
     * @param $password
     * @return bool|string
     * 密码加密
     */
    public static function password($password){

        return password_hash($password,PASSWORD_DEFAULT);
    }

    /**
     * bin2hex()把ASCII字符串转换为十六进制
     * @param int $length
     * @return string
     * @throws \Exception
     */
    public static function salt($length=32){
        return bin2hex(random_bytes($length));
    }

    /**
     * @param $value
     * @return string
     * 加密
     */
    public static function encryptValue($value){
        $value = sha1('cmera_') . md5($value) . md5('_encrypt') . sha1($value);
        return sha1($value);
    }
}