<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Listeners;

use OCA\Mail\Events\MessageDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\FullTextSearch\Model\IIndex;

class HandleMessageDeleted extends ListenersCore implements IEventListener {
	#[\Override]
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices() || !($event instanceof MessageDeletedEvent)) {
			return;
		}

		$this->fullTextSearchManager->updateIndexStatus('mail', $event->getMessageId(), IIndex::INDEX_REMOVE, true);

		/*
			TODO: here I have to find also attachments into the original
			message, and de-index them too.
			Reminder: attachments have index IDs in the form
			messageid|attachmentfilename
			Maybe can be retrieved using FullTextSearch itself, querying a
			subtag (created at index time for the attachment)
		*/
	}
}
