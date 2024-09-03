<?php
namespace Neo;

    class NeoLog {

        static $name='unknown';

        static $logdir='/var/log';
        static $logfile="";
        static $panic_logfile="";
        static $panic_hook=null;
        static $cleanexit=false;
	    static $quietexit=false;

        static $console_logging_active=false;
        static $file_logging_active=false;
        static $console_stream=null;
        static $logstream=null;
        static $panicstream=null;
        static $level=100;

        const DEBUG = 100;
        const INFO = 200;
        const NOTICE = 250;
        const WARNING = 300;
        const ERROR = 400;
        const CRITICAL = 500;
        const ALERT = 550;



        static function init($name,$logdir='',$force_console=false,$level=NeoLog::INFO)
        {
            if (!empty($logdir))
                NeoLog::$logdir=$logdir;

            NeoLog::$name=$name;
            NeoLog::$level=$level;

            if ( defined('STDIN') ) {
                //try php 7.2 tty check first
                if ( function_exists("stream_isatty") ) {
                    if ( stream_isatty(STDIN) || $force_console ) {
                        NeoLog::initConsoleLogging();
                    } else {
                        NeoLog::initFileLogging();
                    }
                //fallback to posix otherwise
                }  else if ( @posix_isatty(STDIN) || $force_console)  {
                        NeoLog::initConsoleLogging();
                } else {
                    NeoLog::initFileLogging();    
                }
            } else {
                NeoLog::initFileLogging();
            }

            NeoLog::initErrorHandlers();

            //maintain some backward compatible constants
            if (!defined('PEAR_LOG_EMERG')) {
                define('PEAR_LOG_EMERG',    0);     /* System is unusable */
                define('PEAR_LOG_ALERT',    1);     /* Immediate action required */
                define('PEAR_LOG_CRIT',     2);     /* Critical conditions */
                define('PEAR_LOG_ERR',      3);     /* Error conditions */
                define('PEAR_LOG_WARNING',  4);     /* Warning conditions */
                define('PEAR_LOG_NOTICE',   5);     /* Normal but significant */
                define('PEAR_LOG_INFO',     6);     /* Informational */
                define('PEAR_LOG_DEBUG',    7);     /* Debug-level messages */
            }

        }

        static function enableIntrospection() {
            $processor=new NeoIntrospectionProcessor();

            /* if (NeoLog::$logger_handler)
                NeoLog::$logger_handler->pushProcessor($processor);
            if (NeoLog::$panic_logger_handler)
                NeoLog::$panic_logger_handler->pushProcessor($processor);
            */
        }

        static function disableIntrospection($handler) {
            /*
            if (NeoLog::$logger_handler)
                NeoLog::$logger_handler->popProcessor();
            if (NeoLog::$panic_logger_handler)
                NeoLog::$panic_logger_handler->popProcessor();
            */
        }


        static public function exitCleanly($msg,$context) {
            NeoLog::$cleanexit=true;
            l_notice($msg,$context);
        }

        

        static function initConsoleLogging()
        {
            NeoLog::$console_logging_active=true;
            NeoLog::$console_stream='php://stdout';
            NeoLog::$logstream=fopen(NeoLog::$console_stream,'a');

            NeoLog::$panicstream=NeoLog::$logstream;

        }

        static function initFileLogging()
        {
            if (function_exists('\neo_initFileLogging')) {
                \neo_initFileLogging();
                return;
            }


            NeoLog::$file_logging_active=true;
            NeoLog::$panic_logfile=NeoLog::$logdir.'/panic.log';
            NeoLog::$logfile=NeoLog::$logdir."/".NeoLog::$name.".log";

            if (is_file(NeoLog::$logdir)) {
                throw new Exception("Unable to log to directory: {NeoLog::$logdir}, it is a file.");
            }


            //plain stream logger
            try { 
                $stream=fopen(NeoLog::$logfile, 'a');
                if (!is_resource($stream)) {
                    throw new \Exception("unable to open logfile " . NeoLog::$logfile ." for append");
                }
            } catch(\Exception $e) {
                throw new \Exception($e);
            }
            NeoLog::$logstream=$stream;

            $panicstream=fopen(NeoLog::$panic_logfile, 'a');
            if (!is_resource($panicstream)) {
                throw new \Exception("unable to open logfile " . NeoLog::$panic_logfile ." for append");
            }
            NeoLog::$panicstream=$stream;

        }

        static function initErrorHandlers()
        {
            register_shutdown_function('Neo\NeoLog::shutdownHandler');
            error_reporting(E_ALL);
            set_error_handler("\Neo\NeoLog::errorHandler",E_ALL);
        }

        static function errorHandler($code, $message, $file, $line)
        {
            global $module_name;

            //Ignore stuff supressed by the @ operator
            if (!error_reporting())
                return;


            /* Map the PHP error to a Log priority. */
            switch ($code) {
                case E_WARNING:
                case E_USER_WARNING:
                    $priority = self::WARNING;
                    if (
                        preg_match("/twitterIntents.php/",$file) 
                        || preg_match("/templates_c/",$file) 
                        || preg_match("/templates_c/",$file) 
                        || preg_match("/HTMLPurifier.*/",$message) 
                        || ( preg_match("/Invalid argument supplied for foreach/",$message) && preg_match("/cart.php/",$file) )
                        || ( preg_match("/expects parameter 1 to be string, array given/",$message) && preg_match("/lib\/Admin.php/",$file) )
                        || ( preg_match("/expects parameter 1 to be string, object given/",$message) && preg_match("/Module\/Widget.php/",$file) )
                        || ( preg_match("/ Unable to find the wrapper &quot;tcp&quot;/",$message) && preg_match("/Environment\/Php.php/",$file) )
                        || preg_match("/Constant CLIENTAREA already defined/",$message)
                        || preg_match("/Undefined property: stdClass::.contact_country/",$message)
                    ) {
                        //only squelch to debug in production
                        if (defined('ENVIRONMENT') && (ENVIRONMENT=='production')) {
                            $priority = self::DEBUG;
                        } else {
                            $priority = self::INFO;
                        }
                    }
                    break;
                case E_NOTICE:
                case E_USER_NOTICE:
                    $priority = self::WARNING; //map to warning, so we get ppl to fix their code
                    //undefined variables or indexes we thunk to DEBUG level
                    if (
                        preg_match("/Undefined variable/",$message) 
                        || preg_match("/Undefined index/",$message)
                        || ( preg_match("/Uninitialized string offset:0/",$message) && preg_match("/cart.php/",$file) )
                        || ( preg_match("/Invalid argument supplied for foreach/",$message) )
                        || preg_match("/Undefined offset:/",$message)
                        || preg_match("/Uninitialized string offset:/",$message)
                        || preg_match("/Trying to get property 'value' of non-object/",$message)
                        || preg_match("/Constant CLIENTAREA already defined/",$message)
                        || preg_match("/Undefined property: stdClass::.contact_country/",$message)
                    ) {
                        //only squelch to debug in production
                        if (defined('ENVIRONMENT') && (ENVIRONMENT=='production')) {
                            $priority = self::DEBUG;
                        } else {
                            $priority = self::INFO;
                        }
                    }
                    break;
                case E_ERROR:
                case E_USER_ERROR:
                    $priority = self::ERROR;
                    break;
                case E_DEPRECATED:
                    $priority=self::DEBUG;
                    break;
                default:
                    $priority = self::INFO;
            }

            $l="($message) in $file at line $line";
            if (NeoLog::$logstream)  {
                NeoLog::log($priority,$l);
            } else {
                fprintf("php://stderr","(no stream)" . $l );
            }
        }

        static function FriendlyErrorType($type)
        {
            switch($type)
            {
                case E_ERROR: // 1 //
                    return 'E_ERROR';
                case E_WARNING: // 2 //
                    return 'E_WARNING';
                case E_PARSE: // 4 //
                    return 'E_PARSE';
                case E_NOTICE: // 8 //
                    return 'E_NOTICE';
                case E_CORE_ERROR: // 16 //
                    return 'E_CORE_ERROR';
                case E_CORE_WARNING: // 32 //
                    return 'E_CORE_WARNING';
                case E_CORE_ERROR: // 64 //
                    return 'E_COMPILE_ERROR';
                case E_CORE_WARNING: // 128 //
                    return 'E_COMPILE_WARNING';
                case E_USER_ERROR: // 256 //
                    return 'E_USER_ERROR';
                case E_USER_WARNING: // 512 //
                    return 'E_USER_WARNING';
                case E_USER_NOTICE: // 1024 //
                    return 'E_USER_NOTICE';
                case E_STRICT: // 2048 //
                    return 'E_STRICT';
                case E_RECOVERABLE_ERROR: // 4096 //
                    return 'E_RECOVERABLE_ERROR';
                case E_DEPRECATED: // 8192 //
                    return 'E_DEPRECATED';
                case E_USER_DEPRECATED: // 16384 //
                    return 'E_USER_DEPRECATED';
            }
            return "";
        }

        static function shutdownHandler()
        {
            if (NeoLog::$cleanexit)
                return;
            if(is_null($e = error_get_last()) === false) {
                if (isset($e['type'])) {
                    $error_type=NeoLog::FriendlyErrorType($e['type']);
                } else {
                    $error_type="unknown";
                }
                if ($error_type!="E_DEPRECATED" && $error_type!="E_NOTICE") {
                    $msg="Shutdown, Error($error_type):(" . print_r($e, true). ")";
                    NeoLog::panic($msg);
                    if (is_callable(NeoLog::$panic_hook)) {
                        call_user_func(NeoLog::$panic_hook,$e);
                    }
                }
            } else {
                //NeoLog::info("Shutdown");
            }
        }
        static function setPanicHook($callback_panic_hook)
        {
            NeoLog::$panic_hook=$callback_panic_hook;
        }

        static function setLevel($level) {
            NeoLog::$level=$level;
        }

        static function getLevel() {
            return NeoLog::$level;
        }

        static function ansi_color($color) {
            $s="\033";
            switch ($color) {
                case 'RED'     : $s.="[31m"; break;
                case 'GREEN'   : $s.="[32m"; break;
                case 'YELLOW'  : $s.="[33m"; break;
                case 'BLUE'    : $s.="[34m"; break;
                case 'MAGENTA' : $s.="[35m"; break;
                case 'CYAN'    : $s.="[36m"; break;
                case 'WHITE'   : $s.="[37m"; break;
                default        : $s.="[37m"; break;
            }
            return $s;
        }

        static function ansi_close() {    
            return "\033"."[0m";
        }

        static function format_message($level,$msg,$context=array()) {
            $prefix="UNKNOWN";
            $color="WHITE";
            switch($level) {
                case NeoLog::DEBUG    : { $prefix="DEBUG";    $color="WHITE";  break; }
                case NeoLog::INFO     : { $prefix="INFO";     $color="GREEN";   break; }
                case NeoLog::NOTICE   : { $prefix="NOTICE";   $color="CYAN";   break; }
                case NeoLog::WARNING  : { $prefix="WARNING";  $color="YELLOW"; break; }
                case NeoLog::ERROR    : { $prefix="ERROR";    $color="RED"; break; }
                case NeoLog::CRITICAL : { $prefix="CRITICAL"; $color="RED";  break; }
                case NeoLog::ALERT    : { $prefix="ALERT";    $color="RED"; break; }
            }
            $date=date("Y-m-d H:i:s");
            $cont=json_encode($context);
            $context2=array();
            $cont2=json_encode($context2);
            return NeoLog::ansi_color($color) . "[{$date}] " . NeoLog::$name . ".{$prefix}: $msg {$cont} {$cont2}" . NeoLog::ansi_close() . "\n";
        }

        static function log($level,$msg,$context=array())
        {
            if ($level>=NeoLog::$level) {
                fputs(NeoLog::$logstream, NeoLog::format_message($level,$msg,$context));
            }
        }

        static function panic_log($level,$msg,$context=array())
        {
            fputs(NeoLog::$logstream, NeoLog::format_message($level,"**PANIC**:$msg",$context));
            fputs(NeoLog::$panicstream, NeoLog::format_message($level,"**PANIC**:$msg",$context));
        }

        static function panic($msg,$context=array()) {
            NeoLog::panic_log(NeoLog::ALERT,$msg,$context);
        }

        static function debug($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::log(NeoLog::DEBUG,$msg, $context);
        }

        static function info($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::log(NeoLog::INFO,$msg, $context);
        }

        static function notice($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::log(NeoLog::NOTICE,$msg, $context);
        }

        static function warning($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::log(NeoLog::WARNING,$msg, $context);
        }

        static function error($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::log(NeoLog::ERROR,$msg, $context);
        }

        static function critical($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::log(NeoLog::CRITICAL,$msg, $context);
        }

        static function alert($msg,$context=array())
        {
            if (!is_array($context))
            $context=array( ''.gettype($context)=>$context);
            return NeoLog::log(NeoLog::ALERT,$msg, $context);
        }

        static function mapPearLevel($level) {
            switch ($level) {
                case PEAR_LOG_INFO   : return NeoLog::INFO;
                case PEAR_LOG_DEBUG  : return NeoLog::DEBUG;
                case PEAR_LOG_NOTICE : return NeoLog::NOTICE;

                case PEAR_LOG_EMERG : return NeoLog::CRITICAL;
                case PEAR_LOG_ALERT : return NeoLog::ALERT;
                case PEAR_LOG_CRIT  : return NeoLog::CRITICAL;
                case PEAR_LOG_ERR   : return NeoLog::ERROR;
                case PEAR_LOG_WARNING: return NeoLog::WARNING;
            }
            return DEBUG;
        }

    }


include_once(__DIR__ . "/neo_globals.php");
