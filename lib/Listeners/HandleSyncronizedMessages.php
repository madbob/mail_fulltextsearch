<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Listeners;

use OCA\Mail\Events\NewMessagesSynchronized;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\FullTextSearch\Model\IIndex;

class HandleSyncronizedMessages extends ListenersCore implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices() || !($event instanceof NewMessagesSynchronized)) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			return;
		}

		$messages = $event->getMessages();

		foreach ($messages as $message) {
			try {
				$this->fullTextSearchManager->createIndex('mail', $message->getId(), $user->getUID(), IIndex::INDEX_FULL);
			} catch (\Exception $e) {
				$this->logger->warning('issue while updating index status', ['exception' => $e]);
			}
		}
	}
}
