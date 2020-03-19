<?php

namespace Plugin\Server;

use PAMI\Message\Event\NewConnectedLineEvent;
use PAMI\Message\Event\QueueMemberStatusEvent;
use PAMI\Message\Event\DeviceStateChangeEvent;
use PAMI\Message\Event\NewchannelEvent;
use PAMI\Message\Event\PeerStatusEvent;
use PAMI\Message\Event\QueueMemberEvent;
use PAMI\Message\Event\HangupEvent;
use PAMI\Message\Event\AgentsEvent;
use PAMI\Message\Action\OriginateAction;

use PAMI\Message\Event\EventMessage;
use PAMI\Listener\IEventListener;

use Plugin\Server\Response;


class AsteriskListener implements IEventListener
{
    private $server;
    private $socket;

    public function __construct($config)
    {
        $this->server = null;
        $this->socket = $config['socket'];
    }

    public function handle(EventMessage $event)
    {
        $this->stream($event);
    }

    public function stream(EventMessage $event) {
        $response = new Response($event);
        $response->user = $event->getKey('Exten');
        $response->name = $event->getName();
        $response->message = null;
        $response->username = null;
        $response->status = null;

        if ($event instanceof NewchannelEvent) {
            $response->name = 'talkStart';
            $response->status = 'newChannel';
            $response->caller = $event->getCallerIDNum();
            $response->user = $event->getExtension();
            $response->message = $response->caller . ' >>> ' . $response->user . PHP_EOL;
        } elseif ($event instanceof QueueMemberStatusEvent) {
            preg_match('/(\d)+/', $event->getMemberName(), $members);
            if ($members) {
                $response->caller = $members[0];
            }

            $response->user = -1;
            $response->username = $event->getMemberName();
            $response->name = 'peerStatus';
            $response->status = 'Online';
            $device_status = $event->getStatus();

            switch ($device_status) {
                case '0' :
                    $device_status = 'AST_DEVICE_UNKNOWN';
                    break;
                case '1' :
                    $device_status = 'AST_DEVICE_NOT_INUSE';
                    break;
                case '2' :
                    $device_status = 'AST_DEVICE_INUSE';
                    $response->status = 'Talk';
                    break;
                case '3' :
                    $device_status = 'AST_DEVICE_BUSY';
                    $response->status = 'Busy';
                    break;
                case '4' :
                    $device_status = 'AST_DEVICE_INVALID';
                    break;
                case '5' :
                    $device_status = 'AST_DEVICE_UNAVAILABLE';
                    break;
                case '6' :
                    $device_status = 'AST_DEVICE_RINGING';
                    $response->name = 'ringStart';
                    $response->status = 'Ring';
                    break;
                case '7' :
                    $device_status = 'AST_DEVICE_RINGINUSE';
                    $response->status = 'Ring';
                    break;
                case '8':
                    $device_status = 'AST_DEVICE_ONHOLD';
                    break;
            }
            $response->message = ' ringStart2 ' . $response->user . ' ' . $response->status;
        } elseif ($event instanceof DeviceStateChangeEvent  && $event->getState() == 'RINGING') {
            $response->name = 'ringStart';
            $response->status = 'Ring';
            $response->user = -1;
            $response->message = ' ringStart3 ' . $response->name . ' >>> ' . $response->user;
        }   elseif ($event instanceof QueueMemberEvent) {
            $response->user = -1;
            $response->name = 'peerStatus';
            $response->status = 'Online';
            $response->username = $event->getMemberName();
        }   elseif ($event instanceof PeerStatusEvent) {
            $response->user = -1;
            $response->name = 'peerStatus';
            $response->status = $event->getPeerStatus();
            $response->username = $event->getPeer();
            switch ($response->status) {
                case 'Reachable':
                case 'Registered':
                    $response->status = 'Online';
                    break;
                case 'Unregistered' :
                case 'Rejected' :
                case 'Unknown' :
                case 'Lagged' :
                    $response->status = 'Offline';
                    break;
            }
            if (preg_match('/(\d)+/', $response->username, $peers)) {
                $response->caller = $peers[0];
            }
            $response->message = ' agentsEvent ' . $response->name . ' >>> ' . var_export($event, true);
        }

        if (!$response->message) {
            return;
        }

        $instance = stream_socket_client($this->socket);
        fwrite($instance, json_encode($response->get()));

        print_r($response->user . '>>> ' . $event->getName()   . ' ' . get_class($event) . PHP_EOL);

    }
}
