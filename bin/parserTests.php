<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Parsoid\Tests\ParserTests\Stats;
use Parsoid\Tests\ParserTests\TestRunner;
use Parsoid\Tools\TestUtils;

// phpcs:ignore MediaWiki.Files.ClassMatchesFilename.WrongCase
class ParserTests extends \Parsoid\Tools\Maintenance {
	use \Parsoid\Tools\ExtendedOptsProcessor;

	/** @var array */
	public $processedOptions;

	public function __construct() {
		TestUtils::setupOpts( $this );
		$this->setAllowUnregisteredOptions( false );
	}

	public function execute(): bool {
		$this->processedOptions = TestUtils::processOptions( $this );

		$testFile = $this->getArg( 0 );
		if ( $testFile ) {
			$testFilePaths = [ realpath( $testFile ) ];
		} else {
			$testFilePaths = [];
			$testFiles = json_decode( file_get_contents( __DIR__ . '/../tests/parserTests.json' ), true );
			foreach ( $testFiles as $f => $info ) {
				$testFilePaths[] = realpath( __DIR__ . '/../tests/' . $f );
			}
		}

		$globalStats = new Stats();
		$blacklistChanged = false;
		$exitCode = 0;
		foreach ( $testFilePaths as $testFile ) {
			$testRunner = new TestRunner( $testFile, $this->processedOptions['modes'] );
			$result = $testRunner->run( $this->processedOptions );
			$globalStats->accum( $result['stats'] ); // Sum all stats
			$blacklistChanged = $blacklistChanged || $result['blacklistChanged'];
			$exitCode = $exitCode ?: $result['exitCode'];
			if ( $exitCode !== 0 && $this->processedOptions['exit-unexpected'] ) {
				break;
			}
		}

		$this->processedOptions['reportSummary'](
			[], $globalStats, null, null, $blacklistChanged
		);

		return $exitCode === 0;
	}
}

$maintClass = ParserTests::class;
require_once PARSOID_RUN_MAINTENANCE_IF_MAIN;
