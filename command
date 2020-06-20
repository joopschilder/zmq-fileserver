#!/usr/bin/env php
<?php

array_shift($argv);
if (count($argv) === 0) {
	print('Command needs at least one argument' . PHP_EOL);
	die(1);
}

$context = new ZMQContext();
$socket = new ZMQSocket($context, ZMQ::SOCKET_PUSH);
$socket->connect('ipc:///tmp/storage_server_command.ipc');
$socket->sendmulti($argv);
