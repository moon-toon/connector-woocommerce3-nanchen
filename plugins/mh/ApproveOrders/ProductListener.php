<?php

namespace mh\ApproveOrders;

use \jtl\Connector\Event\CustomerOrder\CustomerOrderAfterPullEvent;
use \jtl\Connector\Core\Logger\Logger;
use \jtl\Connector\Formatter\ExceptionFormatter;

if ( ! function_exists('write_log')) {
    function write_log ( $log )  {
       if ( is_array( $log ) || is_object( $log ) ) {
          error_log( print_r( $log, true ) );
       } else {
          error_log( $log );
       }
    }
 }

class ProductListener
{
    public function onCustomerOrderAfterPullAction(CustomerOrderAfterPullEvent $event)
    {
        $wc_order_no = $event->getCustomerOrder()->getOrderNumber();

        $url = 'h2867583.stratoserver.net:8181/addOrder';
        $ch = curl_init($url);
        $data = array(
            'token' => '7822184a-41ec-45a4-9452-3a75ad6ee3b3',
            'orderid' => $wc_order_no
        );
        $payload = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type:application/json'));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $result = curl_exec($ch);
        curl_close($ch);
        write_log($result);
    } 
}