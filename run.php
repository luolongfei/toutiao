<?php
/**
 * 我，罗叔叔，打钱
 *
 * @author mybsdc <mybsdc@gmail.com>
 * @date 2019/8/3
 * @time 7:15
 */

error_reporting(E_ERROR);
ini_set('display_errors', 1);
set_time_limit(0);

define('DS', DIRECTORY_SEPARATOR);
define('APP_PATH', realpath(__DIR__));

date_default_timezone_set('Asia/Shanghai');

/**
 * 定制错误处理
 */
register_shutdown_function('customize_error_handler');
function customize_error_handler()
{
    if (!is_null($error = error_get_last())) {
        system_log($error);
    }
}

/**
 * 写日志
 *
 * @param $content
 * @param array $response
 * @param string $fileName
 */
function system_log($content, array $response = [], $fileName = '')
{
    try {
        $path = sprintf('%s/logs/%s/', APP_PATH, date('Y-m'));
        $file = $path . ($fileName ?: date('d')) . '.log';

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
            chmod($path, 0777);
        }

        $handle = fopen($file, 'a'); // 追加而非覆盖

        if (!filesize($file)) {
            chmod($file, 0666);
        }

        fwrite($handle, sprintf(
                "[%s] %s %s\n",
                date('Y-m-d H:i:s'),
                is_string($content) ? $content : json_encode($content),
                $response ? json_encode($response, JSON_UNESCAPED_UNICODE) : '')
        );

        fclose($handle);
    } catch (\Exception $e) {
        // DO NOTHING
    }
}

/**
 * 检查任务是否已被锁定
 *
 * @param string $taskName
 *
 * @return bool
 * @throws Exception
 */
function is_locked($taskName = '')
{
    try {
        $lock = APP_PATH . '/num_limit/' . date('Y-m-d') . '/' . $taskName . '.lock';

        if (file_exists($lock)) return true;
    } catch (\Exception $e) {
        system_log(sprintf('检查任务%s是否锁定时出错，错误原因：%s', $taskName, $e->getMessage()));
    }

    return false;
}

/**
 * 锁定任务
 *
 * 防止重复执行
 *
 * @param string $taskName
 *
 * @return bool
 */
function lock_task($taskName = '')
{
    try {
        $path = APP_PATH . '/num_limit/' . date('Y-m-d') . '/';
        $file = $taskName . '.lock';
        $lock = $path . $file;

        if (!is_dir($path)) {
            mkdir($path, 0777, true);
            chmod($path, 0777);
        }

        if (file_exists($lock)) {
            return true;
        }

        $handle = fopen($lock, 'a'); // 追加而非覆盖

        if (!filesize($lock)) {
            chmod($lock, 0666);
        }

        fwrite($handle, sprintf(
                "Locked at %s.\n",
                date('Y-m-d H:i:s')
            )
        );

        fclose($handle);

        system_log(sprintf('%s已被锁定，此任务今天内已不会再执行，请知悉', $taskName));
    } catch (\Exception $e) {
        system_log(sprintf('创建锁定任务文件%s时出错，错误原因：%s', $lock, $e->getMessage()));

        return false;
    }

    return true;
}

require __DIR__ . '/vendor/autoload.php';

use Curl\Curl;

class Money
{
    const VERSION = 'v0.1.3 beta';

    /**
     * @var Money
     */
    protected static $instance;

    /**
     * @var Curl
     */
    protected static $client;

    /**
     * @throws ErrorException
     */
    public static function handle()
    {
        $money = self::getInstance();

        // 发送心跳
        $money->heartBeat();

        // 更新token
        $money->updateToken();

        // 签到任务
        $money->signInTask();

        // 开宝箱任务，有冷却时间相关限制
        $money->openTreasureBoxTask();

        // 步数任务
        $money->walkTask();

        // 看扫码演示视频任务
        $money->watchTutorialTask();

        // 晒收入任务
        $money->shareTask();

        // 看广告任务
        $money->watchADTask();

        // 睡眠任务
        $money->sleepTask();

        // 搜索任务
        $money->searchTask();

        // 拜拜了您勒
        self::$client && self::$client->close();
    }

