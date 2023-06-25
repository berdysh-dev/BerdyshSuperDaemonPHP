<?php
    class BerdyshSuperDaemonBase {

        const
            AddrListen      = 'AddrListen'  ,
            ModeNode        = 'ModeNode'    ,
            ModeMaster      = 'ModeMaster'  ,
            ModeListen      = 'ModeListen'  ,
            ModeClient      = 'ModeClient'  ,
            ModeLogger      = 'ModeLogger'  ,
            Mode            = 'Mode'        ;

        const
            RD              = 1 ,
            WR              = 2 ,
            SOCK            = 'SOCK'        ,
            FIFO            = 'FIFO'        ;

        function Setter($params){
            foreach($params as $k => $v){
                $this->$k = $v ;
            }
        }

    }

    class BerdyshSuperDaemonPHP extends BerdyshSuperDaemonBase {

        function __construct($P = FALSE){
            if($P === FALSE){
                $this->P = $this ;
                $this->InitMaster() ;
            }else{
                $this->P = $P ;
            }
        }

        function InitMaster(){
            $this->Logger = new BerdyshSuperDaemonPHP($this) ;
            $this->Logger->Setter([self::Mode => self::ModeLogger]) ;

            $this->Mode = self::ModeMaster ;
            $this->Nodes = [] ;
            $this->CntNodes = 0 ;
        }

        function GenNode(){
            if($this->Mode === self::ModeMaster){
                $this->CntNodes++ ;
                $Node = $this->Nodes[$this->CntNodes] = new BerdyshSuperDaemonPHP($this->P) ;
                $Node->NodeId = $this->CntNodes ;
                $Node->Mode = self::ModeNode ;
                $Node->Cons = [] ;
                $Node->CntCons = 0 ;
                return $Node ;
            }
            return FALSE ;
        }

        function GenCon(){
            if($this->Mode === self::ModeNode){
                $this->CntCons += 1 ;
                $Con = $this->Cons[$this->CntCons] = new BerdyshSuperDaemonPHP($this->P) ;

                $Con->NodeId = $this->NodeId ;
                $Con->ConId  = $this->CntCons ;

                return $Con ;
            }
            return FALSE ;
        }

        function debug(){

            $trace = debug_backtrace() ;
            $line = $trace[0]['line'] ;
            $func = $trace[1]['function'] ;

            $args = NULL ;
            if(func_num_args() > 0){ $args = func_get_args() ; }
            if(is_array($args)){
                if(count($args) >= 2){
                    if(is_string($args[0])){
                        $str = call_user_func_array('sprintf',$args) ;
                    }else{
                        $str = print_r($args,1) ;
                    }
                }else{
                    $str = print_r($args[0],1) ;
                }
            }else{
                $str = '' ;
            }
            printf("%04d:%s:%s\n",$line,$func,$str) ;
        }

        function ProcListner(){
            if(! array_key_exists($this->AddrListen,$this->P->SOCKS)){
                $errno = $errstr = FALSE ;
                if($this->P->SOCKS[$this->AddrListen] = @stream_socket_server($this->AddrListen,$errno,$errstr)){
                    ;
                }else{
                    unset($this->P->SOCKS[$this->AddrListen]) ;
                    $this->P->Logger->debug('[%s][%s][%s]',$this->AddrListen,$errno,$errstr) ;
                }
            }else{
                $this->P->Logger->debug('OK') ;
            }
            sleep(1) ;
        }

        function ProcNodeConPoll(&$Con){

            if(property_exists($Con,self::SOCK) === FALSE){ $Con->SOCK = FALSE ; }

            if($Con->SOCK === FALSE){
                $errno = $errstr = FALSE ;
                if(($Con->SOCK = @stream_socket_server($Con->AddrListen,$errno,$errstr)) === FALSE){
                    $this->P->Logger->debug('ERR:[%s][%s][%s]',$Con->AddrListen,$errno,$errstr) ;
                }else{
                    $this->P->Logger->debug('OK:[%s]',$Con->AddrListen) ;
                }
            }

            if($Con->SOCK !== FALSE){
                if($Con->SOCK !== FALSE){
                    $this->P->RD_fds[] = $Con->SOCK ;
                }
            }

            $Con->RW_FLAGS = 0 ;
        }

        function ProcNodeConPollAfter(&$Con,$RD_fds,$WR_fds,$EX_fds){
            $Con->RW_FLAGS = 0 ;

            if(is_array($RD_fds)){
                foreach($RD_fds as $fd){
                    if($fd === $Con->SOCK){ $Con->RW_FLAGS |= self::RD ; }
                }
            }
            if(is_array($WR_fds)){
                foreach($WR_fds as $fd){
                    if($fd === $Con->SOCK){ $Con->RW_FLAGS |= self::WR ; }
                }
            }
            if($Con->RW_FLAGS != 0){
                $this->P->ProcNodeEventCon($Con) ;
            }
        }

        function ProcNodePoll(&$Node){
            foreach(array_keys($Node->Cons) as $ConId){
                $this->ProcNodeConPoll($Node->Cons[$ConId]) ;
            }
        }

        function ProcNodePollAfter(&$Node,$RD_fds,$WR_fds,$EX_fds){
            foreach(array_keys($Node->Cons) as $ConId){
                $this->ProcNodeConPollAfter($Node->Cons[$ConId],$RD_fds,$WR_fds,$EX_fds) ;
            }
        }

        function ProcNodeEventCon(&$Con){

            if(feof($Con->SOCK)){
                fclose($Con->SOCK) ;
                $Con->SOCK = FALSE ;
                unset($this->P->Nodes[$Con->NodeId]->Cons[$Con->ConId]) ;
                return ;
            }

            if(($Con->RW_FLAGS & self::WR) == self::WR){
                $this->P->Logger->debug('書込可') ;
            }

            if(($Con->RW_FLAGS & self::RD) == self::RD){
                switch($Con->Mode){
                case self::ModeListen:
                    $this->P->Logger->debug('受信可[%s]',$Con->Mode) ;
                    $timeout = 0 ;
                    $peer_name = FALSE ;
                    if(($sock_new = stream_socket_accept($Con->SOCK,$timeout,$peer_name)) !== FALSE){
                        if($NewNode = $this->P->GenNode()){
                            if($NewCon = $NewNode->GenCon()){
                                $NewCon->SOCK = $sock_new ;
                                $NewCon->Setter([self::Mode => self::ModeClient]) ;
                            }else{
                                $this->P->Logger->debug('ALERT') ;
                            }
                        }else{
                            $this->P->Logger->debug('ALERT') ;
                        }
                    }
                    break ;
                case self::ModeClient:
                    $this->P->Logger->debug('読込可[%s]',$Con->Mode) ;
                    $buf = fread($Con->SOCK,4096) ;
                    $this->P->Logger->debug($buf) ;
                    break ;
                default:
                    $this->P->Logger->debug('ALERT') ;
                    exit ;
                    break ;
                }
            }
        }

        function Tick(){
        }

        function Proc(){

            $this->RD_fds = [] ;
            $this->WR_fds = [] ;

            $NodeIds = array_keys($this->Nodes) ;

            foreach($NodeIds as $NodeId){
                $this->ProcNodePoll($this->Nodes[$NodeId]) ;
            }

            if(! $this->RD_fds){ $this->RD_fds = NULL ; }
            if(! $this->WR_fds){ $this->WR_fds = NULL ; }

            $seconds = 0 ; $microseconds = 100000 ;

            if($this->RD_fds || $this->WR_fds){
                $this->EX_fds = NULL ;
                $rc = stream_select($this->RD_fds,$this->WR_fds,$this->EX_fds,$seconds,$microseconds) ;
                if($rc > 0){
                    foreach($NodeIds as $NodeId){
                        $this->ProcNodePollAfter($this->Nodes[$NodeId],$this->RD_fds,$this->WR_fds,$this->EX_fds) ;
                    }
                }
            }else{
                usleep($microseconds) ;
            }
        }
    }

    if($Master = new BerdyshSuperDaemonPHP()){
        if($Node = $Master->GenNode()){
            if($Con = $Node->GenCon()){
                $Con->Setter([
                    BerdyshSuperDaemonPHP::Mode => BerdyshSuperDaemonPHP::ModeListen,
                    BerdyshSuperDaemonPHP::AddrListen => 'tcp://0.0.0.0:8080'
                ]) ;
            }
            if($Con = $Node->GenCon()){
                $Con->Setter([
                    BerdyshSuperDaemonPHP::Mode => BerdyshSuperDaemonPHP::ModeListen,
                    BerdyshSuperDaemonPHP::AddrListen => 'tcp://0.0.0.0:8081'
                ]) ;
            }
        }
        $Master->TM = time() ;
        for(;;){
            $Master->Proc() ;
            $t = time() ;
            if($Master->TM !== $t){
                $Master->Tick() ;
                $Master->TM = $t ;
            }
        }
    }


