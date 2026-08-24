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
use OCP\IConfig;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class IndexService extends BaseService {
	/**
	 * Here, many internal parts of the Mail app are included.
	 * Perhaps this is not optimal, yet probably better than directly mess with
	 * the Mail's database
	 */
	public function __construct(
		private IConfig $config,
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
	 * All mailboxes are chunked in blocks of 1000 items (or less).
	 * Each chunk may then become larger, as attachments found in the messages
	 * are handled as individual documents.
	 * Reminder: do not generate a chunk for each single mailbox without
	 * slicing, if one contains too many messages the whole indexing process may
	 * allocate too much memory once
	 */
	public function generateChunks(string $userId): array {
		$ret = [];

		$mailVersion = (float) $this->config->getAppValue('mail', 'installed_version');

		$accounts = $this->accountService->findByUserId($userId);
		foreach ($accounts as $account) {
			// The "protocol" attribute has been introduced in Mail 5.12, to
			// sort IMAP and JMAP accounts
			if ($mailVersion >= 5.12 && $account->getMailAccount()->getProtocol() !== 'imap') {
				continue;
			}

			try {
				$mailboxes = $this->mailManager->getMailboxes($account, true);
				foreach ($mailboxes as $mailbox) {
					$ids = $this->messageMapper->findAllIds($mailbox);
					while(true) {
						$subIds = array_splice($ids, 0, 1000);
						if (empty($subIds)) {
							break;
						}

						$chunk = sprintf('%s-%s-%s', $mailbox->getId(), $subIds[0], $subIds[count($subIds) - 1]);
						$ret[] = $chunk;
					}
				}
			} catch (\Exception $e) {
				$this->logger->warning('Unable to list mailboxes to index in account ' . $account->getId(), ['exception' => $e]);
			}
		}

		$this->logger->debug('Found ' . count($ret) . ' mailboxes to index');
		return $ret;
	}

	public function generateIndexableDocuments(string $userId, string $chunk) {
		$list = [];

		try {
			[$mailboxId, $start, $end] = explode('-', $chunk);
			$start = intval($start);
			$end = intval($end);

			$mailbox = $this->getMailbox($userId, $mailboxId);
			$account = $this->getAccount($userId, $mailbox->getAccountId());
			$client = $this->getClient($account);

			$filteredIds = [];
			$valid = false;
			$ids = $this->messageMapper->findAllIds($mailbox);

			foreach($ids as $id) {
				if (!$valid) {
					if ($id === $start) {
						$filteredIds[] = $id;
						$valid = true;

						if ($id === $end) {
							break;
						}
					}
				}
				else {
					$filteredIds[] = $id;
					if ($id === $end) {
						$valid = false;
						break;
					}
				}
			}

			$messages = $this->messageMapper->findByMailboxAndIds($mailbox, $userId, $filteredIds);

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

		$this->logger->debug('Found ' . count($list) . ' messages in mailboxes ' . $mailboxId . ' to index');
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
