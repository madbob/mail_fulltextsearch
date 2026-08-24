<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Services;

use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\AccountService;
use OCA\Mail_FullTextSearch\Utils\Strings;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\IURLGenerator;
use PhpMimeMailParser\Parser;

class BaseService {
	/*
		Here I keep some local cache for recurring items
	*/
	private array $imapClients = [];
	private array $mailboxes = [];
	private array $accounts = [];
	private array $sources = [];

	protected IMAPClientFactory $clientFactory;
	protected AccountService $accountService;
	protected IMailManager $mailManager;
	protected IURLGenerator $urlGenerator;

	protected function isPopulated($document): bool {
		return !empty($document->getMetaTags());
	}

	/**
	 * Populates some meta tags to the IndexDocument, to be then used to improve
	 * search filters
	 */
	private function fillMetaTags($document, $message) {
		$recipients = [
			'from' => $message->getFrom(),
			'to' => $message->getTo(),
			'cc' => $message->getCc(),
			'bcc' => $message->getBcc(),
		];

		foreach ($recipients as $type => $addresses) {
			foreach ($addresses->iterate() as $address) {
				$fullAddress = join(' ', array_filter(array_unique([$address->getLabel(), $address->getEmail()])));
				$meta = sprintf('%s:%s', $type, $fullAddress);
				$document->addMetaTag($meta);
			}
		}

		/*
			Keeping the maildir ID is useful both for fine-grained search than
			to build a link to reach the message in the Mail app
		*/
		$document->addMetaTag('maildir:' . $message->getMailboxId());

		return $document;
	}

	protected function populateDocument($document): void {
		$userId = $document->getAccess()->getOwnerId();
		if (empty($userId)) {
			$userId = $document->getAccess()->getViewerId();
		}

		$messageId = $document->getId();
		$attachmentId = null;

		[$messageId, $attachmentId] = Strings::splitIndexDocumentId($messageId);

		$message = $this->mailManager->getMessage($userId, $messageId);
		$document->setModifiedTime($message->getSentAt());

		$link = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('mail.page.thread', ['mailboxId' => $message->getMailboxId(), 'id' => $message->getId()]));
		$document->setLink($link);

		$document = $this->fillMetaTags($document, $message);

		/*
			For convenience, here I use PhpMimeMailParser instead of the messy
			Mail internal functions to retrieve the attachments' contents
		*/
		$source = $this->getMessageSource($userId, $message);
		$parser = new Parser();
		$parser->setText($source);
		$attachments = $parser->getAttachments(false);

		if ($attachmentId) {
			$found = false;

			foreach ($attachments as $attachment) {
				if ($attachment->getFilename() === $attachmentId) {
					$document->setTitle($attachment->getFilename());
					$document->setContent(base64_encode($attachment->getContent()), IIndexDocument::ENCODED_BASE64);
					$document->addMetaTag('mime:' . $attachment->getContentType());
					$found = true;
					break;
				}
			}

			if (!$found) {
				$document->getIndex()->setStatus(IIndex::INDEX_IGNORE);
			}
		} else {
			$document->setTitle($message->getSubject());

			$body = $parser->getMessageBody();
			if (empty($body)) {
				$body = $parser->getMessageBody('text');
			} else {
				$body = strip_tags($body);
			}

			if (!empty($attachments)) {
				$document->addMetaTag('has:attachments');
			}

			$document->setContent($body);
		}
	}

	protected function getMailbox($userId, $id) {
		if (!isset($this->mailboxes[$id])) {
			$this->mailboxes[$id] = $this->mailManager->getMailbox($userId, $id);
		}

		return $this->mailboxes[$id];
	}

	protected function getAccount($userId, $id) {
		if (!isset($this->accounts[$id])) {
			$this->accounts[$id] = $this->accountService->find($userId, $id);
		}

		return $this->accounts[$id];
	}

	protected function getMessageSource($userId, $message) {
		$messageId = $message->getId();

		if (!isset($this->sources[$messageId])) {
			$mailbox = $this->getMailbox($userId, $message->getMailboxId());
			$account = $this->getAccount($userId, $mailbox->getAccountId());
			$client = $this->getClient($account);
			$this->sources[$messageId] = $this->mailManager->getSource($client, $account, $mailbox->getName(), $message->getUid());

			/*
				Here I keep a limited amount of items in cache, to avoid large
				memory consumption
			*/
			if (count($this->sources) > 10) {
				$older = array_key_first($this->sources);
				unset($this->sources[$older]);
			}
		}

		return $this->sources[$messageId];
	}

	protected function getClient($account) {
		$id = $account->getId();
		if (!isset($this->imapClients[$id])) {
			$this->imapClients[$id] = $this->clientFactory->getClient($account);
		}

		return $this->imapClients[$id];
	}
}
