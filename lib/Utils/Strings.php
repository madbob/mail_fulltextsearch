<?php

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2026 Roberto Guido
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail_FullTextSearch\Utils;

class Strings {
	/**
	 * Given a IndexDocument ID, parses it to determine if it refers to a mail
	 * message or to an attachment
	 */
	public static function splitIndexDocumentId($id) {
		$separator = strpos($id, '|');
		if ($separator !== false) {
			$attachmentId = substr($id, $separator + 1);
			$messageId = substr($id, 0, $separator);
		} else {
			$attachmentId = null;
			$messageId = $id;
		}

		return [$messageId, $attachmentId];
	}
}