    /**
     * @return Money
     */
    public static function getInstance()
    {
        if (!self::$instance instanceof Money) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * 发送心跳
     *
     * @return bool
     * @throws ErrorException
     */
    public function heartBeat()
    {
        $curl = self::getClient();
        $curl->get('https://security.snssdk.com/passport/token/beat/' . $this->getQuery());

        if ($curl->error) {
            system_log(sprintf('%s %s#%s', '发送心跳出错', $curl->errorCode, $curl->errorMessage));

            return false;
        }

        $response = json_decode($curl->rawResponse, true);
        if (isset($response['message']) && $response['message'] === 'success') {
            system_log('成功发送心跳', $response);

            return true;
        }

        system_log('发送心跳出错', $response);

        return false;
    }

    /**
     * @return Curl
     * @throws ErrorException
     */
    public function getClient()
    {
        if (!self::$client instanceof Curl) {
            self::$client = new Curl();

            // 模拟今日头条客户端
            self::$client->setUserAgent('NewsLite 6.8.8 rv:6.8.8.0 (iPhone; iOS 12.4; zh_CN) Cronet');

            // 注意CURLOPT_HTTPHEADER原生设置不会存到curl对象中
            self::$client->setHeaders([
                // 基础Header
                'Accept' => 'application/json',
                'x-Tt-Token' => '00c1c7dd24f94f7c49d26febdfeb3bc05b76c01f9350bf1ca3b2065d5fd6d9138597768515d8fffd5edf697044173753f014',
                'Content-Type' => 'application/json; encoding=utf-8',
                'X-SS-Cookie' => 'excgd=0803; install_id=80841499878; ttreq=1$21885ec84d8dd6c79a5ea9f3622c368689a145d1; SLARDAR_WEB_ID=7dbf5927-4045-4433-a698-b8ffd21d5ba5; odin_tt=9f2534db82e4b012d7b8a53bd9cc6f542bedbac5063289252c15ca6969c397066b69fbd668d2334b62ee07d4cfd5699a83ea9ce82410fdf607569afab2120aab; sessionid=c1c7dd24f94f7c49d26febdfeb3bc05b; sid_guard=c1c7dd24f94f7c49d26febdfeb3bc05b%7C1564529554%7C5184000%7CSat%2C+28-Sep-2019+23%3A32%3A34+GMT; sid_tt=c1c7dd24f94f7c49d26febdfeb3bc05b; uid_tt=f0bcf6f28bd1807a12a75f66e01d9c8f',
                'tt-request-time' => str_replace('.', '', microtime(true)),
                'sdk-version' => 1,
                'X-SS-STUB' => '22E67CC3AE278CB47BCA0058382D3330',
                'X-Khronos' => time(),
                'X-Pods' => '',
                'x-ss-sessionid' => 'c1c7dd24f94f7c49d26febdfeb3bc05b',
                'X-Gorgon' => '8300000000007bab819b91c627487d43797e85d18b4986ac15c4',

                // Cookies 2019/09/28 两个月
                'Cookie' => 'excgd=0803; odin_tt=9f2534db82e4b012d7b8a53bd9cc6f542bedbac5063289252c15ca6969c397066b69fbd668d2334b62ee07d4cfd5699a83ea9ce82410fdf607569afab2120aab; sid_guard=c1c7dd24f94f7c49d26febdfeb3bc05b%7C1564529554%7C5184000%7CSat%2C+28-Sep-2019+23%3A32%3A34+GMT; uid_tt=f0bcf6f28bd1807a12a75f66e01d9c8f; sid_tt=c1c7dd24f94f7c49d26febdfeb3bc05b; sessionid=c1c7dd24f94f7c49d26febdfeb3bc05b; SLARDAR_WEB_ID=7dbf5927-4045-4433-a698-b8ffd21d5ba5; install_id=80841499878; ttreq=1$21885ec84d8dd6c79a5ea9f3622c368689a145d1'
            ]);
        }

        return self::$client;
    }

    protected function getQuery($addQuery = [])
    {
        return '?' . http_build_query(array_merge(
                [
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
                $addQuery
            ), '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 更新token
     *
     * 保持token活性，注意header中的Content-Type字段必须准确，应尽量每次请求都设定此值
     *
     * @return bool
     * @throws ErrorException
     */
    public function updateToken()
    {
        $curl = self::getClient();
        $curl->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $curl->post('https://ib.snssdk.com/service/1/update_token/' . $this->getQuery(), [
            'aid' => '35',
            'app_name' => 'news_article_lite',
            'device_id' => '66197677567',
            'install_id' => '80841499878',
            'token' => 'e0371f4183d284b82f4e06e5ea313b74b5eb6cc94709f68eac36aeb23922cdde'
        ]);

        if ($curl->error) {
            system_log(sprintf('%s %s#%s', '更新token出错', $curl->errorCode, $curl->errorMessage));

            return false;
        }

        $response = json_decode($curl->rawResponse, true);
        if (isset($response['message']) && $response['message'] === 'success') {
            system_log('成功更新token', $response);

            return true;
        }

        system_log('更新token出错', $response);

        return false;
    }

    /**
     * 签到任务
     *
     * 每天签到一次，金币100+，连续多天签到有更多金币
     *
     * @return bool
     * @throws ErrorException
     * @throws \Exception
     */
    public function signInTask()
    {
        if (time() < strtotime('00:02') || is_locked('signInTask')) { // 凌晨签到吧
            return true;
        }

        sleep(mt_rand(2, 33));

        $curl = self::getClient();
        $curl->post('https://is.snssdk.com/score_task/v1/task/sign_in/' . $this->getQuery());

        if ($curl->error) {
            system_log(sprintf('%s %s#%s', '签到出错', $curl->errorCode, $curl->errorMessage));

            return false;
        }

        $response = json_decode($curl->rawResponse, true);
        if (isset($response['err_tips']) && $response['err_tips'] === 'success') {
            system_log(sprintf(
                '成功完成签到任务，今天是第%d天，金币+%d，加油加油',
                $response['data']['sign_times'],
                $response['data']['score_amount']
            ), $response);

            // 锁定任务
            lock_task('signInTask');

            return true;
        }

        system_log('签到出错', $response);

        return false;
    }

    /**
     * 开宝箱任务
     *
     * 每小时一次，每次50至100+金币，此任务应该尽早执行，延迟的话下次再来可能遇到宝箱冷却时间未过
     *
     * @return bool
     * @throws ErrorException
     */
    public function openTreasureBoxTask()
    {
        // 随机延迟，模拟人工
        sleep(mt_rand(2, 22));

        $curl = self::getClient();
        $curl->setHeader('Content-Type', 'application/json; encoding=utf-8');

        for ($i = 1; $i <= 10; $i++) { // 计划任务只能每小时执行一次，宝箱每小时冷却结束，然而时间不一定恰好同步，失败就多试几次

            // 也许宝箱还没冷却
            sleep($i * 6);

            $curl->post('https://is.snssdk.com/score_task/v1/task/open_treasure_box/' . $this->getQuery(), []);

            if ($curl->error) {
                system_log(sprintf('%s %s#%s', sprintf('开宝箱出错，第%d次尝试', $i), $curl->errorCode, $curl->errorMessage));
                continue;
            }

            $response = json_decode($curl->rawResponse, true);
            if (isset($response['err_tips']) && $response['err_tips'] === 'success') {
                system_log(sprintf('成功开启宝箱，金币+%d，共尝试%d次', $response['data']['score_amount'], $i), $response);

                return true;
            }

            system_log(sprintf('开宝箱出错，第%d次尝试', $i), $response);
        }

        return false;
    }

    /**
     * 步数任务
     *
     * 每天一次，980金币
     *
     * @return bool
     * @throws ErrorException
     * @throws \Exception
     */
    public function walkTask()
    {
        if (time() < strtotime('16:16:16') || is_locked('walkTask')) { // 每天下午16点后走上万步，不过分吧
            return true;
        }

        sleep(mt_rand(2, 33));

        $curl = self::getClient();
        $curl->setHeader('Content-Type', 'application/json; encoding=utf-8');

        // 先悄悄咪咪自定义步数
        $curl->post('https://i.snssdk.com/score_task/v1/walk/count/' . $this->getQuery(), [
            'count' => mt_rand(10520, 23333)
        ]);

        if ($curl->error) {
            system_log(sprintf('%s %s#%s', '自定义步数出错', $curl->errorCode, $curl->errorMessage));

            return false;
        }

        $response = json_decode($curl->rawResponse, true);
        if (!isset($response['err_tips']) || $response['err_tips'] !== 'success') {
            system_log('自定义步数失败', $response);

            return false;
        }
        $walkCount = $response['data']['walk_count'];

        // 再悄悄咪咪领取步数奖励
        $curl->post('https://i.snssdk.com/score_task/v1/walk/bonus/' . $this->getQuery(), [
            'task_id' => 136
        ]);

        if ($curl->error) {
            system_log(sprintf('%s %s#%s', '领取步数奖励出错', $curl->errorCode, $curl->errorMessage));

            return false;
        }

        $response = json_decode($curl->rawResponse, true);
        if (isset($response['err_tips']) && $response['err_tips'] === 'success') {
            system_log(sprintf('成功领取步数奖励，共%d步，金币+%d', $walkCount, $response['data']['score_amount']), $response);

            // 锁定任务
            lock_task('walkTask');

            return true;
        }

        system_log('领取步数奖励出错', $response);

        return false;
    }

    /**
     * 看扫码教程视频任务
     *
     * 只想说，蚊子也是肉，金币100，每天一次
     *
     * @return bool
     * @throws ErrorException
     * @throws \Exception
     */
    public function watchTutorialTask()
    {
        if (time() < strtotime('08:08') || is_locked('watchTutorialTask')) {
            return true;
        }

        sleep(mt_rand(2, 33));

        $curl = self::getClient();
        $curl->setHeader('Content-Type', 'application/json; encoding=utf-8');
        $curl->post('https://i.snssdk.com/score_task/v1/task/done_task/' . $this->getQuery(), [
            'task_id' => 135
        ]);

        if ($curl->error) {
            system_log(sprintf('%s %s#%s', '看扫码演示视频任务出错', $curl->errorCode, $curl->errorMessage));

            return false;
        }

        $response = json_decode($curl->rawResponse, true);
        if (isset($response['err_tips']) && $response['err_tips'] === 'success') {
            system_log(sprintf('成功完成看扫码演示视频任务，金币+%d', $response['data']['score_amount']), $response);

            // 锁定任务
            lock_task('watchTutorialTask');

            return true;
        }

        system_log('看扫码演示视频任务出错', $response);

        return false;
    }

    /**
     * 晒收入任务
     *
     * 每天3次，每次200金币
     *
     * @return bool
     * @throws ErrorException
     * @throws \Exception
     */
    public function shareTask()
    {
        if (time() < strtotime('09:09') || is_locked('shareTask')) {
            return true;
        }

        $curl = self::getClient();
        $curl->setHeader('Content-Type', 'application/json; encoding=utf-8');

        for ($i = 1; $i <= 3; $i++) { // 每天分享三次，每次200金币
            sleep(mt_rand(2, 33));

            $curl->post('https://is.snssdk.com/score_task/v1/landing/add_amount/' . $this->getQuery(), [
                'task_id' => 100
            ]);

            if ($curl->error) {
                system_log(sprintf('%s %s#%s', sprintf('晒收入任务出错（第%d次）', $i), $curl->errorCode, $curl->errorMessage));

                return false;
            }

            $response = json_decode($curl->rawResponse, true);
            if (isset($response['err_tips']) && $response['err_tips'] === 'success') {
                system_log(sprintf('成功完成晒收入任务（第%d次）', $i), $response);
            } else {
                system_log(sprintf('晒收入任务出错（第%d次）', $i), $response);
            }

            if ($i === 3) {
                // 锁定任务
                lock_task('shareTask');
            }
        }

        return true;
    }

    /**
     * 看广告任务
     *
     * 每小时一次，每次1500金币 + 900金币，可以说是主要收入来源了，未知次数，接口返回次数限制时锁定任务
     *
     * @return bool
     * @throws ErrorException
     * @throws \Exception
     */
    public function watchADTask()
    {
        if (time() < strtotime('01:01') || is_locked('watchADTask')) {
            return true;
        }

        $curl = self::getClient();
        $curl->setHeader('Content-Type', 'application/json; encoding=utf-8');

        $adTypes = [1, 2]; // 目前已知两种广告类型
        foreach ($adTypes as $type) {
            sleep(mt_rand(11, 33));

            // 编码有问题，导致账户异常，暂时处理为直接拼接编码后的query字符
            $curl->post('https://is.snssdk.com/score_task/v1/task/new_excitation_ad/?fp=G2TZLlXrcSZ7FlGIcSU1J2xeLlZ1&version_code=6.8.8&app_name=news_article_lite&vid=087B36DF-1F76-4665-BF9A-537489141AFE&device_id=66197677567&channel=App%20Store&resolution=1125*2001&aid=35&ab_version=668904%2C1023119%2C668906%2C668903%2C679106%2C668905%2C933995%2C661929%2C785656%2C668907%2C808414%2C1016025%2C846821%2C861726%2C1009099%2C914859%2C928942&ab_feature=201617%2Cz1&review_flag=0&ab_group=z1%2C201617&update_version_code=6880&openudid=3a16a477d786b2bb97555b89c5e94b763af7e497&idfv=087B36DF-1F76-4665-BF9A-537489141AFE&ac=WIFI&os_version=12.4&ssmix=a&device_platform=iphone&iid=80841499878&ab_client=a1%2Cf2%2Cf7%2Ce1&device_type=iPhone%206S%20Plus&idfa=601F31ED-7CF9-4B5A-8E4D-FDD34195BC59', [
                'task_id' => 143,
                'score_source' => $type
            ]);

            if ($curl->error) {
                system_log(sprintf('%s %s#%s', sprintf('看广告任务出错，广告类型为%d', $type), $curl->errorCode, $curl->errorMessage));
            }

            $response = json_decode($curl->rawResponse, true);
            if (isset($response['err_tips']) && $response['err_tips'] === 'success') {
                system_log(sprintf('成功完成看广告任务，广告类型为%d，获得金币%d', $type, $response['data']['score_amount']), $response);
            } else {
                system_log(sprintf('看广告任务出错，广告类型为%d', $type), $response);

                // 锁定任务
                if ($type === 2 && isset($response['err_no']) && $response['err_no'] === 4) { // 冷却期间会返回1054错误，任务达到次数限制返回4错误
                    lock_task('watchADTask');
                }
            }
        }

        return true;
    }

    /**
     * 睡眠任务
     *
     * 可以看作是同一天内的两个任务，上午结束上一次睡眠，晚上开始下一次睡眠
     * 每天睡眠3600金币
     *
     * @return bool
     * @throws ErrorException
     * @throws \Exception
     */
    public function sleepTask()
    {
        $curl = self::getClient();
        $curl->setHeader('Content-Type', 'application/json; encoding=utf-8');
        $now = time();

        // 结束上一次睡眠并领取金币
        if ($now >= strtotime('09:09') && $now <= strtotime('11:11') && !is_locked('sleepStopTask')) {
            sleep(mt_rand(2, 33));

            // 结束睡眠
            $curl->post('https://i.snssdk.com/score_task/v1/sleep/stop/' . $this->getQuery(), [
                'task_id' => 145
            ]);

            if ($curl->error) {
                system_log(sprintf('%s %s#%s', '结束睡眠出错', $curl->errorCode, $curl->errorMessage));
            }

            $response = json_decode($curl->rawResponse, true);
            if (isset($response['err_tips']) && $response['err_tips'] === 'success') {
                system_log('成功结束睡眠', $response);

                // 领取睡眠金币奖励
                $curl->post('https://i.snssdk.com/score_task/v1/sleep/done_task/' . $this->getQuery(), [
                    'task_id' => 145,
                    'score_amount' => 3600
                ]);

                if ($curl->error) {
                    system_log(sprintf('%s %s#%s', '领取睡眠金币奖励出错', $curl->errorCode, $curl->errorMessage));
                }

                $rp = json_decode($curl->rawResponse, true);
                if (isset($rp['err_tips']) && $rp['err_tips'] === 'success') {
                    // 锁定任务
                    lock_task('sleepStopTask');
                    system_log('成功完成睡眠任务，领取金币3600', $rp);
                } else {
                    system_log('领取睡眠金币奖励出错', $rp);
                }
            } else {
                system_log('结束睡眠出错', $response);
            }
        }

        // 开始下一次睡眠
        if ($now >= strtotime('21:21') && $now <= strtotime('23:23') && !is_locked('sleepStartTask')) {
            sleep(mt_rand(2, 33));

            // 开始睡眠
            $curl->post('https://i.snssdk.com/score_task/v1/sleep/start/' . $this->getQuery(), [
                'task_id' => 145
            ]);

            if ($curl->error) {
                system_log(sprintf('%s %s#%s', '开始睡眠出错', $curl->errorCode, $curl->errorMessage));
            }

            $response = json_decode($curl->rawResponse, true);
            if (isset($response['err_tips']) && $response['err_tips'] === 'success') {
                // 锁定任务
                lock_task('sleepStartTask');
                system_log('成功开始睡眠，晚安啦', $response);
            } else {
                system_log('开始睡眠出错', $response);
            }
        }

        return true;
    }

    /**
     * 搜索任务
     *
     * 每天搜索5次，共300金币，他妈的这么点金币，搞这么复杂，操
     *
     * @return bool
     * @throws ErrorException
     * @throws \Exception
     */
    public function searchTask()
    {
        if (time() < strtotime('11:24') || is_locked('searchTask')) {
            return true;
        }

        $curl = self::getClient();
        $curl->setHeader('Content-Type', 'application/x-www-form-urlencoded');
        $curl->setHeader('Referer', 'https://is.snssdk.com/feoffline/search/template/tt_search/search/search.html');
        $curl->setHeader('X-Requested-With', 'XMLHttpRequest');
        $curl->setHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 12_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 NewsArticle/6.8.8.0 JsSdk/2.0 NetType/WIFI (NewsLite 6.8.8 12.400000)');

        $count = 0;
        for ($i = 1; $i <= 5; $i++) { // 每天搜索5次，共300金币
            sleep(mt_rand(2, 33));

            $curl->get('https://is.snssdk.com/api/search/content/' . $this->getQuery([
                    'action_type' => 'history_keyword_search',
                    'count' => 10,
                    'format' => 'json',
                    'forum' => 1,
                    'from' => 'search_tab',
                    'from_search_subtab' => '',
                    'has_count' => 0,
                    'keyword' => '刘婷',
                    'keyword_type' => 'hist',
                    'offset' => 0,
                    'pd' => 'synthesis',
                    'qc_query' => '',
                    'search_id' => '',
                    'search_position' => 'search_h5',
                    'search_sug' => 1,
                    'source' => 'search_history'
                ]));

            if ($curl->error) {
                system_log(sprintf('%s %s#%s', sprintf('搜索任务出错（第%d次）', $i), $curl->errorCode, $curl->errorMessage));
            }

            $response = json_decode($curl->rawResponse, true);
            if (isset($response['message']) && $response['message'] === 'success') {
                system_log(sprintf('成功完成搜索任务（第%d次）', $i), $response);
                $count++;
            } else {
                system_log(sprintf('搜索任务出错（第%d次）', $i), $response);
            }

            if ($count >= 5) {
                // 锁定任务
                lock_task('searchTask');
            }
        }

        // 恢复基础header
        $curl->setHeader('Content-Type', 'application/json; encoding=utf-8');
        $curl->setHeader('Referer', '');
        $curl->setUserAgent('NewsLite 6.8.8 rv:6.8.8.0 (iPhone; iOS 12.4; zh_CN) Cronet');
        $curl->unsetHeader('X-Requested-With');

        return true;
    }

    /**
     * @param string $origUri
     * @return string
     */
    public static function formatQuery($origUri = '')
    {
        $origUri = urldecode($origUri);

        if (preg_match_all('/[\?&](?P<key>[^=]+)=(?P<val>[^&$]+)/i', $origUri, $matches, PREG_SET_ORDER)) {
            $rtStr = '';
            foreach ($matches as $match) {
                $rtStr .= sprintf("%s'%s' => '%s',\n", str_repeat(' ', 4), $match['key'], $match['val']);
            }

            if ($rtStr) {
                return sprintf("[\n%s\n]", rtrim($rtStr, ",\n"));
            }
        }

        return '';
    }

    /**
     * @param string $allHeaders
     * @return string
     */
    public static function formatHeaders($allHeaders = '')
    {
        if (preg_match_all('/(?P<name>[\w-]+):(?P<val>[^\n]+)\n/i', $allHeaders, $matches, PREG_SET_ORDER)) {
            $rtStr = '';
            foreach ($matches as $match) {
                $rtStr .= sprintf("%s'%s' => '%s',\n", str_repeat(' ', 4), $match['name'], $match['val']);
            }

            if ($rtStr) {
                return sprintf("[\n%s\n]", rtrim($rtStr, ",\n"));
            }
        }

        return '';
    }
}

try {
    Money::handle();
} catch (\Exception $e) {
    system_log($e->getMessage());
}
