<?php
/**
 * 配置项
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/8/17
 * @time 20:13
 */

return [
    'users' => [
        /**
         * 每一个用户对应一个ID，环境变量名以_ID结尾以区分不同用户
         */
        [
            'ID' => 0,
            'username' => env('USERNAME_0'),

            'headers' => [
                // 模拟今日头条客户端
                'User-Agent' => env('USER_AGENT_0'),

                // 基础header
                'x-Tt-Token' => env('X_TT_TOKEN_0'),
                'X-SS-Cookie' => env('X_SS_COOKIE_0'),
                'X-SS-STUB' => env('X_SS_STUB_0'),
                'x-ss-sessionid' => env('X_SS_SESSIONID_0'),
                'X-Gorgon' => env('X_GORGON_0'),

                // Cookies 2019/09/28 两个月
                'Cookie' => env('COOKIE_0')
            ],

            'queryArr' => [
                '&_request_from' => 'web',
                'fp' => 'G2TZLlXrcSZ7FlGIcSU1J2xeLlZu',
                'version_code' => '6.8.8',
                'app_name' => 'news_article_lite',
                'vid' => '087B36DF-1F76-4665-BF9A-537489141AFE',
                'device_id' => '66197677567',
                'channel' => 'App Store',
                'resolution' => '1125*2001',
                'aid' => '35',
                // 广告版本，注意使用PHP_QUERY_RFC3986编码，否则报账户异常错误
                'ab_version' => '668904,1023119,668906,668903,679106,668905,933995,661929,785656,668907,808414,1016025,846821,861726,1009099,914859,928942',
                'ab_feature' => '201617,z1',
                'review_flag' => '0',
                'ab_group' => 'z1,201617',
                'update_version_code' => '6880',
                'openudid' => '3a16a477d786b2bb97555b89c5e94b763af7e497',
                'idfv' => '087B36DF-1F76-4665-BF9A-537489141AFE',
                'ac' => 'WIFI',
                'os_version' => '12.4',
                'ssmix' => 'a',
                'device_platform' => 'iphone',
                'iid' => '80841499878',
                'ab_client' => 'a1,f2,f7,e1',
                'device_type' => 'iPhone 6S Plus',
                'idfa' => '601F31ED-7CF9-4B5A-8E4D-FDD34195BC59'
            ],
        ],
    ],
    'redis' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'password' => env('REDIS_PASSWORD', null),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DB', 0)
    ],
];