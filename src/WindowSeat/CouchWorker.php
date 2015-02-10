<?php

namespace WindowSeat;

use \Windowseat\WindowSeat;
use \EventUtil;
use \EventBufferEvent;
use \Event;
use \EventBuffer;

class CouchWorker
{
	private $ws;
	private $id;
	private $changes;
	private $deleted;
	private $parsing;
	private $length;
	private $content;

	public function __construct(array $parsed,WindowSeat $ws){
		$this->length = 0;
		$this->response = '';
		$this->parsing = 'headers';
		$this->ws = $ws;
		$this->info = $parsed;
		if(!empty($parsed['deleted'])){
			$this->dispatch(null);  // dispatch empty event
		}
	}

	public function __destruct(){
		if(!empty($this->bev)){
			$this->bev->free();
		}
	}

	public function processResponse(){
		list($headers,$body) = explode("\r\n\r\n",$this->response);
		$this->dispatch(trim($body));
	}

	public function event($bev,$events,$base){
        if(EventBufferEvent::READING & $events){
			$buf = $bev->getInput();
			if($buf->length > 0){
				$this->response .= $buf->read($buf->length);
			}
        }
        if(EventBufferEvent::WRITING & $events){
        }
        if(EventBufferEvent::EOF & $events){
			$this->processResponse();
        }
        if(EventBufferEvent::ERROR & $events){
			error_log('ERROR: WindowSeat\CouchWorker '.EventUtil::getLastSocketError(),' '.EventUtil::getLastSocketErrno());
        }
        if(EventBufferEvent::TIMEOUT & $events){
        }
        if(EventBufferEvent::CONNECTED & $events){
        }
	}

	public function retrieveDoc(){
		$this->bev = new EventBufferEvent(
			$this->ws->getConfig()->getBase(),
			null,
			EventBufferEvent::OPT_CLOSE_ON_FREE | EventBufferEvent::OPT_DEFER_CALLBACKS,
			null,
			null,
			array($this,'event'),
			$this->ws->getConfig()->getBase()
		);

		$this->bev->enable(Event::READ|Event::WRITE);
		$output = $this->bev->getOutput();

		$revisions = array();
		foreach($this->info['changes'] as $change){
			list($revnum,$rev) = explode('-',$change['rev']);
			$revisions[$revnum] = $rev;
		}

		$max = max(array_keys($revisions));
		$path = '/'.$this->ws->getConfig()->getDbName().'/'.$this->info['id'].'?rev='.$max.'-'.$revisions[$max];
		$output->add(implode("\r\n",array(
			'GET '.$path.' HTTP/1.1',
			'Host: '.$this->ws->getConfig()->getHost().':'.$this->ws->getConfig()->getPort(),
			'Content-Length: 0',
			'Accept: */*',
			'Connection: close'
		))."\r\n\r\n");
		$this->bev->connectHost($this->ws->dnsbase,$this->ws->getConfig()->getHost(),$this->ws->getConfig()->getPort(),EventUtil::AF_UNSPEC);
	}

	public function dispatch($event){
		$ev = $this->ws->getEventHandler()->createEvent(
			$this->ws->getInstructions()['parse_json'] ? json_decode($event) : $event
		);
		$this->ws->dispatchEvent($ev);
		$this->__destruct();
	}
}
