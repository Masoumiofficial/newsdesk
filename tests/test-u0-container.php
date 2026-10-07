<?php
/**
 * Integration smoke test: the REAL DI container must build.
 *
 * Unit tests construct services by hand, so they cannot catch a wiring
 * mistake in Plugin::container() — a missing argument there is a fatal error
 * on a live site. This resolves every service the U-0 work touched through
 * the actual container definition.
 */

require __DIR__ . '/bootstrap.php';

use NewsDesk\AI\Application\Audit\ArticleAuditor;
use NewsDesk\AI\Application\Content\DraftService;
use NewsDesk\AI\Application\Content\FillerPhraseDetector;
use NewsDesk\AI\Application\Content\StoryContentService;
use NewsDesk\AI\Application\FactCheck\ClaimRiskAssessor;
use NewsDesk\AI\Application\Images\ImagePlanService;
use NewsDesk\AI\Application\Research\StoryResearchService;
use NewsDesk\AI\Application\Security\SecurityIntelExtractor;
use NewsDesk\AI\Application\Contracts\NewsItemRepositoryInterface;
use NewsDesk\AI\Application\Linking\InternalLinkEngine;
use NewsDesk\AI\Application\Scoring\CannibalizationEngine;
use NewsDesk\AI\Application\StoryEditorialService;
use NewsDesk\AI\Application\Seo\SeoAdapterResolver;
use NewsDesk\AI\Core\Plugin;

// The real WpDb adapter forwards to $wpdb, so a full double is required.
global $wpdb;
$wpdb = new FakeGlobalWpdb();

T::group( 'container — builds without error' );

$container = null;
try {
	$container = Plugin::container();
	T::ok( true, 'Plugin::container() returns' );
} catch ( \Throwable $e ) {
	T::ok( false, 'Plugin::container() threw: ' . $e->getMessage() );
}

T::group( 'container — services touched by U-0 all resolve' );

$services = array(
	DraftService::class,
	SeoAdapterResolver::class,
	InternalLinkEngine::class,
	NewsItemRepositoryInterface::class,
	// U-1: StoryEditorialService gained two constructor arguments.
	StoryEditorialService::class,
	CannibalizationEngine::class,
	// v2.0 (A-3…A-7): new services, plus the two consumers whose constructor
	// signatures grew to accept them. A missing argument here is a fatal on a
	// live site, which no unit test would catch.
	ArticleAuditor::class,
	ClaimRiskAssessor::class,
	SecurityIntelExtractor::class,
	FillerPhraseDetector::class,
	ImagePlanService::class,
	StoryContentService::class,
	StoryResearchService::class,
);
foreach ( $services as $id ) {
	try {
		$svc = $container->get( $id );
		T::ok( is_object( $svc ), basename( str_replace( '\\', '/', $id ) ) . ' resolves' );
	} catch ( \Throwable $e ) {
		T::ok( false, $id . ' failed to resolve: ' . $e->getMessage() );
	}
}

T::group( 'container — SEO adapters are all registered' );

$resolver = $container->get( SeoAdapterResolver::class );
T::eq(
	array( 'native', 'yoast', 'rankmath', 'aioseo' ),
	$resolver->ids(),
	'all four adapters are in the resolver (v1.6.0 registered only native)'
);

T::group( 'container — singletons are shared' );

T::ok(
	$container->get( DraftService::class ) === $container->get( DraftService::class ),
	'DraftService is a singleton'
);

exit( T::summary() );
