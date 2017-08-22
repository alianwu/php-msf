<?php
/**
 * mysql异步客户端连接池
 *
 * @author tmtbe
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */

namespace PG\MSF\Pools;

use Exception;
use Noodlehaus\Config;
use PG\MSF\Coroutine\Mysql;
use PG\MSF\Base\Core;
use PG\AOP\MI;
use PG\MSF\Helpers\Context;

class MysqlAsynPool extends AsynPool
{
    // use property and method insert
    use MI;

    /**
     * 连接池类型名称
     */
    const AsynName = 'mysql';

    /**
     * @var Miner SQL Builder
     */
    public $dbQueryBuilder;

    /**
     * @var array
     */
    public $bindPool;

    /**
     * @var int 连接峰值
     */
    protected $mysqlMaxCount = 0;

    /**
     * @var string 连接池标识
     */
    private $active;

    /**
     * @var Miner 同步MySQL客户端
     */
    private $mysqlClient;

    /**
     * MysqlAsynPool constructor.
     *
     * @param Config $config
     * @param string $active
     */
    public function __construct($config, $active)
    {
        parent::__construct($config);
        $this->active         = $active;
        $this->bindPool       = [];
    }

    public function getDBQueryBuilder(Context $context)
    {
        if (empty($this->dbQueryBuilder)) {
            $this->dbQueryBuilder            = new Miner();
            $this->dbQueryBuilder->mysqlPool = $this;
        }
        $this->dbQueryBuilder->context = $context;

        return $this->dbQueryBuilder;
    }

    /**
     * 执行mysql命令
     *
     * @param $data
     * @throws Exception
     */
    public function execute($data)
    {
        $client = null;
        $bindId = $data['bind_id'] ?? null;
        if ($bindId != null) {//绑定
            $client = $this->bindPool[$bindId]['client'] ?? null;
            $sql = strtolower($data['sql']);
            if ($sql != 'begin' && $client == null) {
                throw new Exception('error mysql affairs not begin.');
                return;
            }
        }
        if ($client == null) {
            if (count($this->pool) == 0) {//代表目前没有可用的连接
                $this->prepareOne();
                $this->commands->push($data);
                return;
            } else {
                $client = $this->pool->shift();
                if ($client->isClose ?? false) {
                    $this->reconnect($client);
                    $this->commands->push($data);
                    return;
                }
                if ($bindId != null) {//添加绑定
                    $this->bindPool[$bindId]['client'] = $client;
                }
            }
        }

        $sql = $data['sql'];
        $client->query($sql, function ($client, $result) use ($data) {
            if ($result === false) {
                if ($client->errno == 2006 || $client->errno == 2013) {//断线重连
                    $this->reconnect($client);
                    if (!isset($data['bind_id'])) {//非事务可以重新执行
                        $this->commands->unshift($data);
                    }
                    return;
                } else {//发生错误
                    if (isset($data['bind_id'])) {//事务的话要rollback
                        $data['sql'] = 'rollback';
                        $this->commands->push($data);
                    }
                    //设置错误信息
                    $data['result']['error'] = "[mysql]:" . $client->error . "[sql]:" . $data['sql'];
                }
            }
            $sql = strtolower($data['sql']);
            if ($sql == 'begin') {
                $data['result'] = $data['bind_id'];
            } else {
                $data['result']['client_id'] = $client->client_id;
                $data['result']['result'] = $result;
                $data['result']['affected_rows'] = $client->affected_rows;
                $data['result']['insert_id'] = $client->insert_id;
            }
            //给worker发消息
            $this->asynManager->sendMessageToWorker($this, $data);


            //不是绑定的连接就回归连接
            if (!isset($data['bind_id'])) {
                //回归连接
                if (((time() - $client->genTime) < 3600)
                    || (($this->mysqlMaxCount + $this->waitConnectNum) <= 30)
                ) {
                    $this->pushToPool($client);
                } else {
                    $client->close();
                    $this->mysqlMaxCount--;
                }
            } else {//事务
                $bindId = $data['bind_id'];
                if ($sql == 'commit' || $sql == 'rollback') {//结束事务
                    $this->freeBind($bindId);
                }
            }
        });
    }

    /**
     * 创建一个Mysql连接
     */
    public function prepareOne()
    {
        $this->reconnect();
    }

