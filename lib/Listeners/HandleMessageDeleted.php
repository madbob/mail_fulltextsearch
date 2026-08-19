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
use OCA\Mail\Events\BeforeMessageDeletedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\FullTextSearch\Model\IIndex;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class HandleMessageDeleted extends ListenersCore implements IEventListener {
	private $mailManager;

	public function __construct(
		IMailManager $mailManager,
		Coordinator $coordinator,
		IUserSession $userSession,
		IFullTextSearchManager $fullTextSearchManager,
		LoggerInterface $logger,
	) {
		parent::__construct($coordinator, $userSession, $fullTextSearchManager, $logger);
		$this->mailManager = $mailManager;
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!$this->registerFullTextSearchServices() || !($event instanceof BeforeMessageDeletedEvent)) {
			return;
		}

		try {
			/*
				To be fixed when this is merged
				https://github.com/nextcloud/mail/pull/13445
			*/
			$mailboxes = $this->mailManager->getMailboxes($event->getAccount());
			$targetMailbox = $event->getFolderId();
			$found = false;

			foreach($mailboxes as $mailbox) {
				if ($mailbox->getName() == $targetMailbox) {
					/*
						Reminder: in $event->getMessageId() you find, actually,
						the UID of the message.
						The function is named in ambiguous way...
					*/
					$messageId = $this->mailManager->getMessageIdForUid($mailbox, $event->getMessageId());
					if ($messageId) {
						$this->fullTextSearchManager->updateIndexStatus('mail', $messageId, IIndex::INDEX_REMOVE, true);
						$found = true;
						break;
					}
				}
			}

			if ($found == false) {
				$this->logger->warning('Deleted message not found for de-indexing');
			}

			/*
				TODO: here I have to find also attachments into the original
				message, and de-index them too.
				Reminder: attachments have index IDs in the form
				messageid|attachmentfilename
				Maybe can be retrieved using FullTextSearch itself, querying a
				subtag (created at index time for the attachment)
			*/
		}
		catch(Exception $e) {
			$this->logger->error('Error while de-indexing deleted message: ' . $e->getMessage());
		}
	}
}
