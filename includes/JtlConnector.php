<?php

/**
 * @author    Jan Weskamp <jan.weskamp@jtl-software.com>
 * @copyright 2010-2013 JTL-Software GmbH
 */

if ( ! function_exists('write_log')) {
    function write_log ( $log )  {
       if ( is_array( $log ) || is_object( $log ) ) {
          error_log( print_r( $log, true ) );
       } else {
          error_log( $log );
       }
    }
}

final class JtlConnector
{
    protected static $_instance = null;

    public static function instance()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    public static function capture_request()
    {
        global $wp;

        if (!empty($wp->request) && ($wp->request === 'jtlconnector_ext' || $wp->request === 'index.php/jtlconnector_ext')) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            if (isset($_POST['token']) && $_POST['token'] == '7822184a-41ec-45a4-9452-3a75ad6ee3b3') {
                if(isset($_POST['orderids'])) {
                    $orders = explode(",",$_POST['orderids']);
                    foreach ($orders as $orderid) {
                        $order = wc_get_order($orderid);
                        $ret = $order->set_status('approved', '' , false);
                        $order->save();
                        write_log("Changed order status from id: " .$orderid);
                        write_log($ret);
                    }      
                }
                echo "saved";
            }
            exit;
        }
        
        if (!empty($wp->request) && ($wp->request === 'jtlconnector' || $wp->request === 'index.php/jtlconnector')) {
            $application = null;
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }

            self::unslash_gpc();

            try {
                require(JTLWCC_CONNECTOR_DIR . '/src/bootstrap.php');
            } catch (\Exception $e) {
                if (is_object($application)) {
                    $handler = $application->getErrorHandler()->getExceptionHandler();
                    $handler($e);
                }
            }
        }
    }

    private static function unslash_gpc()
    {
        $_GET = array_map('stripslashes_deep', $_GET);
        $_POST = array_map('stripslashes_deep', $_POST);
        $_COOKIE = array_map('stripslashes_deep', $_COOKIE);
        $_SERVER = array_map('stripslashes_deep', $_SERVER);
        $_REQUEST = array_map('stripslashes_deep', $_REQUEST);
    }
}
