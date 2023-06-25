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
            RD                  = 1 ,
            WR                  = 2 ,
            SOCK                = 'SOCK'                ,
            HTTP                = 'HTTP'                ,
            HTTP_HEAD           = 'HTTP_HEAD'           ,
            HTTP_STEP           = 'HTTP_STEP'           ,
            HTTP_STEP_HEAD      = 'HTTP_STEP_HEAD'      ,
            HTTP_STEP_POST      = 'HTTP_STEP_POST'      ,
            HTTP_STEP_POST_COMP = 'HTTP_STEP_POST_COMP' ,
            HTTP_STEP_NO_POST   = 'HTTP_STEP_NO_POST'   ,

            POST_RAW            = 'POST_RAW' ,
            POST_CONTENT_LENGTH = 'POST_CONTENT_LENGTH' ,
            POST_CONTENT_TYPE   = 'POST_CONTENT_TYPE' ,
            FIFO                = 'FIFO'        ;

        const
            CLOSE_BY_WR     = 'CLOSE_BY_WR'     ,
            HTTP_VERSION    = 'HTTP_VERSION'    ,
            METHOD          = 'METHOD'          ,
            REQUEST_URI     = 'REQUEST_URI'     ;

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

            if(property_exists($Con,self::FIFO)){
                if(array_key_exists(self::WR,$Con->FIFO)){
                    if($Con->FIFO[self::WR] !== ''){
                        $this->P->WR_fds[] = $Con->SOCK ;
                    }
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
                $this->ProcNodeEventCon($Con) ;
            }
        }

        function ProcNodePoll(&$Node){
            foreach(array_keys($Node->Cons) as $ConId){
                $this->ProcNodeConPoll($Node->Cons[$ConId]) ;
            }
        }

        function ProcNodePollAfter(&$Node,$RD_fds,$WR_fds,$EX_fds){
            foreach(array_keys($Node->Cons) as $ConId){
                $Node->ProcNodeConPollAfter($Node->Cons[$ConId],$RD_fds,$WR_fds,$EX_fds) ;
            }
        }

        function NodeGenRes(&$Con){

            $payload = '<pre>' . print_r($Con->HTTP,1) . '</pre>' ;
            $payload .= '<pre>' . print_r($Con->HTTP_HEAD,1) . '</pre>' ;
            $payload .= '<hr>' ;
            $payload .= '<form method="POST" enctype="multipart/form-data">' ;
            $payload .= '<input type="test" name="hoge" value="hoge">' ;
            $payload .= '<input type="submit" name="submit" value="フォーム送信">' ;
            $payload .= '</form>' ;

            $payload .= '<form method="POST" enctype="application/x-www-form-urlencoded">' ;
            $payload .= '<input type="test" name="hoge" value="hoge">' ;
            $payload .= '<input type="submit" name="submit" value="フォーム送信">' ;
            $payload .= '</form>' ;

            $payload .= '<form method="GET">' ;
            $payload .= '<input type="test" name="hoge" value="hoge">' ;
            $payload .= '<input type="submit" name="submit" value="GET送信">' ;
            $payload .= '</form>' ;

            $len = strlen($payload) ;

            $Con->FIFO[self::WR] = "" ;
            $Con->FIFO[self::WR] .= $Con->HTTP[self::HTTP_VERSION] . " 200 OK\r\n" ;
            $Con->FIFO[self::WR] .= "Content-Type: text/html; charset=utf8\r\n" ;
            $Con->FIFO[self::WR] .= sprintf("Content-Length: %d\r\n",$len) ;
            $Con->FIFO[self::WR] .= "\r\n" ;
            $Con->FIFO[self::WR] .= $payload ;

                        $Con->CLOSE_BY_WR = 1 ;
        }

        function NodeEvRecvCon(&$Con){

            if(property_exists($Con,self::HTTP) === FALSE){ $Con->HTTP = [] ; }
            if(property_exists($Con,self::HTTP_HEAD) === FALSE){ $Con->HTTP_HEAD = [] ; }
            if(property_exists($Con,self::HTTP_STEP) === FALSE){ $Con->HTTP_STEP = self::HTTP_STEP_HEAD ; }

retry:
            for(;;){
                if($Con->HTTP_STEP === self::HTTP_STEP_POST){
                    if(strlen($Con->FIFO[self::RD]) >= $Con->HTTP[self::POST_CONTENT_LENGTH]){
                        $Con->HTTP_STEP = self::HTTP_STEP_POST_COMP ;

                        $Con->HTTP[self::POST_RAW] = substr($Con->FIFO[self::RD],0,$Con->HTTP[self::POST_CONTENT_LENGTH]) ;

                        $Con->FIFO[self::RD] = substr($Con->FIFO[self::RD],$Con->HTTP[self::POST_CONTENT_LENGTH]) ;

                        if(($len = strlen($Con->FIFO[self::RD])) != 0){
                            $this->P->Logger->debug('残骸[%s]',$Con->FIFO[self::RD]) ;
                        }

                        break ;
                    }
                }else if($Con->HTTP_STEP === self::HTTP_STEP_NO_POST){
                    break ;
                }else if($Con->HTTP_STEP === self::HTTP_STEP_HEAD){
                    if(($pos = strpos($Con->FIFO[self::RD],chr(0x0a))) !== FALSE){
                        $line = substr($Con->FIFO[self::RD],0,$pos+1) ;
                        $line = trim($line) ;
                        $Con->FIFO[self::RD] = substr($Con->FIFO[self::RD],$pos+1) ;

                        if(preg_match('/^([^:]+)\s*\:\s*(.*)$/',$line,$matches) === 1){
                            $k = strtolower(trim($matches[1])) ;
                            $v = trim($matches[2]) ;

                            switch($k){
                            case 'content-type':
                                $Con->HTTP[self::POST_CONTENT_TYPE]  = $v ;
                                break ;
                            case 'content-length':
                                $Con->HTTP[self::POST_CONTENT_LENGTH]  = $v ;
                                break ;
                            default:
                                $Con->HTTP_HEAD[$k] = $v ;
                                break ;
                            }

                        }else if(preg_match('/^([^\s]+)\s+([^\s]+)\s+([^\s]+)$/',$line,$matches) === 1){
                            // $this->P->Logger->debug('[%s][%s][%s]',$matches[1],$matches[2],$matches[3]) ;
                            $Con->HTTP[self::METHOD]        = $matches[1] ;
                            $Con->HTTP[self::REQUEST_URI]   = $matches[2] ;
                            $Con->HTTP[self::HTTP_VERSION]  = $matches[3] ;
                        }else if($line === ''){
                            if(
                                array_key_exists(self::POST_CONTENT_TYPE,$Con->HTTP) ||
                                array_key_exists(self::POST_CONTENT_LENGTH,$Con->HTTP)
                            ){
                                $Con->HTTP_STEP = self::HTTP_STEP_POST ;
                            }else{
                                $Con->HTTP_STEP = self::HTTP_STEP_NO_POST ;
                            }
                            goto retry ;
                        }else{
                            $this->P->Logger->debug('[%s]',$line) ;
                        }
                    }else{
                        break ;
                    }
                }
            }
            if(
                ($Con->HTTP_STEP === self::HTTP_STEP_NO_POST) ||
                ($Con->HTTP_STEP === self::HTTP_STEP_POST_COMP)
            ){
                $this->NodeGenRes($Con) ;
            }
        }

        function ProcNodeEventConClose(&$Con){
            $Node = $this ;

            fclose($Con->SOCK) ;
            $Con->SOCK = FALSE ;

            unset($Node->Cons[$Con->ConId]) ;

            if(count($Node->Cons) === 0){
                $NodeId = $Node->NodeId ;
                unset($this->P->Nodes[$NodeId]) ;
            }
        }

        function ProcNodeEventCon(&$Con){

            if(feof($Con->SOCK)){
                $this->ProcNodeEventConClose($Con) ;
                return ;
            }

            if(($Con->RW_FLAGS & self::WR) == self::WR){
                // $this->P->Logger->debug('書込可') ;

                $len = strlen($Con->FIFO[self::WR]) ;

                if(($rc = @fwrite($Con->SOCK,$Con->FIFO[self::WR],$len)) === FALSE){
                    $this->P->Logger->debug('書込失敗') ;
                    $this->ProcNodeEventConClose($Con) ;
                    return ;

                }else if($rc === $len){
                    if($Con->CLOSE_BY_WR){
                        // $this->P->Logger->debug('書込完了:CLOSE') ;
                        $this->ProcNodeEventConClose($Con) ;
                    }else{
                        // $this->P->Logger->debug('書込完了') ;
                        $Con->FIFO[self::WR] = '' ;
                    }
                }else{
                    $this->P->Logger->debug('書込中') ;
                    $Con->FIFO[self::WR] = substr($Con->FIFO[self::WR],$rc) ;
                }
            }

            if(($Con->RW_FLAGS & self::RD) == self::RD){
                switch($Con->Mode){
                case self::ModeListen:
                    // $this->P->Logger->debug('受信可[%s]',$Con->Mode) ;
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
                    // $this->P->Logger->debug('読込可[%s]',$Con->Mode) ;

                    if(($buf = fread($Con->SOCK,4096)) === FALSE){
                        $this->P->Logger->debug('読込[%s][FALSE]',$Con->Mode) ;
                    }else if($buf === ''){
                        $this->P->Logger->debug('読込[%s][EAGAIN]',$Con->Mode) ;
                    }else{
                        if(property_exists($Con,self::FIFO) === FALSE){ $Con->FIFO = [] ; }
                        if(! array_key_exists(self::RD,$Con->FIFO)){ $Con->FIFO[self::RD] = '' ; }
                        $Con->FIFO[self::RD] .= $buf ;
                        $this->P->Nodes[$Con->NodeId]->NodeEvRecvCon($Con) ;
                    }

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
                if(array_key_exists($NodeId,$this->Nodes)){
                    $this->ProcNodePoll($this->Nodes[$NodeId]) ;
                }
            }

            if(! $this->RD_fds){ $this->RD_fds = NULL ; }
            if(! $this->WR_fds){ $this->WR_fds = NULL ; }

            $seconds = 0 ; $microseconds = 100000 ;

            if($this->RD_fds || $this->WR_fds){
                $this->EX_fds = NULL ;
                $rc = stream_select($this->RD_fds,$this->WR_fds,$this->EX_fds,$seconds,$microseconds) ;
                if($rc > 0){
                    foreach($NodeIds as $NodeId){
                        if(array_key_exists($NodeId,$this->Nodes)){
                            $this->ProcNodePollAfter($this->Nodes[$NodeId],$this->RD_fds,$this->WR_fds,$this->EX_fds) ;
                        }
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


