<?php

require 'vendor/autoload.php';

// prepend a base path if Predis is not present in the "include_path".
// require 'Predis/Autoloader.php';
Predis\Autoloader::register();

// Open Redis, catch exceptions
// but first wait a while before trying 
sleep (30);

// since the dns does not always work, fix the ip address for localhost
try {
    $redis = new Predis\Client(array(
        'scheme' => 'tcp',
        'host'   => '127.0.0.1',
        'port'   => 6379,
        'database' => 1,
        // no timeouts on socket
        'read_write_timeout' => 0,
    ));
}
catch (Exception $e) {
    $message = date('Y-m-d H:i') . " Cannot connect to Redis " . $e->getMessage() . "\n";
    error_log($message, 3, $LOGFILE);
    // Just return to prevent the daemon from crashing
    // exit(1);
    return;
}

// Socketstream uses specific kinds of messages
// "publish" "ss:event" "{\"t\":\"all\",\"e\":\"newMessage\",\"p\":[\"\\u0013\\u0000Error in command.\"]}"
// 
// {
//    "t" : "all",
//    "e" : "newMessage",
//    "p" : [ "param1", "param2" ]
//}

class PubMessage {
    public $t = "all";              // type
    public $e = "portux";           // event, could also be portux
    public $p = array(
        'type' => '',
        'location' => '',
        'quantity' => '',
        'value' => 0.0);
        
    public function setType ($t) {
        $this->t = $t;
    }

    public function getType () {
       return $this->p['type'];
    }

    public function getLocation () {
       return $this->p['location'];
    }

    public function getQuantity () {
       return $this->p['quantity'];
    }

    public function getValue () {
       return $this->p['value'];
    }

    public function setEvent ($e) {
        $this->e = $e;
    }
    
    public function setParams ($t, $l, $q, $v) {
        $this->p['type'] = $t;
        $this->p['location'] = $l;
        $this->p['quantity'] = $q;
        $this->p['value'] = $v;
    }
    
}

?>
