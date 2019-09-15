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
define('ROOT_PATH', realpath(__DIR__));

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
        $path = sprintf('%s/logs/%s/', ROOT_PATH, date('Y-m'));
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
        $lock = ROOT_PATH . '/num_limit/' . date('Y-m-d') . '/' . $taskName . '.lock';

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
        $path = ROOT_PATH . '/num_limit/' . date('Y-m-d') . '/';
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

/**
 * 获取配置
 *
 * @param string $key 键，支持点式访问
 *
 * @return array|mixed
 */
function config($key = '')
{
    $allConfig = Money::getInstance()->getConfig();

    if (strlen($key)) {
        if (strpos($key, '.')) {
            $keys = explode('.', $key);
            $val = $allConfig;

            foreach ($keys as $k) {
                if (!isset($val[$k])) {
                    return null; // 任一下标不存在就返回null
                }
                $val = $val[$k];
            }

            return $val;
        } else {
            if (isset($allConfig[$key])) {
                return $allConfig[$key];
            }

            return null;
        }
    }

    return $allConfig;
}

/**
 * 获取环境变量值
 *
 * @param $key
 * @param null $default
 *
 * @return array|bool|false|null|string
 */
function env($key, $default = null)
{
    Money::getInstance()->loadAllEnv();

    $value = getenv($key);
    if ($value === false) {
        return $default;
    }

    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'empty':
        case '(empty)':
            return '';
        case 'null':
        case '(null)':
            return null;
    }

    if (($valueLength = strlen($value)) > 1 && $value[0] === '"' && $value[$valueLength - 1] === '"') { // 去除双引号
        return substr($value, 1, -1);
    }

    return $value;
}

/**
 * Redis
 *
 * @return object|Redis
 */
function redis()
{
    return Money::getInstance()->redis();
}

require __DIR__ . '/vendor/autoload.php';

use Curl\Curl;
use Dotenv\Dotenv;
use Predis\Client AS RedisClient;

class Money
{
    const VERSION = 'v0.1.4 beta';

    /**
     * @var Money
     */
    protected static $instance;

    /**
     * @var Curl
     */
    protected static $client;

    /**
     * @var array config
     */
    protected static $config;

    /**
     * @var array 所有环境变量，那些个见不得人的东西
     */
    protected static $allEnv;

    /**
     * @var object redis client
     */
    protected static $redis;

    /**
     * @var string
     */
    public $username = '';

    /**
     * @var array
     */
    public $queryArr;

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
     * @throws \Exception
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
        }

        return self::$client;
    }

    /**
     * @param array $addQuery
     *
     * @return string
     * @throws Exception
     */
    protected function getQuery($addQuery = [])
    {
        if ($this->queryArr === null) {
            throw new \Exception('queryArr为空');
        } else if (!is_array($this->queryArr)) {
            throw new \Exception('queryArr必须是一个数组');
        }

        return '?' . http_build_query(array_merge(
                $this->queryArr,
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
     * @throws \Exception
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
     * @throws \Exception
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
            $curl->post('https://is.snssdk.com/score_task/v1/task/new_excitation_ad/?', [
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
     *
     * @return string
     */
    public static function formatQuery($origUri = '')
    {
        $origUri = urldecode($origUri);

        if (preg_match_all('/[?&](?P<key>[^=]+)=(?P<val>[^&$]+)/i', $origUri, $matches, PREG_SET_ORDER)) {
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
     *
     * @return string
     */
    public static function formatHeaders($allHeaders = '')
    {
        if (preg_match_all('/(?P<name>[\w-]+):\s(?P<val>[^\n]+)\n/i', $allHeaders, $matches, PREG_SET_ORDER)) {
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

    /**
     * @return array|mixed
     */
    public function getConfig()
    {
        if (is_null(self::$config)) {
            self::$config = require ROOT_PATH . '/config.php';
        }

        return self::$config;
    }

    /**
     * @param string $fileName
     *
     * @return array
     */
    public function loadAllEnv($fileName = '.env')
    {
        if (is_null(self::$allEnv)) {
            self::$allEnv = Dotenv::create(ROOT_PATH, $fileName)->load();
        }

        return self::$allEnv;
    }

    /**
     * @return object|RedisClient
     */
    public function redis()
    {
        if (!self::$redis instanceof RedisClient) {
            self::$redis = new RedisClient([
                'scheme' => 'tcp',
                'host' => config('redis.host'),
                'password' => config('redis.password'),
                'port' => config('redis.port'),
                'database' => config('redis.database')
            ]);
        }

        return self::$redis;
    }
}

try {
    $redis = redis();

    while (true) {
        if ($redis->exists('is_locked')) {
            usleep(500000);
            continue;
        }

        /**
         * 多用户
         */
        $users = config('users');
        foreach ($users as $user) {
            $money = Money::getInstance();

            $money->queryArr = $user['queryArr'];
            $money->username = $user['username'];

            /**
             * 设置客户端
             * 注意CURLOPT_HTTPHEADER原生设置不会存到这里封装的curl对象中，应调用curl类封装的setHeaders
             */
            $client = $money->getClient();
            $client->setHeaders($user['headers']);
            $client->setHeaders([ // 附加默认的headers
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; encoding=utf-8', // 类型参数最好每次请求前单独指定，此处指定默认值
                'tt-request-time' => str_replace('.', '', microtime(true)),
                'sdk-version' => 1,
                'X-Khronos' => time(),
                'X-Pods' => '',
            ]);

            /**
             * 任务处理
             */
            $money::handle();
        }

        /**
         * 控制执行频率
         * 因为宝箱每小时刷新一次，加些延迟
         */
        $redis->setex('is_locked', 3600, 1);
    }
} catch (\Exception $e) {
    system_log($e->getMessage());
}
