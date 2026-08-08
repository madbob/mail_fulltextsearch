<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Services;

use OC\FullTextSearch\Model\DocumentAccess;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\Db\MessageMapper;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\Attachment\AttachmentService;
use OCA\Mail_FullTextSearch\Model\Attachment;
use OCA\Mail_FullTextSearch\Model\Message;
use OCA\Mail_FullTextSearch\Utils\Strings;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class IndexService extends BaseService {
	/**
	 * Here, many internal parts of the Mail app are included.
	 * Perhaps this is not optimal, yet probably better than directly mess with
	 * the Mail's database
	 */
	public function __construct(
		protected IMAPClientFactory $clientFactory,
		protected AccountService $accountService,
		protected IMailManager $mailManager,
		protected MessageMapper $messageMapper,
		protected AttachmentService $attachmentService,
		protected IURLGenerator $urlGenerator,
		protected readonly LoggerInterface $logger,
	) {
		$this->clientFactory = $clientFactory;
		$this->accountService = $accountService;
		$this->mailManager = $mailManager;
		$this->urlGenerator = $urlGenerator;
	}

	/**
	 * The strategy is to chunk indexing by mailbox (where mailbox = each folder
	 * into a mail account). Here I collect all accounts for the required user,
	 * and for each I prepare a separate chunk
	 */
	public function listMailboxes(string $userId): array {
		$ret = [];

		$accounts = $this->accountService->findByUserId($userId);
		foreach ($accounts as $account) {
			if ($account->getMailAccount()->getProtocol() !== 'imap') {
				continue;
			}

			try {
				$mailboxes = $this->mailManager->getMailboxes($account, true);
				foreach ($mailboxes as $mailbox) {
					$ret[] = (string)$mailbox->getId();
				}
			} catch (\Exception $e) {
				$this->logger->warning('Unable to list mailboxes to index in account ' . $account->getId(), ['exception' => $e]);
			}
		}

		return $ret;
	}

	public function getEasyMessages(string $userId, int $mailboxId) {
		$list = [];

		try {
			$mailbox = $this->getMailbox($userId, $mailboxId);
			$account = $this->getAccount($userId, $mailbox->getAccountId());
			$client = $this->getClient($account);

			$ids = $this->messageMapper->findAllIds($mailbox);
			$messages = $this->messageMapper->findByMailboxAndIds($mailbox, $userId, $ids);

			foreach ($messages as $message) {
				try {
					$msg = new Message('mail', $message->getId());
					$msg->setSource($account->getEmail());
					$msg->setAccess(new DocumentAccess($userId));
					$msg->setModifiedTime($message->getSentAt());
					$msg->setTitle($message->getSubject());
					$list[] = $msg;

					if ($message->getFlagAttachments()) {
						/*
							For each attachment I create a separated index entry, which
							will be linked to the actual email
						*/
						$attachments = $this->attachmentService->getAttachmentNames($account, $mailbox, $message, $client);
						foreach ($attachments as $attachment) {
							try {
								if ($attachment['fileName']) {
									$composedId = sprintf('%s|%s', $message->getId(), $attachment['fileName']);
									$att = new Attachment('mail', $composedId);
									$msg->setSource($account->getEmail());
									$att->setAccess(new DocumentAccess($userId));
									$att->setModifiedTime($message->getSentAt());
									$att->setTitle($attachment['fileName']);
									$list[] = $att;
								}
							} catch (\Exception $e) {
								$this->logger->warning('Unable to prepare attachment in message ' . $message->getId() . ' in mailbox ' . $mailboxId . ' for indexing', ['exception' => $e]);
							}
						}
					}
				} catch (\Exception $e) {
					$this->logger->warning('Unable to prepare message ' . $message->getId() . ' in mailbox ' . $mailboxId . ' for indexing', ['exception' => $e]);
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning('Unable to retrieve messages for mailbox ' . $mailboxId, ['exception' => $e]);
		}

		return $list;
	}

	public function getFullMessage($document) {
		$this->populateDocument($document);
	}

	public function updateByIndex($index) {
		$fullId = $index->getDocumentId();
		[$messageId, $attachmentId] = Strings::splitIndexDocumentId($fullId);

		if ($attachmentId) {
			$document = new Attachment($index->getProviderId(), $fullId);
		} else {
			$document = new Message($index->getProviderId(), $fullId);
		}

		$userId = $index->getOwnerId();
		$document->setAccess(new DocumentAccess($userId));

		$this->getFullMessage($document);
		return $document;
	}
}
