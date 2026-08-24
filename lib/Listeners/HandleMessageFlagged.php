<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Listeners;

use Exception;
use OC\AppFramework\Bootstrap\Coordinator;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Events\MessageFlaggedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndex;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class HandleMessageDeleted extends ListenersCore implements IEventListener {
	private $mailManager;

	public function __construct(
		Coordinator $coordinator,
		IUserSession $userSession,
		IFullTextSearchManager $fullTextSearchManager,
		LoggerInterface $logger,
	) {
		parent::__construct($coordinator, $userSession, $fullTextSearchManager, $logger);
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices() || !($event instanceof MessageFlaggedEvent)) {
			return;
		}

		try {
			$message = $event->getMessage();
			$this->fullTextSearchManager->updateIndexStatus('mail', $message->getId(), IIndex::INDEX_META, true);
		}
		catch(Exception $e) {
			$this->logger->error('Error while updating flags for message: ' . $e->getMessage());
		}
	}
}
