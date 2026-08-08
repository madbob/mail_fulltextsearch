<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Services;

use OC\FullTextSearch\Model\SearchRequestSimpleQuery;
use OCA\Mail\Contracts\IMailManager;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\AccountService;
use OCA\Mail_FullTextSearch\Utils\Strings;
use OCP\Files\IMimeTypeDetector;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchRequestSimpleQuery;
use OCP\FullTextSearch\Model\ISearchResult;
use OCP\IURLGenerator;

class SearchService extends BaseService {
	/**
	 * Here, many internal parts of the Mail app are included.
	 * Perhaps this is not optimal, yet probably better than directly mess with
	 * the Mail's database or remote mail server
	 */
	public function __construct(
		protected IMAPClientFactory $clientFactory,
		protected AccountService $accountService,
		protected IMailManager $mailManager,
		protected readonly IMimeTypeDetector $mimeTypeDetector,
		protected IURLGenerator $urlGenerator,
	) {
		$this->clientFactory = $clientFactory;
		$this->accountService = $accountService;
		$this->mailManager = $mailManager;
		$this->urlGenerator = $urlGenerator;
	}

	public function improveSearchRequest(ISearchRequest $request): void {
		$query = $request->getSearch();
		$remain = [];

		$tokens = explode(' ', $query);
		foreach($tokens as $token) {
			$token = trim($token);
			if (empty($token)) {
				continue;
			}

			$separator = strpos($token, ':');
			if ($separator === false) {
				$remain[] = $token;
				continue;
			}

			$operator = trim(substr($token, 0, $separator));
			$value = trim(substr($token, $separator + 1));

			switch($operator) {
				case 'from':
				case 'to':
				case 'cc':
				case 'bcc':
					$subquery = new SearchRequestSimpleQuery('metatags', ISearchRequestSimpleQuery::COMPARE_TYPE_REGEX);
					$subquery->addValue($operator . ':.*' . $value . '.*');
					$request->addSimpleQuery($subquery);
					break;

				case 'has':
					if (str_starts_with($value, 'attachment')) {
						$subquery = new SearchRequestSimpleQuery('metatags', ISearchRequestSimpleQuery::COMPARE_TYPE_KEYWORD);
						$subquery->addValue('has:attachments');
						$request->addSimpleQuery($subquery);
						break;
					}

					/*
						Intentional missing break
					*/

				default:
					$remain[] = $token;
					break;
			}
		}

		$request->setSearch(join(' ', $remain));
	}

	private function getTargetMetaTag($document, $metatag) {
		$metatags = $document->getMetaTags();
		$formatted = $metatag . ':';
		foreach($metatags as $tag) {
			if (str_starts_with($tag, $formatted)) {
				return substr($tag, strlen($formatted));
			}
		}

		return null;
	}

	private function getMailFrom($document) {
		$from = $this->getTargetMetaTag($document, 'from');
		if ($from) {
			$regex = '/\\S+@\\S+\\.\\S+/';
			preg_match($regex, $from, $matches);
			return $matches[0] ?? '';
		}

		return '';
	}

	public function improveSearchResult(ISearchResult $searchResult): void {
		$documents = $searchResult->getDocuments();

		foreach ($documents as $document) {
			/*
				Most of the required data should already be in the document,
				retrieved directly from the index platform. If not, I fetch them
				again from the local database and/or the IMAP server
			*/
			if ($this->isPopulated($document) == false) {
				$this->populateDocument($document);
			}
			else {
				/*
					Link is not saved in the indexer: here I retrieve the route
					to reach the message in the Mail app
				*/
				$maildirId = $this->getTargetMetaTag($document, 'maildir');
				if ($maildirId) {
					$link = $this->urlGenerator->getAbsoluteURL($this->urlGenerator->linkToRoute('mail.page.thread', [
						'mailboxId' => $maildirId,
						'id' => $document->getId()
					]));

					$document->setLink($link);
				}
			}

			[$messageId, $attachmentId] = Strings::splitIndexDocumentId($document->getId());

			if ($attachmentId) {
				$mime = $this->getTargetMetaTag($document, 'mime');
				$icon = $this->mimeTypeDetector->mimeTypeIcon($mime ?: '');
				$document->setInfoArray('unified', [
					'thumbUrl' => '',
					'icon' => $icon,
					'rounded' => true,
				]);
			} else {
				$from = $this->getMailFrom($document);
				if ($from !== '') {
					$link = $this->urlGenerator->linkToRoute('mail.avatars.image', ['email' => $from]);
					$document->setInfoArray('unified', [
						'thumbUrl' => $link,
						'icon' => 'icon-mail',
						'rounded' => true,
					]);
				}
			}
		}
	}
}
