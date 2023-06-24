<?php
    class BerdyshSuperDaemonBase {

        const
            AddrListen      = 'AddrListen'  ,
            ModeListner     = 'ModeListner' ,
            ModeClient      = 'ModeClient'  ,
            ModeLogger      = 'ModeLogger'  ,
            Mode            = 'Mode'        ;

        const
            SOCK            = 'SOCK'        ;

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

            $this->Nodes = [] ;

            $this->CntNodes = 0 ;
        }

        function GenClient(){
            $this->CntNodes++ ;
            $node = $this->Nodes[$this->CntNodes] = new BerdyshSuperDaemonPHP($this->P) ;
            $node->Setter([self::Mode => self::ModeClient]) ;
            $node->SOCK = FALSE ;
            $node->RW_FLAGS = 0 ;
            $node->ID = $this->CntNodes ;
            return $node ;
        }

        function GenListner(){
            $this->CntNodes++ ;

            $node = $this->Nodes[$this->CntNodes] = new BerdyshSuperDaemonPHP($this->P) ;
            $node->Setter([self::Mode => self::ModeListner]) ;
            $node->SOCK = FALSE ;
            $node->RW_FLAGS = 0 ;
            $node->ID = $this->CntNodes ;
            return $node ;
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

        function ProcNode(&$node){

            if($node->SOCK === FALSE){
                $this->P->Logger->debug($node->AddrListen) ;
                $errno = $errstr = FALSE ;
                if(($node->SOCK = @stream_socket_server($node->AddrListen,$errno,$errstr)) === FALSE){
                    $this->P->Logger->debug('[%s][%s][%s]',$node->AddrListen,$errno,$errstr) ;
                }
            }

            if($node->SOCK !== FALSE){

                if(($node->RW_FLAGS & 2) == 2){
                    $this->P->Logger->debug('書込可') ;
                }
                if(($node->RW_FLAGS & 1) == 1){
                    switch($node->Mode){
                    case self::ModeListner:
                        $this->P->Logger->debug('読込可[%s]',$node->Mode) ;

                        $timeout = 0 ;
                        $peer_name = FALSE ;

                        if(($sock_new = stream_socket_accept($node->SOCK,$timeout,$peer_name)) !== FALSE){
                            $node_new = $this->P->GenClient() ;
                            $node_new->SOCK = $sock_new ;
                        }

                        break ;
                    default:
                        $this->P->Logger->debug('読込可[%s]',$node->Mode) ;

                        if(feof($node->SOCK)){
                            fclose($node->SOCK) ;
                            $node->SOCK = FALSE ;
                            unset($this->P->Nodes[$node->ID]) ;
                            return ;
                        }else{
                            $buf = fread($node->SOCK,4096) ;
                            $this->P->Logger->debug($buf) ;
                        }
                        break ;
                    }
                }

                if($node->SOCK !== FALSE){
                    $this->RD_fds[] = $node->SOCK ;
                }
            }
            $node->RW_FLAGS = 0 ;
        }

        function Proc(){

            $this->RD_fds = [] ;
            $this->WR_fds = [] ;

            $keys = array_keys($this->Nodes) ;

            foreach($keys as $idx){
                $this->ProcNode($this->Nodes[$idx]) ;
            }

            if(! $this->RD_fds){ $this->RD_fds = NULL ; }
            if(! $this->WR_fds){ $this->WR_fds = NULL ; }

            if($this->RD_fds || $this->WR_fds){
                $seconds = 1 ; $microseconds = null ;
                $this->EX_fds = NULL ;
                $rc = stream_select($this->RD_fds,$this->WR_fds,$this->EX_fds,$seconds,$microseconds) ;
                $this->P->Logger->debug('CNT[%d]',$rc) ;

                if($rc > 0){
                    foreach($keys as $idx){
                        if(is_array($this->WR_fds)){
                            foreach($this->WR_fds as $fd){
                                if($this->Nodes[$idx]->SOCK === $fd){
                                    $this->Nodes[$idx]->RW_FLAGS |= 2 ;
                                }
                            }
                        }
                        if(is_array($this->RD_fds)){
                            foreach($this->RD_fds as $fd){
                                if($this->Nodes[$idx]->SOCK === $fd){
                                    $this->Nodes[$idx]->RW_FLAGS |= 1 ;
                                }
                            }
                        }
                    }
                }
            }else{
                sleep(1) ;
            }
        }
    }

    if($Master = new BerdyshSuperDaemonPHP()){

        if($Listner = $Master->GenListner()){
            $Listner->Setter([BerdyshSuperDaemonPHP::AddrListen => 'tcp://0.0.0.0:8080']) ;
        }

        for(;;){
            $Master->Proc() ;
        }
    }


