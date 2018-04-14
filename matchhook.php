<?php
/**
 * Created by PhpStorm.
 * User: jcpw
 * Date: 27.03.18
 * Time: 07:20
 */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

//todo: use spl_outoloader instead
include 'classes/player.php';
include 'classes/match.php';
include 'classes/recentMatchHook.php';
include 'classes/discordMessage.php';

$meathookMessage = new recentMatchHook();

$meathookDiscord = new discordMessage('???????', '???????');

$meathookDiscord->username = 'Match Bot';

$meathookDiscord->send($meathookMessage->run());

//shows status messages of the script
echo $meathookDiscord->showMessage();

