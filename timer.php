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
// <date>2013-12-15</date>
// <summary>timer sends a redis pubsub message every minute or so to keep the receiver alive</summary>


/**
 * System_Daemon Example Code
 * 
 * If you run this code successfully, a daemon will be spawned
 * and stopped directly. You should find a log enty in 
 * /var/log/simple.log
 * 
 */

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
    'appName' => 'timer',
    'appDir' => dirname(__FILE__),
    'appDescription' => 'Sends pubsub message with time every minute',
    'authorName' => 'Peter van Es',
    'authorEmail' => 'vanesp@escurio.com',
    'sysMaxExecutionTime' => '0',
    'sysMaxInputTime' => '0',
    'sysMemoryLimit' => '1024M',
    'appRunAsGID' => 20,            // dialout group for /dev/ttyS1
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


// get access credentials
include('access.php');

date_default_timezone_set('Europe/Amsterdam');

// DEBUG
$debug = false;

// don't timeout!
set_time_limit(0);

// include Redis pub sub functionality
include('redis.php');


if ($debug) System_Daemon::info("timer.php: Ready to send clock ticks...\n");
while (true) {

    if ($debug) System_Daemon::info("timer.php: Publishing tick\n"); 							
    
    // update Redis using socketstream message
    $msg = new PubMessage;
    $msg->setParams('Tick', '', '', time());
    // check if redis is still connected
    if (!$redis->isConnected()) {
        try {
            $redis->connect();
        }
        catch (Exception $e) {
            $message = date('Y-m-d H:i') . " timer.php: Cannot connect to Redis " . $e->getMessage() . "\n";
            if ($debug) System_Daemon::notice($message);
        }
    }
    if ($redis->isConnected()) {
        try {
            $redis->publish('ss:event', json_encode($msg));
        }
        catch (Exception $e) {
            $message = date('Y-m-d H:i') . " timer.php: Cannot publish to Redis " . $e->getMessage() . "\n";
            if ($debug) System_Daemon::notice($message);
        }
    }
    
    // and now sleep for a minute or so
    sleep (60);
} // while


System_Daemon::stop();

?>
