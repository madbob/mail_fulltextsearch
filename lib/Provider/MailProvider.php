<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Provider;

use OC\FullTextSearch\Model\SearchTemplate;
use OCA\Mail_FullTextSearch\Model\Message;
use OCA\Mail_FullTextSearch\Services\IndexService;
use OCA\Mail_FullTextSearch\Services\SearchService;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\IFullTextSearchProvider;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IIndexOptions;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\FullTextSearch\Model\ISearchTemplate;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

class MailProvider implements IFullTextSearchProvider {
	public const MAIL_PROVIDER_ID = 'mail';
	private ?IRunner $runner = null;
	private IIndexOptions $indexOptions;

	public function __construct(
		private readonly IL10N $l10n,
		private IndexService $indexService,
		private SearchService $searchService,
		private readonly LoggerInterface $logger,
	) {
	}

	public function getId(): string {
		return self::MAIL_PROVIDER_ID;
	}

	public function getName(): string {
		return $this->l10n->t('Mail');
	}

	public function getConfiguration(): array {
		return [];
	}

	public function setRunner(IRunner $runner): void {
		$this->runner = $runner;
	}

	public function setIndexOptions(IIndexOptions $options): void {
		$this->indexOptions = $options;
	}

	public function getSearchTemplate(): ISearchTemplate {
		return new SearchTemplate('icon-fts-mail', 'fulltextsearch');
	}

	public function loadProvider() {
		// dummy
	}

	/**
	 * Retrieves the list of all mailboxes for the given user.
	 * Indexing will procede one mailbox at a time
	 */
	public function generateChunks(string $userId): array {
		return $this->indexService->generateChunks($userId);
	}

	/**
	 * Given a mailboxes, retrieves a light version of each message
	 */
	public function generateIndexableDocuments(string $userId, string $chunk): array {
		return $this->indexService->generateIndexableDocuments($userId, $chunk);
	}

	/**
	 * Given a message, it retrieves all of his contents to be indexed
	 */
	public function fillIndexDocument(IIndexDocument $document): void {
		$this->indexService->getFullMessage($document);

		/*
			Slowing down to avoid break the IMAP server...
		*/
		usleep(100000);
	}

	/**
	 * Mails never change...
	 */
	public function isDocumentUpToDate(IIndexDocument $document): bool {
		return true;
	}

	public function updateDocument(IIndex $index): IIndexDocument {
		return $this->indexService->updateByIndex($index);
	}

	public function onInitializingIndex(IFullTextSearchPlatform $platform) {
		// dummy
	}

	public function onResettingIndex(IFullTextSearchPlatform $platform) {
		// dummy
	}

	public function unloadProvider(): void {
		// dummy
	}

	public function improveSearchRequest(ISearchRequest $searchRequest): void {
		$this->searchService->improveSearchRequest($searchRequest);
	}

	public function improveSearchResult(ISearchResult $searchResult): void {
		$this->searchService->improveSearchResult($searchResult);
	}
}
