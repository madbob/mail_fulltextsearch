<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\AppInfo;

use OCA\Mail\Events\BeforeMessageDeletedEvent;
use OCA\Mail\Events\MessageFlaggedEvent;
use OCA\Mail\Events\NewMessagesSynchronized;
use OCA\Mail_FullTextSearch\Listeners\HandleMessageDeleted;
use OCA\Mail_FullTextSearch\Listeners\HandleMessageFlagged;
use OCA\Mail_FullTextSearch\Listeners\HandleSyncronizedMessages;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

require_once __DIR__ . '/../../vendor/autoload.php';

class Application extends App implements IBootstrap {
	public const APP_ID = 'mail_fulltextsearch';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(NewMessagesSynchronized::class, HandleSyncronizedMessages::class);
		$context->registerEventListener(BeforeMessageDeletedEvent::class, HandleMessageDeleted::class);
		$context->registerEventListener(MessageFlaggedEvent::class, HandleMessageFlagged::class);
	}

	public function boot(IBootContext $context): void {
	}
}
