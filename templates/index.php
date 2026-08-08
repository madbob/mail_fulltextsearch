<?php

declare(strict_types=1);

use OCP\Util;

Util::addScript(OCA\Mail_FullTextSearch\AppInfo\Application::APP_ID, OCA\Mail_FullTextSearch\AppInfo\Application::APP_ID . '-main');
Util::addStyle(OCA\Mail_FullTextSearch\AppInfo\Application::APP_ID, OCA\Mail_FullTextSearch\AppInfo\Application::APP_ID . '-main');

?>

<div id="mail_fulltextsearch"></div>
