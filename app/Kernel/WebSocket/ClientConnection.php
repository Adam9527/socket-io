<?php
declare(strict_types = 1);

namespace App\Kernel\WebSocket;

use Hyperf\Contract\ConnectionInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\Pool\Connection as BaseConnection;
use Hyperf\Pool\Exception\ConnectionException;
use Hyperf\Pool\Pool;
use Hyperf\WebSocketClient\Client as BClient;
use Psr\Container\ContainerInterface;

class ClientConnection extends BaseConnection implements ConnectionInterface
{

    /**
     * @var BClient
     */
    protected $connection;

    protected $config = [
        'host' => '127.0.0.1',
        'port' => '9502',
        'ws'   => 'ws://'
    ];

    public function __construct(ContainerInterface $container, Pool $pool, array $config)
    {
        parent::__construct($container, $pool);
        $this->config = array_replace($this->config, $config);

        $this->reconnect();
    }

    public function getActiveConnection()
    {
        if ($this->check()) {
            return $this;
        }

        if (!$this->reconnect()) {
            throw new ConnectionException('Connection reconnect failed.');
        }

        return $this;
    }

    public function release() : void
    {
        parent::release();
    }

    public function reconnect() : bool
    {
        $host   = $this->config['host'];
        $port   = $this->config['port'];
        $ws     = $this->config['ws'];
        $uri    = sprintf('%s%s:%s', $ws, $host, $port);
        $client = make(BClient::class, ['uri' => new Uri($uri)]);
        if (!$client instanceof BClient) {
            throw new ConnectionException('Connection reconnect failed.');
        }
        $this->connection  = $client;
        $this->lastUseTime = microtime(true);

        return true;
    }

    public function close() : bool
    {
        $this->connection->close();
        unset($this->connection);

        return true;
    }

    public function __call($name, $arguments)
    {
        try {
            $result = $this->connection->{$name}(...$arguments);
        } catch (\Throwable $exception) {
            $result = $this->retry($name, $arguments, $exception);
        }

        return $result;
    }

    protected function retry($name, $arguments, \Throwable $exception)
    {
        $logger = $this->container->get(StdoutLoggerInterface::class);
        $logger->warning(sprintf('WebSocket::__call failed, because ' . $exception->getMessage()));

        try {
            $this->reconnect();
            $result = $this->connection->{$name}(...$arguments);
        } catch (\Throwable $exception) {
            $this->lastUseTime = 0.0;
            throw $exception;
        }

        return $result;
    }
}


