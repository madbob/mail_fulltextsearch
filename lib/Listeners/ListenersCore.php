<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Listeners;

use OC\AppFramework\Bootstrap\Coordinator;
use OCP\FullTextSearch\IFullTextSearchManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ListenersCore {
	public function __construct(
		protected Coordinator $coordinator,
		protected IUserSession $userSession,
		protected IFullTextSearchManager $fullTextSearchManager,
		protected LoggerInterface $logger,
	) {
	}

	protected function registerFullTextSearchServices(): bool {
		$this->coordinator->bootApp('fulltextsearch');
		return $this->fullTextSearchManager->isAvailable();
	}
}
