<?php

/**
 * 输出控制
 * 
 * @author Corz
 * @namespace package_name
 */
class Logger
{

    /**
     * debug信息
     * @param string $info
     */
    public static function debug($info)
    {
        if (self::isDebug()) {
			
			$now = date('Y-m-d H:i:s',time());
			echo '['.$now.']'.$info. PHP_EOL;
            //echo var_export($info, true) . PHP_EOL;
		//file_put_contents('log.txt',$info."\n",FILE_APPEND);
        }
    }

    /**
     * debug信息
     * @param string $info
     */
    public static function info($info)
    {
        //if (! DAEMON) {
			$now = date('Y-m-d H:i:s',time());
			echo '['.$now.'] '.$info. PHP_EOL;
            //echo var_export($info, true) . PHP_EOL;
        //}
    }

    /**
     * 
     */
    protected static function isDebug()
    {
        return defined('DEBUG') ? DEBUG : false;
    }
}
