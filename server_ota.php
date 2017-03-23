<?php
/*
created by @Zac
 */
include 'lib/autoload.php';
// 状态相关
define('STAGE_INIT', 0);
define('STAGE_ADDR', 1);
define('STAGE_UDP_ASSOC', 2);
define('STAGE_DNS', 3);
define('STAGE_CONNECTING', 4);
define('STAGE_STREAM', 5);
define('STAGE_DESTROYED', -1);
// 命令
define('CMD_CONNECT', 1);
define('CMD_BIND', 2);
define('CMD_UDP_ASSOCIATE', 3);
//https://shadowsocks.org/en/spec/protocol.html 协议说明
//parse_header 请求地址类型
define('ADDRTYPE_IPV4', 1);//IPv4
define('ADDRTYPE_IPV6', 4);//IPv6
define('ADDRTYPE_HOST', 3);//Remote DNS

/*
原始包头格式
+--------------+---------------------+------------------+----------+
| Address Type | Destination Address | Destination Port |   Data   |
+--------------+---------------------+------------------+----------+
|      1       |       Variable      |         2        | Variable |
+--------------+---------------------+------------------+----------+
*/
// ss OTA 相关
//https://shadowsocks.org/en/spec/one-time-auth.html
define('ONETIMEAUTH_BYTES',10);			//OTA hash长度
define('ONETIMEAUTH_CHUNK_BYTES',12);	//数据流阶段每个数据包 OTA 所占用长度
define('ONETIMEAUTH_CHUNK_DATA_LEN',2);	//OTA data.len 长度
define('ADDRTYPE_AUTH',0x10);//即是0b00010000
//define('ADDRTYPE_MASK', 0xF);//即是0b00011111
/* 
开启OTA后包头格式
+------+---------------------+------------------+-----------+----------+
| ATYP | Destination Address | Destination Port | HMAC-SHA1 |   Data   |
+------+---------------------+------------------+-----------+----------+
|  1   |       Variable      |         2        |    10     | Variable |
+------+---------------------+------------------+-----------+----------+
开启OTA后 每个数据包格式
+----------+-----------+----------+----
| DATA.LEN | HMAC-SHA1 |   DATA   | ...
+----------+-----------+----------+----
|     2    |     10    | Variable | ...
+----------+-----------+----------+----
*/
function onetimeauth_gen($data,$key){
	$Sig = hash_hmac('sha1', $data, $key, true);
	return substr($Sig,0,ONETIMEAUTH_BYTES);
}
class ShadowSocksServer
{
	protected $serv = array();
	// 前端
	protected $myClients;
	// logger
	protected $logger;
	// config
	protected $config;
	public function __construct()
	{
		$this->config = [
			'daemon'=>true,
			'server'=>'0.0.0.0',
			'server_port'=>444,
			'password'=>'yourpassword',
			'method'=>'aes-256-cfb',
			'ota_enable'=>false
		];
		$this->serv = new swoole_server($this->config['server'], $this->config['server_port'], SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
		$this->serv->addlistener($this->config['server'], $this->config['server_port'],SWOOLE_SOCK_UDP);
		
		$this->serv->on('connect', [$this, 'onConnect']);
		$this->serv->on('receive', [$this, 'onReceive']);
		$this->serv->on('close', [$this, 'onClose']);
		
		//监听udp数据发送事件
		$this->serv->on('Packet',[$this,'onPacket']);		
		
		$this->logger = new Logger();
	}
	public function onWorkerStart($serv,$worker_id){
		//每6小时清空一次dns缓存
		swoole_timer_tick(21600000, function() {
			swoole_clear_dns_cache();
			$this->logger->info('Flush dnsCache');
		});
	}
	//缓冲区控制	
	public function onBufferFull($serv, $fd)
	{
		$this->myClients[$fd]['overflowed']		= true;
		$this->logger->info("server is overflowed connection[fd=$fd]");
	}
	public function onBufferEmpty($serv,$fd)
	{
		$this->myClients[$fd]['overflowed']		= false;
	}
	public function onConnect($serv, $fd)
	{
		// 设置当前连接的状态为 STAGE_INIT ，初始状态
		if (!isset($this->myClients[$fd])) {
			$this->myClients[$fd]['stage'] = STAGE_INIT;
		}
		
		$this->myClients[$fd]['info']			= $serv->connection_info($fd);//server_port
		$this->myClients[$fd]['encryptor']		= new Encryptor($this->config['password'], $this->config['method']);
		//初始化各属性
		$this->myClients[$fd]['splQueue'] 		= new SplQueue();
		//判断缓冲区是否已满
		$this->myClients[$fd]['overflowed']		= false;
		
		//$this->myClients[$fd]['ota_enable'] = $this->config['ota_enable'];
		$this->myClients[$fd]['_ota_chunk_idx'] = 0;
		$this->myClients[$fd]['_ota_len'] = 0;
		$this->myClients[$fd]['_ota_buff_head']	= b"";
		$this->myClients[$fd]['_ota_buff_data']	= b"";
	}

	public function onReceive($serv, $fd, $from_id, $data)
	{
		// 先解密数据
		$data = $this->myClients[$fd]['encryptor']->decrypt($data);
		
		$remote_port	= $this->myClients[$fd]['info']['remote_port'];
		$remote_ip		= $this->myClients[$fd]['info']['remote_ip'];
		$server_port	= $this->myClients[$fd]['info']['server_port'];
		switch ($this->myClients[$fd]['stage']) {
			// 如果不是STAGE_STREAM，则尝试解析实际的请求地址及端口
			case STAGE_INIT:
			case STAGE_ADDR:
				// 解析socket5头
				$header = $this->parse_socket5_header($data);
				// 解析头部出错，则关闭连接
				if (!$header) {
					$this->logger->info("parse header error maybe wrong password {$remote_ip}:{$remote_port} server port:{$server_port}");
					return $serv->close($fd);
				}
				//头部长度
				$header_len = $header[3];
				$this->myClients[$fd]['ota_enable'] = $header[4];
				//头部OTA判断
				if ($this->myClients[$fd]['ota_enable']){
					if( strlen($data) < ($header_len + ONETIMEAUTH_BYTES) ){
						$this->logger->info("TCP OTA header is too short server port:{$server_port}");
						return $serv->close($fd);
						//return;
					}
                    //$offset	= $header_len + ONETIMEAUTH_BYTES;
					//客户端发过来的头部hash值
					$_hash	= substr($data,$header_len,ONETIMEAUTH_BYTES);
					$_data	= substr($data,0,$header_len);
					
					$_key = $this->myClients[$fd]['encryptor']->_key;
					$key	= $this->myClients[$fd]['encryptor']->_decipherIv.$_key;
					//验证OTA 头部hash值
					$gen = onetimeauth_gen($_data,$key);
					if( $gen!=$_hash ){
						$this->logger->info("TCP OTA header fail server port:{$server_port}");
						return $serv->close($fd);
					}
					$header_len	+= ONETIMEAUTH_BYTES;
					//数据部分OTA判断
					//$data = substr($data,$header_len);
				}
				
				//尚未建立连接
				if (!isset($this->myClients[$fd]['clientSocket'])) {
					$this->myClients[$fd]['stage'] = STAGE_CONNECTING;
					//连接到后台服务器
					$clientSocket = new swoole_client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
					$clientSocket->closing = false;
					$clientSocket->on('connect', function (swoole_client $clientSocket) use ($data, $fd, $header_len) {
						
						$this->myClients[$fd]['clientSocket'] = $clientSocket;
						// shadowsocks客户端第一次发来的数据超过头部，则要把头部后面的数据发给远程服务端
						if (strlen($data) > $header_len) {
							$this->writeToSock($fd,substr($data, $header_len));
						}
						$count  = isset($this->myClients[$fd]['splQueue'])? count($this->myClients[$fd]['splQueue']):0;
						for($i=0;$i<$count;$i++){//读取队列
							$v = $this->myClients[$fd]['splQueue']->shift();
							$this->writeToSock($fd,$v);
						}
						$this->myClients[$fd]['stage'] = STAGE_STREAM;
					});
					$clientSocket->on('error', function (swoole_client $clientSocket) use ($fd) {
						$this->serv->close($fd);
					});
					$clientSocket->on('close', function (swoole_client $clientSocket) use ($fd) {
						if (!$clientSocket->closing) {
							$clientSocket->closing = true;
							$this->serv->close($fd);
						}
						if ( isset($this->myClients[$fd]) )  unset($this->myClients[$fd]);
						//$this->logger->info( "client {$fd} closed memory_get_usage:" . memory_get_usage() );
					});
					$clientSocket->on('receive', function (swoole_client $clientSocket, $_data) use ($fd) {
						$_data = $this->myClients[$fd]['encryptor']->encrypt($_data);
						if( isset($this->myClients[$fd]['overflowed']) && $this->myClients[$fd]['overflowed']==false ){
							$res = $this->serv->send($fd,$_data);
							if($res){
								//todo流量统计
							}else{
								$errCode = $this->serv->getLastError(); 
								if (1008 == $errCode){//缓存区已满
								}else{
									$this->logger->info("send uncatched errCode:$errCode");	
								}
							}							
						}
						
					});

					if ($header[0] == ADDRTYPE_HOST) {
						swoole_async_dns_lookup($header[1], function ($host, $ip) use ($header,$clientSocket,$fd) {
							$remote_ip	= $this->myClients[$fd]['info']['remote_ip'];
							$remote_port	= $this->myClients[$fd]['info']['remote_port'];
							$server_port	= $this->myClients[$fd]['info']['server_port'];
							$ota = $header[4] ? 'OTA' : '';
							$this->logger->info(
								"TCP {$ota} connecting {$host}:{$header[2]} from {$remote_ip}:{$remote_port} server port:{$server_port} @line:".__LINE__
							);
							if( $ip && 0<$header[2] && $server_port ){
								$clientSocket->connect($ip, $header[2]);
							}							
							$this->myClients[$fd]['stage'] = STAGE_CONNECTING;
						});
					} elseif ($header[0] == ADDRTYPE_IPV4) {
						$ota = $header[4] ? 'OTA' : '';
						$this->logger->info(
							"TCP {$ota} connecting {$header[1]}:{$header[2]} from {$remote_ip}:{$remote_port} server port:{$server_port} @Line:".__LINE__
						);
						$clientSocket->connect($header[1], $header[2]);
						$this->myClients[$fd]['stage'] = STAGE_CONNECTING;
					} else {

					}
				}
				break;
			case STAGE_CONNECTING:
				$this->myClients[$fd]['splQueue']->push($data);
				break;
			case STAGE_STREAM:
				if (isset($this->myClients[$fd]['clientSocket'])) {
					$this->writeToSock($fd,$data);
				}
				break;
			default:
				break;
		}
	}
	public function writeToSock($fd,$data){
		if ($this->myClients[$fd]['ota_enable']){
			$this->_ota_chunk_data($fd,$data);
		}else{
			if (isset($this->myClients[$fd]['clientSocket'])) {
				$this->myClients[$fd]['clientSocket']->send($data);
			}
		}
	}
	/*
	UDP 代理部分的规则 by @Zac 
	$clientInfo
	Array
	(
		[server_socket] => 4
		[address] => 127.0.0.1
		[port] => 55376
	)
	*/
	function onPacket($serv, $data, $clientInfo) {
		$encryptor = new Encryptor($this->config['password'], $this->config['method']);
		
		//计算当前server数据 
		$info = $serv->getClientInfo(ip2long($clientInfo['address']), ($clientInfo['server_socket'] << 16) + $clientInfo['port']);
		$server_port = $info['server_port'];

		$this->logger->info("UDP,server_socket :{$clientInfo['server_socket']}");
		$data = $encryptor -> decrypt($data);
		if(!$data){
				$this->logger->info("UDP handle_server: data is empty after decrypt server port:{$server_port}");
				return;
		}
		$header = $this->parse_socket5_header($data,$server_port);
		// 解析头部出错，则关闭连接
		if (!$header) {
				$this->logger->info("parse UDP header error maybe wrong password {$clientInfo['address']}:{$clientInfo['port']} server port:{$server_port}");
				return;
		}
		//addrtype, dest_addr, dest_port, header_length, ota_enable= header_result
		$clientSocket = new swoole_client(SWOOLE_SOCK_UDP, SWOOLE_SOCK_ASYNC);
		//头部长度
		$header_len = $header[3];
		if($header[4]){
			if( strlen($data) < ($header_len + ONETIMEAUTH_BYTES) ){
				$this->logger->info("UDP OTA header is too short server port:{$server_port}");
				return;
			}
			$_hash= substr($data,-ONETIMEAUTH_BYTES);
			$data = substr($data,0,-ONETIMEAUTH_BYTES);

			$_key = $encryptor->_key;
			$key	= $encryptor->_decipherIv.$_key;
			//验证OTA 头部hash值
			$gen = onetimeauth_gen($data,$key);
			if( $gen!=$_hash ){
				$this->logger->info("UDP OTA header fail  server port:{$server_port}");
				return;
			}
		}
		$clientSocket->on('connect', function (swoole_client $clientSocket) use ($data,  $header_len) {
			if (strlen($data) > $header_len) {
				$clientSocket->send(substr($data, $header_len));
			}			
			#$res = $client->recv();
		});
		

		$clientSocket->on('receive', function (swoole_client $clientSocket, $_data) use ($serv,$clientInfo,$header) {
			//$errCode = $serv->getLastError(); if (1008 == $errCode)//缓存区已满
			//先判断 send或者 push的返回是否为false, 然后调用 getLastError得到错误码，进行对应的处理逻辑
			try{
				$_header = $this->pack_header($header[1],$header[0],$header[2]);
				$_data = $encryptor->encrypt($_header.$_data);
				$serv->sendto($clientInfo['address'], $clientInfo['port'], $_data,$clientInfo['server_socket']);
			}catch(Exception $e){
				//var_dump($e);
			}
		});
		if ($header[0] == ADDRTYPE_HOST) {
			swoole_async_dns_lookup($header[1], function ($host, $ip) use (&$header, $clientSocket,$clientInfo,$server_port) {
				$ota = $header[4] ? 'OTA' : '';
				$this->logger->info(
					"UDP {$ota} connecting {$host}:{$header[2]} from {$clientInfo['address']}:{$clientInfo['port']} server port:{$server_port}"
				);
				$header[1]	=	$ip;
				$clientSocket->connect($ip, $header[2]);
			});
		} elseif ($header[0] == ADDRTYPE_IPV4) {
			$ota = $header[4] ? 'OTA' : '';
			$this->logger->info(
				"UDP {$ota} connecting {$header[1]}:{$header[2]} from {$clientInfo['address']}:{$clientInfo['port']} server port:{$server_port}"
			);			
			$clientSocket->connect($header[1], $header[2]);
		} else {

		}
	}
	/*
	UDP 部分 返回客户端 头部数据 by @Zac 
	//生成UDP header 它这里给返回解析出来的域名貌似给udp dns域名解析用的
	*/
	public function pack_header($addr,$addr_type,$port){
		$header = '';
		//$ip = pack('N',ip2long($addr));
		//判断是否是合法的公共IPv4地址，192.168.1.1这类的私有IP地址将会排除在外
		/*
		if(filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE)) {
			// it's valid
			$addr_type = ADDRTYPE_IPV4;
		//判断是否是合法的IPv6地址
		}elseif(filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE)){
			$addr_type = ADDRTYPE_IPV6;
		}
		*/
		switch ($addr_type) {
			case ADDRTYPE_IPV4:
				$header = b"\x01".inet_pton($addr);
			break;
			case ADDRTYPE_IPV6:
				$header = b"\x04".inet_pton($addr);
			break;
			case ADDRTYPE_HOST:
				if(strlen($addr)>255){
					$addr = substr($addr,0,255);
				}
				$header =  b"\x03".chr(strlen($addr)).$addr;
			break;
			default:
				return;
		}
		return $header.pack('n',$port);
	}
	function onClose($serv, $fd, $from_id)
	{
		//清理掉后端连接
		if ( isset( $this->myClients[$fd]['clientSocket'] ) && $this->myClients[$fd]['clientSocket']->closing === false) {
			$this->myClients[$fd]['clientSocket']->closing = true;
			$this->myClients[$fd]['clientSocket']->close();
		}
		if ( isset($this->myClients[$fd]) )  unset($this->myClients[$fd]);
		//$this->logger->info( "server {$fd} closed memory_get_usage:" . memory_get_usage() );
	}

	public function start()
	{
		$default = [
			'daemonize' => $this->config['daemon'],
			'timeout' => 60,
			//'poll_thread_num' => 4,
			//'worker_num' => 2,
			//'backlog' => 128,
			'dispatch_mode' => 2,
			'log_file' => './swoole.log'
		];
		$this->serv->set($default);
		$this->serv->start();
	}

	/**
	 * 解析shadowsocks客户端发来的socket5头部数据
	 * @param string $buffer
	 */
	 
	function parse_socket5_header($buffer)
	{
		$addr_type = ord($buffer[0]);
		$ota_enable  = false;
		if($this->config['ota_enable'] || ($addr_type & ADDRTYPE_AUTH) == ADDRTYPE_AUTH){
			$ota_enable = true;
			$addr_type = $addr_type ^ ADDRTYPE_AUTH;//把第四位值空0b00010000==>0b00000000
		}
		switch ($addr_type) {
			case ADDRTYPE_IPV4:
				$dest_addr = ord($buffer[1]) . '.' . ord($buffer[2]) . '.' . ord($buffer[3]) . '.' . ord($buffer[4]);
				$port_data = unpack('n', substr($buffer, 5, 2));
				$dest_port = $port_data[1];
				$header_length = 7;
				break;
			case ADDRTYPE_HOST:
				$addrlen = ord($buffer[1]);
				$dest_addr = substr($buffer, 2, $addrlen);
				$port_data = unpack('n', substr($buffer, 2 + $addrlen, 2));
				$dest_port = $port_data[1];
				$header_length = $addrlen + 4;
				break;
			case ADDRTYPE_IPV6:
				$this->logger->info("todo ipv6 not support yet");
				return false;
			default:
				$this->logger->info("unsupported addrtype $addr_type");
				return false;
		}
		//将是否是 OTA 一并返回
		return array($addr_type, $dest_addr, $dest_port, $header_length,$ota_enable);
	}

	/*
		ss OTA 功能拆包部分 by @Zac 
	*/
	function _ota_chunk_data($fd,$data){
		//tcp 是流式传输，接收到的数据包可能不是一个完整的chunk 不能以strlen来判断长度然后直接return
		$server_port	= $this->myClients[$fd]['info']['server_port'];
		while(strlen($data)>0){
			if($this->myClients[$fd]['_ota_len']==0){
				$_ota_buff_head_len = strlen($this->myClients[$fd]['_ota_buff_head']);	//已缓存的头部长度
				$left_ota_buff_head = ONETIMEAUTH_CHUNK_BYTES - $_ota_buff_head_len;	//还需缓存的头部长度
				$this->myClients[$fd]['_ota_buff_head'] .= substr($data,0,$left_ota_buff_head);
				
				$data = substr($data,$left_ota_buff_head);
				//缓存到规定长度后开始解析头部
				if(strlen($this->myClients[$fd]['_ota_buff_head'])===ONETIMEAUTH_CHUNK_BYTES){
					$data_len = substr($this->myClients[$fd]['_ota_buff_head'],0,ONETIMEAUTH_CHUNK_DATA_LEN);
					//理论上一个完整OTA加密包的长度
					$this->myClients[$fd]['_ota_len'] = unpack('n', $data_len)[1];
				}
			}
			
			$buffed_data_len = strlen($this->myClients[$fd]['_ota_buff_data']);//已获取数据长度
			$left_buffed_data_len = $this->myClients[$fd]['_ota_len'] - $buffed_data_len;//还应该获取的数据长度

			$this->myClients[$fd]['_ota_buff_data'] .= substr($data,0,$left_buffed_data_len);
			$data = substr($data,$left_buffed_data_len);
			//接收到了一个完整的包，开始OTA包校验
			if(strlen($this->myClients[$fd]['_ota_buff_data']) == $this->myClients[$fd]['_ota_len']){
				$_hash = substr($this->myClients[$fd]['_ota_buff_head'],ONETIMEAUTH_CHUNK_DATA_LEN);
			
				$_data = $this->myClients[$fd]['_ota_buff_data'];
				$index = pack('N', $this->myClients[$fd]['_ota_chunk_idx']);
				$key = $this->myClients[$fd]['encryptor']->_decipherIv.$index;
				$gen = onetimeauth_gen($_data, $key);
				if ( $gen == $_hash ){
					//将当前通过校验的数据包转发出去,同时编号+1
					$this->myClients[$fd]['clientSocket']->send($this->myClients[$fd]['_ota_buff_data']);
					$this->myClients[$fd]['_ota_chunk_idx'] += 1;
					//$this->logger->info("TCP OTA chunk ok ok ok ok! server port:{$server_port}");
				}else{
					$this->logger->info("TCP OTA fail, drop chunk ! server port:{$server_port}");
				}
				$this->myClients[$fd]['_ota_buff_head'] = b"";
				$this->myClients[$fd]['_ota_buff_data'] = b"";
				$this->myClients[$fd]['_ota_len'] = 0;	
			}
		}
	}
}
$s = new ShadowSocksServer();
$s->start();
