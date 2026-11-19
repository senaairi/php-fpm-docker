<?php
namespace Lihzorg\PhpFpmSrvApi;

class Logs {

    /**
     * @param string $ns 日志空间
     * @param string $message 日志内容
     * @return void
     */
    public static function info(string $ns, string $message): void {
        self::logs($ns,'info', $message);
    }
    /**
     * @param string $ns 日志空间
     * @param string $message 日志内容
     * @return void
     */
    public static function error(string $ns, string $message): void {
        self::logs($ns,'error', $message);
    }
    /**
     * @param string $ns 日志空间
     * @param string $message 日志内容
     * @return void
     */
    public static function debug(string $ns, string $message): void {
        self::logs($ns,'debug', $message);
    }
    /**
     * @param string $ns 日志空间
     * @param string $message 日志内容
     * @return void
     */
    public static function warning(string $ns, string $message): void {
        self::logs($ns,'warning', $message);
    }

    /**
     * @param string $ns 日志空间
     * @param string $level 日志等级
     * @param string $message 日志内容
     * @return void
     */
    public static function logs(string $ns, string $level, string $message)
    {
        $dir = ROOT_PATH.'/data/logs/'.date('Y-m-d');
        if (!file_exists($dir)) mkdir($dir, 0777, true);
        if (
            ($level == 'info' && Content::env('PFS_LOGS_INFO_PRINT', 'true')=='true')
            || ($level == 'debug' && Content::env('PFS_LOGS_DEBUG_PRINT', 'true')=='true')
            || ($level == 'warning' && Content::env('PFS_LOGS_WARNING_PRINT', 'true')=='true')
            || ($level == 'error' && Content::env('PFS_LOGS_ERROR_PRINT', 'true')=='true')
        ) {
            echo '['.date('Y-m-d H:i:s').']['.$level.']['.$ns.'] '.$message.PHP_EOL;
        }
        if (
            ($level == 'info' && Content::env('PFS_LOGS_INFO_SAVE', '') == 'true')
            || ($level == 'debug' && Content::env('PFS_LOGS_DEBUG_SAVE', '') == 'true')
            || ($level == 'warning' && Content::env('PFS_LOGS_WARNING_SAVE', 'true') == 'true')
            || ($level == 'error' && Content::env('PFS_LOGS_ERROR_SAVE', 'true') == 'true')
        ) {
            error_log('['.date('Y-m-d H:i:s').'] '.$message."\n\n", 3, $dir.'/'.$ns.'-'.$level.'.log');
        }
    }
}