    /**
     * 重连或者连接
     * @param null $client
     */
    public function reconnect($client = null)
    {
        $this->waitConnectNum++;
        if ($client == null) {
            $client = new \swoole_mysql();
            $client->genTime = time();
        }
        $set = $this->config['mysql'][$this->active];
        $client->connect($set, function ($client, $result) use ($set) {
            $this->waitConnectNum--;
            if (!$result) {
                getInstance()->log->error($client->connect_error . ' with Mysql ' . $set['host'] . ':' . $set['port']);
            } else {
                $client->isClose = false;
                if (!isset($client->client_id)) {
                    $client->client_id = $this->mysqlMaxCount;
                    $this->mysqlMaxCount++;
                }
                $this->pushToPool($client);
            }
        });
        $client->on('Close', [$this, 'onClose']);
    }

    /**
     * 释放绑定
     *
     * @param Context $context
     * @param int $bindId
     */
    public function freeBind(Context $context, $bindId)
    {
        $client = $this->bindPool[$bindId]['client'];
        if ($client != null) {
            $this->pushToPool($client);
        }
        unset($this->bindPool[$bindId]);
    }

    /**
     * 断开链接
     *
     * @param $client
     */
    public function onClose($client)
    {
        $client->isClose = true;
    }

    /**
     * @return string
     */
    public function getAsynName()
    {
        return self::AsynName . ":" . $this->active;
    }

    /**
     * 开启一个事务
     *
     * @param Context $context
     * @param $object
     * @param $callback
     * @return string
     */
    public function begin(Context $context, $object, $callback)
    {
        $id = $this->bind($object);
        $this->query($callback, $id, 'begin');
        return $id;
    }

    /**
     * 获取绑定值
     *
     * @param Context $context
     * @param $object
     * @return string
     */
    public function bind(Context $context, $object)
    {
        if (!isset($object->UBID)) {
            $object->UBID = 0;
        }
        $object->UBID++;
        return spl_object_hash($object) . $object->UBID;
    }

    /**
     * 执行一个sql语句
     *
     * @param Context $context
     * @param $callback
     * @param null $bindId
     * @param null $sql
     * @throws Exception
     */
    public function query(Context $context, $callback, $bindId = null, $sql = null)
    {
        if ($sql == null) {
            $sql = $this->dbQueryBuilder->getStatement(false);
            $this->dbQueryBuilder->clear();
        }
        if (empty($sql)) {
            throw new Exception('sql empty');
        }
        $data = [
            'sql' => $sql
        ];
        $data['token'] = $this->addTokenCallback($callback);
        if (!empty($bindId)) {
            $data['bind_id'] = $bindId;
        }
        //写入管道
        $this->asynManager->writePipe($this, $data, $this->workerId);
    }

    /**
     * 开启一个协程事务
     *
     * @param Context $context
     * @param $object
     * @return MySql
     */
    public function coroutineBegin(Context $context, $object)
    {
        $id = $this->bind($context, $object);
        return $this->dbQueryBuilder->go($id, 'begin');
    }

    /**
     * 提交一个事务
     *
     * @param Context $context
     * @param $callback
     * @param $id
     */
    public function commit(Context $context, $callback, $id)
    {
        $this->query($context, $callback, $id, 'commit');
    }

    /**
     * 协程Commit
     *
     * @param Context $context
     * @param $id
     * @return MySql
     */
    public function coroutineCommit(Context $context, $id)
    {
        return $this->dbQueryBuilder->go($id, 'commit');
    }

    /**
     * 回滚
     *
     * @param Context $context
     * @param $callback
     * @param $id
     */
    public function rollback(Context $context, $callback, $id)
    {
        $this->query($context, $callback, $id, 'rollback');
    }

    /**
     * 协程Rollback
     *
     * @param Context $context
     * @param $id
     * @return MySql
     */
    public function coroutineRollback(Context $context, $id)
    {
        return $this->dbQueryBuilder->go($id, 'rollback');
    }

    /**
     * 获取同步
     * @return Miner
     */
    public function getSync()
    {
        if (isset($this->mysqlClient)) {
            return $this->mysqlClient;
        }
        $activeConfig = $this->config['mysql'][$this->active];
        $this->mysqlClient = new Miner();
        $this->mysqlClient->pdoConnect($activeConfig);
        return $this->mysqlClient;
    }
}
