#!/usr/bin/php
<?php
// Git version

// <copyright> Copyright (c) 2012 All Rights Reserved,
// Escurio
// http://www.escurio.com/
//
// THIS CODE AND INFORMATION ARE PROVIDED "AS IS" WITHOUT WARRANTY OF ANY
// KIND, EITHER EXPRESSED OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
// IMPLIED WARRANTIES OF MERCHANTABILITY AND/OR FITNESS FOR A
// PARTICULAR PURPOSE.
//
// </copyright>
// <author>Peter van Es</author>
// <version>1.0</version>
// <email>vanesp@escurio.com</email>
// <date>2014-11-11</date>
// <summary>notifier receives messages from pub/sub redis and alerts the user via e-mail</summary>

// version 1.0

// This program needs to be run from inittab as a Daemon
//
// Currently it receives all data as Pub/Sub messages from the Redis instance stored
// and run on the portux.local machine
//
// It uses the Predis library for this
//
// When a Motion event is received, for location 6, it sends an e-mail to the user alerting
// them on the water condition.

/**
 * System_Daemon Code
 *
 * If you run this code successfully, a daemon will be spawned
 * and stopped directly. You should find a log enty in
 * /var/log/simple.log
 *
 */

// Definitions that etermine operation of this deamon
define ('LOCATION', 6);         // location on which the motion alert indicates water leak
define ('EMAIL', 'vanes.peter@gmail.com');      // where is the alert sent, can be a comma
                                                // separated list of emails
define ('MSG', 'Water gedetecteerd onder de Nefit ketel!');
define ('INTERVAL', 24*60*60);  // seconds between e-mails

// Global data structure
$debug = false;
$lastemail = 0;         // time of last email sent
$alerts = 0;            // nr of alerts received in the meantime
$headers = 'From: root@escurio.com' . "\r\n" .
    'Reply-To: root@escurio.com' . "\r\n" .
    'X-Mailer: PHP/' . phpversion();


// Make it possible to test in source directory
// This is for PEAR developers only
ini_set('include_path', ini_get('include_path').':..');

// Include Class
error_reporting(E_ALL);
require_once "System/Daemon.php";

// No PEAR, run standalone
System_Daemon::setOption("usePEAR", false);

// Setup
$options = array(
	'appName' => 'notifier',
	'appDir' => dirname(__FILE__),
	'appDescription' => 'Notifies user on alerts for water conditions',
	'authorName' => 'Peter van Es',
	'authorEmail' => 'vanesp@escurio.com',
	'sysMaxExecutionTime' => '0',
	'sysMaxInputTime' => '0',
	'sysMemoryLimit' => '1024M',
	'appRunAsGID' => 1000,
	'appRunAsUID' => 1000,
);

System_Daemon::setOptions($options);
System_Daemon::log(System_Daemon::LOG_INFO, "Daemon not yet started so this ".
	"will be written on-screen");

// Spawn Deamon!
System_Daemon::start();
System_Daemon::log(System_Daemon::LOG_INFO, "Daemon: '".
	System_Daemon::getOption("appName").
	"' spawned! This will be written to ".
	System_Daemon::getOption("logLocation"));

date_default_timezone_set('Europe/Amsterdam');

// don't timeout!
set_time_limit(0);

// function to handle a motion event on a location
function handleMotion($location) {
    global $debug, $lastemail, $alerts, $headers;

	// do we have the correct location ?
	if ($location == LOCATION) {
	    if ($debug) System_Daemon::info('Wateralarm received, secs: %d', time()-$lastemail );

	    // yes we do...
	    $alerts++;          // count the number of alerts
	    if (time() >= ($lastemail + INTERVAL)) {
	        // we last sent the email more than interval seconds ago, send again
	        mail (EMAIL, MSG, 'Alerts: '.$alerts, $headers);
	        $lastemail = time();
	        $alerts=0;
	        if ($debug) System_Daemon::info("Email sent");
	    }
	}
}

// MAIN Code
require 'vendor/autoload.php';

// prepend a base path if Predis is not present in the "include_path".
// require 'Predis/Autoloader.php';
Predis\Autoloader::register();

// Open Redis, catch exceptions
// but first wait a while before trying
sleep (10);

// since the dns does not always work, fix the ip address for localhost
try {
    $redis = new Predis\Client(array(
        'scheme' => 'tcp',
        'host'   => 'portux.local',
        'port'   => 6379,
        'database' => 1,
        // no timeouts on socket
        'read_write_timeout' => 0,
    ));
}
catch (Exception $e) {
    $message = date('Y-m-d H:i') . " Cannot connect to Redis " . $e->getMessage() . "\n";
    error_log($message, 3, $LOGFILE);
}

// connect to redis and quit if impossible, as we cannot do anything when that happens
if (!$redis->isConnected()) {
	try {
		$redis->connect();
		$pubredis = true;
	}
	catch (Exception $e) {
		$pubredis = false;
		$message = date('Y-m-d H:i') . " Cannot connect to Redis for subscribing " . $e->getMessage();
		System_Daemon::notice($message);
		// Just return to prevent the daemon from crashing
		// exit(1);
		return;
	}
}

// Socketstream uses specific kinds of messages
// "publish" "ss:event" "{\"t\":\"all\",\"e\":\"newMessage\",\"p\":[\"\\u0013\\u0000Error in command.\"]}"
//
// {
//    "t" : "all",
//    "e" : "newMessage",
//    "p" : [ "param1", "param2" ]
//}

if ($pubredis) {
	// Initialize a new pubsub context
	$pubsub = $redis->pubSubLoop();

	// Subscribe to your channels
	$pubsub->subscribe('ss:event');

	// Start processing the pubsub messages
	foreach ($pubsub as $message) {
		switch ($message->kind) {
			case 'subscribe':
				if ($debug) System_Daemon::info("Subscribed to {$message->channel}");
				break;

			case 'message':
				if ($debug) System_Daemon::info("Received {$message->payload}");
				// determine the kind of message
				$obj = json_decode ($message->payload);
				// if e is "newMessage" it is stuff from the Nodo
				// if e is portux, then we deal with it below
                if ($obj->e == 'portux') {
					// deal with the different kinds of message, take action on the
					// switches in the array switch
					if ($obj->p->type == 'Motion') {
							handleMotion($obj->p->location);
							// and that's where we sent the e-mail
					} // switch on type
				}
			    break;
		} //switch on message kind
	} // foreach message

} // if $pubredis

// Always unset the pubsub context instance when you are done! The
// class destructor will take care of cleanups and prevent protocol
// desynchronizations between the client and the server.
unset($pubsub);

System_Daemon::stop();

?>
