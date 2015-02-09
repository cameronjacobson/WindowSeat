<?php

namespace WindowSeat;

use \WindowSeat\EventHandlerInterface;
use \WindowSeat\EventInterface;
use \EventBuffer;
use \EventBufferEvent;
use \EventDnsBase;
use \Event;
use \EventUtil;

class WindowSeat
{
	private $eventHandler;
	private $uri;
	private $config;
	private $connection;
	private $request;

	public function __construct(CouchConfig $config){
		$this->config = $config;
	}

	public function setEventHandler(EventHandlerInterface $eh){
		$this->eventHandler = $eh;
	}

	public function initialize(){
		$this->dnsbase = new EventDnsBase($this->config->getBase(),true);
		$this->connect();
	}
	private function connect(){
		$this->bev = new EventBufferEvent(
			$this->config->getBase(),
			null,
			EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS,
			array($this,'readcb'),
			array($this,'writecb'),
			array($this,'eventcb'),
			$this->config->getBase()
		);
		$this->bev->enable(Event::READ|Event::WRITE);
		$output = $this->bev->getOutput();
		$path = $this->config->getPath();
		if(!empty($this->last_seq)){
			$path .= '&since='.$this->last_seq;
		}
		$output->add(implode("\r\n",array(
			'GET '.$path.' HTTP/1.1',
			'Host: '.$this->config->getHost(),
			'Content-Length: 0',
			'Connection: Keep-Alive'
		))."\r\n\r\n");
		$this->bev->connectHost($this->dnsbase,$this->config->getHost(),$this->config->getPort(),EventUtil::AF_UNSPEC);
	}

	public function writecb($bev,$base){}

	public function eventcb($bev,$events,$base){}

	public function readcb($bev,$base){
		$buf = $bev->getInput();
		while($data = $buf->readLine(EventBuffer::EOL_ANY)){
error_log(trim($data));
			if($json = json_decode($data,true)){
				if(isset($json['last_seq'])){
error_log('last seq reached');
					$this->last_seq = $json['last_seq'];
					$this->bev->free();
					$this->connect();
				}
				else{
echo $data.PHP_EOL;
					$ev = $this->eventHandler->createEvent(trim($data));
					$this->dispatchEvent($ev);
				}
			}
			else{
				// for debugging only.
				//  nothing interesting here
			}
		}
	}

	private function dispatchEvent(EventInterface $event){
		$this->eventHandler->handle($event);
	}

}
