<?php

namespace Parsoid\Tests;

use Parsoid\Config\PageConfig;
use Parsoid\Config\PageContent;

class MockPageConfig extends PageConfig {

	/** @var PageContent|null */
	private $content;

	/** @var int */
	private $pageid;

	/** @var int */
	private $pagens;

	/** @var string */
	private $title;

	/**
	 * Construct a mock environment object for use in tests
	 * @param array $opts
	 * @param PageContent|null $content
	 */
	public function __construct( array $opts, ?PageContent $content ) {
		$this->content = $content;
		$this->title = $opts['title'] ?? 'TestPage';
		$this->pageid = $opts['pageid'] ?? -1;
		$this->pagens = $opts['pagens'] ?? 0;
	}

	public function getContentModel(): string {
		return 'wikitext';
	}

	public function hasLintableContentModel(): bool {
		return true;
	}

	/** @inheritDoc */
	public function getTitle(): string {
		return $this->title;
	}

	/** @inheritDoc */
	public function getNs(): int {
		return $this->pagens;
	}

	/** @inheritDoc */
	public function getPageId(): int {
		return $this->pageid;
	}

	/** @inheritDoc */
	public function getPageLanguage(): string {
		return 'en';
	}

	/** @inheritDoc */
	public function getPageLanguageDir(): string {
		return 'rtl';
	}

	/** @inheritDoc */
	public function getRevisionId(): ?int {
		return 1;
	}

	/** @inheritDoc */
	public function getParentRevisionId(): ?int {
		return null;
	}

	/** @inheritDoc */
	public function getRevisionTimestamp(): ?string {
		return null;
	}

	/** @inheritDoc */
	public function getRevisionUser(): ?string {
		throw new \BadMethodCallException( 'Not implemented' );
	}

	/** @inheritDoc */
	public function getRevisionUserId(): ?int {
		throw new \BadMethodCallException( 'Not implemented' );
	}

	/** @inheritDoc */
	public function getRevisionSha1(): ?string {
		return null;
	}

	/** @inheritDoc */
	public function getRevisionSize(): ?int {
		throw new \BadMethodCallException( 'Not implemented' );
	}

	/** @inheritDoc */
	public function getRevisionContent(): ?PageContent {
		return $this->content;
	}

}
